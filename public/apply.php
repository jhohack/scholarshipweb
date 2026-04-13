<?php
// Set proper execution timeout and memory limits for large uploads
set_time_limit(55); // Slightly less than Vercel's 60-second limit
ini_set('max_execution_time', 55);
ini_set('memory_limit', '256M');

ob_start();

// Global error and exception handler for Vercel compatibility
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if ($errno === E_WARNING || $errno === E_NOTICE) {
        error_log("PHP Warning/Notice: [$errno] $errstr in $errfile:$errline");
        return true; // Suppress warnings
    }
    return false;
});

set_exception_handler(function(Throwable $e) {
    error_log("Uncaught Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    ob_end_clean();
    echo '<div style="font-family: sans-serif; text-align: center; padding: 50px; color: #333;">';
    echo '<h1>Application Submission Error</h1>';
    echo '<p>Something went wrong while processing your application.</p>';
    echo '<p style="color: #666; font-size: 14px;">Please refresh the page and try again.</p>';
    echo '<a href="/public/scholarships.php" style="color: #0066cc;">Back to Scholarships</a>';
    echo '</div>';
    exit;
});

$base_path = dirname(__DIR__);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $base_path . '/includes/functions.php';

// --- Database Migration: Keep application/form schema aligned across drivers ---
try {
    dbEnsureApplicationsSchema($pdo);
    dbEnsureFormsSchema($pdo);
    dbEnsureExamSchema($pdo);
} catch (PDOException $e) {
    die("A critical database error occurred during schema update. Please contact support.");
}

checkSessionTimeout();

// --- Authentication Check ---
if (!isStudent()) { // Only students can apply
    // Save the intended destination and redirect to login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("Location: login.php");
    exit();
}

// --- Get Scholarship ID Early ---
$scholarship_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$scholarship_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
}
if (!$scholarship_id) {
    header("Location: scholarships.php");
    exit();
}

if (!function_exists('renderApplicationNoticePage')) {
    function renderApplicationNoticePage($base_path, $page_title, $icon_class, $icon_color_class, $lead_text, $body_text, $button_href = 'scholarships.php', $button_label = 'Back to Scholarships')
    {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo htmlspecialchars($page_title); ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
            <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
            <link rel="stylesheet" href="assets/css/style.css">
        </head>
        <body>
        <?php
        include 'header.php';
        echo '<main class="container py-5 mt-5">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card shadow-sm border-0 text-center p-5" data-aos="fade-up">
                            <div class="display-1 ' . htmlspecialchars($icon_color_class) . ' mb-3"><i class="bi ' . htmlspecialchars($icon_class) . '"></i></div>
                            <h1 class="fw-bold">' . htmlspecialchars($page_title) . '</h1>
                            <p class="lead text-muted">' . htmlspecialchars($lead_text) . '</p>
                            <p>' . htmlspecialchars($body_text) . '</p>
                            <div class="d-grid gap-2 col-6 mx-auto mt-4">
                                <a href="' . htmlspecialchars($button_href) . '" class="btn btn-primary btn-lg">' . htmlspecialchars($button_label) . '</a>
                            </div>
                        </div>
                    </div>
                </div>
            </main>';
        include $base_path . '/includes/footer.php';
        echo '</body></html>';
        exit();
    }
}

// --- Core Rule Checks ---
$user_id = $_SESSION['user_id'];
$can_renew = false;
try {
    // 1. Get Student ID
    $stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $student_id_check = $stmt->fetchColumn();

    if ($student_id_check) {
        // 2. Check for Active Scholarship (Restriction)
        // Check if the student has an active-like scholarship for a DIFFERENT scholarship ID.
        // We check the LATEST application for each scholarship to determine current status.
        $stmt = $pdo->prepare("
            SELECT a.id 
            FROM applications a
            WHERE a.student_id = ? 
            AND a.scholarship_id != ?
            AND a.id = (
                SELECT MAX(sub.id) 
                FROM applications sub 
                WHERE sub.student_id = a.student_id 
                AND sub.scholarship_id = a.scholarship_id
            )
            AND a.status IN ('Active', 'Approved', 'For Renewal', 'Renewal Request', 'Drop Requested')

            LIMIT 1
        ");
        $stmt->execute([$student_id_check, $scholarship_id]);
        $conflicting_scholarship_exists = $stmt->fetchColumn();

        // Check renewal eligibility: Can only renew if the LATEST status of THIS scholarship is Active/Approved
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM applications a
            WHERE a.student_id = ? 
            AND a.scholarship_id = ? 
            AND a.id = (
                SELECT MAX(sub.id) 
                FROM applications sub 
                WHERE sub.student_id = a.student_id 
                AND sub.scholarship_id = a.scholarship_id
            )
            AND (
                a.status IN ('Active', 'Approved', 'For Renewal')
                OR (a.status = 'Rejected' AND a.applicant_type = 'Renewal')
            )
        ");
        $stmt->execute([$student_id_check, $scholarship_id]);
        $can_renew = ($stmt->fetchColumn() > 0);

        // Block if a conflicting scholarship exists, UNLESS the student is renewing the current one.
        // This ensures students can always access their own renewal page, even if the system detects other active records.
        if ($conflicting_scholarship_exists && !$can_renew) {
        $page_title = 'Application Restricted';
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo htmlspecialchars($page_title); ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
            <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
            <link rel="stylesheet" href="assets/css/style.css">
        </head>
        <body>
        <?php
        include 'header.php';
        echo '<main class="container py-5 mt-5">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card shadow-sm border-0 text-center p-5" data-aos="fade-up">
                            <div class="display-1 text-warning mb-3"><i class="bi bi-lock-fill"></i></div>
                            <h1 class="fw-bold">Application Restricted</h1>
                            <p class="lead text-muted">You already have an active scholarship.</p>
                            <p>Our policy limits students to <strong>one active scholarship</strong> at a time. Since you already have an approved or active scholarship, you cannot apply for another one.</p>
                            <div class="d-grid gap-2 col-6 mx-auto mt-4">
                                <a href="../student/dashboard.php" class="btn btn-primary btn-lg">Go to My Dashboard</a>
                            </div>
                        </div>
                    </div>
                </div>
            </main>';
        include $base_path . '/includes/footer.php';
        echo '</body></html>';
        exit();
        }

        // 3. Check for Duplicate/Pending Applications for THIS scholarship
        // Prevent multiple pending applications for the same scholarship.
        $stmt = $pdo->prepare("SELECT status FROM applications WHERE student_id = ? AND scholarship_id = ? AND status IN ('Pending', 'Under Review', 'Pending Exam', 'Renewal Request', 'Drop Requested') LIMIT 1");
        $stmt->execute([$student_id_check, $scholarship_id]);
        $existing_status = $stmt->fetchColumn();

        if ($existing_status) {
            $page_title = 'Application Already Submitted';
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title><?php echo htmlspecialchars($page_title); ?></title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
                <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
                <link rel="stylesheet" href="assets/css/style.css">
            </head>
            <body>
            <?php
            include 'header.php';
            echo '<main class="container py-5 mt-5">
                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <div class="card shadow-sm border-0 text-center p-5" data-aos="fade-up">
                                <div class="display-1 text-info mb-3"><i class="bi bi-info-circle-fill"></i></div>
                                <h1 class="fw-bold">Application Already Submitted</h1>
                                <p class="lead text-muted">You already have a pending application for this scholarship.</p>
                                <p>Your application status is currently: <strong>' . htmlspecialchars($existing_status) . '</strong>. Please wait for the admin to process your request.</p>
                                <div class="d-grid gap-2 col-6 mx-auto mt-4">
                                    <a href="../student/dashboard.php" class="btn btn-primary btn-lg">Go to My Dashboard</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>';
            include $base_path . '/includes/footer.php';
            echo '</body></html>';
            exit();
        }
    }
} catch (PDOException $e) {
    die("A database error occurred while checking your application status. Please try again later.");
}

$errors = [];
$success = false;
$form_fields = [];
$form_info = null;
$accepting_new_applicants = false;
$accepting_renewal_applicants = false;
$is_new_application_window_open = false;

// --- Fetch Scholarship Details ---
try {
    $scholarship_stmt = $pdo->prepare("SELECT * FROM scholarships WHERE id = ?");
    $scholarship_stmt->execute([$scholarship_id]);
    $scholarship = $scholarship_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$scholarship) {
        // Scholarship not found, redirect
        header("Location: scholarships.php");
        exit();
    }

    // If scholarship is inactive, strictly restrict access to Renewal applicants only
    if ($scholarship['status'] !== 'active' && !$can_renew) {
        header("Location: scholarships.php");
        exit();
    }

    // --- Check for and Fetch Dynamic Form ---
    $form_stmt = $pdo->prepare("SELECT * FROM forms WHERE scholarship_id = ? LIMIT 1");
    $form_stmt->execute([$scholarship_id]);
    if ($form_info = $form_stmt->fetch(PDO::FETCH_ASSOC)) {
        $fields_stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY field_order ASC");
        $fields_stmt->execute([$form_info['id']]);
        $form_fields = $fields_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- Check Slot Availability ---
    // Count currently active scholars to see if slots are full
    $slot_check_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE scholarship_id = ? AND status = 'Active'");
    $slot_check_stmt->execute([$scholarship_id]);
    $current_active_scholars = $slot_check_stmt->fetchColumn();
    $slots_full = ($current_active_scholars >= $scholarship['available_slots']);

    $deadline = !empty($scholarship['deadline']) ? new DateTime($scholarship['deadline']) : null;
    $now = new DateTime();
    $accepting_new_applicants = !empty($scholarship['accepting_new_applicants']);
    $accepting_renewal_applicants = !empty($scholarship['accepting_renewal_applicants']);
    $is_new_application_window_open = $deadline ? ($now <= $deadline) : true;

    if ($can_renew && !$accepting_renewal_applicants) {
        renderApplicationNoticePage(
            $base_path,
            'Renewal Closed',
            'bi-lock-fill',
            'text-warning',
            'This scholarship is not accepting renewal applications right now.',
            'Please wait for the next renewal period or check your dashboard for updates.',
            '../student/dashboard.php',
            'Go to My Dashboard'
        );
    }

    if (!$can_renew) {
        if (!$accepting_new_applicants) {
            renderApplicationNoticePage(
                $base_path,
                'New Applications Closed',
                'bi-lock-fill',
                'text-warning',
                'This scholarship is not accepting new applicants right now.',
                'You can return to the scholarship list and explore other available programs.',
                'scholarships.php',
                'Back to Scholarships'
            );
        }

        if (!$is_new_application_window_open) {
            renderApplicationNoticePage(
                $base_path,
                'Application Closed',
                'bi-calendar-x-fill',
                'text-danger',
                'The deadline for new applicants has already passed.',
                'This scholarship is no longer open for new applications.',
                'scholarships.php',
                'Back to Scholarships'
            );
        }
    }

} catch (PDOException $e) {
    $errors[] = "Could not retrieve scholarship details. Please try again.";
    $errors[] = "Database Error: " . $e->getMessage(); // For debugging purposes
}

// --- Fetch Student Data for Pre-filling Form ---
$student_data = [];
$user_details = [];
if (empty($errors)) {
    try {
        // Fetch from students table first
        $stmt = $pdo->prepare("SELECT * FROM students WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $student_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch user details for separate names
        $user_stmt = $pdo->prepare("SELECT first_name, middle_name, last_name FROM users WHERE id = ?");
        $user_stmt->execute([$_SESSION['user_id']]);
        $user_details = $user_stmt->fetch(PDO::FETCH_ASSOC);

        // Calculate Age
        if (!empty($student_data['date_of_birth'])) {
            $dob = new DateTime($student_data['date_of_birth']);
            $now = new DateTime();
            $student_data['age'] = $now->diff($dob)->y;
        }
    } catch (Exception $e) { /* Ignore */ }
}

// --- Handle Form Submission ---
// Applicant type option removed from UI: renewal/new is derived from application status.
// Use $can_renew to render renewal form; re-check at submission time with $is_renewing.

$selected_student_status = $_POST['student_status'] ?? '';
$selected_year_level = $_POST['year_level'] ?? '';
$selected_program = trim($_POST['program'] ?? '');
$selected_school_id = $_POST['school_id'] ?? ($student_data['school_id_number'] ?? '');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $posted_scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
    $user_id = $_SESSION['user_id'];
    $supporting_documents = $_FILES['supporting_documents'] ?? null;
    $gwa_document = $_FILES['gwa_document'] ?? null;
    $dynamic_responses = $_POST['fields'] ?? [];

    // Capture contact and birthdate for potential update
    $contact_number = trim($_POST['contact_number'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $selected_student_status = trim($_POST['student_status'] ?? '');
    $selected_year_level = trim($_POST['year_level'] ?? '');
    $selected_program = trim($_POST['program'] ?? '');
    $selected_school_id = trim($_POST['school_id'] ?? ($student_data['school_id_number'] ?? ''));
    $normalizeUploadedFiles = static function ($file_input) {
        if (empty($file_input) || !isset($file_input['name'])) {
            return [];
        }

        if (is_array($file_input['name'])) {
            $files = [];
            foreach ($file_input['name'] as $index => $name) {
                $files[] = [
                    'name' => $name,
                    'tmp_name' => $file_input['tmp_name'][$index] ?? '',
                    'error' => $file_input['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'type' => $file_input['type'][$index] ?? '',
                    'size' => $file_input['size'][$index] ?? 0,
                ];
            }
            return $files;
        }

        return [[
            'name' => $file_input['name'],
            'tmp_name' => $file_input['tmp_name'] ?? '',
            'error' => $file_input['error'] ?? UPLOAD_ERR_NO_FILE,
            'type' => $file_input['type'] ?? '',
            'size' => $file_input['size'] ?? 0,
        ]];
    };
    $hasUploadedFile = static function ($file_input) use ($normalizeUploadedFiles) {
        foreach ($normalizeUploadedFiles($file_input) as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && trim((string)($file['name'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    };
    $supporting_document_files = $normalizeUploadedFiles($supporting_documents);
    $gwa_document_files = $normalizeUploadedFiles($gwa_document);

    // Update user info if provided. This happens before the main application logic.
    if (!empty($contact_number) || !empty($birthdate) || (array_key_exists('school_id', $_POST) && $selected_school_id !== '')) {
        try {
            $update_fields = [];
            $update_params = [];
            if (!empty($contact_number)) { $update_fields[] = "contact_number = ?"; $update_params[] = $contact_number; }
            if (!empty($birthdate)) { $update_fields[] = "birthdate = ?"; $update_params[] = $birthdate; }
            if (array_key_exists('school_id', $_POST) && $selected_school_id !== '') {
                $update_fields[] = "school_id = ?";
                $update_params[] = $selected_school_id;
            }
            $update_params[] = $user_id;

            if (!empty($update_fields)) {
                $update_sql = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = ?";
                $update_user_stmt = $pdo->prepare($update_sql);
                $update_user_stmt->execute($update_params);
            }
        } catch (PDOException $e) {
            $errors[] = "Failed to update your profile information. Please try again.";
            error_log("Profile update during apply error: " . $e->getMessage());
        }
    }
    
    // Combine Year Graduated fields
    if (isset($_POST['jhs_year_start'], $_POST['jhs_year_end'])) {
        $dynamic_responses['jhs_year'] = $_POST['jhs_year_start'] . ' - ' . $_POST['jhs_year_end'];
    }
    if (isset($_POST['shs_year_start'], $_POST['shs_year_end'])) {
        $dynamic_responses['shs_year'] = $_POST['shs_year_start'] . ' - ' . $_POST['shs_year_end'];
    }
    
    // Renewal specific fields
    $year_level = ($selected_year_level !== '') ? $selected_year_level : null;
    $program = ($selected_program !== '') ? $selected_program : null;
    $year_program = ($year_level && $program) ? $year_level . ' - ' . $program : null;

    $units_enrolled = filter_input(INPUT_POST, 'units_enrolled', FILTER_SANITIZE_NUMBER_INT);
    $gwa = filter_input(INPUT_POST, 'gwa', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

    // Get the student ID associated with the logged-in user.
    $student_id = null;
    try {
        // 1. Check if a student record already exists for this user.
        $stmt = $pdo->prepare("SELECT id, school_id_number FROM students WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $student = $stmt->fetch();

        if ($student) { // Found a student record linked to this user
            $student_id = $student['id'];

            // --- Data Synchronization ---
            // Always update the student record with the latest from the users table before applying.
            // This ensures data consistency across the system.
            $user_sync_stmt = $pdo->prepare("
                SELECT first_name, middle_name, last_name, school_id, email, contact_number, birthdate 
                FROM users 
                WHERE id = ?
            ");
            $user_sync_stmt->execute([$user_id]);
            $user_data_sync = $user_sync_stmt->fetch();
            
            if ($user_data_sync) {
                $full_name_sync = trim(
                    ($user_data_sync['first_name'] ?? '') . ' ' . 
                    ($user_data_sync['middle_name'] ?? '') . ' ' . 
                    ($user_data_sync['last_name'] ?? '')
                );
                $full_name_sync = preg_replace('/\s+/', ' ', $full_name_sync); // Remove extra spaces
                $school_id_sync = !empty($user_data_sync['school_id']) ? $user_data_sync['school_id'] : NULL;

                $update_student_stmt = $pdo->prepare(
                    "UPDATE students SET 
                        student_name = ?, 
                        school_id_number = ?, 
                        email = ?, 
                        phone = ?, 
                        date_of_birth = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?"
                );
                $update_student_stmt->execute([
                    $full_name_sync, 
                    $school_id_sync, 
                    $user_data_sync['email'], 
                    $user_data_sync['contact_number'], 
                    $user_data_sync['birthdate'], 
                    $student_id
                ]);
            }
            // --- End Data Synchronization ---

        } else {
            // No student record is linked to this user_id. Let's create one.
            $user_stmt = $pdo->prepare("
                SELECT first_name, middle_name, last_name, school_id, email, contact_number, birthdate 
                FROM users 
                WHERE id = ?
            ");
            $user_stmt->execute([$user_id]);
            $user_data = $user_stmt->fetch();

            if ($user_data) {
                $full_name = trim(
                    ($user_data['first_name'] ?? '') . ' ' . 
                    ($user_data['middle_name'] ?? '') . ' ' . 
                    ($user_data['last_name'] ?? '')
                );
                $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces
                $school_id_for_student = !empty($user_data['school_id']) ? $user_data['school_id'] : NULL;
                
                $student_id = dbExecuteInsert(
                    $pdo,
                    "INSERT INTO students (user_id, student_name, school_id_number, email, phone, date_of_birth) VALUES (?, ?, ?, ?, ?, ?)",
                    [
                    $user_id, 
                    $full_name, 
                    $school_id_for_student,
                    $user_data['email'],
                    $user_data['contact_number'],
                    $user_data['birthdate']
                    ]
                );
            } else {
                $errors[] = "Could not retrieve user information for student record creation.";
            }
        }
    } catch (PDOException $e) {
        $errors[] = "Unable to map user to student record: " . $e->getMessage();
    }

    if (!$student_id) {
        $errors[] = "Could not determine student ID for this application. Please contact support.";
    }

    if ($posted_scholarship_id != $scholarship_id) {
        $errors[] = "Form submission error. Please try again.";
    } 
    else {
        // Re-check renewal eligibility for this scholarship to prevent race conditions
        $renew_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM applications a 
            WHERE a.student_id = ? 
            AND a.scholarship_id = ? 
            AND a.id = (SELECT MAX(sub.id) FROM applications sub WHERE sub.student_id = a.student_id AND sub.scholarship_id = a.scholarship_id) 
            AND (
                a.status IN ('Active', 'Approved', 'For Renewal')
                OR (a.status = 'Rejected' AND a.applicant_type = 'Renewal')
            )
        ");
        $renew_stmt->execute([$student_id, $scholarship_id]);
        $is_renewing = ($renew_stmt->fetchColumn() > 0);
        $is_incoming_student = !$is_renewing && $selected_student_status === 'Incoming Student';
        $is_continuing_student = !$is_renewing && $selected_student_status === 'Continuing Student';
        // --- Validation: Renewal vs New Logic ---
        // If applying as Renewal, ensure they actually have an active scholarship for this ID.
        // If applying as New, check if slots are full
        if ($is_renewing && !$accepting_renewal_applicants) {
            $errors[] = "This scholarship is not accepting renewal applications right now.";
        }
        if (!$is_renewing && !$accepting_new_applicants) {
            $errors[] = "This scholarship is not accepting new applicants right now.";
        }
        if (!$is_renewing && !$is_new_application_window_open) {
            $errors[] = "The deadline for new applicants has already passed.";
        }
        if (!$is_renewing && $slots_full) {
            $errors[] = "This scholarship has reached its limit for new applicants (Slots Full). Only renewal applications are currently accepted.";
        }

        // Validation for Renewal Applicants
        if ($is_renewing) {
            if (empty($year_program) || empty($units_enrolled) || empty($gwa)) {
                $errors[] = "Year & Program, Units Enrolled, and GWA are required for Renewal Applicants.";
            }
            if (!$hasUploadedFile($gwa_document)) {
                $errors[] = "A scanned copy of your GWA must be uploaded for renewal.";
            }
        }
        // Validation for New Applicants
        else {
            if (!$is_incoming_student && !$is_continuing_student) {
                $errors[] = "Please select whether you are an Incoming Student or a Continuing Student.";
            }
            if ($is_continuing_student) {
                if ($selected_school_id === '') {
                    $errors[] = "School ID is required for Continuing Students.";
                }
                if (empty($year_program) || empty($units_enrolled) || empty($gwa)) {
                    $errors[] = "Year & Program, Units Enrolled, and GWA are required for Continuing Students.";
                }
            }
            if (($dynamic_responses['is_working_student'] ?? 'No') === 'Yes' && empty(trim($dynamic_responses['working_student_job'] ?? ''))) {
                $errors[] = "Please specify your job since you indicated you are a working student.";
            }
            if (($dynamic_responses['is_pwd'] ?? 'No') === 'Yes' && empty(trim($dynamic_responses['pwd_details'] ?? ''))) {
                $errors[] = "Please specify your disability details since you indicated you are a PWD.";
            }
            if (!$hasUploadedFile($supporting_documents)) {
                $errors[] = "Required supporting documents must be uploaded.";
            }
            if ($is_continuing_student && !$hasUploadedFile($gwa_document)) {
                $errors[] = "A scanned copy of your GWA must be uploaded for Continuing Students.";
            }
        }

        // --- Secure File Upload Handling ---
        $uploaded_files = [];
        $allowed_types = ['application/pdf'];
        $max_file_size = appUploadMaxBytes();
        $supporting_file_count = 0;
        foreach ($supporting_document_files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && trim((string)($file['name'] ?? '')) !== '') {
                $supporting_file_count++;
            }
        }
        if ($supporting_file_count > 5) {
            $errors[] = "You can only upload a maximum of 5 supporting documents.";
        }

        foreach ([$supporting_document_files, $gwa_document_files] as $file_group) {
            foreach ($file_group as $file) {
                $name = $file['name'] ?? '';
                if (empty($name)) continue; // Skip empty file inputs
                $tmp_name = $file['tmp_name'] ?? '';
                $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
                $type = $file['type'] ?? '';
                $size = $file['size'] ?? 0;

                if ($error === UPLOAD_ERR_OK) {
                    if (!in_array($type, $allowed_types)) {
                        $errors[] = "Invalid file type for '{$name}'. Only PDF files are allowed.";
                        continue;
                    }
                    if ($size > $max_file_size) {
                        $errors[] = "File '{$name}' exceeds the " . appUploadMaxLabel() . " upload limit.";
                        continue;
                    }

                    $upload_result = storeUploadedFile(
                        $pdo,
                        $file,
                        'applications',
                        $student_id . '_',
                        $allowed_types,
                        $max_file_size,
                        $base_path
                    );

                    if ($upload_result['success']) {
                        $uploaded_files[] = [
                            'name' => $upload_result['name'],
                            'path' => $upload_result['path']
                        ];
                    } else {
                        $errors[] = $upload_result['error'] ?? "Failed to upload file: '{$name}'.";
                    }
                } else {
                    if ($error !== UPLOAD_ERR_NO_FILE) {
                        $errors[] = "Error uploading file: '{$name}'.";
                    }
                }
            }
        }

        // --- Database Insertion ---
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Check if an exam exists for this scholarship (only for new applicants)
                $has_exam = false;
                if (!$is_renewing) {
                    try {
                        if (dbTableExists($pdo, 'exam_questions')) {
                            $exam_check = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE scholarship_id = ?");
                            $exam_check->execute([$scholarship_id]);
                            $has_exam = $exam_check->fetchColumn() > 0;
                        }
                    } catch (PDOException $e) {
                        $has_exam = false;
                    }
                }
            // Note: exams are only for new applicants; renewals are processed via status only.
            // $is_renewing is determined earlier during submission and used to skip exams for renewals.
                
                // Determine status and insert application record
                $initial_status = ($is_renewing) ? 'Renewal Request' : ($has_exam ? 'Pending Exam' : 'Pending');
                $applicant_type_insert = $is_renewing ? 'Renewal' : 'New';
                $student_status_insert = $is_renewing ? 'Renewal Student' : $selected_student_status;
                
                $application_id = null;
                $should_update = false;

                if ($is_renewing) {
                    // Check if the latest application for this scholarship is 'Rejected'
                    // If so, we update it instead of creating a new one to preserve the record ID.
                    $check_rejected = $pdo->prepare("
                        SELECT id FROM applications 
                        WHERE student_id = ? AND scholarship_id = ? 
                        AND status = 'Rejected' AND applicant_type = 'Renewal'
                        ORDER BY id DESC LIMIT 1
                    ");
                    $check_rejected->execute([$student_id, $scholarship_id]);
                    $existing_id = $check_rejected->fetchColumn();
                    
                    if ($existing_id) {
                        $should_update = true;
                        $application_id = $existing_id;
                    }
                }

                if ($should_update) {
                    $app_stmt = $pdo->prepare("UPDATE applications SET status = ?, student_status = ?, year_program = ?, program = ?, year_level = ?, units_enrolled = ?, gwa = ?, submitted_at = CURRENT_TIMESTAMP, remarks = NULL WHERE id = ?");
                    $app_stmt->execute([$initial_status, $student_status_insert, $year_program, $program, $year_level, $units_enrolled, $gwa, $application_id]);
                    // Clean up old responses to prevent duplicates
                    $pdo->prepare("DELETE FROM application_responses WHERE application_id = ?")->execute([$application_id]);
                } else {
                    $application_id = dbExecuteInsert(
                        $pdo,
                        "INSERT INTO applications (student_id, scholarship_id, status, applicant_type, student_status, year_program, program, year_level, units_enrolled, gwa, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)",
                        [$student_id, $scholarship_id, $initial_status, $applicant_type_insert, $student_status_insert, $year_program, $program, $year_level, $units_enrolled, $gwa]
                    );
                }

                // If applying as a New Applicant (e.g., after being dropped), update the user's student_type
                if ($applicant_type_insert === 'New') {
                    $update_user_type = $pdo->prepare("UPDATE users SET student_type = 'New Applicant' WHERE id = ?");
                    $update_user_type->execute([$user_id]);
                }

                // --- Robustly Handle All Dynamic Form Responses ---
                if (!empty($dynamic_responses)) {
                    // 1. Find or create the form for this scholarship
                    $form_id = null;
                    $form_find_stmt = $pdo->prepare("SELECT id FROM forms WHERE scholarship_id = ?");
                    $form_find_stmt->execute([$scholarship_id]);
                    $form_id = $form_find_stmt->fetchColumn();
    
                    if (!$form_id) {
                        $form_id = dbExecuteInsert(
                            $pdo,
                            "INSERT INTO forms (scholarship_id, title) VALUES (?, ?)",
                            [$scholarship_id, $scholarship['name'] . ' Application Form']
                        );
                    }
    
                    // 2. Fetch all existing fields for this form once to avoid repeated queries
                    $existing_fields_stmt = $pdo->prepare("SELECT field_name, id FROM form_fields WHERE form_id = ?");
                    $existing_fields_stmt->execute([$form_id]);
                    $existing_fields = $existing_fields_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
                    // Check if form_fields table has scholarship_id column (to satisfy foreign key constraints)
                    $has_scholarship_id_col = dbColumnExists($pdo, 'form_fields', 'scholarship_id');

                    // Prepare statements for reuse
                    $response_stmt = $pdo->prepare("INSERT INTO application_responses (application_id, form_field_id, response_value) VALUES (?, ?, ?)");
    
                    foreach ($dynamic_responses as $field_name => $response_value) {
                        $trimmed_value = is_array($response_value) ? implode(', ', $response_value) : trim($response_value);
                        if ($trimmed_value === '') continue; // Skip empty responses
    
                        if (isset($existing_fields[$field_name])) {
                            $field_id = $existing_fields[$field_name];
                        } else {
                            // Field doesn't exist, create it on-the-fly
                            $field_label = ucwords(str_replace('_', ' ', $field_name));
                            $field_type = (strpos($field_name, 'essay') !== false) ? 'textarea' : 'text';
                            if ($has_scholarship_id_col) {
                                $field_id = dbExecuteInsert(
                                    $pdo,
                                    "INSERT INTO form_fields (form_id, scholarship_id, field_label, field_name, field_type, is_required) VALUES (?, ?, ?, ?, ?, ?)",
                                    [$form_id, $scholarship_id, $field_label, $field_name, $field_type, 1]
                                );
                            } else {
                                $field_id = dbExecuteInsert(
                                    $pdo,
                                    "INSERT INTO form_fields (form_id, field_label, field_name, field_type, is_required) VALUES (?, ?, ?, ?, ?)",
                                    [$form_id, $field_label, $field_name, $field_type, 1]
                                );
                            }
                            $existing_fields[$field_name] = $field_id; // Add to our cache for this request
                        }
    
                        // 3. Insert the response
                        $response_stmt->execute([$application_id, $field_id, $trimmed_value]);
                    }
                }
                // 3. Insert uploaded files into documents table, linking to the user and application
                if (!empty($uploaded_files)) {
                    $doc_stmt = $pdo->prepare("INSERT INTO documents (user_id, application_id, file_name, file_path) VALUES (?, ?, ?, ?)");
                    foreach ($uploaded_files as $file_data) {
                        $doc_stmt->execute([$user_id, $application_id, $file_data['name'], $file_data['path']]);
                    }
                }

                $pdo->commit();
                
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (Exception $rollbackError) {
                        error_log("Rollback failed: " . $rollbackError->getMessage());
                    }
                }
                $errorMsg = $e->getMessage();
                if (stripos($errorMsg, 'timeout') !== false || stripos($errorMsg, 'gone away') !== false) {
                    $errors[] = "The connection to the database was lost during submission. Please try again.";
                } else {
                    $errors[] = "Database Error: " . substr($errorMsg, 0, 100);
                }
                error_log("Application submission error: " . $errorMsg);
            }

            if (empty($errors)) {
                // --- Automatic Dynamic Table Generation & Data Insertion ---
                // This block creates a table specific to the scholarship (if not exists) and saves all inputs.
                try {
                    $dynamic_table_name = "scholarship_submissions_" . $scholarship_id;
                    
                    // 1. Prepare Data
                    $flat_data = [];
                    $flat_data['application_id'] = $application_id;
                    $flat_data['student_id'] = $student_id;
                    $flat_data['submitted_at'] = date('Y-m-d H:i:s');

                    // Add standard inputs
                    $std_fields = ['student_status', 'school_id', 'year_level', 'program', 'units_enrolled', 'gwa', 'contact_number', 'birthdate'];
                    foreach ($std_fields as $f) {
                        if (isset($_POST[$f])) $flat_data[$f] = $_POST[$f];
                    }

                    // Add dynamic responses (includes constructed fields like jhs_year)
                    foreach ($dynamic_responses as $k => $v) {
                        $flat_data[$k] = is_array($v) ? implode(', ', $v) : $v;
                    }

                    // 2. Sanitize Keys & Values
                    $sanitized_data = [];
                    foreach ($flat_data as $key => $value) {
                        $safe_col = preg_replace('/[^a-z0-9_]/i', '', $key); // Remove non-alphanumeric
                        if (!empty($safe_col)) {
                            $sanitized_data[substr($safe_col, 0, 60)] = $value; // Limit length
                        }
                    }

                    // 3. Create Table if not exists
                    // Note: We use a separate try-catch for creation to handle race conditions or existence checks
                    dbEnsureDynamicSubmissionTable($pdo, $dynamic_table_name);
                    
                    // If updating, remove old entry for this application_id to avoid duplicates in dynamic table
                    if ($should_update) {
                        $delete_stmt = $pdo->prepare("DELETE FROM " . dbQuoteIdentifier($dynamic_table_name) . " WHERE application_id = ?");
                        $delete_stmt->execute([$application_id]);
                    }

                    // 4. Ensure Columns Exist (Dynamic Schema Update)
                    $existing_cols = dbListColumns($pdo, $dynamic_table_name);

                    foreach ($sanitized_data as $col => $val) {
                        if (!in_array($col, $existing_cols)) {
                            dbAddColumnIfMissing($pdo, $dynamic_table_name, $col, 'TEXT NULL', 'TEXT NULL');
                        }
                    }

                    // 5. Insert Data
                    $cols_sql = implode(', ', array_map('dbQuoteIdentifier', array_keys($sanitized_data)));
                    $placeholders = implode(', ', array_fill(0, count($sanitized_data), '?'));
                    $stmt_insert = $pdo->prepare("INSERT INTO " . dbQuoteIdentifier($dynamic_table_name) . " ($cols_sql) VALUES ($placeholders)");
                    $stmt_insert->execute(array_values($sanitized_data));

                } catch (Exception $e) {
                    // Log error but do not stop the redirect flow
                    error_log("Dynamic table save failed: " . $e->getMessage());
                }
                // -----------------------------------------------------------

                // Redirect based on whether this was a renewal
                if ($is_renewing) {
                    // Renewal applicants skip the exam and go to dashboard
                    header("Location: ../student/dashboard.php?success=renewal_submitted");
                } elseif ($has_exam) {
                    // New applicants take the entrance exam
                    header("Location: entrance-exam.php?id=" . $application_id);
                } else {
                    // New applicants with no exam go to dashboard
                    header("Location: ../student/dashboard.php?success=application_submitted");
                }
                exit();
            }
        }
    }
}

$page_title = 'Apply for Scholarship';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css"> <!-- Main stylesheet -->
</head>
<body>
    <?php include 'header.php'; // Includes the main public navigation ?>

    <main class="container py-5 mt-5">
        <?php if ($success): ?>
            <div class="text-center py-5" data-aos="zoom-in">
                <div class="success-icon mx-auto mb-4">
                    <i class="bi bi-check2-circle"></i>
                </div>
                <h1 class="display-4 fw-bold">Application Submitted!</h1>
                <p class="lead text-muted col-lg-6 mx-auto">Your application for the "<?php echo htmlspecialchars($scholarship['name']); ?>" has been received. You can track its status in your dashboard.</p>
                <div class="mt-4">
                    <a href="../student/dashboard.php" class="btn btn-primary btn-lg me-2">Go to Dashboard</a>
                    <a href="scholarships.php" class="btn btn-outline-secondary btn-lg">Explore More</a>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center mb-5" data-aos="fade-down">
                <h1 class="fw-bold">Apply for Scholarship</h1>
                <p class="lead text-muted">You are applying for the "<?php echo htmlspecialchars($scholarship['name']); ?>" scholarship.</p>
            </div>

            <div class="row g-5">
                <!-- Left Column: Scholarship Details -->
                <div class="col-lg-5" data-aos="fade-right">
                    <div class="details-card p-4">
                        <h4 class="fw-bold mb-4">Scholarship Overview</h4>
                        <?php
                        $amt_type = $scholarship['amount_type'] ?? 'Peso';
                        $amt_val = $scholarship['amount'];
                        if ($amt_type === 'Percentage') {
                            $amt_display = number_format($amt_val, 0) . '%';
                        } elseif ($amt_type === 'None') {
                            $amt_display = 'None';
                        } else {
                            $amt_display = '₱' . number_format($amt_val, 0);
                        }
                        ?>
                        <p><strong>Amount:</strong> <span class="text-primary fw-bold fs-5"><?php echo htmlspecialchars($amt_display); ?></span></p>
                        <p><strong>Deadline:</strong> <span class="text-danger fw-bold"><?php echo htmlspecialchars(date("F j, Y", strtotime($scholarship['deadline']))); ?></span></p>
                        <hr>
                        <h5 class="fw-bold mt-4">Description</h5>
                        <p class="text-muted small"><?php echo nl2br(htmlspecialchars($scholarship['description'])); ?></p>
                        <h5 class="fw-bold mt-4">Requirements</h5>
                        <div class="text-muted small requirements-list"><?php echo nl2br(htmlspecialchars($scholarship['requirements'])); ?></div>
                    </div>
                </div>

                <!-- Right Column: Application Form -->
                <div class="col-lg-7" data-aos="fade-left" data-aos-delay="100">
                    <div class="form-card p-4 p-md-5">
                        <h4 class="fw-bold mb-4">Submit Your Application</h4>
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <?php foreach ($errors as $error): ?>
                                    <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form action="apply.php?id=<?php echo $scholarship_id; ?>" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="scholarship_id" value="<?php echo $scholarship_id; ?>">

                            <!-- Renewal Applicant Section -->
                            <?php if ($can_renew): ?>
                            <div id="renewal-section">
                                <h5 class="fw-bold text-primary mb-3">Renewal Applicant Information</h5>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Last Name</label>
                                        <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($user_details['last_name'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">First Name</label>
                                        <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($user_details['first_name'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Middle Name</label>
                                        <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($user_details['middle_name'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">School ID Number</label>
                                        <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($student_data['school_id_number'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Birthdate</label>
                                        <input type="date" class="form-control" name="birthdate" value="<?php echo htmlspecialchars($student_data['date_of_birth'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Age</label>
                                        <input type="number" class="form-control" name="fields[age]" value="<?php echo htmlspecialchars($student_data['age'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" class="form-control" name="contact_number" value="<?php echo htmlspecialchars($student_data['phone'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label for="year_level_renewal" class="form-label fw-bold">Year Level <span class="text-danger">*</span></label>
                                        <select class="form-select" id="year_level_renewal" name="year_level" required>
                                            <option value="" <?php echo $selected_year_level === '' ? 'selected' : ''; ?> disabled>Select Year</option>
                                            <option value="1st Year" <?php echo $selected_year_level === '1st Year' ? 'selected' : ''; ?>>1st Year</option>
                                            <option value="2nd Year" <?php echo $selected_year_level === '2nd Year' ? 'selected' : ''; ?>>2nd Year</option>
                                            <option value="3rd Year" <?php echo $selected_year_level === '3rd Year' ? 'selected' : ''; ?>>3rd Year</option>
                                            <option value="4th Year" <?php echo $selected_year_level === '4th Year' ? 'selected' : ''; ?>>4th Year</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="program_renewal" class="form-label fw-bold">Program <span class="text-danger">*</span></label>
                                        <select class="form-select" id="program_renewal" name="program" required>
                                            <option value="" <?php echo $selected_program === '' ? 'selected' : ''; ?> disabled>Select Program</option>
                                            <option value="BSIT" <?php echo $selected_program === 'BSIT' ? 'selected' : ''; ?>>BSIT</option>
                                            <option value="AB-THEO" <?php echo $selected_program === 'AB-THEO' ? 'selected' : ''; ?>>AB-THEO</option>
                                            <option value="BSED- ENGLISH" <?php echo $selected_program === 'BSED- ENGLISH' ? 'selected' : ''; ?>>BSED- ENGLISH</option>
                                            <option value="BSED-MATH" <?php echo $selected_program === 'BSED-MATH' ? 'selected' : ''; ?>>BSED-MATH</option>
                                            <option value="BEED" <?php echo $selected_program === 'BEED' ? 'selected' : ''; ?>>BEED</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label for="units_enrolled" class="form-label fw-bold">Number of Units Enrolled <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="units_enrolled" name="units_enrolled" value="<?php echo htmlspecialchars($_POST['units_enrolled'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="gwa" class="form-label fw-bold">GWA (per semester) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" min="1.00" max="100.00" class="form-control" id="gwa" name="gwa" value="<?php echo htmlspecialchars($_POST['gwa'] ?? ''); ?>" placeholder="00.00" required oninput="if(parseFloat(this.value) > 100) this.value = 100;">
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label for="gwa_file" class="form-label fw-bold">Upload Scanned Copy of GWA <span class="text-danger">*</span></label>
                                    <input type="file" class="form-control" id="gwa_file" name="gwa_document" accept=".pdf" required>
                                    <div class="form-text">Accepted file format: PDF only. <span class="text-danger">The attached picture should be scanned, not a picture attached to a Word document and converted to PDF.</span></div>
                                </div>
                            </div>
                            <?php else: ?>

                            <!-- New Applicant / Dynamic Form Section -->
                            <div id="new-applicant-section">
                                <h5 class="fw-bold text-primary mb-3">New Applicant Information</h5>
                                <div class="alert alert-info border-0 shadow-sm">
                                    <div class="fw-bold mb-1">Choose the correct path before filling out the form.</div>
                                    <small class="d-block">Incoming Student: not yet enrolled, so school ID and current academic details are not required.</small>
                                    <small class="d-block">Continuing Student: already enrolled, so complete all current school and academic fields.</small>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold">Student Status <span class="text-danger">*</span></label>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="border rounded-3 p-3 w-100 h-100 d-block">
                                                <input class="form-check-input me-2" type="radio" name="student_status" value="Incoming Student" <?php echo $selected_student_status === 'Incoming Student' ? 'checked' : ''; ?> onchange="toggleStudentStatusSections()" required>
                                                <span class="fw-bold d-block">Incoming Student</span>
                                                <small class="text-muted">Apply as a new applicant without school ID, program, year level, units, or GWA.</small>
                                            </label>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="border rounded-3 p-3 w-100 h-100 d-block">
                                                <input class="form-check-input me-2" type="radio" name="student_status" value="Continuing Student" <?php echo $selected_student_status === 'Continuing Student' ? 'checked' : ''; ?> onchange="toggleStudentStatusSections()" required>
                                                <span class="fw-bold d-block">Continuing Student</span>
                                                <small class="text-muted">Apply as a new applicant and complete all current academic requirements.</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <h5 class="fw-bold text-primary mb-3">Personal Information</h5>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Last Name</label>
                                        <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($user_details['last_name'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">First Name</label>
                                        <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($user_details['first_name'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Middle Name</label>
                                        <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($user_details['middle_name'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" class="form-control" name="contact_number" value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ($student_data['phone'] ?? '')); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Birthdate</label>
                                        <input type="date" class="form-control" name="birthdate" value="<?php echo htmlspecialchars($_POST['birthdate'] ?? ($student_data['date_of_birth'] ?? '')); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Age</label>
                                        <input type="number" class="form-control" name="fields[age]" value="<?php echo htmlspecialchars($_POST['fields']['age'] ?? ($student_data['age'] ?? '')); ?>">
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Birthplace <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="fields[birthplace]" value="<?php echo htmlspecialchars($_POST['fields']['birthplace'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Permanent Address <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="fields[permanent_address]" value="<?php echo htmlspecialchars($_POST['fields']['permanent_address'] ?? ''); ?>" required>
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Current Address <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="fields[current_address]" value="<?php echo htmlspecialchars($_POST['fields']['current_address'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Tribe (if applicable)</label>
                                        <input type="text" class="form-control" name="fields[tribe]" value="<?php echo htmlspecialchars($_POST['fields']['tribe'] ?? ''); ?>">
                                    </div>
                                </div>

                                <h5 class="fw-bold text-primary mb-3 mt-4">Family Background</h5>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Mother's Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="fields[mother_name]" value="<?php echo htmlspecialchars($_POST['fields']['mother_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Occupation</label>
                                        <input type="text" class="form-control" name="fields[mother_occupation]" value="<?php echo htmlspecialchars($_POST['fields']['mother_occupation'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" class="form-control" name="fields[mother_contact]" value="<?php echo htmlspecialchars($_POST['fields']['mother_contact'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Father's Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="fields[father_name]" value="<?php echo htmlspecialchars($_POST['fields']['father_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Occupation</label>
                                        <input type="text" class="form-control" name="fields[father_occupation]" value="<?php echo htmlspecialchars($_POST['fields']['father_occupation'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" class="form-control" name="fields[father_contact]" value="<?php echo htmlspecialchars($_POST['fields']['father_contact'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Parents' Monthly Income <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="fields[parents_income]" value="<?php echo htmlspecialchars($_POST['fields']['parents_income'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Working Student? <span class="text-danger">*</span></label>
                                        <select class="form-select" name="fields[is_working_student]" id="is_working_student" required onchange="toggleConditionalInputs()">
                                            <option value="No" <?php echo ($_POST['fields']['is_working_student'] ?? 'No') === 'No' ? 'selected' : ''; ?>>No</option>
                                            <option value="Yes" <?php echo ($_POST['fields']['is_working_student'] ?? '') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" id="label_working_student_job">If Yes, specify job</label>
                                        <input type="text" class="form-control" name="fields[working_student_job]" id="working_student_job" value="<?php echo htmlspecialchars($_POST['fields']['working_student_job'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">PWD? <span class="text-danger">*</span></label>
                                        <select class="form-select" name="fields[is_pwd]" id="is_pwd" required onchange="toggleConditionalInputs()">
                                            <option value="No" <?php echo ($_POST['fields']['is_pwd'] ?? 'No') === 'No' ? 'selected' : ''; ?>>No</option>
                                            <option value="Yes" <?php echo ($_POST['fields']['is_pwd'] ?? '') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label" id="label_pwd_details">If Yes, specify disability</label>
                                        <input type="text" class="form-control" name="fields[pwd_details]" id="pwd_details" value="<?php echo htmlspecialchars($_POST['fields']['pwd_details'] ?? ''); ?>">
                                    </div>
                                </div>

                                <h5 class="fw-bold text-primary mb-3 mt-4">Previous School Information</h5>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Junior High School <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="fields[jhs_school]" value="<?php echo htmlspecialchars($_POST['fields']['jhs_school'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Year Graduated <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" name="jhs_year_start" placeholder="Start" value="<?php echo htmlspecialchars($_POST['jhs_year_start'] ?? ''); ?>" required>
                                            <span class="input-group-text">-</span>
                                            <input type="number" class="form-control" name="jhs_year_end" placeholder="End" value="<?php echo htmlspecialchars($_POST['jhs_year_end'] ?? ''); ?>" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Senior High School <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="fields[shs_school]" value="<?php echo htmlspecialchars($_POST['fields']['shs_school'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Year Graduated <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" name="shs_year_start" placeholder="Start" value="<?php echo htmlspecialchars($_POST['shs_year_start'] ?? ''); ?>" required>
                                            <span class="input-group-text">-</span>
                                            <input type="number" class="form-control" name="shs_year_end" placeholder="End" value="<?php echo htmlspecialchars($_POST['shs_year_end'] ?? ''); ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div id="continuing-student-fields" data-student-visibility="Continuing Student">
                                    <h5 class="fw-bold text-primary mb-3 mt-4">Current Enrollment Details</h5>
                                    <div class="alert alert-warning border-0">
                                        <small>Continuing students must complete their current school, school ID, program, year level, units, and GWA.</small>
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label">School ID Number <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="school_id" value="<?php echo htmlspecialchars($selected_school_id); ?>" data-required-for="Continuing Student">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Year Level <span class="text-danger">*</span></label>
                                            <select class="form-select" name="year_level" data-required-for="Continuing Student">
                                                <option value="">Select Year</option>
                                                <option value="1st Year" <?php echo $selected_year_level === '1st Year' ? 'selected' : ''; ?>>1st Year</option>
                                                <option value="2nd Year" <?php echo $selected_year_level === '2nd Year' ? 'selected' : ''; ?>>2nd Year</option>
                                                <option value="3rd Year" <?php echo $selected_year_level === '3rd Year' ? 'selected' : ''; ?>>3rd Year</option>
                                                <option value="4th Year" <?php echo $selected_year_level === '4th Year' ? 'selected' : ''; ?>>4th Year</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Program <span class="text-danger">*</span></label>
                                            <select class="form-select" name="program" data-required-for="Continuing Student">
                                                <option value="">Select Program</option>
                                                <option value="BSIT" <?php echo $selected_program === 'BSIT' ? 'selected' : ''; ?>>BSIT</option>
                                                <option value="AB-THEO" <?php echo $selected_program === 'AB-THEO' ? 'selected' : ''; ?>>AB-THEO</option>
                                                <option value="BSED- ENGLISH" <?php echo $selected_program === 'BSED- ENGLISH' ? 'selected' : ''; ?>>BSED- ENGLISH</option>
                                                <option value="BSED-MATH" <?php echo $selected_program === 'BSED-MATH' ? 'selected' : ''; ?>>BSED-MATH</option>
                                                <option value="BEED" <?php echo $selected_program === 'BEED' ? 'selected' : ''; ?>>BEED</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-8">
                                            <label class="form-label">Current School <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="fields[current_school]" value="<?php echo htmlspecialchars($_POST['fields']['current_school'] ?? ''); ?>" data-required-for="Continuing Student">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Current Year Level <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="fields[current_year_level]" value="<?php echo htmlspecialchars($_POST['fields']['current_year_level'] ?? ''); ?>" data-required-for="Continuing Student">
                                        </div>
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Units Enrolled <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" name="units_enrolled" value="<?php echo htmlspecialchars($_POST['units_enrolled'] ?? ''); ?>" data-required-for="Continuing Student">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">GWA (per semester) <span class="text-danger">*</span></label>
                                            <input type="number" step="0.01" min="1.00" max="100.00" class="form-control" name="gwa" value="<?php echo htmlspecialchars($_POST['gwa'] ?? ''); ?>" placeholder="00.00" oninput="if(parseFloat(this.value) > 100) this.value = 100;" data-required-for="Continuing Student">
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">Upload Scanned Copy of GWA (PDF) <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="file" class="form-control" id="gwa_document" name="gwa_document" accept=".pdf" data-required-for="Continuing Student">
                                            <button class="btn btn-outline-danger" type="button" id="remove_gwa_btn" style="display: none;" title="Remove file"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                </div>

                                <h5 class="fw-bold text-primary mb-3 mt-4">Requirements Upload</h5>
                                <div class="alert alert-info">
                                    <small class="text-justify">
                                        <strong>Required Documents:</strong><br>
                                        1. Certificate of Registration / Enrollment Form<br>
                                        2. Report Card / Copy of Grades<br>
                                        3. Certificate of Good Moral Character<br>
                                        4. Income Tax Return of Parents or Certificate of Indigency<br>
                                        5. Birth Certificate (PSA)<br>
                                        <em>Please upload all documents as PDF files.</em>
                                    <br><em class="text-danger">The attached picture should be scanned, not a picture attached to a Word document and converted to PDF.</em></small>
                                </div>
                                <div class="mb-4">
                                    <label for="documents_new" class="form-label fw-bold">Upload Documents</label>
                                    <div class="file-upload-wrapper">
                                        <input type="file" id="documents_new" name="supporting_documents[]" multiple class="file-upload-input" accept=".pdf" required>
                                        <div class="file-upload-content">
                                            <i class="bi bi-cloud-arrow-up-fill"></i>
                                            <p><strong>Drag & drop files here</strong> or click to browse.</p>
                                            <small class="text-muted">PDF files only. Max <?php echo htmlspecialchars(appUploadMaxLabel()); ?> per file. Max 5 files.</small>
                                        </div>
                                    </div>
                                    <div id="file-list-new" class="mt-3"></div>
                                </div>

                                <h5 class="fw-bold text-primary mb-3 mt-4">Application Questions</h5>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Why do you deserve this scholarship? <span class="text-danger">*</span></label>
                                    <textarea class="form-control" name="fields[essay_motivation]" rows="5" required placeholder="Write a short essay..."></textarea>
                                </div>

                                <?php 
                                // Define fields that are already hardcoded in the form to avoid duplication
                                $excluded_fields = [
                                    'essay_motivation', 
                                    'birthplace', 'age', 'permanent_address', 'current_address', 'tribe',
                                    'mother_name', 'mother_occupation', 'mother_contact',
                                    'father_name', 'father_occupation', 'father_contact',
                                    'parents_income', 'is_working_student', 'working_student_job',
                                    'is_pwd', 'pwd_details',
                                    'jhs_school', 'shs_school', 'current_school', 'current_year_level',
                                    'jhs_year', 'shs_year', 'contact_number', 'birthdate', 'program', 'year_level',
                                    'units_enrolled', 'gwa', 'student_status', 'school_id'
                                ];
                                $display_fields = array_filter($form_fields, function($f) use ($excluded_fields) {
                                    return !in_array($f['field_name'], $excluded_fields);
                                });
                                ?>
                                <?php if (!empty($display_fields)): ?>
                                    <h5 class="fw-bold text-primary mb-3 mt-4">Additional Questions</h5>
                                    <?php foreach ($display_fields as $field): ?>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                <?php echo htmlspecialchars($field['field_label']); ?>
                                                <?php if ($field['is_required']): ?> <span class="text-danger">*</span><?php endif; ?>
                                            </label>
                                            
                                            <?php if ($field['field_type'] === 'textarea'): ?>
                                                <textarea class="form-control" name="fields[<?php echo $field['field_name']; ?>]" rows="4" <?php echo $field['is_required'] ? 'required' : ''; ?>></textarea>
                                            
                                            <?php elseif ($field['field_type'] === 'select'): ?>
                                                <select class="form-select" name="fields[<?php echo $field['field_name']; ?>]" <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                    <option value="">Select an option</option>
                                                    <?php foreach (explode(',', $field['options'] ?? '') as $opt): $opt = trim($opt); if($opt === '') continue; ?>
                                                        <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                                    <?php endforeach; ?>
                                                </select>

                                            <?php elseif ($field['field_type'] === 'radio'): ?>
                                                <?php foreach (explode(',', $field['options'] ?? '') as $opt): $opt = trim($opt); if($opt === '') continue; ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="fields[<?php echo $field['field_name']; ?>]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                        <label class="form-check-label"><?php echo htmlspecialchars($opt); ?></label>
                                                    </div>
                                                <?php endforeach; ?>

                                            <?php elseif ($field['field_type'] === 'checkbox'): ?>
                                                <?php foreach (explode(',', $field['options'] ?? '') as $opt): $opt = trim($opt); if($opt === '') continue; ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="fields[<?php echo $field['field_name']; ?>][]" value="<?php echo htmlspecialchars($opt); ?>">
                                                        <label class="form-check-label"><?php echo htmlspecialchars($opt); ?></label>
                                                    </div>
                                                <?php endforeach; ?>

                                            <?php elseif ($field['field_type'] !== 'file'): // Standard inputs ?>
                                                <input type="<?php echo htmlspecialchars($field['field_type']); ?>" class="form-control" name="fields[<?php echo $field['field_name']; ?>]" <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" value="" id="confirmationCheck" required>
                                <label class="form-check-label" for="confirmationCheck">
                                    I confirm that all the information and documents provided are accurate and true.
                                </label>
                            </div>
                            <div class="d-grid">
                                <button type="button" class="btn btn-primary btn-lg" id="trigger-privacy-modal">Submit Application</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php include $base_path . '/includes/footer.php'; ?>

    <!-- Privacy Notice Modal -->
    <div class="modal fade" id="privacyModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock-fill me-2"></i>Data Privacy Consent</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Please read and agree to the following terms to proceed:</p>
                    <div class="p-3 bg-light rounded border mb-4" style="text-align: justify; font-size: 0.9rem;">
                        I hereby authorize Davao Vision Colleges, Inc.  to collect, use, process, and store my personal and academic information, including supporting documents submitted for my scholarship application, in accordance with the Data Privacy Act of 2012 (RA 10173). I understand that my information will be used solely for the evaluation, processing, monitoring, and reporting of my scholarship application and may be shared with authorized offices and relevant government agencies (e.g., CHED, UniFAST, TES, TDP) as required by law. I confirm that the information I provided is true, correct, and complete.
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="privacyCheckbox">
                        <label class="form-check-label fw-bold" for="privacyCheckbox">
                            I agree to the Data Privacy Consent terms.
                        </label>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-primary fw-bold" id="confirmSubmitBtn" disabled>Submit Application</button>
                        <button type="button" class="btn btn-outline-secondary" id="cancelPrivacyBtn">I Disagree</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function toggleStudentStatusSections() {
        const selectedStatusInput = document.querySelector('input[name="student_status"]:checked');
        const selectedStatus = selectedStatusInput ? selectedStatusInput.value : '';

        document.querySelectorAll('[data-student-visibility]').forEach(section => {
            const shouldShow = selectedStatus !== '' && section.getAttribute('data-student-visibility') === selectedStatus;
            section.style.display = shouldShow ? '' : 'none';

            section.querySelectorAll('[data-required-for]').forEach(field => {
                const isRequired = shouldShow && field.getAttribute('data-required-for') === selectedStatus;
                field.disabled = !shouldShow;
                field.required = isRequired;
            });
        });
    }

    function toggleConditionalInputs() {
        // Working Student Logic
        const workingSelect = document.getElementById('is_working_student');
        const jobInput = document.getElementById('working_student_job');
        const jobLabel = document.getElementById('label_working_student_job');
        
        if (workingSelect && jobInput) {
            const isYes = workingSelect.value === 'Yes';
            jobInput.required = isYes;
            jobInput.disabled = !isYes; 
            if (!isYes) jobInput.value = '';
            if (jobLabel) jobLabel.innerHTML = isYes ? 'If Yes, specify job <span class="text-danger">*</span>' : 'If Yes, specify job';
        }

        // PWD Logic
        const pwdSelect = document.getElementById('is_pwd');
        const pwdInput = document.getElementById('pwd_details');
        const pwdLabel = document.getElementById('label_pwd_details');

        if (pwdSelect && pwdInput) {
            const isYes = pwdSelect.value === 'Yes';
            pwdInput.required = isYes;
            pwdInput.disabled = !isYes;
            if (!isYes) pwdInput.value = '';
            if (pwdLabel) pwdLabel.innerHTML = isYes ? 'If Yes, specify disability <span class="text-danger">*</span>' : 'If Yes, specify disability';
        }
    }

    // Initialize on load
    document.addEventListener('DOMContentLoaded', function() {
        toggleStudentStatusSections();
        // Initialize conditional inputs if they exist (New Applicant flow)
        toggleConditionalInputs();

        // GWA File Remove Logic
        const gwaInput = document.getElementById('gwa_document');
        const removeGwaBtn = document.getElementById('remove_gwa_btn');
        if (gwaInput && removeGwaBtn) {
            gwaInput.addEventListener('change', function() {
                removeGwaBtn.style.display = this.files.length > 0 ? 'block' : 'none';
            });
            removeGwaBtn.addEventListener('click', function() {
                gwaInput.value = ''; // Clear the input
                removeGwaBtn.style.display = 'none';
            });
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        const triggerBtn = document.getElementById('trigger-privacy-modal');
        
        // Initialize modal only if element exists
        const privacyModalEl = document.getElementById('privacyModal');
        let privacyModal;
        if (privacyModalEl) {
            privacyModal = new bootstrap.Modal(privacyModalEl);
        }

        const privacyCheckbox = document.getElementById('privacyCheckbox');
        const confirmSubmitBtn = document.getElementById('confirmSubmitBtn');
        const cancelPrivacyBtn = document.getElementById('cancelPrivacyBtn');

        if (form && triggerBtn && privacyModal) {
            triggerBtn.addEventListener('click', function() {
                if (form.checkValidity()) {
                    if(privacyCheckbox) privacyCheckbox.checked = false;
                    if(confirmSubmitBtn) confirmSubmitBtn.disabled = true;
                    privacyModal.show();
                } else {
                    form.reportValidity();
                    form.classList.add('was-validated');
                }
            });

            if (privacyCheckbox && confirmSubmitBtn) {
                privacyCheckbox.addEventListener('change', function() {
                    confirmSubmitBtn.disabled = !this.checked;
                });
            }

            confirmSubmitBtn.addEventListener('click', function() {
                confirmSubmitBtn.disabled = true;
                cancelPrivacyBtn.disabled = true;
                confirmSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Submitting...';
                form.submit();
            });

            cancelPrivacyBtn.addEventListener('click', function() {
                alert("You cannot proceed with the application if you do not agree to the Data Privacy terms.");
                privacyModal.hide();
            });
        }
    });
    </script>
    <script>
    // JavaScript for file upload UI
    document.addEventListener('DOMContentLoaded', function() {
        const fileInput = document.getElementById('documents_new');
        const fileListDiv = document.getElementById('file-list-new');
        const dt = new DataTransfer();
        const MAX_FILES = 5;

        if (fileInput) {
            fileInput.addEventListener('change', function() {
                let limitExceeded = false;
                for (let i = 0; i < this.files.length; i++) {
                    if (dt.items.length >= MAX_FILES) {
                        limitExceeded = true;
                        continue;
                    }
                    dt.items.add(this.files[i]);
                }
                if (limitExceeded) {
                    alert("You can only upload a maximum of " + MAX_FILES + " files.");
                }
                this.files = dt.files; // Update input with accumulated files
                renderFileList();
            });
        }

        function renderFileList() {
            fileListDiv.innerHTML = '';
            if (dt.files.length > 0) {
                const list = document.createElement('ul');
                list.className = 'list-group';
                
                for (let i = 0; i < dt.files.length; i++) {
                    const file = dt.files[i];
                    const listItem = document.createElement('li');
                    listItem.className = 'list-group-item list-group-item-light d-flex justify-content-between align-items-center';
                    
                    const fileInfo = document.createElement('div');
                    fileInfo.innerHTML = `<strong>${file.name}</strong> <span class="text-muted ms-2">(${(file.size / 1024).toFixed(2)} KB)</span>`;
                    
                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'btn btn-sm btn-outline-danger ms-3';
                    removeBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
                    removeBtn.type = 'button';
                    removeBtn.onclick = function() {
                        dt.items.remove(i);
                        fileInput.files = dt.files;
                        renderFileList();
                    };

                    listItem.appendChild(fileInfo);
                    listItem.appendChild(removeBtn);
                    list.appendChild(listItem);
                }   
                fileListDiv.appendChild(list);
            }
        }
    });
    </script>
</body>
</html>
