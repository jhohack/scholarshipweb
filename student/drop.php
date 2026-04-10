<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/mailer.php';

// --- Database Migration: Ensure 'remarks' column exists for drop reasons ---
try {
    $pdo->query("SELECT remarks FROM applications LIMIT 1");
} catch (PDOException $e) {
    // If the SELECT fails, it means the column likely doesn't exist. Add it.
    try {
        $pdo->exec("ALTER TABLE applications ADD COLUMN remarks TEXT DEFAULT NULL");
    } catch (PDOException $ex) {
        die("A critical database error occurred during schema update. Please contact support.");
    }
}

require_once $base_path . '/includes/functions.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require $base_path . '/vendor/autoload.php';

checkSessionTimeout();

// --- Authentication & Authorization ---
if (!isStudent()) {
    header("Location: ../public/login.php");
    exit();
}

$application_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
$user_id = $_SESSION['user_id'];
$errors = [];
$success = false;

if (!$application_id) {
    header("Location: dashboard.php");
    exit();
}

try {
    // Security Check: Verify this application belongs to the logged-in user and is 'Active'
    $stmt = $pdo->prepare("
        SELECT a.id, s.name as scholarship_name
        FROM applications a
        JOIN students st ON a.student_id = st.id
        JOIN scholarships s ON a.scholarship_id = s.id
        WHERE a.id = ? AND st.user_id = ? AND a.status IN ('Active', 'Approved', 'Pending', 'Under Review', 'For Renewal', 'Pending Exam')
    ");
    $stmt->execute([$application_id, $user_id]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        // Not found, not theirs, or not active. Redirect.
        flashMessage("You do not have permission to drop this scholarship or the status does not allow it.", "danger");
        header("Location: dashboard.php");
        exit();
    }

} catch (PDOException $e) {
    $errors[] = "A database error occurred. Please try again later.";
    error_log("Drop page error: " . $e->getMessage());
}

// --- Handle Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($errors)) {
    $reason = trim($_POST['reason'] ?? '');

    if (empty($reason)) {
        $errors[] = "A reason for dropping the scholarship is required.";
    } elseif (strlen($reason) < 20) {
        $errors[] = "Please provide a more detailed explanation (at least 20 characters).";
    } else {
        try {
            // Update the status to 'Drop Requested' and save the reason in 'remarks'
            $update_stmt = $pdo->prepare("UPDATE applications SET status = 'Drop Requested', remarks = ? WHERE id = ?");
            $update_stmt->execute([$reason, $application_id]);

            // --- Send Email Notification to Student ---
            // Fetch student email
            $stu_stmt = $pdo->prepare("SELECT email, student_name FROM students WHERE user_id = ?");
            $stu_stmt->execute([$user_id]);
            $student_info = $stu_stmt->fetch(PDO::FETCH_ASSOC);

            if ($student_info) {
                $mail = new PHPMailer(true);
                try {
                    configureSmtpMailer($mail, 'DVC Scholarship Hub');

                    // Recipients
                    $mail->addAddress($student_info['email'], $student_info['student_name']);

                    // Content
                    $mail->isHTML(true);
                    $mail->Subject = 'Scholarship Drop Request Submitted';
                    $mail->Body    = "Hello {$student_info['student_name']},<br><br>We have received your request to drop the <strong>{$application['scholarship_name']}</strong> scholarship.<br><br><strong>Reason:</strong> " . nl2br(htmlspecialchars($reason)) . "<br><br>Your request is now pending admin approval. You will be notified once a decision is made.<br><br>Sincerely,<br>The DVC Scholarship Hub Team";

                    $mail->send();
                } catch (\Throwable $e) {
                    // Log error but don't stop the process
                    error_log("Mail error: " . ($mail->ErrorInfo ?: $e->getMessage()));
                }
            }
            
            $success = true;

        } catch (PDOException $e) {
            $errors[] = "A database error occurred while submitting your request. Please try again.";
            error_log("Drop request submission error: " . $e->getMessage());
        }
    }
}

$page_title = 'Request to Drop Scholarship';
include 'header.php';
?>

<div class="page-header" data-aos="fade-down">
    <h1 class="fw-bold">Request to Drop Scholarship</h1>
    <p class="text-muted">You are requesting to drop the "<?php echo htmlspecialchars($application['scholarship_name']); ?>" scholarship.</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success text-center" data-aos="zoom-in">
        <h4 class="alert-heading">Request Submitted!</h4>
        <p>Your request to drop the scholarship has been sent to the administrator for approval. You will be notified via email once a decision is made.</p>
        <hr>
        <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
    </div>
<?php else: ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" data-aos="fade-up">
            <?php foreach ($errors as $error): ?>
                <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-warning" data-aos="fade-up">
        <h4 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Important Notice</h4>
        <p>Dropping your scholarship is a significant decision. Once your request is approved, you will lose all benefits associated with this scholarship. You will then be eligible to apply for a new scholarship.</p>
        <p class="mb-0">This action is not immediately reversible and is subject to administrative approval.</p>
    </div>

    <div class="content-block" data-aos="fade-up" data-aos-delay="100">
        <form action="drop.php?id=<?php echo $application_id; ?>" method="post">
            <div class="mb-4">
                <label for="reason" class="form-label fw-bold">Reason for Dropping</label>
                <textarea class="form-control" id="reason" name="reason" rows="5" placeholder="Please provide a clear and concise reason for your request. For example: Change in eligibility, academic shift, personal circumstances, or transfer to another scholarship." required minlength="20"></textarea>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-danger btn-lg" onclick="return confirm('Are you sure you want to submit this drop request?');"><i class="bi bi-box-arrow-left me-2"></i>Submit Drop Request</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
