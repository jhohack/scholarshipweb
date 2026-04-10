<?php
if (session_status() === PHP_SESSION_NONE) {
    // Enable URL-based session IDs to allow multiple accounts in the same browser (multitasking)
    ini_set('session.use_trans_sid', 1);
    ini_set('session.use_only_cookies', 0);
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';
require_once $base_path . '/includes/auth.php';

checkSessionTimeout();

// Check if the user is an admin
if (!isAdmin()) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
        header("Location: login.php");
        exit();
    }
    
    // Staff Permission Check (Check if they have access to exam results)
    $allowed_pages = $_SESSION['permissions'] ?? [];
    if (!in_array('exam-results.php', $allowed_pages)) {
         die("Access Denied: You do not have permission to view this page.");
    }
    
    // Staff View Only Check (Block POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        die("Action Restricted: Staff accounts are View Only.");
    }
}

// --- Database Migration: Ensure Exam Tables Exist ---
try {
    // Create exam_submissions table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS exam_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        scholarship_id INT NOT NULL,
        score INT DEFAULT 0,
        total_items INT DEFAULT 0,
        status ENUM('in_progress', 'submitted', 'graded') DEFAULT 'in_progress',
        start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        end_time TIMESTAMP NULL,
        FOREIGN KEY (scholarship_id) REFERENCES scholarships(id) ON DELETE CASCADE
    )");

    // Create exam_answers table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS exam_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        submission_id INT NOT NULL,
        question_id INT NOT NULL,
        student_answer TEXT,
        is_correct TINYINT(1) DEFAULT 0,
        FOREIGN KEY (submission_id) REFERENCES exam_submissions(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {
    // Ignore if tables already exist or handle error
}

$submission_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$submission_id) {
    die("Invalid submission ID.");
}

$success = '';
$error = '';

// --- Handle Grading Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grades'])) {
    try {
        $pdo->beginTransaction();
        
        $manual_grades = $_POST['grades'] ?? [];
        
        // 1. Update individual answer statuses
        foreach ($manual_grades as $ans_id => $is_correct) {
            $stmt = $pdo->prepare("UPDATE exam_answers SET is_correct = ? WHERE id = ? AND submission_id = ?");
            $stmt->execute([$is_correct, $ans_id, $submission_id]);
        }
        
        // 2. Recalculate Total Score
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_answers WHERE submission_id = ? AND is_correct = 1");
        $stmt->execute([$submission_id]);
        $new_score = $stmt->fetchColumn();
        
        // 3. Update Submission
        $stmt = $pdo->prepare("UPDATE exam_submissions SET score = ?, status = 'graded' WHERE id = ?");
        $stmt->execute([$new_score, $submission_id]);
        
        // 4. Update Application Status based on Passing Score
        // Fetch submission details to get scholarship passing score
        $stmt = $pdo->prepare("
            SELECT es.*, s.passing_score, a.id as app_id 
            FROM exam_submissions es
            JOIN scholarships s ON es.scholarship_id = s.id
            JOIN applications a ON (a.student_id = es.student_id AND a.scholarship_id = es.scholarship_id)
            WHERE es.id = ?
        ");
        $stmt->execute([$submission_id]);
        $details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($details) {
            // Determine status based on 75% passing rate
            $percentage = ($details['total_items'] > 0) ? ($new_score / $details['total_items']) * 100 : 0;
            $new_app_status = ($percentage >= 75) ? 'Passed' : 'Failed';
            
            $stmt = $pdo->prepare("UPDATE applications SET status = ? WHERE id = ?");
            $stmt->execute([$new_app_status, $details['app_id']]);
        }
        
        $pdo->commit();
        $success = "Exam graded successfully. Score updated to $new_score.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error updating grades: " . $e->getMessage();
    }
}

// --- Fetch Submission Data ---
$stmt = $pdo->prepare("
    SELECT es.*, s.name as scholarship_name, st.student_name, u.email, s.passing_score
    FROM exam_submissions es
    JOIN scholarships s ON es.scholarship_id = s.id
    JOIN students st ON es.student_id = st.id
    JOIN users u ON st.user_id = u.id
    WHERE es.id = ?
");
$stmt->execute([$submission_id]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    $page_title = 'Error';
    include 'header.php';
    echo '<div class="container mt-4"><div class="alert alert-danger">Submission not found. The record may have been deleted. <a href="exam-results.php" class="alert-link">Return to Exam Results</a></div></div>';
    include 'footer.php';
    exit();
}

// --- Fetch Answers with Questions ---
$stmt = $pdo->prepare("
    SELECT ea.id as answer_id, ea.student_answer, ea.is_correct, 
           eq.question_text, eq.question_type, eq.correct_answer as key_answer, eq.options
    FROM exam_answers ea
    JOIN exam_questions eq ON ea.question_id = eq.id
    WHERE ea.submission_id = ?
    ORDER BY eq.id ASC
");
$stmt->execute([$submission_id]);
$answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Review Exam: " . htmlspecialchars($submission['student_name']);
include 'header.php';
?>

<div class="page-header" data-aos="fade-down">
    <h1 class="fw-bold">Review Exam Submission</h1>
    <p class="text-muted">Applicant: <strong><?php echo htmlspecialchars($submission['student_name']); ?></strong> | Scholarship: <strong><?php echo htmlspecialchars($submission['scholarship_name']); ?></strong></p>
    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm mt-2"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
</div>

<?php if ($success) echo '<div class="alert alert-success" data-aos="fade-up">' . htmlspecialchars($success) . '</div>'; ?>
<?php if ($error) echo '<div class="alert alert-danger" data-aos="fade-up">' . htmlspecialchars($error) . '</div>'; ?>

<div class="content-block" data-aos="fade-up">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4>Current Score: <span class="text-primary fw-bold"><?php echo $submission['score']; ?> / <?php echo $submission['total_items']; ?></span></h4>
            <small class="text-muted">Passing Score: <?php echo $submission['passing_score']; ?> pts</small>
        </div>
        <?php if ($submission['status'] === 'graded'): ?>
            <?php $passed = $submission['score'] >= $submission['passing_score']; ?>
            <span class="badge bg-<?php echo $passed ? 'success' : 'danger'; ?> fs-6">
                <?php echo $passed ? 'Passed' : 'Failed'; ?>
            </span>
        <?php else: ?>
            <span class="badge bg-warning fs-6">
                <?php echo ucfirst($submission['status']); ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (isAdmin()): ?>
    <form action="view-exam.php?id=<?php echo $submission_id; ?>" method="POST">
    <?php endif; ?>
        <?php foreach ($answers as $index => $ans): ?>
            <div class="card mb-3 border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title fw-bold">Q<?php echo $index + 1; ?>. <?php echo nl2br(htmlspecialchars($ans['question_text'])); ?></h5>
                    <div class="mb-2">
                        <span class="badge bg-secondary"><?php echo ucwords(str_replace('_', ' ', $ans['question_type'])); ?></span>
                    </div>
                    
                    <div class="p-3 bg-light rounded mb-2">
                        <strong>Student Answer:</strong><br>
                        <span class="text-dark"><?php echo nl2br(htmlspecialchars($ans['student_answer'])); ?></span>
                    </div>
                    
                    <div class="text-muted small mb-3">
                        <strong>Correct Answer / Key:</strong> <?php echo htmlspecialchars($ans['key_answer']); ?>
                    </div>

                    <?php if (isAdmin()): ?>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="hidden" name="grades[<?php echo $ans['answer_id']; ?>]" value="0"> <!-- Default 0 -->
                        <input class="form-check-input" type="checkbox" name="grades[<?php echo $ans['answer_id']; ?>]" value="1" id="grade_<?php echo $ans['answer_id']; ?>" <?php echo $ans['is_correct'] ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="grade_<?php echo $ans['answer_id']; ?>">Mark as Correct</label>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (isAdmin()): ?>
        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
            <button type="submit" name="update_grades" class="btn btn-primary btn-lg"><i class="bi bi-check-circle-fill me-2"></i>Save Grades & Finalize</button>
        </div>
        <?php endif; ?>
    <?php if (isAdmin()): ?>
    </form>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>