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

checkSessionTimeout();

if (!isAdmin()) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
        $perms = $_SESSION['permissions'] ?? [];
        if (!in_array('announcements.php', $perms)) {
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

// Include PHPMailer for email blast feature
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require $base_path . '/vendor/autoload.php';

// --- Database Migration: Ensure announcements table exists ---
try {
    dbEnsureAnnouncementsSchema($pdo);
} catch (PDOException $e) {
    // Silently fail if table exists or other error, log in production
    error_log("Announcement table creation check failed: " . $e->getMessage());
}

$action = $_GET['action'] ?? 'list';
$announcement_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

$errors = [];
$success = '';

// --- Handle POST Requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid request. Please try again.";
    } else {
        $post_action = $_POST['action'] ?? '';

        try {
            if ($post_action === 'save_announcement') {
                $id = filter_input(INPUT_POST, 'announcement_id', FILTER_SANITIZE_NUMBER_INT);
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                if (empty($title) || empty($content)) {
                    $errors[] = "Title and Content are required.";
                } else {
                    if ($id) { // Update
                        $sql = "UPDATE announcements SET title = ?, content = ?, is_active = ? WHERE id = ?";
                        $params = [$title, $content, $is_active, $id];
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        flashMessage("Announcement updated successfully!");
                    } else { // Create
                        $id = dbExecuteInsert(
                            $pdo,
                            "INSERT INTO announcements (title, content, is_active) VALUES (?, ?, ?)",
                            [$title, $content, $is_active]
                        );
                        flashMessage("Announcement created successfully!");
                    }

                    // Handle Multiple File Uploads
                    if (!empty($_FILES['announcement_images']['name'][0])) {
                        $files = $_FILES['announcement_images'];
                        $count = count($files['name']);
                        
                        $stmt_att = $pdo->prepare("INSERT INTO announcement_attachments (announcement_id, file_path, file_name) VALUES (?, ?, ?)");
                        
                        for ($i = 0; $i < $count; $i++) {
                            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                                $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'])) {
                                    $upload_result = storeUploadedFile(
                                        $pdo,
                                        [
                                            'name' => $files['name'][$i],
                                            'type' => $files['type'][$i],
                                            'tmp_name' => $files['tmp_name'][$i],
                                            'error' => $files['error'][$i],
                                            'size' => $files['size'][$i],
                                        ],
                                        'announcements',
                                        'att_',
                                        [
                                            'image/jpeg',
                                            'image/png',
                                            'image/gif',
                                            'image/webp',
                                            'application/pdf',
                                            'application/msword',
                                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                        ],
                                        appUploadMaxBytes(),
                                        $base_path
                                    );
                                    if ($upload_result['success']) {
                                        $stmt_att->execute([$id, $upload_result['path'], $files['name'][$i]]);
                                    }
                                }
                            }
                        }
                    }

                    header("Location: announcements.php");
                    exit();
                }
            } elseif ($post_action === 'delete_attachment') {
                $att_id = filter_input(INPUT_POST, 'attachment_id', FILTER_SANITIZE_NUMBER_INT);
                $ann_id = filter_input(INPUT_POST, 'announcement_id', FILTER_SANITIZE_NUMBER_INT);
                
                if ($att_id) {
                    $stmt = $pdo->prepare("SELECT file_path FROM announcement_attachments WHERE id = ?");
                    $stmt->execute([$att_id]);
                    $file = $stmt->fetchColumn();
                    
                    deleteStoredFileByPath($pdo, $file, $base_path);
                    
                    $pdo->prepare("DELETE FROM announcement_attachments WHERE id = ?")->execute([$att_id]);
                    flashMessage("Attachment removed.");
                }
                header("Location: announcements.php?action=edit&id=" . $ann_id);
                exit();
            } elseif ($post_action === 'delete_announcement') {
                $id = filter_input(INPUT_POST, 'announcement_id', FILTER_SANITIZE_NUMBER_INT);
                if ($id) {
                    $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
                    $stmt->execute([$id]);
                    flashMessage("Announcement deleted successfully.");
                    header("Location: announcements.php");
                    exit();
                }
            }
            elseif ($post_action === 'send_email_blast') {
                $recipient_group = $_POST['recipient_group'];
                $scholarship_ids = $_POST['scholarship_ids'] ?? [];
                $applicant_type = $_POST['applicant_type'] ?? 'all';
                $subject = trim($_POST['subject']);
                $message = trim($_POST['message']);

                if (empty($subject) || empty($message)) {
                    $errors[] = "Subject and Message are required.";
                } else {
                    $recipients = [];
                    
                    if ($recipient_group === 'all_students') {
                        $stmt = $pdo->query("
                            SELECT s.email, s.student_name, 
                                   a.scholarship_percentage as percentage,
                                   sch.amount as base_amount, sch.amount_type
                            FROM students s
                            LEFT JOIN applications a ON a.id = (SELECT id FROM applications a2 WHERE a2.student_id = s.id AND a2.status IN ('Approved', 'Active') ORDER BY a2.updated_at DESC LIMIT 1)
                            LEFT JOIN scholarships sch ON a.scholarship_id = sch.id
                            WHERE s.email IS NOT NULL AND s.email != ''
                        ");
                        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } elseif ($recipient_group === 'selected_scholarships' && !empty($scholarship_ids)) {
                        // Sanitize IDs
                        $ids = array_map('intval', $scholarship_ids);
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        
                        $sql = "SELECT DISTINCT s.email, s.student_name, a.scholarship_percentage as percentage,
                                       sch.amount as base_amount, sch.amount_type
                                FROM applications a 
                                JOIN students s ON a.student_id = s.id 
                                JOIN scholarships sch ON a.scholarship_id = sch.id
                                WHERE a.scholarship_id IN ($placeholders)";
                        
                        $params = $ids;
                        if ($applicant_type !== 'all') {
                            $sql .= " AND a.applicant_type = ?";
                            $params[] = $applicant_type;
                        }
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } elseif ($recipient_group === 'all_approved') {
                        // All students with Approved or Active status
                        $stmt = $pdo->query("
                            SELECT DISTINCT s.email, s.student_name, a.scholarship_percentage as percentage,
                                   sch.amount as base_amount, sch.amount_type
                            FROM applications a 
                            JOIN students s ON a.student_id = s.id 
                            JOIN scholarships sch ON a.scholarship_id = sch.id
                            WHERE a.status IN ('Approved', 'Active')
                        ");
                        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } elseif ($recipient_group === 'no_scholarship') {
                        // All students who do NOT have an Approved or Active application
                        $stmt = $pdo->query("
                            SELECT s.email, s.student_name, NULL as percentage, NULL as base_amount, NULL as amount_type
                            FROM students s 
                            WHERE s.id NOT IN (
                                SELECT DISTINCT student_id 
                                FROM applications 
                                WHERE status IN ('Approved', 'Active')
                            ) AND s.email IS NOT NULL AND s.email != ''
                        ");
                        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } elseif ($recipient_group === 'all_new') {
                        // All students with applicant_type = 'New'
                        $stmt = $pdo->query("
                            SELECT DISTINCT s.email, s.student_name, a.scholarship_percentage as percentage,
                                   sch.amount as base_amount, sch.amount_type
                            FROM applications a 
                            JOIN students s ON a.student_id = s.id 
                            JOIN scholarships sch ON a.scholarship_id = sch.id
                            WHERE a.applicant_type = 'New'
                        ");
                        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } elseif ($recipient_group === 'all_renewal') {
                        // All students with applicant_type = 'Renewal'
                        $stmt = $pdo->query("
                            SELECT DISTINCT s.email, s.student_name, a.scholarship_percentage as percentage,
                                   sch.amount as base_amount, sch.amount_type
                            FROM applications a 
                            JOIN students s ON a.student_id = s.id 
                            JOIN scholarships sch ON a.scholarship_id = sch.id
                            WHERE a.applicant_type = 'Renewal'
                        ");
                        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } elseif ($recipient_group === 'specific_students') {
                        $selected_student_ids = $_POST['student_ids'] ?? [];
                        if (!empty($selected_student_ids)) {
                            $ids = array_map('intval', $selected_student_ids);
                            $placeholders = implode(',', array_fill(0, count($ids), '?'));
                            $stmt = $pdo->prepare("
                                SELECT s.email, s.student_name, 
                                       a.scholarship_percentage as percentage,
                                       sch.amount as base_amount, sch.amount_type
                                FROM students s
                                LEFT JOIN applications a ON a.id = (SELECT id FROM applications a2 WHERE a2.student_id = s.id AND a2.status IN ('Approved', 'Active') ORDER BY a2.updated_at DESC LIMIT 1)
                                LEFT JOIN scholarships sch ON a.scholarship_id = sch.id
                                WHERE s.id IN ($placeholders) AND s.email IS NOT NULL AND s.email != ''
                            ");
                            $stmt->execute($ids);
                            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    } elseif ($recipient_group === 'by_percentage') {
                        $target_percentage = $_POST['target_percentage'] ?? '';
                        $scope = $_POST['percentage_scholarship_scope'] ?? 'all';
                        $perc_scholarship_ids = $_POST['percentage_scholarship_ids'] ?? [];

                        if ($target_percentage !== '') {
                            $sql = "SELECT DISTINCT s.email, s.student_name, a.scholarship_percentage as percentage,
                                           sch.amount as base_amount, sch.amount_type
                                    FROM applications a
                                    JOIN students s ON a.student_id = s.id
                                    JOIN scholarships sch ON a.scholarship_id = sch.id
                                    WHERE a.status IN ('Approved', 'Active') 
                                    AND a.scholarship_percentage = ?";
                            $params = [$target_percentage];

                            if ($scope === 'specific' && !empty($perc_scholarship_ids)) {
                                $ids = array_map('intval', $perc_scholarship_ids);
                                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                                $sql .= " AND a.scholarship_id IN ($placeholders)";
                                $params = array_merge($params, $ids);
                            }

                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($params);
                            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }

                    if (empty($recipients)) {
                        $errors[] = "No recipients found matching your criteria.";
                    } else {
                        $mail = new PHPMailer(true);
                        $sent_count = 0;
                        try {
                            $mail->isSMTP();
                            $mail->Host       = SMTP_HOST;
                            $mail->SMTPAuth   = true;
                            $mail->Username   = SMTP_USER;
                            $mail->Password   = SMTP_PASS;
                            $mail->SMTPSecure = SMTP_SECURE;
                            $mail->Port       = SMTP_PORT;
                            $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
                            $mail->setFrom(SMTP_USER, 'DVC Scholarship Hub');
                            $mail->isHTML(true);

                            // Handle File Attachment
                            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                                $mail->addAttachment($_FILES['attachment']['tmp_name'], $_FILES['attachment']['name']);
                            }

                            foreach ($recipients as $recipient) {
                                $mail->clearAddresses();
                                $mail->addAddress($recipient['email'], $recipient['student_name']);
                                $mail->Subject = $subject;
                                // Simple personalization
                                $body_content = str_replace('{name}', $recipient['student_name'], $message);
                                
                                // Percentage replacement
                                $perc_val = isset($recipient['percentage']) && $recipient['percentage'] !== null ? number_format($recipient['percentage'], 0) . '%' : 'N/A';
                                $body_content = str_replace('{percentage}', $perc_val, $body_content);
                                
                                // Amount replacement (Calculated based on percentage if Peso type)
                                $amount_val = 'N/A';
                                if (isset($recipient['base_amount']) && isset($recipient['percentage']) && is_numeric($recipient['base_amount']) && is_numeric($recipient['percentage'])) {
                                    if (($recipient['amount_type'] ?? 'Peso') === 'Peso') {
                                        $calculated_amount = $recipient['base_amount'] * ($recipient['percentage'] / 100);
                                        $amount_val = '₱' . number_format($calculated_amount, 2);
                                    }
                                }
                                $body_content = str_replace('{amount}', $amount_val, $body_content);

                                $mail->Body = nl2br($body_content);
                                $mail->send();
                                $sent_count++;
                            }
                            flashMessage("Email blast sent successfully to $sent_count recipients.");
                            header("Location: announcements.php?action=email_blast");
                            exit();
                        } catch (Exception $e) {
                            $errors[] = "Mailer Error: " . $mail->ErrorInfo;
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$page_title = 'Manage Announcements';
$csrf_token = generate_csrf_token();
include 'header.php';

displayFlashMessages();

if (!empty($errors)) {
    echo '<div class="alert alert-danger" data-aos="fade-up">' . implode('<br>', array_map('htmlspecialchars', $errors)) . '</div>';
}

$action = $_GET['action'] ?? 'list';

// --- Navigation Tabs ---
?>
<div class="mb-4" data-aos="fade-down">
    <ul class="nav nav-pills">
        <li class="nav-item">
            <a class="nav-link <?php echo ($action === 'list' || $action === 'add' || $action === 'edit') ? 'active' : ''; ?>" href="announcements.php">Website Announcements</a>
        </li>
        <?php if (isAdmin()): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo ($action === 'email_blast') ? 'active' : ''; ?>" href="announcements.php?action=email_blast">Email Blast</a>
        </li>
        <?php endif; ?>
    </ul>
</div>
<?php

if ($action === 'email_blast') {
    // Fetch active scholarships for the dropdown
    $scholarships = $pdo->query("SELECT id, name FROM scholarships WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    // Fetch all students for the specific student search
    $all_students_list = $pdo->query("SELECT id, student_name, school_id_number FROM students WHERE email IS NOT NULL AND email != '' ORDER BY student_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="page-header" data-aos="fade-down">
        <h1 class="fw-bold">Send Email Announcement</h1>
        <p class="text-muted">Send targeted emails to students or scholarship applicants.</p>
    </div>

    <form action="announcements.php" method="POST" enctype="multipart/form-data" data-aos="fade-up">
        <input type="hidden" name="action" value="send_email_blast">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <div class="row g-4">
            <!-- Left Column: Audience Selection -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 border-bottom-0">
                        <h5 class="card-title mb-0 fw-bold text-primary"><i class="bi bi-people-fill me-2"></i>Target Audience</h5>
                    </div>
                    <div class="card-body pt-0">
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-uppercase text-muted">Recipient Group</label>
                            <select name="recipient_group" id="recipient_group" class="form-select form-select-lg" required onchange="toggleRecipientFields()">
                                <option value="all_students">All Registered Students</option>
                                <option value="selected_scholarships">Specific Scholarship Applicants</option>
                                <option value="all_approved">All Approved Scholars</option>
                                <option value="no_scholarship">Students without Scholarship</option>
                                <option value="all_new">All New Applicants</option>
                                <option value="all_renewal">All Renewal Applicants</option>
                                <option value="specific_students">Specific Students</option>
                                <option value="by_percentage">By Scholarship Percentage</option>
                            </select>
                            <div class="form-text">Who should receive this email?</div>
                        </div>

                        <div id="applicant_type_div" style="display:none;" class="mb-3 animate-fade-in">
                            <label class="form-label fw-bold small text-uppercase text-muted">Applicant Type</label>
                            <select name="applicant_type" class="form-select">
                                <option value="all">All Applicants (New & Renewal)</option>
                                <option value="New">New Applicants Only</option>
                                <option value="Renewal">Renewal Applicants Only</option>
                            </select>
                        </div>

                        <div id="scholarships_div" style="display:none;" class="mb-3 animate-fade-in">
                            <label class="form-label fw-bold small text-uppercase text-muted">Select Scholarships</label>
                            <div class="card bg-light border-0">
                                <div class="card-body p-2" style="max-height: 300px; overflow-y: auto;">
                                    <?php if(empty($scholarships)): ?>
                                        <p class="text-muted small mb-0 text-center py-2">No active scholarships found.</p>
                                    <?php else: ?>
                                        <?php foreach ($scholarships as $s): ?>
                                            <div class="form-check p-2 rounded hover-bg-white">
                                                <input class="form-check-input" type="checkbox" name="scholarship_ids[]" value="<?php echo $s['id']; ?>" id="sch_<?php echo $s['id']; ?>">
                                                <label class="form-check-label w-100" for="sch_<?php echo $s['id']; ?>" style="cursor:pointer;">
                                                    <?php echo htmlspecialchars($s['name']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-text small">Select one or more programs.</div>
                        </div>

                        <!-- Specific Students Selection -->
                        <div id="specific_students_div" style="display:none;" class="mb-3 animate-fade-in">
                            <label class="form-label fw-bold small text-uppercase text-muted">Search & Select Students</label>
                            <input type="text" id="student_search" class="form-control mb-2" placeholder="Type to search student..." onkeyup="filterStudents()">
                            
                            <div class="card bg-light border-0">
                                <div class="card-body p-2" style="max-height: 300px; overflow-y: auto;" id="student_checklist">
                                    <?php if(empty($all_students_list)): ?>
                                        <p class="text-muted small mb-0 text-center py-2">No students found.</p>
                                    <?php else: ?>
                                        <?php foreach ($all_students_list as $st): ?>
                                            <div class="form-check p-2 rounded hover-bg-white student-item">
                                                <input class="form-check-input" type="checkbox" name="student_ids[]" value="<?php echo $st['id']; ?>" id="st_<?php echo $st['id']; ?>">
                                                <label class="form-check-label w-100" for="st_<?php echo $st['id']; ?>" style="cursor:pointer;">
                                                    <?php echo htmlspecialchars($st['student_name']); ?> <small class="text-muted">(<?php echo htmlspecialchars($st['school_id_number']); ?>)</small>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-text small">Check the students who should receive the email.</div>
                        </div>

                        <!-- By Percentage Selection -->
                        <div id="percentage_div" style="display:none;" class="mb-3 animate-fade-in">
                            <label class="form-label fw-bold small text-uppercase text-muted">Scholarship Scope</label>
                            <select name="percentage_scholarship_scope" id="percentage_scholarship_scope" class="form-select mb-2" onchange="togglePercentageScope()">
                                <option value="all">All Scholarships</option>
                                <option value="specific">Specific Scholarship</option>
                            </select>

                            <div id="percentage_specific_scholarship_div" style="display:none;" class="mb-2">
                                <div class="card bg-light border-0">
                                    <div class="card-body p-2" style="max-height: 200px; overflow-y: auto;">
                                        <?php foreach ($scholarships as $s): ?>
                                            <div class="form-check p-2 rounded hover-bg-white">
                                                <input class="form-check-input" type="checkbox" name="percentage_scholarship_ids[]" value="<?php echo $s['id']; ?>" id="ps_<?php echo $s['id']; ?>">
                                                <label class="form-check-label w-100" for="ps_<?php echo $s['id']; ?>" style="cursor:pointer;">
                                                    <?php echo htmlspecialchars($s['name']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <label class="form-label fw-bold small text-uppercase text-muted mt-2">Target Percentage</label>
                            <select name="target_percentage" class="form-select">
                                <?php for($i = 100; $i >= 0; $i -= 10): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?>%</option>
                                <?php endfor; ?>
                            </select>
                            <div class="form-text small">Send to students with this exact scholarship percentage.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Email Content -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 border-bottom-0">
                        <h5 class="card-title mb-0 fw-bold text-primary"><i class="bi bi-envelope-paper-fill me-2"></i>Compose Message</h5>
                    </div>
                    <div class="card-body pt-0">
                        <div class="mb-3">
                            <label for="subject" class="form-label fw-bold small text-uppercase text-muted">Subject Line</label>
                            <input type="text" class="form-control form-control-lg" id="subject" name="subject" required placeholder="e.g., Important Update: Scholarship Status">
                        </div>

                        <div class="mb-3">
                            <label for="message" class="form-label fw-bold small text-uppercase text-muted">Message Body</label>
                            <textarea class="form-control" id="message" name="message" rows="10" required placeholder="Type your announcement here..."></textarea>
                            <div class="d-flex justify-content-between mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>Tip: Use <code>{name}</code> for name, <code>{percentage}</code> for scholarship %, <code>{amount}</code> for calculated amount.
                                </small>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="attachment" class="form-label fw-bold small text-uppercase text-muted">Attachment <span class="fw-normal text-muted">(Optional)</span></label>
                            <div class="input-group">
                                <input type="file" class="form-control" id="attachment" name="attachment">
                                <label class="input-group-text" for="attachment"><i class="bi bi-paperclip"></i></label>
                            </div>
                            <div class="form-text">Supported formats: PDF, DOC, JPG, PNG.</div>
                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-end align-items-center gap-3">
                            <a href="announcements.php" class="btn btn-outline-secondary px-4">Cancel</a>
                            <button type="submit" class="btn btn-primary px-5 btn-lg" onclick="return confirm('Are you sure you want to send this email blast? This action cannot be undone.');">
                                <i class="bi bi-send-fill me-2"></i> Send Email Blast
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <style>
        .hover-bg-white:hover {
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .animate-fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>

    <script>
    function toggleRecipientFields() {
        const group = document.getElementById('recipient_group').value;
        const schDiv = document.getElementById('scholarships_div');
        const typeDiv = document.getElementById('applicant_type_div');
        const specificStDiv = document.getElementById('specific_students_div');
        const percDiv = document.getElementById('percentage_div');
        
        // Reset displays
        schDiv.style.display = 'none';
        typeDiv.style.display = 'none';
        specificStDiv.style.display = 'none';
        percDiv.style.display = 'none';

        if (group === 'selected_scholarships') {
            schDiv.style.display = 'block';
            typeDiv.style.display = 'block';
        } else if (group === 'specific_students') {
            specificStDiv.style.display = 'block';
        } else if (group === 'by_percentage') {
            percDiv.style.display = 'block';
        }
    }

    function filterStudents() {
        const input = document.getElementById('student_search');
        const filter = input.value.toLowerCase();
        const container = document.getElementById('student_checklist');
        const items = container.getElementsByClassName('student-item');

        for (let i = 0; i < items.length; i++) {
            const label = items[i].getElementsByTagName("label")[0];
            const txtValue = label.textContent || label.innerText;
            if (txtValue.toLowerCase().indexOf(filter) > -1) {
                items[i].style.display = "";
            } else {
                items[i].style.display = "none";
            }
        }
    }

    function togglePercentageScope() {
        const scope = document.getElementById('percentage_scholarship_scope').value;
        const div = document.getElementById('percentage_specific_scholarship_div');
        if (scope === 'specific') {
            div.style.display = 'block';
        } else {
            div.style.display = 'none';
        }
    }
    </script>
    <?php
} elseif ($action === 'edit' || $action === 'add') {
    if (!isAdmin()) die("Access Denied");
    $announcement = null;
    if ($action === 'edit' && $announcement_id) {
        $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
        $stmt->execute([$announcement_id]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch attachments
        $stmt_att = $pdo->prepare("SELECT * FROM announcement_attachments WHERE announcement_id = ?");
        $stmt_att->execute([$announcement_id]);
        $attachments = $stmt_att->fetchAll(PDO::FETCH_ASSOC);
    }
    ?>
    <div class="page-header" data-aos="fade-down">
        <h1 class="fw-bold"><?php echo $action === 'edit' ? 'Edit Announcement' : 'Create New Announcement'; ?></h1>
        <a href="announcements.php" class="btn btn-outline-secondary btn-sm mt-2"><i class="bi bi-arrow-left"></i> Back to List</a>
    </div>
    <div class="content-block" data-aos="fade-up">
        <form action="announcements.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_announcement">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="announcement_id" value="<?php echo htmlspecialchars($announcement['id'] ?? ''); ?>">
            
            <div class="mb-3">
                <label for="title" class="form-label">Title</label>
                <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($announcement['title'] ?? ''); ?>" required>
            </div>
            <div class="mb-3">
                <label for="announcement_images" class="form-label">Attachments (Images/Files)</label>
                <input type="file" class="form-control" id="announcement_images" name="announcement_images[]" multiple accept="image/*,.pdf,.doc,.docx">
                <div class="form-text">You can select multiple files.</div>
                
                <?php if (!empty($attachments)): ?>
                    <div class="row g-2 mt-3">
                        <?php foreach ($attachments as $att): ?>
                            <div class="col-auto position-relative">
                                <?php if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $att['file_name'] ?? '')): ?>
                                    <img src="<?php echo htmlspecialchars(storedFilePathToUrl($att['file_path'] ?? '')); ?>" class="rounded border" style="width: 100px; height: 100px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="rounded border d-flex align-items-center justify-content-center bg-light" style="width: 100px; height: 100px;">
                                        <i class="bi bi-file-earmark-text fs-3"></i>
                                    </div>
                                <?php endif; ?>
                                <button type="submit" form="delete-att-<?php echo $att['id']; ?>" class="btn btn-danger btn-sm position-absolute top-0 end-0 p-0 d-flex align-items-center justify-content-center rounded-circle" style="width: 20px; height: 20px; transform: translate(30%, -30%);" title="Remove"><i class="bi bi-x"></i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label for="content" class="form-label">Content</label>
                <textarea class="form-control" id="content" name="content" rows="8" required><?php echo htmlspecialchars($announcement['content'] ?? ''); ?></textarea>
            </div>
            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?php echo (isset($announcement['is_active']) && $announcement['is_active']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="is_active">Set as Active (Visible to Public)</label>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Save Announcement</button>
        </form>
        
        <?php if (!empty($attachments)): ?>
            <?php foreach ($attachments as $att): ?>
                <form id="delete-att-<?php echo $att['id']; ?>" action="announcements.php" method="POST" style="display:none;" onsubmit="return confirm('Remove this attachment?');">
                    <input type="hidden" name="action" value="delete_attachment">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="attachment_id" value="<?php echo $att['id']; ?>">
                    <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
} else { // List view
    $announcements = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="page-header d-flex justify-content-between align-items-center" data-aos="fade-down">
        <div>
            <h1 class="fw-bold">Announcements</h1>
            <p class="text-muted">Manage news and updates for the public website.</p>
        </div>
        <?php if (isAdmin()): ?>
        <a href="announcements.php?action=add" class="btn btn-primary"><i class="bi bi-plus-circle-fill me-2"></i>Create New</a>
        <?php endif; ?>
    </div>

    <div class="content-block" data-aos="fade-up" data-aos-delay="100">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>Content</th>
                        <th class="text-center">Status</th>
                        <th>Date Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($announcements)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No announcements found. <a href="announcements.php?action=add">Create one now</a>.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($announcements as $item): ?>
                            <tr>
                                <td>
                                    <span class="fw-bold"><?php echo htmlspecialchars($item['title']); ?></span>
                                </td>
                                <td class="text-muted" title="<?php echo htmlspecialchars($item['content']); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth($item['content'], 0, 80, "...")); ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($item['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(date("M d, Y", strtotime($item['created_at']))); ?></td>
                                <td class="text-end">
                                    <?php if (isAdmin()): ?>
                                    <a href="announcements.php?action=edit&id=<?php echo $item['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="bi bi-pencil-fill"></i>
                                    </a>
                                    <form action="announcements.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this announcement?');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="announcement_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                                            <i class="bi bi-trash-fill"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

include 'footer.php';
?>
