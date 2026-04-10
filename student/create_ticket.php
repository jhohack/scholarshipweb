<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

checkSessionTimeout();
if (!isStudent()) { header("Location: ../public/login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
$stmt->execute([$user_id]);
$student_id = $stmt->fetchColumn();

// Fetch Applications for Dropdown
$apps = getStudentApplications($pdo, $user_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject']);
    $app_id = !empty($_POST['application_id']) ? $_POST['application_id'] : null;
    $message = trim($_POST['message']);

    if ($subject && $message) {
        try {
            $pdo->beginTransaction();
            $ticket_id = dbExecuteInsert(
                $pdo,
                "INSERT INTO support_tickets (student_id, application_id, subject) VALUES (?, ?, ?)",
                [$student_id, $app_id, $subject]
            );

            $msgStmt = $pdo->prepare("INSERT INTO support_messages (ticket_id, sender_id, message) VALUES (?, ?, ?)");
            $msgStmt->execute([$ticket_id, $user_id, $message]);
            
            $pdo->commit();
            header("Location: view_ticket.php?id=" . $ticket_id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating ticket.";
        }
    }
}

$page_title = 'Create Ticket';
include 'header.php';
?>

<div class="page-header" data-aos="fade-down">
    <h1 class="fw-bold">Create New Ticket</h1>
    <a href="support.php" class="btn btn-outline-secondary btn-sm mt-2"><i class="bi bi-arrow-left me-1"></i>Back to List</a>
</div>

<div class="content-block" data-aos="fade-up">
    <form method="post">
        <div class="mb-3">
            <label class="form-label fw-bold">Subject</label>
            <input type="text" name="subject" class="form-control" required placeholder="Brief summary of your issue">
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold">Related Application (Optional)</label>
            <select name="application_id" class="form-select">
                <option value="">-- General Inquiry --</option>
                <?php foreach ($apps as $app): ?>
                    <option value="<?php echo $app['id']; ?>">
                        <?php echo htmlspecialchars($app['scholarship_name']); ?> (<?php echo $app['status']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold">Message</label>
            <textarea name="message" class="form-control" rows="5" required placeholder="Describe your concern in detail..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Submit Ticket</button>
    </form>
</div>
<?php include 'footer.php'; ?>
