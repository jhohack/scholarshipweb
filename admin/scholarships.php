<?php
if (session_status() === PHP_SESSION_NONE) {
    // Keep admin and student logins separate by using a dedicated admin session.
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';
require_once $base_path . '/includes/auth.php';
require_once $base_path . '/includes/mailer.php';

// Include PHPMailer for email notifications
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require $base_path . '/vendor/autoload.php';

checkSessionTimeout();

// --- Database Setup: Automatic Table & Column Creation ---
dbEnsureFormsSchema($pdo);
dbEnsureExamSchema($pdo);
dbEnsureScholarshipColumns($pdo);
dbEnsureApplicationsSchema($pdo);

if (dbIsMysql($pdo)) {
    try {
        $pdo->exec("ALTER TABLE applications MODIFY COLUMN status VARCHAR(50) DEFAULT 'Pending'");
    } catch (PDOException $e) {
        // Already migrated or using a flexible type.
    }

    try {
        $pdo->exec("ALTER TABLE exam_questions MODIFY COLUMN question_type VARCHAR(50) NOT NULL");
    } catch (PDOException $e) {
        // Already migrated or using a flexible type.
    }
}

// Check if the user is an admin
if (!isAdmin()) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
        $perms = $_SESSION['permissions'] ?? [];
        if (!in_array('scholarships.php', $perms)) {
            header("Location: dashboard.php");
            exit();
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            die("Action Restricted: Staff accounts are View Only.");
        }
    } else {
        header("Location: " . (isset($_SESSION['role']) && $_SESSION['role'] === 'student' ? "../student/dashboard.php" : "login.php"));
        exit();
    }
}

$action = $_GET['action'] ?? 'list';
$scholarship_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

$errors = [];
$success = $_GET['success'] ?? '';

// --- Handle POST Requests for all actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    // Validate CSRF token before any other action
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid request. Please try again.";
    } else {
        try {
            // --- Add or Edit Scholarship ---
            if ($post_action === 'save_scholarship') {
                $id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $benefits = trim($_POST['benefits']);
                $requirements = trim($_POST['requirements']);
                $application_requirements = trim($_POST['application_requirements']);
                $amount = filter_var($_POST['amount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                $amount_type = $_POST['amount_type'] ?? 'Peso';
                $deadline = trim($_POST['deadline']);
                $available_slots = filter_input(INPUT_POST, 'available_slots', FILTER_SANITIZE_NUMBER_INT);
                $category = trim($_POST['category']);
                $accepting_new = isset($_POST['accepting_new_applicants']) ? 1 : 0;
                $accepting_renewal = isset($_POST['accepting_renewal_applicants']) ? 1 : 0;
                $end_of_term = !empty($_POST['end_of_term']) ? trim($_POST['end_of_term']) : null;

                if ($amount_type === 'None') {
                    $amount = 0;
                }

                if (empty($name) || empty($description) || ($amount_type !== 'None' && empty($amount)) || empty($deadline)) {
                    $errors[] = "Name, Description, Amount, and Deadline are required fields.";
                } else {
                    if ($id) { // Update existing
                        $stmt = $pdo->prepare("UPDATE scholarships SET name=?, description=?, benefits=?, requirements=?, application_requirements=?, amount=?, amount_type=?, deadline=?, available_slots=?, category=?, accepting_new_applicants=?, accepting_renewal_applicants=?, end_of_term=? WHERE id=?");
                        $stmt->execute([$name, $description, $benefits, $requirements, $application_requirements, $amount, $amount_type, $deadline, $available_slots, $category, $accepting_new, $accepting_renewal, $end_of_term, $id]);
                        $success = "Scholarship updated successfully!";
                    } else { // Insert new
                        // Default status inactive, requires_exam 0 (will be decided in next step)
                        $new_id = dbExecuteInsert(
                            $pdo,
                            "INSERT INTO scholarships (name, description, benefits, requirements, application_requirements, amount, amount_type, deadline, available_slots, category, status, accepting_new_applicants, accepting_renewal_applicants, end_of_term) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'inactive', ?, ?, ?)",
                            [$name, $description, $benefits, $requirements, $application_requirements, $amount, $amount_type, $deadline, $available_slots, $category, $accepting_new, $accepting_renewal, $end_of_term]
                        );
                        $success = "Scholarship created successfully!";
                        header("Location: scholarships.php?action=list&prompt_exam=" . $new_id . "&success=" . urlencode($success));
                        exit();
                    }
                    $action = 'list'; // Redirect to list view after successful save
                }
            }
            // --- Archive/Restore Scholarship ---
            elseif ($post_action === 'archive_scholarship' || $post_action === 'restore_scholarship') {
                $id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
                if ($id) {
                    $new_status = ($post_action === 'archive_scholarship') ? 'archived' : 'active';
                    $stmt = $pdo->prepare("UPDATE scholarships SET status = ? WHERE id = ?");
                    $stmt->execute([$new_status, $id]);
                    $success = "Scholarship has been " . rtrim($new_status, 'd') . "ed.";
                }
                $action = 'list';
            }
            // --- Publish Without Exam ---
            elseif ($post_action === 'publish_no_exam') {
                $id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE scholarships SET status = 'active', requires_exam = 0 WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Scholarship published successfully (No Exam).";
                    header("Location: scholarships.php?success=" . urlencode($success));
                    exit();
                }
            }
            // --- Expire / End Term (Reset for Renewal) ---
            elseif ($post_action === 'expire_scholarship') {
                $id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
                if ($id) {
                    // 0. Fetch students to notify BEFORE updating
                    $notify_stmt = $pdo->prepare("
                        SELECT u.email, u.first_name, u.last_name, s.name as scholarship_name
                        FROM applications a
                        JOIN students st ON a.student_id = st.id
                        JOIN users u ON st.user_id = u.id
                        JOIN scholarships s ON a.scholarship_id = s.id
                        WHERE a.scholarship_id = ? AND a.status = 'Active'
                    ");
                    $notify_stmt->execute([$id]);
                    $students_to_notify = $notify_stmt->fetchAll(PDO::FETCH_ASSOC);

                    // 1. Set Active students to 'For Renewal' so they know they need to re-apply
                    $stmt = $pdo->prepare("UPDATE applications SET status = 'For Renewal', updated_at = CURRENT_TIMESTAMP WHERE scholarship_id = ? AND status = 'Active'");
                    $stmt->execute([$id]);
                    $active_count = $stmt->rowCount();

                    // 2. Reject Pending applications (New Applicants) as the term has ended
                    $stmt = $pdo->prepare("UPDATE applications SET status = 'Rejected', remarks = 'Scholarship term has ended. Please re-apply for the new term.', updated_at = CURRENT_TIMESTAMP WHERE scholarship_id = ? AND status IN ('Pending', 'Pending Exam', 'Under Review')");
                    $stmt->execute([$id]);
                    $pending_count = $stmt->rowCount();

                    // 3. Send Emails
                    if (!empty($students_to_notify)) {
                        $mail = new PHPMailer(true);
                        try {
                            configureSmtpMailer($mail, 'DVC Scholarship Hub');
                            $mail->isHTML(true);
                            $mail->Subject = 'Action Required: Scholarship Renewal';

                            foreach ($students_to_notify as $student) {
                                $mail->clearAddresses();
                                $mail->addAddress($student['email']);
                                $full_name = $student['first_name'] . ' ' . $student['last_name'];
                                $renew_link = BASE_URL . "/public/apply.php?id=" . $id;
                                $mail->Body = "Hello {$full_name},<br><br>The term for your scholarship <strong>{$student['scholarship_name']}</strong> has ended.<br>Your status has been updated to <strong>For Renewal</strong>.<br><br>Please log in to your dashboard and submit a renewal application if you wish to continue receiving this scholarship.<br><br><a href='{$renew_link}' style='background-color: #0d6efd; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Renew Now</a><br><br>Or visit your dashboard: <a href='" . BASE_URL . "/student/dashboard.php'>Go to Dashboard</a><br><br>Sincerely,<br>The DVC Scholarship Hub Team";
                                $mail->send();
                            }
                        } catch (\Throwable $e) {
                            error_log("Mail error during bulk renewal: " . ($mail->ErrorInfo ?: $e->getMessage()));
                        }
                    }

                    $success = "Scholarship term ended. {$active_count} active scholars set to 'For Renewal' and notified. {$pending_count} pending applications rejected.";
                    $action = 'list';
                }
            }
            // --- Publish Scholarship (After Exam Creation) ---
            elseif ($post_action === 'publish_scholarship_with_score') {
                $id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
                $passing_score = filter_input(INPUT_POST, 'passing_score', FILTER_SANITIZE_NUMBER_INT);
                $exam_duration = filter_input(INPUT_POST, 'exam_duration', FILTER_SANITIZE_NUMBER_INT);
                
                if ($id && $passing_score) {
                    // Update status to active AND set the passing score
                    $stmt = $pdo->prepare("UPDATE scholarships SET status = 'active', passing_score = ?, exam_duration = ?, requires_exam = 1 WHERE id = ?");
                    $stmt->execute([$passing_score, $exam_duration, $id]);
                    
                    $success = "Scholarship published with a passing score of {$passing_score}.";
                    $redirect = $_POST['redirect_action'] ?? '';
                    header("Location: scholarships.php?action=manage_exam&id=" . $id . "&success=" . urlencode($success));
                    exit();
                }
            }
            // --- Hard Delete Scholarship ---
            elseif ($post_action === 'delete_scholarship_permanently') {
                $id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
                if ($id) {
                    // Note: This will cascade delete related form fields, applications, etc. if foreign keys are set up correctly.
                    $stmt = $pdo->prepare("DELETE FROM scholarships WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Scholarship has been permanently deleted.";
                }
                $action = 'list';
            }
            // --- Manage Form Fields ---
            elseif ($post_action === 'add_field') {
                $form_id = filter_input(INPUT_POST, 'form_id', FILTER_SANITIZE_NUMBER_INT);
                $scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT) ?: $scholarship_id;
                $label = trim($_POST['field_label']);
                $type = trim($_POST['field_type']);
                $is_required = isset($_POST['is_required']) ? 1 : 0;
                $field_name = strtolower(str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9\s]/', '', $label)));
                $options = trim($_POST['field_options'] ?? '');

                if (!empty($label) && !empty($type) && !empty($field_name)) {
                    $stmt = $pdo->prepare("INSERT INTO form_fields (form_id, field_label, field_name, field_type, is_required, options) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$form_id, $label, $field_name, $type, $is_required, $options]);
                    $success = "Form field added successfully.";
                    header("Location: scholarships.php?action=manage_form&id=" . $scholarship_id . "&success=" . urlencode($success));
                    exit();
                } else {
                    $errors[] = "Field Label and Type are required.";
                }
            } elseif ($post_action === 'delete_field') {
                $form_id = filter_input(INPUT_POST, 'form_id', FILTER_SANITIZE_NUMBER_INT);
                $field_id = filter_input(INPUT_POST, 'field_id', FILTER_SANITIZE_NUMBER_INT);
                $scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT) ?: $scholarship_id;
                if ($field_id) {
                    $stmt = $pdo->prepare("DELETE FROM form_fields WHERE id = ? AND form_id = ?");
                    $stmt->execute([$field_id, $form_id]);
                    $success = "Form field deleted successfully.";
                    header("Location: scholarships.php?action=manage_form&id=" . $scholarship_id . "&success=" . urlencode($success));
                    exit();
                }
            }
            // --- Manage Exam Questions ---
            elseif ($post_action === 'add_exam_question') {
                $scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
                $question_text = trim($_POST['question_text']);
                $question_type = $_POST['question_type'];
                $options = trim($_POST['options'] ?? '');
                $correct_answer = trim($_POST['correct_answer'] ?? '');

                if ($scholarship_id && $question_text && $question_type) {
                    $stmt = $pdo->prepare("INSERT INTO exam_questions (scholarship_id, question_text, question_type, options, correct_answer) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$scholarship_id, $question_text, $question_type, $options, $correct_answer]);
                    $success = "Question added successfully.";
                    header("Location: scholarships.php?action=manage_exam&id=" . $scholarship_id . "&success=" . urlencode($success));
                    exit();
                } else {
                    $errors[] = "Question text and type are required.";
                }
            }
            elseif ($post_action === 'delete_exam_question') {
                $question_id = filter_input(INPUT_POST, 'question_id', FILTER_SANITIZE_NUMBER_INT);
                $scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
                
                if ($question_id) {
                    $stmt = $pdo->prepare("DELETE FROM exam_questions WHERE id = ?");
                    $stmt->execute([$question_id]);
                    $success = "Question deleted successfully.";
                    header("Location: scholarships.php?action=manage_exam&id=" . $scholarship_id . "&success=" . urlencode($success));
                    exit();
                }
            }
            elseif ($post_action === 'update_passing_score') {
                $id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
                $score = filter_input(INPUT_POST, 'passing_score', FILTER_SANITIZE_NUMBER_INT);
                
                if ($id && $score !== false && $score !== null) {
                    $stmt = $pdo->prepare("UPDATE scholarships SET passing_score = ? WHERE id = ?");
                    $stmt->execute([$score, $id]);
                    $success = "Passing score updated successfully.";
                    header("Location: scholarships.php?action=manage_exam&id=" . $id . "&success=" . urlencode($success));
                    exit();
                }
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Unique constraint violation
                $errors[] = "A field with a similar name already exists for this form. Please choose a different label.";
            } else {
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }
}

$page_title = 'Manage Scholarships';
$csrf_token = generate_csrf_token(); // Generate token once for the page
include 'header.php';

// --- View Logic ---
switch ($action) {
    case 'add':
    case 'edit':
        $scholarship = null;
        if ($action === 'edit' && $scholarship_id) {
            $stmt = $pdo->prepare("SELECT * FROM scholarships WHERE id = ?");
            $stmt->execute([$scholarship_id]);
            $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $scholarship_categories = [
            'Yeomchang Scholarship',
            'Student assistant',
            'Academic scholarship',
            'Employees immediate family',
            'Pastors Kids',
            'Church leaders',
            'Public teachers’ kid',
            'Public teachers’ workers',
            'DVC senior High Graduates',
            'Siblings’ scholarship',
            'SSC officer scholarship'
        ];
        // Display Add/Edit Form
        ?>
        <div class="page-header" data-aos="fade-down">
            <h1 class="fw-bold"><?php echo $action === 'edit' ? 'Edit Scholarship' : 'Create New Scholarship'; ?></h1>
            <p class="text-muted">Fill out the details below to manage the scholarship program.</p>
        </div>
        <div class="content-block" data-aos="fade-up">
            <form action="scholarships.php" method="POST">
                <input type="hidden" name="action" value="save_scholarship">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="scholarship_id" value="<?php echo htmlspecialchars($scholarship['id'] ?? ''); ?>">
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label for="name" class="form-label">Scholarship Name</label>
                            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($scholarship['name'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="5" required><?php echo htmlspecialchars($scholarship['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="benefits" class="form-label">Benefits</label>
                            <textarea class="form-control" id="benefits" name="benefits" rows="5" placeholder="List the benefits of this scholarship, one per line."><?php echo htmlspecialchars($scholarship['benefits'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="requirements" class="form-label">Qualifications</label>
                            <textarea class="form-control" id="requirements" name="requirements" rows="5" placeholder="List the qualifications for this scholarship, one per line."><?php echo htmlspecialchars($scholarship['requirements'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="application_requirements" class="form-label">Application Requirements</label>
                            <textarea class="form-control" id="application_requirements" name="application_requirements" rows="5" placeholder="List the documents or items needed for the application, one per line."><?php echo htmlspecialchars($scholarship['application_requirements'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="amount_type" class="form-label">Amount Type</label>
                            <select class="form-select" id="amount_type" name="amount_type" onchange="toggleAmountInput()">
                                <option value="Peso" <?php echo (isset($scholarship['amount_type']) && $scholarship['amount_type'] === 'Peso') ? 'selected' : ''; ?>>Peso (₱)</option>
                                <option value="Percentage" <?php echo (isset($scholarship['amount_type']) && $scholarship['amount_type'] === 'Percentage') ? 'selected' : ''; ?>>Percentage (%)</option>
                                <option value="None" <?php echo (isset($scholarship['amount_type']) && $scholarship['amount_type'] === 'None') ? 'selected' : ''; ?>>None</option>
                            </select>
                        </div>
                        <div class="mb-3" id="amount_container">
                            <label for="amount" class="form-label">Amount</label>
                            <input type="number" step="0.01" class="form-control" id="amount" name="amount" value="<?php echo htmlspecialchars($scholarship['amount'] ?? ''); ?>">
                        </div>
                        <script>
                        function toggleAmountInput() {
                            const type = document.getElementById('amount_type').value;
                            const container = document.getElementById('amount_container');
                            const input = document.getElementById('amount');
                            container.style.display = (type === 'None') ? 'none' : 'block';
                        }
                        document.addEventListener('DOMContentLoaded', toggleAmountInput);
                        </script>
                        <div class="mb-3">
                            <label for="deadline" class="form-label">Application Deadline</label>
                            <input type="date" class="form-control" id="deadline" name="deadline" value="<?php echo htmlspecialchars($scholarship['deadline'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="end_of_term" class="form-label">End of Term (Optional)</label>
                            <input type="date" class="form-control" id="end_of_term" name="end_of_term" value="<?php echo htmlspecialchars($scholarship['end_of_term'] ?? ''); ?>">
                            <div class="form-text">Set this date to automatically expire the scholarship and move active scholars to 'For Renewal'.</div>
                        </div>
                        <div class="mb-3">
                            <label for="available_slots" class="form-label">Available Slots</label>
                            <input type="number" class="form-control" id="available_slots" name="available_slots" value="<?php echo htmlspecialchars($scholarship['available_slots'] ?? '1'); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" name="category" required>
                                <option value="">Select a category...</option>
                                <?php foreach ($scholarship_categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo (isset($scholarship['category']) && $scholarship['category'] === $cat) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Applicant Type Availability</label><div class="form-check">
                                <input class="form-check-input" type="checkbox" id="accepting_new_applicants" name="accepting_new_applicants" value="1" <?php echo (isset($scholarship['accepting_new_applicants']) && $scholarship['accepting_new_applicants']) || !isset($scholarship) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="accepting_new_applicants">Accepting New Applicants</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="accepting_renewal_applicants" name="accepting_renewal_applicants" value="1" <?php echo (isset($scholarship['accepting_renewal_applicants']) && $scholarship['accepting_renewal_applicants']) || !isset($scholarship) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="accepting_renewal_applicants">Accepting Renewal Applicants</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <?php if (isAdmin()): ?>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Save Scholarship</button>
                    <a href="scholarships.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php
        break;
    
    case 'view':
        $scholarship = null;
        $form_fields = [];
        if ($scholarship_id) {
            $stmt = $pdo->prepare("SELECT * FROM scholarships WHERE id = ?");
            $stmt->execute([$scholarship_id]);
            $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($scholarship) {
                // Fetch associated form fields
                $form_stmt = $pdo->prepare("SELECT ff.* FROM form_fields ff JOIN forms f ON ff.form_id = f.id WHERE f.scholarship_id = ? ORDER BY ff.field_order ASC, ff.id ASC");
                $form_stmt->execute([$scholarship_id]);
                $form_fields = $form_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        if (!$scholarship) {
            echo '<div class="alert alert-danger">Scholarship not found.</div>';
            include 'footer.php';
            exit();
        }
        ?>
        <div class="page-header" data-aos="fade-down">
            <h1 class="fw-bold">View Scholarship</h1>
            <p class="text-muted">A read-only overview of "<?php echo htmlspecialchars($scholarship['name']); ?>"</p>
            <a href="scholarships.php" class="btn btn-outline-secondary btn-sm mt-2"><i class="bi bi-arrow-left"></i> Back to List</a>
        </div>
        <div class="content-block" data-aos="fade-up">
            <div class="row">
                <div class="col-md-8">
                    <h4>Description</h4>
                    <p><?php echo nl2br(htmlspecialchars($scholarship['description'])); ?></p> 
                    <h4 class="mt-4">Qualifications</h4>
                    <p><?php echo nl2br(htmlspecialchars($scholarship['requirements'])); ?></p>
                </div>
                <div class="col-md-4">
                    <?php
                    $amt_display = '';
                    $amt_type = $scholarship['amount_type'] ?? 'Peso';
                    if ($amt_type === 'Percentage') {
                        $amt_display = number_format($scholarship['amount'], 0) . '%';
                    } elseif ($amt_type === 'None') {
                        $amt_display = 'None';
                    } else {
                        $amt_display = '₱' . number_format($scholarship['amount'], 2);
                    }
                    ?>
                    <p><strong>Amount:</strong> <?php echo htmlspecialchars($amt_display); ?></p>
                    <p><strong>Deadline:</strong> <?php echo htmlspecialchars(date("F d, Y", strtotime($scholarship['deadline']))); ?></p>
                    <p><strong>Slots:</strong> <?php echo htmlspecialchars($scholarship['available_slots']); ?></p>
                    <p><strong>Category:</strong> <?php echo htmlspecialchars($scholarship['category']); ?></p>
                    <p><strong>Status:</strong> <span class="badge bg-<?php echo $scholarship['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($scholarship['status'])); ?></span></p>
                </div>
            </div>
        </div>
        <?php
        break;

    case 'manage_form':
        $scholarship = null;
        $form_fields = [];
        $form_id = null;

        if ($scholarship_id) {
            $stmt = $pdo->prepare("SELECT id, name FROM scholarships WHERE id = ?");
            $stmt->execute([$scholarship_id]);
            $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($scholarship) {
                $stmt = $pdo->prepare("SELECT id FROM forms WHERE scholarship_id = ?");
                $stmt->execute([$scholarship_id]);
                $form = $stmt->fetch();

                if (!$form) {
                    $form_id = dbExecuteInsert(
                        $pdo,
                        "INSERT INTO forms (scholarship_id, title) VALUES (?, ?)",
                        [$scholarship_id, $scholarship['name'] . ' Application Form']
                    );
                } else {
                    $form_id = $form['id'];
                }

                $stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY field_order ASC, id ASC");
                $stmt->execute([$form_id]);
                $form_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $errors[] = "Scholarship not found.";
            }
        } else {
            $errors[] = "No scholarship ID provided.";
        }

        // If there were errors fetching the scholarship, stop here.
        if (!empty($errors)) {
            echo '<div class="alert alert-danger" data-aos="fade-up">' . implode('<br>', array_map('htmlspecialchars', $errors)) . '</div>';
            include 'footer.php';
            exit();
        }
        ?>
        <div class="page-header" data-aos="fade-down">
            <h1 class="fw-bold">Manage Application Form</h1>
            <p class="text-muted">For scholarship: <strong><?php echo htmlspecialchars($scholarship['name'] ?? 'N/A'); ?></strong></p>
            <a href="scholarships.php" class="btn btn-outline-secondary btn-sm mt-2"><i class="bi bi-arrow-left"></i> Back to Scholarships</a>
        </div>

        <?php if (!empty($errors)) echo '<div class="alert alert-danger" data-aos="fade-up">' . implode('<br>', array_map('htmlspecialchars', $errors)) . '</div>'; ?>
        <?php if ($success) echo '<div class="alert alert-success" data-aos="fade-up">' . htmlspecialchars($success) . '</div>'; ?>

        <div class="row g-4">
            <div class="col-lg-8" data-aos="fade-up" data-aos-delay="100">
                <div class="content-block">
                    <h3>Current Form Fields</h3>
                    <?php if (empty($form_fields)): ?>
                        <p class="text-muted">No fields have been added. Use the form on the right to add one.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead><tr><th>Label</th><th>Type</th><th>Options</th><th>Required</th><th class="text-end">Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($form_fields as $field): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($field['field_label']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($field['field_type']); ?></span></td>
                                            <td><small class="text-muted"><?php echo htmlspecialchars($field['options'] ?? '-'); ?></small></td>
                                            <td><?php echo $field['is_required'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-secondary"></i>'; ?></td>
                                            <td class="text-end">
                                                <?php if (isAdmin()): ?>
                                                <form action="scholarships.php?action=manage_form&id=<?php echo $scholarship_id; ?>" method="POST" onsubmit="return confirm('Are you sure?');" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete_field">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="scholarship_id" value="<?php echo $scholarship_id; ?>">
                                                    <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
                                                    <input type="hidden" name="field_id" value="<?php echo $field['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash-fill"></i></button>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (isAdmin()): ?>
            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="200">
                <div class="content-block">
                    <h3>Add New Field</h3>
                    <form action="scholarships.php?action=manage_form&id=<?php echo $scholarship_id; ?>" method="POST">
                        <input type="hidden" name="action" value="add_field">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="scholarship_id" value="<?php echo $scholarship_id; ?>">
                        <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
                        <div class="mb-3">
                            <label for="field_label" class="form-label">Field Label</label>
                            <input type="text" class="form-control" id="field_label" name="field_label" placeholder="e.g., 'Your Essay'" required>
                        </div>
                        <div class="mb-3">
                            <label for="field_type" class="form-label">Field Type</label>
                            <select class="form-select" id="field_type" name="field_type" required>
                                <option value="text">Text (Single Line)</option>
                                <option value="textarea">Text Area (Multi-line)</option>
                                <option value="select">Dropdown (Select)</option>
                                <option value="radio">Radio Buttons</option>
                                <option value="checkbox">Checkboxes</option>
                                <option value="email">Email</option>
                                <option value="number">Number</option>
                                <option value="date">Date</option>
                                <option value="file">File Upload</option>
                            </select>
                        </div>
                        <div class="mb-3" id="options_container" style="display: none;">
                            <label for="field_options" class="form-label">Options</label>
                            <textarea class="form-control" id="field_options" name="field_options" rows="3" placeholder="Enter options separated by commas (e.g. Option 1, Option 2, Option 3)"></textarea>
                            <div class="form-text">Required for Dropdown, Radio, and Checkbox types.</div>
                        </div>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" id="is_required" name="is_required" value="1" checked>
                            <label class="form-check-label" for="is_required">This field is required</label>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle-fill me-2"></i>Add Field</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const typeSelect = document.getElementById('field_type');
                const optionsContainer = document.getElementById('options_container');
                
                function toggleOptions() {
                    const val = typeSelect.value;
                    if (['select', 'radio', 'checkbox'].includes(val)) {
                        optionsContainer.style.display = 'block';
                        document.getElementById('field_options').required = true;
                    } else {
                        optionsContainer.style.display = 'none';
                        document.getElementById('field_options').required = false;
                    }
                }
                
                typeSelect.addEventListener('change', toggleOptions);
                toggleOptions(); // Run on load
            });
        </script>
        <?php
        break;

    case 'manage_exam':
        // If admin confirms scholarship requires an exam, set the flag.
        if (isset($_GET['set_requires_exam']) && $_GET['set_requires_exam'] == 1 && $scholarship_id) {
            $stmt = $pdo->prepare("UPDATE scholarships SET requires_exam = 1 WHERE id = ?");
            $stmt->execute([$scholarship_id]);
            flashMessage("Scholarship marked as requiring an exam. Add questions to publish it.");
        }

        $scholarship = null;
        $questions = [];

        if ($scholarship_id) {
            $stmt = $pdo->prepare("SELECT id, name, status, requires_exam, passing_score FROM scholarships WHERE id = ?");
            $stmt->execute([$scholarship_id]);
            $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($scholarship) {
                $stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE scholarship_id = ? ORDER BY id ASC");
                $stmt->execute([$scholarship_id]);
                $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total_questions = count($questions);
            } else {
                $errors[] = "Scholarship not found.";
            }
        }

        if (!empty($errors)) {
            echo '<div class="alert alert-danger" data-aos="fade-up">' . implode('<br>', array_map('htmlspecialchars', $errors)) . '</div>';
            include 'footer.php';
            exit();
        }
        ?>
        <div class="page-header" data-aos="fade-down">
            <h1 class="fw-bold">Manage Entrance Exam</h1>
            <p class="text-muted">Create the qualifying test for: <strong><?php echo htmlspecialchars($scholarship['name']); ?></strong></p>
            <div class="d-flex gap-2 align-items-center mt-2">
                <a href="scholarships.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back to Scholarships</a>
                <?php if (isAdmin() && $scholarship['status'] !== 'active' && count($questions) > 0): ?>
                    <button type="button" class="btn btn-success btn-sm" onclick="showPublishModal(<?php echo $total_questions; ?>)"><i class="bi bi-megaphone-fill"></i> Publish Scholarship</button>
                <?php elseif (isAdmin() && $scholarship['status'] !== 'active'): ?>
                    <button class="btn btn-secondary btn-sm" disabled title="Add questions first">Publish Scholarship</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($success) echo '<div class="alert alert-success" data-aos="fade-up">' . htmlspecialchars($success) . '</div>'; ?>

        <div class="row g-4">
            <!-- List Questions -->
            <div class="col-lg-7">
                <div class="content-block">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3>Exam Questions</h3>
                    </div>
                    <?php if (empty($questions)): ?>
                        <div class="alert alert-info">No questions added yet. Add questions using the form.</div>
                    <?php else: ?>
                        <?php foreach ($questions as $index => $q): ?>
                            <div class="card mb-3 border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <h5 class="card-title fw-bold">Q<?php echo $index + 1; ?>. <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $q['question_type']))); ?></h5>
                                        <?php if (isAdmin()): ?>
                                        <form action="scholarships.php?action=manage_exam&id=<?php echo $scholarship_id; ?>" method="POST" onsubmit="return confirm('Delete this question?');">
                                            <input type="hidden" name="action" value="delete_exam_question">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="scholarship_id" value="<?php echo $scholarship_id; ?>">
                                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                    <p class="card-text"><?php echo nl2br(htmlspecialchars($q['question_text'])); ?></p>
                                    <?php if ($q['question_type'] === 'multiple_choice'): ?>
                                        <div class="ms-3 text-muted small">
                                            <strong>Options:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($q['options'])); ?>
                                            <div class="mt-1 text-success"><strong>Correct Answer:</strong> <?php echo htmlspecialchars($q['correct_answer']); ?></div>
                                        </div>
                                    <?php elseif ($q['question_type'] === 'true_false'): ?>
                                        <div class="ms-3 text-muted small">
                                            <div class="mt-1 text-success"><strong>Correct Answer:</strong> <?php echo htmlspecialchars($q['correct_answer']); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <div class="ms-3 text-muted small">
                                            <div class="mt-1 text-primary"><strong>Key Answer/Guide:</strong> <?php echo htmlspecialchars($q['correct_answer']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add Question Form -->
            <?php if (isAdmin()): ?>
            <div class="col-lg-5">
                <div class="content-block sticky-top" style="top: 20px;">
                    <h3>Add Question</h3>
                    <form action="scholarships.php?action=manage_exam&id=<?php echo $scholarship_id; ?>" method="POST">
                        <input type="hidden" name="action" value="add_exam_question">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="scholarship_id" value="<?php echo $scholarship_id; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Question Type</label>
                            <select class="form-select" name="question_type" id="exam_q_type" required onchange="toggleExamFields()">
                                <option value="multiple_choice">Multiple Choice</option>
                                <option value="true_false">True / False</option>
                                <option value="identification">Identification</option>
                                <option value="situational">Situational / Short Answer</option>
                                <option value="essay">Essay</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Question Text</label>
                            <textarea class="form-control" name="question_text" rows="3" required></textarea>
                        </div>
                        <div id="mc_fields">
                            <div class="mb-3">
                                <label class="form-label">Options (One per line, e.g., "A. Option Text")</label>
                                <textarea class="form-control" name="options" rows="4"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Correct Answer (e.g., "C")</label>
                                <input type="text" class="form-control" name="correct_answer" id="mc_answer" placeholder="Letter only">
                            </div>
                        </div>
                        <div id="other_fields" style="display:none;">
                            <div class="mb-3">
                                <label class="form-label">Key Answer / Grading Guide</label>
                                <textarea class="form-control" name="correct_answer" rows="3" placeholder="Guide for admin grading..."></textarea>
                            </div>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Add Question</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Publish & Passing Score Modal -->
        <div class="modal fade" id="publishModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form action="scholarships.php" method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title fw-bold">Finalize & Publish Exam</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="action" value="publish_scholarship_with_score">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="scholarship_id" value="<?php echo $scholarship_id; ?>">
                            
                            <div class="text-center mb-4">
                                <h1 class="display-4 fw-bold text-primary" id="modalTotalPoints">0</h1>
                                <p class="text-muted">Total Points / Items</p>
                            </div>

                            <div class="mb-3">
                                <label for="modalPassingScore" class="form-label fw-bold">Set Passing Score</label>
                                <div class="input-group">
                                    <input type="number" class="form-control form-control-lg" id="modalPassingScore" name="passing_score" min="1" required>
                                    <span class="input-group-text">Points</span>
                                </div>
                                <div class="form-text">Enter the minimum score required to pass (e.g., 1, 2, 3... up to Total).</div>
                            </div>

                            <div class="mb-3">
                                <label for="modalExamDuration" class="form-label fw-bold">Exam Duration (Minutes)</label>
                                <input type="number" class="form-control" id="modalExamDuration" name="exam_duration" value="60" min="5" required>
                            </div>
                            
                            <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i> Once published, students can begin taking this exam.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">Save & Publish</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function toggleExamFields() {
                const type = document.getElementById('exam_q_type').value;
                const mcFields = document.getElementById('mc_fields'); // Options textarea container
                const otherFields = document.getElementById('other_fields'); // Textarea for guide/answer
                
                // Inputs
                const optionsInput = mcFields.querySelector('textarea');
                const mcAnswerInput = mcFields.querySelector('input');
                const otherAnswerInput = otherFields.querySelector('textarea');
                const otherLabel = otherFields.querySelector('label');

                if (type === 'multiple_choice') {
                    mcFields.style.display = 'block';
                    otherFields.style.display = 'none';
                    optionsInput.disabled = false;
                    mcAnswerInput.disabled = false;
                    otherAnswerInput.disabled = true;
                } else {
                    mcFields.style.display = 'none';
                    otherFields.style.display = 'block';
                    optionsInput.disabled = true;
                    mcAnswerInput.disabled = true;
                    otherAnswerInput.disabled = false;

                    if (type === 'true_false') {
                        otherLabel.textContent = 'Correct Answer (True or False)';
                        otherAnswerInput.placeholder = 'e.g., True';
                    } else if (type === 'identification') {
                        otherLabel.textContent = 'Correct Answer';
                        otherAnswerInput.placeholder = 'The exact word or phrase...';
                    } else {
                        otherLabel.textContent = 'Key Answer / Grading Guide';
                        otherAnswerInput.placeholder = 'Guide for admin grading...';
                    }
                }
            }

            function showPublishModal(totalPoints) {
                const modalEl = document.getElementById('publishModal');
                const totalDisplay = document.getElementById('modalTotalPoints');
                const input = document.getElementById('modalPassingScore');
                
                // Move modal to body to prevent z-index issues with AOS/Backdrop
                document.body.appendChild(modalEl);

                totalDisplay.textContent = totalPoints;
                input.max = totalPoints;
                input.value = Math.ceil(totalPoints * 0.75); // Default to 75%
                
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }

            // Init
            document.addEventListener('DOMContentLoaded', toggleExamFields);
        </script>
        <?php
        break;

    case 'list':
    default:
        $active_scholarships = [];
        $archived_scholarships = [];
        try {
            // Fetch active scholarships with applicant counts (Unique Students - Latest Application)
            $active_sql = "
                SELECT 
                    s.*,
                    COUNT(CASE WHEN latest_apps.applicant_type = 'New' AND latest_apps.status NOT IN ('Rejected', 'Dropped') THEN 1 END) as new_applicants,
                    COUNT(CASE WHEN latest_apps.applicant_type = 'Renewal' AND latest_apps.status NOT IN ('Rejected', 'Dropped') THEN 1 END) as renewal_applicants,
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
                ORDER BY s.created_at DESC
            ";
            $active_scholarships = $pdo->query($active_sql)->fetchAll(PDO::FETCH_ASSOC);

            // Fetch archived scholarships (counts are less critical here, so a simple query is fine)
            $archived_sql = "SELECT * FROM scholarships WHERE status = 'archived' ORDER BY created_at DESC";
            $archived_scholarships = $pdo->query($archived_sql)->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            // Add the database error to the errors array to be displayed
            $errors[] = "Database error while fetching scholarships: " . $e->getMessage();
        }
        ?>
        <div class="page-header d-flex justify-content-between align-items-center" data-aos="fade-down">
            <div>
                <h1 class="fw-bold">Scholarship Programs</h1>
                <p class="text-muted">Create, edit, and manage all scholarship programs.</p>
            </div>
            <?php if (isAdmin()): ?>
            <a href="scholarships.php?action=add" class="btn btn-primary"><i class="bi bi-plus-circle-fill me-2"></i>Create New</a>
            <?php endif; ?>
        </div>

        <?php if (!empty($errors)) echo '<div class="alert alert-danger" data-aos="fade-up">' . implode('<br>', array_map('htmlspecialchars', $errors)) . '</div>'; ?>
        <?php if ($success) echo '<div class="alert alert-success" data-aos="fade-up">' . htmlspecialchars($success) . '</div>'; ?>

        <!-- Active Scholarships Table -->
        <div class="content-block" data-aos="fade-up" data-aos-delay="100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="mb-0">Active Programs</h3>
                <span class="badge bg-primary-soft text-primary fs-6 rounded-pill"><?php echo count($active_scholarships); ?> Active</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th class="text-center">Statistics (Total/New/Renewal)</th>
                            <th>Slots</th>
                            <th>End of Term</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($active_scholarships)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No active scholarships found. <a href="scholarships.php?action=add">Create one now</a>.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($active_scholarships as $scholarship): ?>
                                <tr>
                                    <td><a href="scholarships.php?action=view&id=<?php echo $scholarship['id']; ?>" class="fw-bold text-decoration-none"><?php echo htmlspecialchars($scholarship['name']); ?></a></td>
                                    <td class="text-center">
                                        <?php $total_applicants = ($scholarship['new_applicants'] ?? 0) + ($scholarship['renewal_applicants'] ?? 0); ?>
                                        <div class="mb-1">
                                            <span class="fw-bold fs-5"><?php echo $total_applicants; ?></span>
                                            <span class="text-muted small">Total Applicants</span>
                                        </div>
                                        <div class="d-flex justify-content-center gap-2">
                                            <span class="badge bg-light text-primary border border-primary" title="New Applicants"><i class="bi bi-person-plus"></i> <?php echo (int)($scholarship['new_applicants'] ?? 0); ?></span>
                                            <span class="badge bg-light text-success border border-success" title="Renewal Applicants"><i class="bi bi-arrow-repeat"></i> <?php echo (int)($scholarship['renewal_applicants'] ?? 0); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($scholarship['available_slots']); ?></td>
                                    <td>
                                        <?php if (!empty($scholarship['end_of_term'])): ?>
                                            <span class="badge bg-info-soft text-info"><?php echo htmlspecialchars(date("M d, Y", strtotime($scholarship['end_of_term']))); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex align-items-center justify-content-end gap-2">
                                            <?php if ($scholarship['requires_exam'] && $scholarship['exam_question_count'] == 0): ?>
                                                <span class="badge bg-danger" title="This scholarship is not public until exam questions are added.">Unpublished</span>
                                            <?php elseif ($scholarship['status'] === 'inactive'): ?>
                                                <span class="badge bg-warning text-dark" title="Ready to publish">Draft</span>
                                            <?php endif; ?>
                                            <?php if ($scholarship['exam_question_count'] > 0): ?>
                                                <span class="badge bg-info text-dark" title="Entrance Exam Active">Exam Active</span>
                                            <?php endif; ?>
                                            
                                            <?php if (isAdmin()): ?>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    Actions
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="scholarships.php?action=manage_exam&id=<?php echo $scholarship['id']; ?>"><i class="bi bi-pencil-square me-2"></i>Manage Exam</a></li>
                                                    <li><a class="dropdown-item" href="scholarships.php?action=manage_form&id=<?php echo $scholarship['id']; ?>"><i class="bi bi-ui-checks me-2"></i>Manage Form</a></li>
                                                    <li><a class="dropdown-item" href="scholarships.php?action=edit&id=<?php echo $scholarship['id']; ?>"><i class="bi bi-pencil-fill me-2"></i>Edit Details</a></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <form action="scholarships.php" method="POST" onsubmit="return confirm('Are you sure you want to archive this scholarship?');">
                                                            <input type="hidden" name="action" value="archive_scholarship">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                            <input type="hidden" name="scholarship_id" value="<?php echo $scholarship['id']; ?>">
                                                            <button type="submit" class="dropdown-item text-warning"><i class="bi bi-archive-fill me-2"></i>Archive</button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form action="scholarships.php" method="POST" onsubmit="return confirm('End Term? This will set all Active scholars to \'For Renewal\' and reject pending applications.');">
                                                            <input type="hidden" name="action" value="expire_scholarship">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                            <input type="hidden" name="scholarship_id" value="<?php echo $scholarship['id']; ?>">
                                                            <button type="submit" class="dropdown-item text-dark"><i class="bi bi-clock-history me-2"></i>End Term</button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                            <?php else: ?>
                                                <a href="scholarships.php?action=view&id=<?php echo $scholarship['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                <a href="scholarships.php?action=manage_exam&id=<?php echo $scholarship['id']; ?>" class="btn btn-sm btn-outline-secondary">Exam</a>
                                                <a href="scholarships.php?action=manage_form&id=<?php echo $scholarship['id']; ?>" class="btn btn-sm btn-outline-secondary">Form</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Archived Scholarships Table -->
        <div class="content-block mt-5" data-aos="fade-up" data-aos-delay="200">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="mb-0">Archived Programs</h3>
                <span class="badge bg-secondary-soft text-secondary fs-6 rounded-pill"><?php echo count($archived_scholarships); ?> Archived</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Slots</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($archived_scholarships)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No archived scholarships found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($archived_scholarships as $scholarship): ?>
                                <tr class="text-muted">
                                    <td><a href="scholarships.php?action=view&id=<?php echo $scholarship['id']; ?>" class="fw-bold text-decoration-none text-muted"><?php echo htmlspecialchars($scholarship['name']); ?></a></td>
                                    <td><?php echo htmlspecialchars($scholarship['available_slots']); ?></td>
                                    <td class="text-end">
                                        <?php if (isAdmin()): ?>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                Actions
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <form action="scholarships.php" method="POST" onsubmit="return confirm('Are you sure you want to restore this scholarship?');">
                                                        <input type="hidden" name="action" value="restore_scholarship">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="scholarship_id" value="<?php echo $scholarship['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-success"><i class="bi bi-arrow-counterclockwise me-2"></i>Restore</button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form action="scholarships.php" method="POST" onsubmit="return confirm('PERMANENTLY DELETE? This action cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete_scholarship_permanently">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="scholarship_id" value="<?php echo $scholarship['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash-fill me-2"></i>Delete Permanently</button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
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
        // Check for prompt_exam parameter to show the modal
        $prompt_exam_id = filter_input(INPUT_GET, 'prompt_exam', FILTER_SANITIZE_NUMBER_INT);
        if ($prompt_exam_id) {
            ?>
            <!-- Exam Prompt Modal -->
            <div class="modal fade" id="examPromptModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header border-0">
                            <h5 class="modal-title fw-bold">Entrance Exam Configuration</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-center p-4">
                            <i class="bi bi-pencil-square display-1 text-primary mb-3"></i>
                            <h4 class="mb-3">Does this scholarship require an Entrance Exam?</h4>
                            <p class="text-muted mb-4">You can set up qualifying questions now or skip this step if the scholarship is open to everyone.</p>
                            <div class="d-grid gap-2">
                                <a href="scholarships.php?action=manage_exam&id=<?php echo $prompt_exam_id; ?>&set_requires_exam=1" class="btn btn-primary btn-lg">
                                    Yes, Create Entrance Exam
                                </a>
                                <form action="scholarships.php" method="POST" class="d-grid">
                                    <input type="hidden" name="action" value="publish_no_exam">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="scholarship_id" value="<?php echo $prompt_exam_id; ?>">
                                    <button type="submit" class="btn btn-outline-secondary btn-lg">
                                    No, It's Free for Everyone
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var modalEl = document.getElementById('examPromptModal');
                    document.body.appendChild(modalEl); // Move to body to prevent z-index issues with AOS
                    var examModal = new bootstrap.Modal(modalEl);
                    examModal.show();
                });
            </script>
            <?php
        }
        ?>
        <?php
        break;
}

include 'footer.php';
?>
