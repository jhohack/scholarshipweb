<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';
require_once $base_path . '/includes/auth.php';
require_once $base_path . '/includes/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require $base_path . '/vendor/autoload.php';

// Helper: Convert GWA to Percentage
function convertGwaToPercentage($gwa) {
    if ($gwa === null || $gwa === '') return '-';
    $gwa = floatval($gwa);
    
    // If already percentage (e.g. 85, 90), return as is
    if ($gwa > 5.0) return round($gwa);

    // Conversion based on input: 1.0 -> 100, 2.0 -> 90
    return round(110 - (10 * $gwa));
}

function isIncomingApplicant(array $application): bool {
    return ($application['applicant_type'] ?? '') === 'New'
        && ($application['student_status'] ?? '') === 'Incoming Student';
}

function getApplicantStatusLabel(array $application): string {
    if (($application['applicant_type'] ?? '') === 'Renewal') {
        return 'Renewal Student';
    }

    return $application['student_status'] ?? 'Continuing Student';
}

function formatAcademicDisplay(array $application, string $field): string {
    if (isIncomingApplicant($application)) {
        return match ($field) {
            'program' => 'Incoming',
            'year_level' => 'Not Enrolled',
            'units_enrolled', 'gwa' => 'Pending',
            default => '-',
        };
    }

    if ($field === 'gwa') {
        return convertGwaToPercentage($application[$field] ?? '');
    }

    $value = $application[$field] ?? null;
    return ($value === null || $value === '') ? '-' : (string)$value;
}

dbEnsureNotificationsTable($pdo);
dbEnsureApplicationsSchema($pdo);

// Sync Data: Automatically parse year_program into new columns if they are empty
// This ensures existing data is split correctly based on specific programs and year levels
$known_programs = ['AB-THEO', 'BEED', 'BSIT', 'BSED_ENGLISH', 'BSED_MATH', 'BSED- ENGLISH'];
foreach ($known_programs as $prog) {
    $stmt = $pdo->prepare("UPDATE applications SET program = ? WHERE year_program LIKE ? AND (program IS NULL OR program = '')");
    $stmt->execute([$prog, "%$prog%"]);
}

// Extract Year Levels (1, 2, 3, 4)
for ($i = 1; $i <= 4; $i++) {
    $suffix = ($i == 1 ? 'st' : ($i == 2 ? 'nd' : ($i == 3 ? 'rd' : 'th')));
    $standard_yl = "{$i}{$suffix} Year";
    // Match formats like "1st Year", " - 1", " 1", "-1" inside the string
    $stmt = $pdo->prepare("UPDATE applications SET year_level = ? WHERE (year_program LIKE ? OR year_program LIKE ? OR year_program LIKE ?) AND (year_level IS NULL OR year_level = '')");
    $stmt->execute([$standard_yl, "%{$i}{$suffix}%", "% $i%", "%-$i%"]);
}

checkSessionTimeout();

$autoRejectNote = "\nSystem: Auto-rejected due to approval in another scholarship.";

if (!isAdmin()) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
        $perms = $_SESSION['permissions'] ?? [];
        if (!in_array('applications.php', $perms)) {
            header("Location: dashboard.php");
            exit();
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            die("Action Restricted: Staff accounts are View Only.");
        }
    } else {
        header("Location: login.php");
        exit();
    }
}

$scholarship_id = filter_input(INPUT_GET, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
$action = $_GET['action'] ?? 'list';

// --- AJAX: Get Applicant Details ---
if ($action === 'get_details' && isset($_GET['app_id'])) {
    $app_id = filter_input(INPUT_GET, 'app_id', FILTER_SANITIZE_NUMBER_INT);
    try {
        // 1. Application & Student Info
        $stmt = $pdo->prepare("
            SELECT a.*, s.student_name, s.school_id_number, s.email, s.phone, s.date_of_birth,
                   sch.name as scholarship_name, sch.amount as base_amount, sch.amount_type
            FROM applications a
            JOIN students s ON a.student_id = s.id
            JOIN scholarships sch ON a.scholarship_id = sch.id
            WHERE a.id = ?
        ");
        $stmt->execute([$app_id]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            echo json_encode(['error' => 'Application not found']);
            exit;
        }

        // 2. Form Responses
        $stmt = $pdo->prepare("
            SELECT ar.id as response_id, ff.field_label, ff.field_type, ar.response_value
            FROM application_responses ar
            JOIN form_fields ff ON ar.form_field_id = ff.id
            WHERE ar.application_id = ?
            ORDER BY ff.field_order ASC
        ");
        $stmt->execute([$app_id]);
        $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Documents
        $stmt = $pdo->prepare("SELECT file_name, file_path FROM documents WHERE application_id = ?");
        $stmt->execute([$app_id]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($documents as &$document) {
            $fileInfo = describeStoredFile($pdo, $document['file_path'] ?? '', $base_path);
            $document['file_url'] = $fileInfo['url'];
            $document['file_exists'] = $fileInfo['exists'];
            $document['file_status'] = $fileInfo['reason'];
        }
        unset($document);

        // 4. Exam Results
        $stmt = $pdo->prepare("
            SELECT id, score, total_items, status 
            FROM exam_submissions 
            WHERE student_id = ? AND scholarship_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$app['student_id'], $app['scholarship_id']]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'application' => $app,
            'responses' => $responses,
            'documents' => $documents,
            'exam' => $exam
        ]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// --- Handle Details Update (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_details'])) {
    $app_id = filter_input(INPUT_POST, 'application_id', FILTER_SANITIZE_NUMBER_INT);
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $scholarship_id_redirect = $_POST['scholarship_id'];

    try {
        $pdo->beginTransaction();

        // 1. Update Student Info
        $s_name = $_POST['student_name'] ?? '';
        $s_id_num = $_POST['school_id_number'] ?? '';
        $s_email = $_POST['email'] ?? '';
        $s_phone = $_POST['phone'] ?? '';
        $s_dob = $_POST['date_of_birth'] ?? '';
        $s_id_num = trim($s_id_num);
        $student_user_id = null;

        $stmt_user = $pdo->prepare("SELECT user_id FROM students WHERE id = ?");
        $stmt_user->execute([$student_id]);
        $student_user_id = $stmt_user->fetchColumn();
        
        $stmt = $pdo->prepare("UPDATE students SET student_name = ?, school_id_number = ?, email = ?, phone = ?, date_of_birth = ? WHERE id = ?");
        $stmt->execute([$s_name, ($s_id_num !== '' ? $s_id_num : null), $s_email, $s_phone, $s_dob, $student_id]);

        if ($student_user_id) {
            $stmt_user_sync = $pdo->prepare("UPDATE users SET school_id = ? WHERE id = ?");
            $stmt_user_sync->execute([($s_id_num !== '' ? $s_id_num : null), $student_user_id]);
        }

        // 2. Update Application Info
        $prog = $_POST['program'] ?? '';
        $yl = $_POST['year_level'] ?? '';
        $student_status = trim($_POST['student_status'] ?? '');
        $units = ($_POST['units_enrolled'] ?? '') !== '' ? $_POST['units_enrolled'] : null;
        $gwa = ($_POST['gwa'] ?? '') !== '' ? $_POST['gwa'] : null;
        
        $stmt = $pdo->prepare("UPDATE applications SET program = ?, year_level = ?, units_enrolled = ?, gwa = ?, student_status = ? WHERE id = ?");
        $stmt->execute([
            trim($prog) !== '' ? trim($prog) : null,
            trim($yl) !== '' ? trim($yl) : null,
            $units,
            $gwa,
            $student_status !== '' ? $student_status : null,
            $app_id
        ]);

        // --- Auto-Recalculate Amount/Percentage if Logic Applies ---
        // Fetch necessary info to check logic
        $stmt_check = $pdo->prepare("
            SELECT s.name, a.scholarship_percentage, a.scholarship_amount 
            FROM applications a 
            JOIN scholarships s ON a.scholarship_id = s.id 
            WHERE a.id = ?
        ");
        $stmt_check->execute([$app_id]);
        $sch_info = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($sch_info) {
            $sch_name = $sch_info['name'];
            $current_perc = $sch_info['scholarship_percentage'];
            
            // Logic: Student Assistant or Academic Scholarship -> Base = 339 * units
            if (strpos($sch_name, 'Student Assistant') !== false || strpos($sch_name, 'Academic Scholarship') !== false) {
                $new_base = 339 * floatval($units);
                
                if ($current_perc !== null) {
                    // If percentage is set, recalculate the amount based on the new units
                    $new_amt = ($new_base * floatval($current_perc)) / 100;
                    $stmt_update_calc = $pdo->prepare("UPDATE applications SET scholarship_amount = ? WHERE id = ?");
                    $stmt_update_calc->execute([$new_amt, $app_id]);
                }
            }
        }

        // 3. Update Responses
        if (isset($_POST['responses']) && is_array($_POST['responses'])) {
            $stmt_resp = $pdo->prepare("UPDATE application_responses SET response_value = ? WHERE id = ?");
            foreach ($_POST['responses'] as $resp_id => $val) {
                $stmt_resp->execute([$val, $resp_id]);
            }
        }

        // 4. Update Exam (if provided)
        if (isset($_POST['exam_id']) && isset($_POST['exam_score'])) {
            $stmt_exam = $pdo->prepare("UPDATE exam_submissions SET score = ?, total_items = ? WHERE id = ?");
            $stmt_exam->execute([$_POST['exam_score'], $_POST['exam_total'], $_POST['exam_id']]);
        }

        $pdo->commit();
        flashMessage("Applicant details updated successfully.");
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage("Error updating details: " . $e->getMessage(), 'danger');
    }
    
    header("Location: applications.php?scholarship_id=" . $scholarship_id_redirect);
    exit();
}

// --- Handle Status Update (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $app_id = filter_input(INPUT_POST, 'application_id', FILTER_SANITIZE_NUMBER_INT);
    $new_status = $_POST['status'];
    $remarks = trim($_POST['remarks']);
    $scholarship_percentage = !empty($_POST['scholarship_percentage']) ? $_POST['scholarship_percentage'] : null;
    $scholarship_amount = !empty($_POST['scholarship_amount']) ? $_POST['scholarship_amount'] : null;
    $scholarship_id_redirect = $_POST['scholarship_id'];

    if ($app_id && $new_status) {
        try {
            // Validation: Check limits
            $stmt_val = $pdo->prepare("SELECT s.amount, s.amount_type FROM applications a JOIN scholarships s ON a.scholarship_id = s.id WHERE a.id = ?");
            $stmt_val->execute([$app_id]);
            $sch_data = $stmt_val->fetch(PDO::FETCH_ASSOC);
            $base_amount = $sch_data['amount'];
            $amount_type = $sch_data['amount_type'];

            if ($scholarship_percentage !== null && ($scholarship_percentage < 0 || $scholarship_percentage > 100)) {
                throw new Exception("Percentage must be between 0 and 100.");
            }
            if ($amount_type === 'Peso' && $scholarship_amount !== null && $base_amount > 0 && $scholarship_amount > $base_amount) {
                throw new Exception("Amount cannot exceed the base scholarship amount of ₱" . number_format($base_amount, 2));
            }
            if ($amount_type === 'Percentage' && $scholarship_percentage !== null && $base_amount > 0 && $scholarship_percentage > $base_amount) {
                throw new Exception("Percentage cannot exceed the scholarship limit of " . number_format($base_amount, 0) . "%.");
            }

            // Update Status
            $stmt = $pdo->prepare("UPDATE applications SET status = ?, remarks = ?, scholarship_percentage = ?, scholarship_amount = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$new_status, $remarks, $scholarship_percentage, $scholarship_amount, $app_id]);

            // Auto-reject other pending applications if Approved
            if ($new_status === 'Approved') {
                $stmt_student = $pdo->prepare("SELECT student_id FROM applications WHERE id = ?");
                $stmt_student->execute([$app_id]);
                $student_id = $stmt_student->fetchColumn();

                if ($student_id) {
                    $stmt_reject = $pdo->prepare("
                        UPDATE applications 
                        SET status = 'Rejected', 
                            remarks = CONCAT(COALESCE(remarks, ''), ?),
                            updated_at = CURRENT_TIMESTAMP
                        WHERE student_id = ? AND id != ? AND status IN ('Pending', 'Pending Exam', 'Under Review')
                    ");
                    $stmt_reject->execute([$autoRejectNote, $student_id, $app_id]);
                }
            }

            // Fetch info for email
            $stmt = $pdo->prepare("
                SELECT s.email, s.student_name, sch.name as scholarship_name, a.student_id 
                FROM applications a
                JOIN students s ON a.student_id = s.id
                JOIN scholarships sch ON a.scholarship_id = sch.id
                WHERE a.id = ?
            ");
            $stmt->execute([$app_id]);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);

            // Send Email Notification
            if ($info) {
                // 1. Insert Database Notification
                try {
                    $notif_title = "Application Status Update";
                    $notif_msg = "Your application for " . $info['scholarship_name'] . " has been updated to: " . $new_status . ".";
                    if (!empty($remarks)) $notif_msg .= " Remarks: " . $remarks;
                    
                    $stmt_notif = $pdo->prepare("INSERT INTO notifications (student_id, title, message) VALUES (?, ?, ?)");
                    $stmt_notif->execute([$info['student_id'], $notif_title, $notif_msg]);
                } catch (PDOException $e) { error_log("Notif Error: " . $e->getMessage()); }

                // 2. Send Email
                $mail = new PHPMailer(true);
                try {
                    configureSmtpMailer($mail, 'DVC Scholarship Hub');
                    $mail->addAddress($info['email'], $info['student_name']);
                    $mail->isHTML(true);
                    $mail->Subject = 'Application Status Update: ' . $info['scholarship_name'];
                    
                    $body = "<p>Dear {$info['student_name']},</p>";
                    $body .= "<p>Your application for <strong>{$info['scholarship_name']}</strong> has been updated to: <strong style='color: #0d6efd;'>{$new_status}</strong>.</p>";
                    if (!empty($remarks)) {
                        $body .= "<p><strong>Remarks:</strong> " . nl2br(htmlspecialchars($remarks)) . "</p>";
                    }
                    if (!empty($scholarship_percentage)) {
                        $body .= "<p><strong>Scholarship Coverage:</strong> " . htmlspecialchars(number_format($scholarship_percentage, 0)) . "%</p>";
                    }
                    if (!empty($scholarship_amount)) {
                        $body .= "<p><strong>Scholarship Amount:</strong> ₱" . htmlspecialchars(number_format($scholarship_amount, 2)) . "</p>";
                    }
                    $body .= "<p>Please log in to your dashboard for more details.</p>";
                    $body .= "<br><p>Best regards,<br>DVC Scholarship Committee</p>";
                    
                    $mail->Body = $body;
                    $mail->send();
                } catch (Exception $e) {
                    error_log("Mail error: " . ($mail->ErrorInfo ?: $e->getMessage()));
                }
            }

            flashMessage("Application status updated to '$new_status' and email sent.");
        } catch (Exception $e) {
            flashMessage("Error updating status: " . $e->getMessage(), 'danger');
        }
    }
    header("Location: applications.php?scholarship_id=" . $scholarship_id_redirect);
    exit();
}

// --- Handle CSV Export (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_csv'])) {
    $scholarship_id_export = $_POST['scholarship_id'];

    // Fetch ALL data for the scholarship (ignoring selection)
    $stmt = $pdo->prepare("
        SELECT s.student_name, s.school_id_number, s.email, s.phone,
               sch.name as scholarship_name, a.applicant_type, a.student_status, a.program, a.year_level,
               a.status, a.scholarship_percentage, a.scholarship_amount, a.submitted_at
        FROM applications a
        JOIN students s ON a.student_id = s.id
        JOIN scholarships sch ON a.scholarship_id = sch.id
        WHERE a.scholarship_id = ?
        ORDER BY a.submitted_at DESC
    ");
    $stmt->execute([$scholarship_id_export]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Output CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="applicants_export_all_' . date('Y-m-d_H-i') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student Name', 'School ID', 'Email', 'Phone', 'Scholarship', 'Type', 'Student Status', 'Program', 'Year Level', 'Status', 'Percentage', 'Amount', 'Date Applied']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['student_name'],
            $row['school_id_number'],
            $row['email'],
            $row['phone'],
            $row['scholarship_name'],
            $row['applicant_type'],
            $row['student_status'],
            formatAcademicDisplay($row, 'program'),
            formatAcademicDisplay($row, 'year_level'),
            $row['status'],
            $row['scholarship_percentage'] ? $row['scholarship_percentage'] . '%' : '',
            $row['scholarship_amount'],
            date("M d, Y h:i A", strtotime($row['submitted_at']))
        ]);
    }
    fclose($output);
    exit();
}

// --- Handle Word/Doc Export (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_doc'])) {
    $scholarship_id_export = $_POST['scholarship_id'];

    // Fetch ALL data for the scholarship (ignoring selection)
    $stmt = $pdo->prepare("
        SELECT s.student_name, s.school_id_number, s.email, s.phone,
               sch.name as scholarship_name, a.applicant_type, a.student_status, a.program, a.year_level,
               a.status, a.scholarship_percentage, a.scholarship_amount, a.submitted_at
        FROM applications a
        JOIN students s ON a.student_id = s.id
        JOIN scholarships sch ON a.scholarship_id = sch.id
        WHERE a.scholarship_id = ?
        ORDER BY a.submitted_at DESC
    ");
    $stmt->execute([$scholarship_id_export]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header("Content-Type: application/vnd.ms-word");
    header("Content-Disposition: attachment; filename=\"applicants_export_all_" . date('Y-m-d_H-i') . ".doc\"");
    
    echo "<html>";
    echo "<head><meta charset='utf-8'><style>body{font-family: Arial, sans-serif;} table{width: 100%; border-collapse: collapse;} th, td{border: 1px solid #000; padding: 8px; text-align: left;} th{background-color: #f2f2f2;}</style></head>";
    echo "<body>";
    echo "<h2>Full Applicant List Export</h2>";
    echo "<table>";
    echo "<thead><tr><th>Student Name</th><th>School ID</th><th>Email</th><th>Phone</th><th>Scholarship</th><th>Type</th><th>Student Status</th><th>Program</th><th>Year Level</th><th>Status</th><th>Percentage</th><th>Amount</th><th>Date Applied</th></tr></thead>";
    echo "<tbody>";
    foreach ($rows as $row) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['student_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['school_id_number']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td>" . htmlspecialchars($row['phone']) . "</td>";
        echo "<td>" . htmlspecialchars($row['scholarship_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['applicant_type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['student_status'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars(formatAcademicDisplay($row, 'program')) . "</td>";
        echo "<td>" . htmlspecialchars(formatAcademicDisplay($row, 'year_level')) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . ($row['scholarship_percentage'] ? htmlspecialchars($row['scholarship_percentage']) . '%' : '') . "</td>";
        echo "<td>" . htmlspecialchars($row['scholarship_amount']) . "</td>";
        echo "<td>" . date("M d, Y h:i A", strtotime($row['submitted_at'])) . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    echo "</body></html>";
    exit();
}

// --- Handle Bulk Actions (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $selected_ids = $_POST['selected_applicants'] ?? [];
    $bulk_status = $_POST['bulk_status'] ?? '';
    $scholarship_id_redirect = $_POST['scholarship_id'];

    if (!empty($selected_ids) && in_array($bulk_status, ['Approved', 'Rejected'])) {
        $count = 0;
        $stmt = $pdo->prepare("UPDATE applications SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        
        foreach ($selected_ids as $app_id) {
            $stmt->execute([$bulk_status, $app_id]);
            
            // Auto-reject logic if Approved
            if ($bulk_status === 'Approved') {
                $stmt_student = $pdo->prepare("SELECT student_id FROM applications WHERE id = ?");
                $stmt_student->execute([$app_id]);
                $student_id = $stmt_student->fetchColumn();
                if ($student_id) {
                    $stmt_reject = $pdo->prepare("UPDATE applications SET status = 'Rejected', remarks = CONCAT(COALESCE(remarks, ''), ?), updated_at = CURRENT_TIMESTAMP WHERE student_id = ? AND id != ? AND status IN ('Pending', 'Pending Exam', 'Under Review')");
                    $stmt_reject->execute([$autoRejectNote, $student_id, $app_id]);
                }
            }

            // Insert Notification for Bulk Action
            try {
                $stmt_info = $pdo->prepare("SELECT a.student_id, sch.name FROM applications a JOIN scholarships sch ON a.scholarship_id = sch.id WHERE a.id = ?");
                $stmt_info->execute([$app_id]);
                $b_info = $stmt_info->fetch();
                if($b_info) {
                    $stmt_notif = $pdo->prepare("INSERT INTO notifications (student_id, title, message) VALUES (?, ?, ?)");
                    $stmt_notif->execute([$b_info['student_id'], "Application Status Update", "Your application for " . $b_info['name'] . " has been updated to: " . $bulk_status . "."]);
                }
            } catch (PDOException $e) {}

            $count++;
        }
        flashMessage("$count applications updated to '$bulk_status'.");
    }
    header("Location: applications.php?scholarship_id=" . $scholarship_id_redirect);
    exit();
}

// --- PRE-FETCH DATA FOR VIEW 2 (Specific Scholarship) ---
// Logic moved here to handle AJAX requests before HTML header output
$scholarship = null;
$applicants = [];
$stats = [];
$type_counts = [];
$total_specific = 0;
$total_pages = 0;
$page = 1;
$search = '';
$start_date = '';
$status_filter = 'pending';
$distinct_programs = [];
$distinct_year_levels = [];

if ($scholarship_id) {
    // Fetch Scholarship Details
    $stmt = $pdo->prepare("SELECT name FROM scholarships WHERE id = ?");
    $stmt->execute([$scholarship_id]);
    $scholarship = $stmt->fetch();
    
    if ($scholarship) {
        // Fetch Applicant Type Counts (Unique Students - Latest Application)
        $type_stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN a.applicant_type = 'New' AND a.status NOT IN ('Rejected', 'Dropped') THEN 1 ELSE 0 END), 0) as new_applicants,
                COALESCE(SUM(CASE WHEN a.applicant_type = 'Renewal' AND a.status NOT IN ('Rejected', 'Dropped') THEN 1 ELSE 0 END), 0) as renewal_applicants
            FROM applications a
            INNER JOIN (
                SELECT student_id, MAX(id) as max_id
                FROM applications
                WHERE scholarship_id = ?
                GROUP BY student_id
            ) latest ON a.id = latest.max_id
            WHERE a.scholarship_id = ?
        ");
        $type_stmt->execute([$scholarship_id, $scholarship_id]);
        $type_counts = $type_stmt->fetch(PDO::FETCH_ASSOC);
        $total_specific = $type_counts['new_applicants'] + $type_counts['renewal_applicants'];

        // Fetch Statistics (Unique Students - Latest Application)
        $stats_sql = "
            SELECT a.status, COUNT(a.id) as count 
            FROM applications a 
            INNER JOIN (
                SELECT student_id, MAX(id) as max_id
                FROM applications
                WHERE scholarship_id = ?
                GROUP BY student_id
            ) latest ON a.id = latest.max_id
            WHERE a.scholarship_id = ? 
            GROUP BY a.status
        ";
        $stmt = $pdo->prepare($stats_sql);
        $stmt->execute([$scholarship_id, $scholarship_id]);
        $raw_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $stats = [
            'Approved' => $raw_stats['Approved'] ?? 0,
            'Pending' => ($raw_stats['Pending'] ?? 0) + ($raw_stats['Pending Exam'] ?? 0),
            'Under Review' => $raw_stats['Under Review'] ?? 0,
            'Renewal Request' => $raw_stats['Renewal Request'] ?? 0,
            'Drop Requested' => $raw_stats['Drop Requested'] ?? 0,
            'Dropped' => $raw_stats['Dropped'] ?? 0
        ];

        // Fetch Applicants (Filter & Search Logic)
        $status_filter = $_GET['status_filter'] ?? 'pending';
        $search = $_GET['search'] ?? '';
        $start_date = $_GET['start_date'] ?? '';
        $program_filter = $_GET['program_filter'] ?? '';
        $year_level_filter = $_GET['year_level_filter'] ?? '';
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $base_sql = "
            FROM applications a 
            JOIN students s ON a.student_id = s.id 
            LEFT JOIN users u ON s.user_id = u.id
            WHERE a.scholarship_id = ?
            AND a.id = (
                SELECT MAX(sub.id) 
                FROM applications sub 
                WHERE sub.student_id = a.student_id 
                AND sub.scholarship_id = ?
            )
    ";
        $params = [$scholarship_id, $scholarship_id];

        if ($status_filter === 'official') {
            $base_sql .= " AND a.status IN ('Approved', 'Active')";
        } elseif ($status_filter === 'pending') {
            $base_sql .= " AND a.status IN ('Pending', 'Pending Exam', 'Under Review', 'Renewal Request', 'Drop Requested', 'For Renewal')";
        } elseif ($status_filter === 'renewal') {
            $base_sql .= " AND a.status IN ('Renewal Request', 'For Renewal')";
        } elseif ($status_filter === 'dropped') {
            $base_sql .= " AND a.status IN ('Dropped', 'Drop Requested')";
        } elseif ($status_filter === 'rejected') {
            $base_sql .= " AND a.status = 'Rejected'";
        }

        if (!empty($search)) {
            $base_sql .= " AND (s.student_name LIKE ? OR s.school_id_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if (!empty($program_filter)) {
            $base_sql .= " AND a.program = ?"; 
            $params[] = $program_filter;
        }

        if (!empty($year_level_filter)) {
            $base_sql .= " AND a.year_level = ?";
            $params[] = $year_level_filter;
        }
        
        if (!empty($start_date)) {
            $base_sql .= " AND DATE(a.submitted_at) >= ?";
            $params[] = $start_date;
        }

        // Count Total Records
        $count_stmt = $pdo->prepare("SELECT COUNT(*) " . $base_sql);
        $count_stmt->execute($params);
        $total_rows = $count_stmt->fetchColumn();
        $total_pages = ceil($total_rows / $limit);

        // Fetch Data
        $sql = "SELECT a.*, s.student_name, s.email, s.school_id_number, u.profile_picture_path, u.first_name, u.middle_name, u.last_name " . $base_sql;
        $sql .= " 
            ORDER BY 
            CASE 
                WHEN a.status IN ('Pending', 'Pending Exam', 'Under Review', 'Renewal Request', 'Drop Requested', 'For Renewal') THEN 1
                WHEN a.status IN ('Approved', 'Active') THEN 2
                ELSE 3 
            END ASC, a.submitted_at DESC
            LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- AJAX Response for Live Search ---
        if (isset($_GET['ajax_search'])) {
            // Generate Table Rows HTML
            ob_start();
            if (empty($applicants)): ?>
                <tr><td colspan="12" class="text-center py-4 text-muted">No applicants found.</td></tr>
            <?php else: ?>
                <?php foreach ($applicants as $app): ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="selected_applicants[]" value="<?php echo $app['id']; ?>" class="form-check-input applicant-checkbox">
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if (!empty($app['profile_picture_path'])): ?>
                                    <img src="<?php echo htmlspecialchars(storedFilePathToUrl($app['profile_picture_path'])); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32" style="object-fit: cover;">
                                <?php else: ?>
                                    <div class="rounded-circle bg-secondary bg-opacity-10 d-flex align-items-center justify-content-center me-2 text-secondary" style="width: 32px; height: 32px;"><i class="bi bi-person-fill"></i></div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-bold text-dark" style="cursor:pointer;" onclick="viewApplicant(<?php echo $app['id']; ?>)">
                                        <?php 
                                            $displayName = $app['student_name'];
                                            if (!empty($app['last_name'])) {
                                                $displayName = $app['last_name'] . ', ' . $app['first_name'] . ' ' . $app['middle_name'];
                                            }
                                            echo htmlspecialchars(strtoupper(trim($displayName))); 
                                        ?>
                                    </div>
                                    <div class="small text-muted"><?php echo htmlspecialchars((string)($app['school_id_number'] ?? '')); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($app['applicant_type'] === 'New'): ?>
                                <span class="badge bg-info text-dark">New</span>
                            <?php else: ?>
                                <span class="badge bg-primary">Renewal</span>
                            <?php endif; ?>
                            <div class="small text-muted mt-1"><?php echo htmlspecialchars(getApplicantStatusLabel($app)); ?></div>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars(formatAcademicDisplay($app, 'program')); ?></span></td>
                        <td><small class="text-muted fw-bold"><?php echo htmlspecialchars(formatAcademicDisplay($app, 'year_level')); ?></small></td>
                        <td><span class="fw-bold"><?php echo htmlspecialchars(formatAcademicDisplay($app, 'units_enrolled')); ?></span></td>
                        <td><span class="fw-bold"><?php echo htmlspecialchars(formatAcademicDisplay($app, 'gwa')); ?></span></td>
                        <td>
                            <div><?php echo date("M d, Y", strtotime($app['submitted_at'])); ?></div>
                            <small class="text-muted"><?php echo date("h:i A", strtotime($app['submitted_at'])); ?></small>
                        </td>
                        <td>
                            <?php 
                            $status_color = match($app['status']) {
                                'Approved', 'Active' => 'success',
                                'Rejected' => 'danger',
                                'Pending', 'Pending Exam' => 'warning text-dark',
                                'Renewal Request' => 'primary',
                                'Dropped' => 'secondary',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?php echo $status_color; ?>"><?php echo htmlspecialchars($app['status']); ?></span>
                        </td>
                        <td class="text-center fw-bold text-primary">
                            <?php echo isset($app['scholarship_percentage']) && $app['scholarship_percentage'] !== null ? htmlspecialchars(number_format($app['scholarship_percentage'], 0)) . '%' : '-'; ?>
                        </td>
                        <td class="text-center fw-bold text-success">
                            <?php echo isset($app['scholarship_amount']) && $app['scholarship_amount'] !== null ? '₱' . htmlspecialchars(number_format($app['scholarship_amount'], 2)) : '-'; ?>
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="viewApplicant(<?php echo $app['id']; ?>)">
                                <i class="bi bi-eye-fill"></i> View
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif;
            $table_rows = ob_get_clean();

            // Generate Pagination HTML
            ob_start();
            if ($total_pages > 1): ?>
            <nav class="mt-4" aria-label="Page navigation">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="#" onclick="changePage(<?php echo $page - 1; ?>); return false;">Previous</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                            <a class="page-link" href="#" onclick="changePage(<?php echo $i; ?>); return false;"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="#" onclick="changePage(<?php echo $page + 1; ?>); return false;">Next</a>
                    </li>
                </ul>
            </nav>
            <?php endif;
            $pagination = ob_get_clean();

            // --- Calculate Dynamic Stats based on current filters ---
            // 1. Status Counts
            $stats_query = "SELECT a.status, COUNT(*) as count " . $base_sql . " GROUP BY a.status";
            $stmt_stats = $pdo->prepare($stats_query);
            $stmt_stats->execute($params);
            $fetched_stats = $stmt_stats->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $ajax_stats = [
                'Approved' => ($fetched_stats['Approved'] ?? 0) + ($fetched_stats['Active'] ?? 0),
                'Pending' => ($fetched_stats['Pending'] ?? 0) + ($fetched_stats['Pending Exam'] ?? 0),
                'Under Review' => $fetched_stats['Under Review'] ?? 0,
                'Renewal Request' => ($fetched_stats['Renewal Request'] ?? 0) + ($fetched_stats['For Renewal'] ?? 0),
                'Drop Requested' => $fetched_stats['Drop Requested'] ?? 0,
                'Dropped' => $fetched_stats['Dropped'] ?? 0
            ];

            // 2. Type Counts (Total/New/Renewal)
            $type_query = "
                SELECT 
                    COUNT(*) as total,
                    COALESCE(SUM(CASE WHEN a.applicant_type = 'New' THEN 1 ELSE 0 END), 0) as new_apps,
                    COALESCE(SUM(CASE WHEN a.applicant_type = 'Renewal' THEN 1 ELSE 0 END), 0) as renewal_apps
                " . $base_sql;
            $stmt_type = $pdo->prepare($type_query);
            $stmt_type->execute($params);
            $type_res = $stmt_type->fetch(PDO::FETCH_ASSOC);
            
            $ajax_types = $type_res;

            // Return JSON and EXIT to prevent Header/HTML output
            header('Content-Type: application/json');
            echo json_encode([
                'rows' => $table_rows, 
                'pagination' => $pagination,
                'stats' => $ajax_stats,
                'types' => $ajax_types
            ]);
            exit;
        }

        // Define the complete list of programs and year levels
        $distinct_programs = ['AB-THEO', 'BSIT', 'BEED', 'BSED_MATH', 'BSED- ENGLISH'];
        $distinct_year_levels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

        // Fetch distinct programs from the new column
        $prog_stmt = $pdo->prepare("SELECT DISTINCT program FROM applications WHERE scholarship_id = ? AND program IS NOT NULL AND program != ''");
        $prog_stmt->execute([$scholarship_id]);
        $db_programs = $prog_stmt->fetchAll(PDO::FETCH_COLUMN);
        $distinct_programs = array_unique(array_merge($distinct_programs, $db_programs));
        sort($distinct_programs);

        // Fetch distinct year levels from the new column
        $yl_stmt = $pdo->prepare("SELECT DISTINCT year_level FROM applications WHERE scholarship_id = ? AND year_level IS NOT NULL AND year_level != ''");
        $yl_stmt->execute([$scholarship_id]);
        $db_year_levels = $yl_stmt->fetchAll(PDO::FETCH_COLUMN);
        $distinct_year_levels = array_unique(array_merge($distinct_year_levels, $db_year_levels));
        sort($distinct_year_levels);
    }
}

$page_title = 'Manage Applications';
$csrf_token = generate_csrf_token();
include 'header.php';
displayFlashMessages();
?>

<style>
    .overview-card .card-body {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .overview-card-actions {
        margin-top: auto;
    }

    .overview-card-actions .btn {
        font-weight: 600;
        padding-top: 0.55rem;
        padding-bottom: 0.55rem;
    }

    #bulkActionButtons {
        gap: 0.6rem;
    }

    #bulkActionButtons .action-btn {
        min-height: 40px;
        padding: 0.45rem 0.85rem;
        font-weight: 600;
    }

    #clearFilterBtn {
        min-height: 38px;
        white-space: nowrap;
    }

    #dynamicStatusButtons {
        gap: 0.65rem !important;
    }

    #dynamicStatusButtons .btn {
        min-height: 42px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    #dynamicStatusButtons .alert {
        margin-bottom: 0.25rem !important;
    }

    @media (max-width: 767.98px) {
        #bulkActionButtons .action-btn {
            width: 100%;
        }
    }

    @media print {
        .sidebar, .main-header, .page-header, #filterForm, .pagination, .btn, .form-check-input, .alert, #bulkActionButtons, .d-print-none {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }
        .card {
            border: none !important;
            box-shadow: none !important;
        }
        /* Ensure table fits */
        table {
            width: 100% !important;
            font-size: 12px;
        }
        /* Show status text instead of badge color if needed, or keep badges */
        .badge {
            border: 1px solid #000;
            color: #000 !important;
            background: none !important;
        }
        /* Hide Checkbox (1), Action (9) for print template */
        table th:nth-child(1), table td:nth-child(1),
        table th:nth-child(9), table td:nth-child(9) {
            display: none !important;
        }
        .d-print-block {
            display: block !important;
        }
    }
</style>

<div class="container-fluid">
    <?php if (!$scholarship_id): ?>
        <!-- VIEW 1: All Scholarships Overview -->
        <div class="page-header mb-4" data-aos="fade-down">
            <h1 class="fw-bold">Applications Overview</h1>
            <p class="text-muted">Select a scholarship program to view and manage its applicants.</p>
        </div>

        <?php
        // Fetch scholarships with applicant counts (Unique Students - Latest Application)
        $stmt = $pdo->query("
            SELECT 
                s.*,
                COUNT(CASE WHEN latest_apps.applicant_type = 'New' AND latest_apps.status NOT IN ('Rejected', 'Dropped') THEN 1 END) as new_applicants,
                COUNT(CASE WHEN latest_apps.applicant_type = 'Renewal' AND latest_apps.status NOT IN ('Rejected', 'Dropped') THEN 1 END) as renewal_applicants,
                COUNT(CASE WHEN latest_apps.status IN ('Pending', 'Pending Exam', 'Under Review', 'Renewal Request', 'Drop Requested', 'For Renewal') THEN 1 END) as pending_requests,
                s.requires_exam,
                (SELECT COUNT(*) FROM exam_questions eq WHERE eq.scholarship_id = s.id) as exam_question_count
            FROM scholarships s
            LEFT JOIN (
                SELECT a.scholarship_id, a.applicant_type, a.status
                FROM applications a
                INNER JOIN (
                    SELECT student_id, scholarship_id, MAX(id) as max_id
                    FROM applications
                    GROUP BY student_id, scholarship_id
                ) latest ON a.id = latest.max_id
            ) latest_apps ON s.id = latest_apps.scholarship_id
            WHERE s.status IN ('active', 'inactive')
            GROUP BY s.id
            ORDER BY pending_requests DESC, s.created_at DESC
        ");
        $scholarships = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="row g-4" data-aos="fade-up">
            <?php foreach ($scholarships as $s): ?>
                <?php $total_applicants = $s['new_applicants'] + $s['renewal_applicants']; ?>
                <?php $pending_requests = (int)($s['pending_requests'] ?? 0); ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 border-0 shadow-sm hover-card overview-card <?php echo $pending_requests > 0 ? 'border-start border-4 border-warning' : ''; ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h5 class="card-title fw-bold text-primary mb-0"><?php echo htmlspecialchars($s['name']); ?></h5>
                                <div class="d-flex gap-1">
                                    <?php if ($pending_requests > 0): ?>
                                        <span class="badge bg-warning text-dark"><?php echo $pending_requests; ?> Pending</span>
                                    <?php endif; ?>
                                    <span class="badge bg-<?php echo $s['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($s['status']); ?></span>
                                </div>
                            </div>
                            
                            <div class="row text-center g-2 mb-3">
                                <div class="col-6 col-md-3">
                                    <div class="p-2 bg-light rounded">
                                        <div class="h4 mb-0 fw-bold"><?php echo $total_applicants; ?></div>
                                        <small class="text-muted">Total</small>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="p-2 <?php echo $pending_requests > 0 ? 'bg-warning bg-opacity-10' : 'bg-light'; ?> rounded">
                                        <div class="h4 mb-0 fw-bold <?php echo $pending_requests > 0 ? 'text-warning' : 'text-muted'; ?>"><?php echo $pending_requests; ?></div>
                                        <small class="text-muted">Pending</small>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="p-2 bg-light rounded">
                                        <div class="h4 mb-0 fw-bold text-info"><?php echo $s['new_applicants']; ?></div>
                                        <small class="text-muted">New</small>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="p-2 bg-light rounded">
                                        <div class="h4 mb-0 fw-bold text-success"><?php echo $s['renewal_applicants']; ?></div>
                                        <small class="text-muted">Renewal</small>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 overview-card-actions">
                                <a href="applications.php?scholarship_id=<?php echo $s['id']; ?>&status_filter=pending" class="btn btn-warning <?php echo $pending_requests === 0 ? 'disabled' : ''; ?>" <?php echo $pending_requests === 0 ? 'aria-disabled="true"' : ''; ?>>
                                    Review Pending (<?php echo $pending_requests; ?>)
                                </a>
                                <a href="applications.php?scholarship_id=<?php echo $s['id']; ?>&status_filter=all" class="btn btn-outline-primary">
                                    View All Applicants <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <!-- VIEW 2: Specific Scholarship Applicants -->
        <?php
        if (!$scholarship) {
            echo "<div class='alert alert-danger'>Scholarship not found.</div>";
            include 'footer.php';
            exit;
        }
        ?>

        <div class="d-flex align-items-center mb-4 d-print-none" data-aos="fade-down">
            <a href="applications.php" class="btn btn-outline-secondary me-3"><i class="bi bi-arrow-left"></i> Back</a>
            <div>
                <h1 class="fw-bold mb-0"><?php echo htmlspecialchars($scholarship['name']); ?></h1>
                <p class="text-muted mb-0">Managing Applicants</p>
            </div>
        </div>

        <!-- Applicant Counts (Total / New / Renewal) -->
        <div class="row g-3 mb-4 d-print-none" data-aos="fade-up">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="text-muted text-uppercase mb-1">Total Applicants</h6>
                            <h2 class="fw-bold mb-0 text-primary" id="stat-total"><?php echo $total_specific; ?></h2>
                        </div>
                        <div class="icon-shape bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                            <i class="bi bi-people-fill fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="text-muted text-uppercase mb-1">New Applicants</h6>
                            <h2 class="fw-bold mb-0 text-info" id="stat-new"><?php echo $type_counts['new_applicants']; ?></h2>
                        </div>
                        <div class="icon-shape bg-info bg-opacity-10 text-info rounded-circle p-3">
                            <i class="bi bi-person-plus-fill fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="text-muted text-uppercase mb-1">Renewal Applicants</h6>
                            <h2 class="fw-bold mb-0 text-success" id="stat-renewal"><?php echo $type_counts['renewal_applicants']; ?></h2>
                        </div>
                        <div class="icon-shape bg-success bg-opacity-10 text-success rounded-circle p-3">
                            <i class="bi bi-arrow-repeat fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4 d-print-none" data-aos="fade-up">
            <div class="col-md">
                <div class="card bg-success text-white h-100">
                    <div class="card-body text-center p-3">
                        <h3 class="fw-bold mb-0" id="stat-approved"><?php echo $stats['Approved']; ?></h3>
                        <small>Approved</small>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card bg-warning text-dark h-100">
                    <div class="card-body text-center p-3">
                        <h3 class="fw-bold mb-0" id="stat-pending"><?php echo $stats['Pending']; ?></h3>
                        <small>Pending</small>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card bg-info text-white h-100">
                    <div class="card-body text-center p-3">
                        <h3 class="fw-bold mb-0" id="stat-review"><?php echo $stats['Under Review']; ?></h3>
                        <small>Under Review</small>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card bg-primary text-white h-100">
                    <div class="card-body text-center p-3">
                        <h3 class="fw-bold mb-0" id="stat-renewal-req"><?php echo $stats['Renewal Request']; ?></h3>
                        <small>Renewal Req.</small>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card bg-danger text-white h-100">
                    <div class="card-body text-center p-3">
                        <h3 class="fw-bold mb-0" id="stat-drop-req"><?php echo $stats['Drop Requested']; ?></h3>
                        <small>Drop Req.</small>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card bg-secondary text-white h-100">
                    <div class="card-body text-center p-3">
                        <h3 class="fw-bold mb-0" id="stat-dropped"><?php echo $stats['Dropped']; ?></h3>
                        <small>Dropped</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter & Search (Redesigned) -->
        <div class="card border-0 shadow-sm mb-4 d-print-none" data-aos="fade-up">
            <div class="card-body bg-light rounded-3 p-4">
                <form method="GET" id="filterForm" onsubmit="return false;">
                    <input type="hidden" name="scholarship_id" id="scholarshipIdInput" value="<?php echo $scholarship_id; ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label for="liveSearchInput" class="form-label fw-bold text-muted small text-uppercase">Search Applicant</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                                <input type="text" name="search" id="liveSearchInput" class="form-control border-start-0 ps-0" placeholder="Name or School ID..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label for="programFilterInput" class="form-label fw-bold text-muted small text-uppercase">Program</label>
                            <select name="program_filter" id="programFilterInput" class="form-select">
                                <option value="">All Programs</option>
                                <?php foreach ($distinct_programs as $prog): ?>
                                    <option value="<?php echo htmlspecialchars($prog); ?>" <?php echo $program_filter === $prog ? 'selected' : ''; ?>><?php echo htmlspecialchars($prog); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="yearLevelFilterInput" class="form-label fw-bold text-muted small text-uppercase">Year Level</label>
                            <select name="year_level_filter" id="yearLevelFilterInput" class="form-select">
                                <option value="">All Levels</option>
                                <?php foreach ($distinct_year_levels as $yl): ?>
                                    <option value="<?php echo htmlspecialchars($yl); ?>" <?php echo $year_level_filter === $yl ? 'selected' : ''; ?>><?php echo htmlspecialchars($yl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="statusFilterInput" class="form-label fw-bold text-muted small text-uppercase">Filter by Status</label>
                            <select name="status_filter" id="statusFilterInput" class="form-select">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="official" <?php echo $status_filter === 'official' ? 'selected' : ''; ?>>Official Scholars</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                <option value="renewal" <?php echo $status_filter === 'renewal' ? 'selected' : ''; ?>>Renewal Requests</option>
                                <option value="dropped" <?php echo $status_filter === 'dropped' ? 'selected' : ''; ?>>Dropped</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="startDateInput" class="form-label fw-bold text-muted small text-uppercase">Date Applied (From)</label>
                            <input type="date" name="start_date" id="startDateInput" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="col-md-1">
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="button" id="clearFilterBtn" class="btn btn-outline-secondary w-100" title="Reset filters"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Print Header (Visible only in print) -->
        <div class="d-none d-print-block text-center mb-4">
            <h2 class="fw-bold"><?php echo htmlspecialchars($scholarship['name']); ?></h2>
            <p class="mb-0">Applicant List Report</p>
            <p class="text-muted small">Generated on <?php echo date('F d, Y'); ?></p>
        </div>

        <!-- Applicants Table -->
        <?php if (isAdmin()): ?>
        <form method="POST" id="bulkActionForm">
        <input type="hidden" name="scholarship_id" value="<?php echo $scholarship_id; ?>">
        <input type="hidden" name="bulk_action" value="1">
        
        <div class="mb-2 d-flex flex-wrap" data-aos="fade-up" id="bulkActionButtons">
            <button type="submit" name="bulk_status" value="Approved" class="btn btn-success action-btn" onclick="return confirm('Approve selected applicants?')">
                <i class="bi bi-check-circle"></i> Approve Selected
            </button>
            <button type="submit" name="bulk_status" value="Rejected" class="btn btn-danger action-btn" onclick="return confirm('Reject selected applicants?')">
                <i class="bi bi-x-circle"></i> Reject Selected
            </button>
            <button type="submit" name="export_csv" value="1" class="btn btn-outline-success action-btn" onclick="return confirm('Export ALL applicants to CSV?')">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export All CSV
            </button>
            <button type="submit" name="export_doc" value="1" class="btn btn-outline-primary action-btn" onclick="return confirm('Export ALL applicants to Word Document?')">
                <i class="bi bi-file-earmark-word"></i> Export All Doc
            </button>
            <button type="button" onclick="window.print()" class="btn btn-outline-secondary action-btn">
                <i class="bi bi-printer"></i> Print Table
            </button>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm" data-aos="fade-up" data-aos-delay="100">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <?php if (isAdmin()): ?>
                                <th style="width: 40px;"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                                <?php endif; ?>
                                <th>Student Name</th>
                                <th>Type</th>
                                <th>Program</th>
                                <th>Year Level</th>
                                <th>Units</th>
                                <th>GWA</th>
                                <th>Date Applied</th>
                                <th>Status</th>
                                <th class="text-center">Percentage</th>
                                <th class="text-center">Amount</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="applicantsTableBody">
                            <?php if (empty($applicants)): ?>
                                <tr><td colspan="12" class="text-center py-4 text-muted">No applicants found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($applicants as $app): ?>
                                    <tr>
                                        <?php if (isAdmin()): ?>
                                        <td>
                                            <input type="checkbox" name="selected_applicants[]" value="<?php echo $app['id']; ?>" class="form-check-input applicant-checkbox">
                                        </td>
                                        <?php endif; ?>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <?php if (!empty($app['profile_picture_path'])): ?>
                                                    <img src="<?php echo htmlspecialchars(storedFilePathToUrl($app['profile_picture_path'])); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32" style="object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="rounded-circle bg-secondary bg-opacity-10 d-flex align-items-center justify-content-center me-2 text-secondary" style="width: 32px; height: 32px;"><i class="bi bi-person-fill"></i></div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="fw-bold text-dark" style="cursor:pointer;" onclick="viewApplicant(<?php echo $app['id']; ?>)">
                                                        <?php 
                                                            $displayName = $app['student_name'];
                                                            if (!empty($app['last_name'])) {
                                                                $displayName = $app['last_name'] . ', ' . $app['first_name'] . ' ' . $app['middle_name'];
                                                            }
                                                            echo htmlspecialchars(strtoupper(trim($displayName))); 
                                                        ?>
                                                    </div>
                                                    <div class="small text-muted"><?php echo htmlspecialchars((string)($app['school_id_number'] ?? '')); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($app['applicant_type'] === 'New'): ?>
                                                <span class="badge bg-info text-dark">New</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary">Renewal</span>
                                            <?php endif; ?>
                                            <div class="small text-muted mt-1"><?php echo htmlspecialchars(getApplicantStatusLabel($app)); ?></div>
                                        </td>
                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars(formatAcademicDisplay($app, 'program')); ?></span></td>
                        <td><small class="text-muted fw-bold"><?php echo htmlspecialchars(formatAcademicDisplay($app, 'year_level')); ?></small></td>
                        <td><span class="fw-bold"><?php echo htmlspecialchars(formatAcademicDisplay($app, 'units_enrolled')); ?></span></td>
                        <td><span class="fw-bold"><?php echo htmlspecialchars(formatAcademicDisplay($app, 'gwa')); ?></span></td>
                        <td class="text-nowrap">
                            <div class="small"><?php echo date("M d, Y", strtotime($app['submitted_at'])); ?></div>
                            <small class="text-muted" style="font-size: 0.75rem;"><?php echo date("h:i A", strtotime($app['submitted_at'])); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            $status_color = match($app['status']) {
                                                'Approved', 'Active' => 'success',
                                                'Rejected' => 'danger',
                                                'Pending', 'Pending Exam' => 'warning text-dark',
                                                'Renewal Request' => 'primary',
                                                'Dropped' => 'secondary',
                                                default => 'secondary'
                                            };
                                            ?>
                                            <span class="badge bg-<?php echo $status_color; ?>"><?php echo htmlspecialchars($app['status']); ?></span>
                                        </td>
                                        <td class="text-center fw-bold text-primary">
                                            <?php echo isset($app['scholarship_percentage']) && $app['scholarship_percentage'] !== null ? htmlspecialchars(number_format($app['scholarship_percentage'], 0)) . '%' : '-'; ?>
                                        </td>
                                        <td class="text-center fw-bold text-success">
                                            <?php echo isset($app['scholarship_amount']) && $app['scholarship_amount'] !== null ? '₱' . htmlspecialchars(number_format($app['scholarship_amount'], 2)) : '-'; ?>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="viewApplicant(<?php echo $app['id']; ?>)">
                                                <i class="bi bi-eye-fill"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php if (isAdmin()): ?>
        </form>
        <?php endif; ?>

        <!-- Pagination -->
        <div id="paginationContainer">
        <?php if ($total_pages > 1): ?>
        <nav class="mt-4" aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="#" onclick="changePage(<?php echo $page - 1; ?>); return false;">Previous</a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                        <a class="page-link" href="#" onclick="changePage(<?php echo $i; ?>); return false;"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="#" onclick="changePage(<?php echo $page + 1; ?>); return false;">Next</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<!-- Applicant Details Modal -->
<div class="modal fade" id="applicantModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold" id="modalStudentName">Loading...</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Left Column: Details -->
                    <div class="col-lg-8 border-end">
                        <?php if (isAdmin()): ?>
                        <form action="applications.php" method="POST" id="detailsForm">
                        <input type="hidden" name="update_details" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="scholarship_id" value="<?php echo $scholarship_id; ?>">
                        <input type="hidden" name="application_id" id="detailsAppId">
                        <input type="hidden" name="student_id" id="detailsStudentId">
                        <?php endif; ?>

                        <ul class="nav nav-tabs mb-3" id="appTabs" role="tablist">
                            <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-personal">Personal Info</button></li>
                            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-education">Education</button></li>
                            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-responses">Q&A Responses</button></li>
                            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-documents">Documents</button></li>
                            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-exam">Exam Result</button></li>
                        </ul>
                        <div class="tab-content" id="appTabsContent">
                            <div class="tab-pane fade show active" id="tab-personal">
                                <div id="personal-content">Loading...</div>
                            </div>
                            <div class="tab-pane fade" id="tab-education">
                                <div id="education-content">Loading...</div>
                            </div>
                            <div class="tab-pane fade" id="tab-responses">
                                <div id="responses-content">Loading...</div>
                            </div>
                            <div class="tab-pane fade" id="tab-documents">
                                <div id="documents-content" class="row g-3">Loading...</div>
                            </div>
                            <div class="tab-pane fade" id="tab-exam">
                                <div id="exam-content">Loading...</div>
                            </div>
                        </div>
                        <?php if (isAdmin()): ?>
                        <div class="mt-3 text-end border-top pt-3">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
                        </div>
                        </form>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Right Column: Actions -->
                    <div class="col-lg-4">
                        <div class="p-3 bg-light rounded">
                            <h5 class="fw-bold mb-3">Application Status Control</h5>
                            <?php if (isAdmin()): ?>
                            <form action="applications.php" method="POST">
                                <input type="hidden" name="update_status" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="scholarship_id" value="<?php echo $scholarship_id; ?>">
                                <input type="hidden" name="application_id" id="statusAppId">
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Remarks / Note</label>
                                    <textarea name="remarks" id="statusRemarks" class="form-control" rows="4" placeholder="Reason for rejection or notes for the student..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Scholarship Percentage (%)</label>
                                    <input type="number" step="1" name="scholarship_percentage" id="statusPercentage" class="form-control" placeholder="e.g. 100">
                                    <div class="form-text small">Set or adjust the scholarship coverage percentage.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Scholarship Amount (₱)</label>
                                    <input type="number" step="0.01" name="scholarship_amount" id="statusAmount" class="form-control" placeholder="e.g. 5000.00">
                                    <div class="form-text small">Set specific amount granted.</div>
                                </div>
                                <div id="dynamicStatusButtons" class="d-grid gap-2">
                                    <!-- Buttons injected via JS -->
                                </div>
                                <div class="mt-3 text-muted small">
                                    <i class="bi bi-envelope-fill me-1"></i> An email will be sent to the student automatically.
                                </div>
                            </form>
                            <?php else: ?>
                                <div class="alert alert-info">View Only Mode. You cannot change application status.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Document Preview Modal -->
<div class="modal fade" id="documentPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="docPreviewTitle">Document Preview</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="docPreviewMissingState" class="alert alert-warning m-3 d-none">
                    This file is not available in the current storage yet.
                </div>
                <iframe id="docPreviewFrame" src="" style="width: 100%; height: 100%;" frameborder="0"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
const availablePrograms = <?php echo json_encode($distinct_programs ?? []); ?>;
const availableYearLevels = <?php echo json_encode($distinct_year_levels ?? []); ?>;

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function bindDocumentPreviewButtons() {
    document.querySelectorAll('.document-preview-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = decodeURIComponent(this.dataset.url || '');
            const title = decodeURIComponent(this.dataset.title || 'Document');
            viewDocument(url, title);
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Move modal to body to prevent z-index/backdrop issues caused by AOS animations
    const modalEl = document.getElementById('applicantModal');
    if (modalEl) {
        document.body.appendChild(modalEl);
    }
    const docModalEl = document.getElementById('documentPreviewModal');
    if (docModalEl) {
        document.body.appendChild(docModalEl);
    }

    // Select All Checkbox
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.applicant-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }

    // Live Search & Filter Logic
    const searchInput = document.getElementById('liveSearchInput');
    const startDateInput = document.getElementById('startDateInput');
    const programFilterInput = document.getElementById('programFilterInput');
    const yearLevelFilterInput = document.getElementById('yearLevelFilterInput');
    const statusFilterInput = document.getElementById('statusFilterInput');
    let debounceTimer;

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            currentPage = 1;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(performLiveSearch, 300);
        });
    }
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
            currentPage = 1;
            performLiveSearch();
        });
    }
    if (programFilterInput) {
        programFilterInput.addEventListener('change', function() {
            currentPage = 1;
            performLiveSearch();
        });
    }
    if (yearLevelFilterInput) {
        yearLevelFilterInput.addEventListener('change', function() {
            currentPage = 1;
            performLiveSearch();
        });
    }
    if (statusFilterInput) {
        statusFilterInput.addEventListener('change', function() {
            currentPage = 1;
            performLiveSearch();
        });
    }

    // Clear Filter Button Logic
    const clearFilterBtn = document.getElementById('clearFilterBtn');
    if (clearFilterBtn) {
        clearFilterBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (startDateInput) startDateInput.value = '';
            if (programFilterInput) programFilterInput.value = '';
            if (yearLevelFilterInput) yearLevelFilterInput.value = '';
            if (statusFilterInput) statusFilterInput.value = 'all';
            currentPage = 1;
            performLiveSearch();
        });
    }
});

let currentPage = 1;

function changePage(page) {
    if (page < 1) return;
    currentPage = page;
    performLiveSearch();
}

function performLiveSearch() {
    const search = document.getElementById('liveSearchInput').value;
    const startDate = document.getElementById('startDateInput').value;
    const program = document.getElementById('programFilterInput').value;
    const yearLevel = document.getElementById('yearLevelFilterInput').value;
    const status = document.getElementById('statusFilterInput').value;
    const scholarshipId = document.getElementById('scholarshipIdInput').value;

    const url = `applications.php?scholarship_id=${scholarshipId}&ajax_search=1&search=${encodeURIComponent(search)}&start_date=${encodeURIComponent(startDate)}&status_filter=${encodeURIComponent(status)}&program_filter=${encodeURIComponent(program)}&year_level_filter=${encodeURIComponent(yearLevel)}&page=${currentPage}`;

    // Show loading spinner
    document.getElementById('applicantsTableBody').innerHTML = '<tr><td colspan="9" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

    fetch(url)
        .then(response => response.json())
        .then(data => {
            document.getElementById('applicantsTableBody').innerHTML = data.rows;
            document.getElementById('paginationContainer').innerHTML = data.pagination;
            
            // Update Statistics Cards
            if (data.stats) {
                if(document.getElementById('stat-approved')) document.getElementById('stat-approved').textContent = data.stats.Approved;
                if(document.getElementById('stat-pending')) document.getElementById('stat-pending').textContent = data.stats.Pending;
                if(document.getElementById('stat-review')) document.getElementById('stat-review').textContent = data.stats['Under Review'];
                if(document.getElementById('stat-renewal-req')) document.getElementById('stat-renewal-req').textContent = data.stats['Renewal Request'];
                if(document.getElementById('stat-drop-req')) document.getElementById('stat-drop-req').textContent = data.stats['Drop Requested'];
                if(document.getElementById('stat-dropped')) document.getElementById('stat-dropped').textContent = data.stats.Dropped;
            }
            if (data.types) {
                if(document.getElementById('stat-total')) document.getElementById('stat-total').textContent = data.types.total;
                if(document.getElementById('stat-new')) document.getElementById('stat-new').textContent = data.types.new_apps;
                if(document.getElementById('stat-renewal')) document.getElementById('stat-renewal').textContent = data.types.renewal_apps;
            }
        })
        .catch(error => console.error('Error:', error));
}

function viewApplicant(appId) {
    const modalEl = document.getElementById('applicantModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();

    // Reset content
    document.getElementById('modalStudentName').innerText = 'Loading...';
    document.getElementById('statusAppId').value = appId;
    document.getElementById('detailsAppId').value = appId;
    
    // Fetch Data
    fetch(`applications.php?action=get_details&app_id=${appId}`)
        .then(response => response.json())
        .then(data => {
            if(data.error) {
                alert(data.error);
                return;
            }
            const app = data.application;
            
            // Header & Status Form
            document.getElementById('modalStudentName').innerText = app.student_name;
            document.getElementById('detailsStudentId').value = app.student_id;
            document.getElementById('statusRemarks').value = app.remarks || '';
            document.getElementById('statusPercentage').value = app.scholarship_percentage || '';
            document.getElementById('statusAmount').value = app.scholarship_amount || '';

            // Auto-Sync Logic for Amount and Percentage
            let effectiveBaseAmount = parseFloat(app.base_amount) || 0;
            const amountType = app.amount_type;
            const scholarshipName = app.scholarship_name || '';
            const unitsEnrolled = parseFloat(app.units_enrolled) || 0;
            
            // Determine the 100% Base Value (Tuition Cost) based on Scholarship Rules
            let isCalculatedBase = false;

            if (scholarshipName.includes('Student Assistant') || scholarshipName.includes('Academic Scholarship')) {
                // Formula: 339 * units
                effectiveBaseAmount = 339 * unitsEnrolled;
                isCalculatedBase = true;
            } else if (scholarshipName.includes('Yeomchang')) {
                // As is: Use the database amount (effectiveBaseAmount is already app.base_amount)
                isCalculatedBase = false;
            } else {
                // The rest: Formula 339 * 21
                effectiveBaseAmount = 339 * 21;
                isCalculatedBase = true;
            }

            const percInput = document.getElementById('statusPercentage');
            const amtInput = document.getElementById('statusAmount');

            // Clear previous event listeners to prevent stacking
            percInput.oninput = null;
            amtInput.oninput = null;

            // Set Input Limits
            percInput.max = 100;
            percInput.min = 0;
            
            if (isCalculatedBase) {
                // If we calculated the base, the max amount is that base
                amtInput.max = effectiveBaseAmount.toFixed(2);
            } else {
                // Existing logic for Yeomchang or others (As Is)
                if (amountType === 'Peso' && effectiveBaseAmount > 0) {
                    amtInput.max = effectiveBaseAmount;
                } else if (amountType === 'Percentage' && effectiveBaseAmount > 0) {
                    percInput.max = effectiveBaseAmount; // Cap percentage to scholarship limit
                    amtInput.removeAttribute('max'); 
                } else {
                    amtInput.removeAttribute('max');
                }
            }

            // Enable Sync if we have a valid base amount (Calculated or Peso-based)
            const shouldEnableSync = (effectiveBaseAmount > 0) && (isCalculatedBase || amountType === 'Peso');
            
            if (shouldEnableSync) {
                const syncAmount = function() {
                    const p = parseFloat(percInput.value);
                    if (!isNaN(p)) {
                        amtInput.value = ((effectiveBaseAmount * p) / 100).toFixed(2);
                    } else {
                        amtInput.value = '';
                    }
                };

                const syncPercentage = function() {
                    const a = parseFloat(amtInput.value);
                    if (!isNaN(a)) {
                        percInput.value = ((a / effectiveBaseAmount) * 100).toFixed(2);
                    } else {
                        percInput.value = '';
                    }
                };

                percInput.oninput = syncAmount;
                amtInput.oninput = syncPercentage;

                // Initial Sync: If one value is present and the other is missing, calculate it.
                if (percInput.value !== '' && amtInput.value === '') {
                    syncAmount();
                } else if (amtInput.value !== '' && percInput.value === '') {
                    syncPercentage();
                }
            }

            // Dynamic Buttons based on Status
            const btnContainer = document.getElementById('dynamicStatusButtons');
            let buttonsHtml = '';

            if (['Pending', 'Pending Exam'].includes(app.status)) {
                buttonsHtml += '<button type="submit" name="status" value="Approved" class="btn btn-success"><i class="bi bi-check-circle-fill me-2"></i>Approve Application</button>';
                buttonsHtml += '<button type="submit" name="status" value="Under Review" class="btn btn-warning text-dark"><i class="bi bi-hourglass-split me-2"></i>Mark Under Review</button>';
                buttonsHtml += '<button type="submit" name="status" value="Rejected" class="btn btn-danger"><i class="bi bi-x-circle-fill me-2"></i>Reject Application</button>';
            } 
            else if (app.status === 'Under Review') {
                buttonsHtml += '<button type="submit" name="status" value="Approved" class="btn btn-success"><i class="bi bi-check-circle-fill me-2"></i>Approve Application</button>';
                buttonsHtml += '<button type="submit" name="status" value="Rejected" class="btn btn-danger"><i class="bi bi-x-circle-fill me-2"></i>Reject Application</button>';
            }
            else if (app.status === 'Renewal Request' || app.status === 'For Renewal') {
                buttonsHtml += '<button type="submit" name="status" value="Approved" class="btn btn-success"><i class="bi bi-check-circle-fill me-2"></i>Approve Renewal</button>';
                buttonsHtml += '<button type="submit" name="status" value="Under Review" class="btn btn-warning text-dark"><i class="bi bi-hourglass-split me-2"></i>Mark Under Review</button>';
                buttonsHtml += '<button type="submit" name="status" value="Rejected" class="btn btn-danger"><i class="bi bi-x-circle-fill me-2"></i>Reject Renewal</button>';
            }
            else if (app.status === 'Drop Requested') {
                buttonsHtml += '<button type="submit" name="status" value="Dropped" class="btn btn-danger"><i class="bi bi-check-circle-fill me-2"></i>Approve Drop Request</button>';
                buttonsHtml += '<button type="submit" name="status" value="Active" class="btn btn-secondary"><i class="bi bi-x-circle-fill me-2"></i>Reject Drop Request</button>';
            }
            else if (['Approved', 'Active'].includes(app.status)) {
                buttonsHtml += '<div class="alert alert-success mb-2"><i class="bi bi-check-circle-fill me-2"></i>Active Scholar</div>';
                buttonsHtml += `<button type="submit" name="status" value="${app.status}" class="btn btn-primary"><i class="bi bi-pencil-square me-2"></i>Update Details</button>`;
                buttonsHtml += '<button type="submit" name="status" value="Dropped" class="btn btn-outline-danger">Force Drop Student</button>';
            }
            else {
                buttonsHtml += `<div class="alert alert-secondary mb-2">Status: ${app.status}</div>`;
                buttonsHtml += '<button type="submit" name="status" value="Pending" class="btn btn-outline-secondary">Revert to Pending</button>';
            }
            btnContainer.innerHTML = buttonsHtml;

            // Personal Info
            document.getElementById('personal-content').innerHTML = `
                <table class="table table-borderless">
                    <tr><th width="30%">Name:</th><td><input type="text" class="form-control form-control-sm" name="student_name" value="${app.student_name}"></td></tr>
                    <tr><th>School ID:</th><td><input type="text" class="form-control form-control-sm" name="school_id_number" value="${app.school_id_number || ''}" placeholder="${app.student_status === 'Incoming Student' ? 'No school ID yet' : ''}"></td></tr>
                    <tr><th>Email:</th><td><input type="email" class="form-control form-control-sm" name="email" value="${app.email}"></td></tr>
                    <tr><th>Phone:</th><td><input type="text" class="form-control form-control-sm" name="phone" value="${app.phone || ''}"></td></tr>
                    <tr><th>Birthdate:</th><td><input type="date" class="form-control form-control-sm" name="date_of_birth" value="${app.date_of_birth || ''}"></td></tr>
                    <tr><th>Type:</th><td><input type="text" class="form-control form-control-sm" value="${app.applicant_type}" readonly disabled></td></tr>
                    <tr><th>Student Status:</th><td>
                        <select class="form-select form-select-sm" name="student_status">
                            <option value="">Select status</option>
                            <option value="Incoming Student" ${app.student_status === 'Incoming Student' ? 'selected' : ''}>Incoming Student</option>
                            <option value="Continuing Student" ${app.student_status === 'Continuing Student' ? 'selected' : ''}>Continuing Student</option>
                            <option value="Renewal Student" ${app.student_status === 'Renewal Student' ? 'selected' : ''}>Renewal Student</option>
                        </select>
                    </td></tr>
                </table>
            `;

            // Education
            let programOptions = '<option value="">Select Program</option>';
            availablePrograms.forEach(p => {
                programOptions += `<option value="${p}" ${app.program === p ? 'selected' : ''}>${p}</option>`;
            });
            if (app.program && !availablePrograms.includes(app.program)) {
                programOptions += `<option value="${app.program}" selected>${app.program}</option>`;
            }

            let yearLevelOptions = '<option value="">Select Year Level</option>';
            availableYearLevels.forEach(y => {
                yearLevelOptions += `<option value="${y}" ${app.year_level === y ? 'selected' : ''}>${y}</option>`;
            });
            if (app.year_level && !availableYearLevels.includes(app.year_level)) {
                yearLevelOptions += `<option value="${app.year_level}" selected>${app.year_level}</option>`;
            }

            const incomingNotice = app.student_status === 'Incoming Student'
                ? '<div class="alert alert-warning py-2 small mb-3">This applicant is tagged as Incoming Student. Academic fields may stay blank until enrollment is completed.</div>'
                : '';

            document.getElementById('education-content').innerHTML = `
                ${incomingNotice}
                <table class="table table-borderless">
                    <tr><th width="30%">Program:</th><td><select class="form-select form-select-sm" name="program">${programOptions}</select></td></tr>
                    <tr><th>Year Level:</th><td><select class="form-select form-select-sm" name="year_level">${yearLevelOptions}</select></td></tr>
                    <tr><th>Units Enrolled:</th><td><input type="number" class="form-control form-control-sm" name="units_enrolled" value="${app.units_enrolled || ''}"></td></tr>
                    <tr><th>GWA:</th><td><input type="number" step="0.01" class="form-control form-control-sm" name="gwa" value="${app.gwa || ''}"></td></tr>
                </table>
            `;

            // Responses
            let respHtml = '';
            if(data.responses.length > 0) {
                respHtml += '<div class="row g-3">';
                data.responses.forEach(r => {
                    // Use full width for textareas or long content, half width for short fields
                    let isLong = (r.field_type === 'textarea' || (r.response_value && r.response_value.length > 60));
                    let colClass = isLong ? 'col-12' : 'col-md-6';
                    
                    let inputField = '';
                    if (r.field_type === 'textarea') {
                        inputField = `<textarea class="form-control" name="responses[${r.response_id}]" rows="3">${r.response_value || ''}</textarea>`;
                    } else {
                        inputField = `<input type="text" class="form-control" name="responses[${r.response_id}]" value="${r.response_value || ''}">`;
                    }

                    respHtml += `<div class="${colClass}"><div class="p-3 border rounded bg-light h-100"><small class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem;">${r.field_label}</small><div class="mt-1">${inputField}</div></div></div>`;
                });
                respHtml += '</div>';
            } else {
                respHtml = '<div class="text-center text-muted py-4">No additional information provided.</div>';
            }
            document.getElementById('responses-content').innerHTML = respHtml;

            // Documents
            let docHtml = '';
            if(data.documents.length > 0) {
                data.documents.forEach(d => {
                    const fileUrl = d.file_url || '#';
                    const fileName = d.file_name || 'Document';
                    const encodedUrl = encodeURIComponent(fileUrl);
                    const encodedTitle = encodeURIComponent(fileName);
                    const unavailableNote = d.file_status === 'legacy_upload_missing'
                        ? 'Legacy upload not found in current storage.'
                        : 'File is unavailable.';
                    docHtml += `
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-body d-flex align-items-center">
                                    <i class="bi bi-file-earmark-pdf fs-2 text-danger me-3"></i>
                                    <div class="text-truncate">
                                        ${d.file_exists
                                            ? `<button type="button" class="btn btn-link p-0 text-start text-decoration-none fw-bold stretched-link document-preview-btn" data-url="${encodedUrl}" data-title="${encodedTitle}">${escapeHtml(fileName)}</button>`
                                            : `<div class="fw-bold text-muted">${escapeHtml(fileName)}</div><small class="text-warning">${escapeHtml(unavailableNote)}</small>`
                                        }
                                    </div>
                                </div>
                            </div>
                        </div>`;
                });
            } else {
                docHtml = '<p class="text-muted">No documents uploaded.</p>';
            }
            document.getElementById('documents-content').innerHTML = docHtml;
            bindDocumentPreviewButtons();

            // Exam
            let examHtml = '';
            if(data.exam) {
                examHtml = `
                    <input type="hidden" name="exam_id" value="${data.exam.id}">
                    <div class="alert alert-${data.exam.status === 'graded' ? 'info' : 'warning'}">
                        <div class="row align-items-center">
                            <div class="col-auto"><h4>Score:</h4></div>
                            <div class="col"><input type="number" class="form-control" name="exam_score" value="${data.exam.score}" style="width: 100px; display: inline-block;"> / <input type="number" class="form-control" name="exam_total" value="${data.exam.total_items}" style="width: 100px; display: inline-block;"></div>
                        </div>
                        <p class="mb-0">Status: ${data.exam.status}</p>
                    </div>
                    <a href="view-exam.php?id=${data.exam.id}" class="btn btn-outline-primary btn-sm" target="_blank">View Full Exam Details</a>
                `;
            } else {
                examHtml = '<p class="text-muted">No exam record found for this application.</p>';
            }
            document.getElementById('exam-content').innerHTML = examHtml;
        })
        .catch(error => {
            console.error('Error fetching details:', error);
            alert('Failed to load applicant details. Please try again.');
        });
}

function viewDocument(url, title) {
    const modalEl = document.getElementById('documentPreviewModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('docPreviewTitle').innerText = title;
    const frame = document.getElementById('docPreviewFrame');
    const missingState = document.getElementById('docPreviewMissingState');
    if (!url || url === '#') {
        frame.src = '';
        frame.classList.add('d-none');
        missingState.classList.remove('d-none');
    } else {
        frame.src = url;
        frame.classList.remove('d-none');
        missingState.classList.add('d-none');
    }
    modal.show();
}
</script>

<?php include 'footer.php'; ?>
