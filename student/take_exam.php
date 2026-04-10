<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

// --- 1. Database Migrations (Auto-setup) ---
try {
    // Add exam_duration to scholarships if not exists (Default 60 mins)
    try {
        $pdo->query("SELECT exam_duration FROM scholarships LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE scholarships ADD COLUMN exam_duration INT DEFAULT 60");
    }

    dbEnsureExamSchema($pdo);
    dbEnsureNotificationsTable($pdo);
} catch (PDOException $e) {
    die("Database setup error: " . $e->getMessage());
}

// --- 2. Authentication & Validation ---
checkSessionTimeout();

if (!isStudent()) {
    header("Location: ../public/login.php");
    exit();
}

$scholarship_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$scholarship_id) {
    flashMessage("Invalid scholarship exam request.");
    header("Location: dashboard.php");
    exit();
}

// Legacy compatibility route: move students into the application-based exam flow.
try {
    $student_stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $student_stmt->execute([$_SESSION['user_id']]);
    $student_id = $student_stmt->fetchColumn();

    if (!$student_id) {
        flashMessage("Student profile not found.");
        header("Location: dashboard.php");
        exit();
    }

    $application_stmt = $pdo->prepare("
        SELECT id, status
        FROM applications
        WHERE student_id = ? AND scholarship_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $application_stmt->execute([$student_id, $scholarship_id]);
    $application = $application_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        flashMessage("You must apply for this scholarship first.");
        header("Location: dashboard.php");
        exit();
    }

    if ($application['status'] !== 'Pending Exam') {
        flashMessage("Cannot access exam. Current status: " . ($application['status'] ?: 'Invalid/Empty'));
        header("Location: dashboard.php");
        exit();
    }

    header("Location: ../public/entrance-exam.php?id=" . $application['id']);
    exit();
} catch (PDOException $e) {
    flashMessage("Unable to open the exam right now. Please try again.");
    header("Location: dashboard.php");
    exit();
}

// Fetch Scholarship Details
$stmt = $pdo->prepare("SELECT * FROM scholarships WHERE id = ?");
$stmt->execute([$scholarship_id]);
$scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$scholarship || !$scholarship['requires_exam']) {
    die("This scholarship does not require an exam.");
}

// Check Application Status
$stmt = $pdo->prepare("SELECT * FROM applications WHERE student_id = ? AND scholarship_id = ?");
$stmt->execute([$student_id, $scholarship_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    die("You must apply for this scholarship first.");
}

// --- 3. Exam Session Management (Timer Logic) ---
// Check if a submission already exists
$stmt = $pdo->prepare("SELECT * FROM exam_submissions WHERE student_id = ? AND scholarship_id = ?");
$stmt->execute([$student_id, $scholarship_id]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

$duration_seconds = ($scholarship['exam_duration'] ?: 60) * 60;

if (!$submission) {
    // Start new exam
    $submission_id = dbExecuteInsert(
        $pdo,
        "INSERT INTO exam_submissions (student_id, scholarship_id, start_time, status) VALUES (?, ?, CURRENT_TIMESTAMP, 'in_progress')",
        [$student_id, $scholarship_id]
    );
    $start_time = time();
} else {
    // Resume existing exam
    $submission_id = $submission['id'];
    $start_time = strtotime($submission['start_time']);
    
    // If already submitted, show results or redirect
    if ($submission['status'] !== 'in_progress') {
        // Simple Result View
        include '../includes/header.php'; // Assuming you have this
        ?>
        <div class="container mt-5">
            <div class="card shadow-sm">
                <div class="card-body text-center p-5">
                    <?php if ($submission['status'] === 'graded'): ?>
                        <h1 class="fw-bold mb-3">Exam Results</h1>
                        <div class="display-4 mb-3"><?php echo $submission['score']; ?> / <?php echo $submission['total_items']; ?></div>
                        <p class="lead">
                            <?php 
                            $percentage = ($submission['total_items'] > 0) ? ($submission['score'] / $submission['total_items']) * 100 : 0;
                            if ($percentage >= $scholarship['passing_score']) {
                                echo '<span class="text-success fw-bold">Congratulations! You Passed.</span>';
                            } else {
                                echo '<span class="text-danger fw-bold">You did not meet the passing score.</span>';
                            }
                            ?>
                        </p>
                    <?php else: ?>
                        <h1 class="fw-bold mb-3">Exam Submitted</h1>
                        <p class="lead">Your exam has been submitted and is currently <strong>Under Review</strong>.</p>
                        <p>Please wait for the administrator to check your essay/situational answers.</p>
                    <?php endif; ?>
                    <a href="dashboard.php" class="btn btn-primary mt-3">Return to Dashboard</a>
                </div>
            </div>
        </div>
        <?php
        include '../includes/footer.php';
        exit();
    }
}

// Calculate remaining time
$elapsed = time() - $start_time;
$remaining = $duration_seconds - $elapsed;

// Force submit if time is up
if ($remaining <= 0) {
    // We'll handle the "auto-submit" logic in the POST handler below, 
    // but if it's a GET request, we just set remaining to 0 to trigger JS submission.
    $remaining = 0;
}

// --- 4. Handle Submission & Grading ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    $answers = $_POST['answers'] ?? [];
    
    // Fetch all questions to grade
    $q_stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE scholarship_id = ?");
    $q_stmt->execute([$scholarship_id]);
    $questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

    $score = 0;
    $total_items = count($questions);
    $needs_manual_review = false;

    $insert_ans = $pdo->prepare("INSERT INTO exam_answers (submission_id, question_id, student_answer, is_correct) VALUES (?, ?, ?, ?)");

    foreach ($questions as $q) {
        $qid = $q['id'];
        $student_ans = isset($answers[$qid]) ? trim($answers[$qid]) : '';
        $is_correct = 0;
        $correct_ans = trim($q['correct_answer']);

        // Grading Logic
        if ($q['question_type'] === 'multiple_choice' || $q['question_type'] === 'true_false') {
            // Exact match (case-insensitive for safety)
            if (strcasecmp($student_ans, $correct_ans) === 0) {
                $is_correct = 1;
                $score++;
            }
        } elseif ($q['question_type'] === 'identification') {
            // Case-insensitive string comparison
            if (strcasecmp($student_ans, $correct_ans) === 0) {
                $is_correct = 1;
                $score++;
            }
        } elseif ($q['question_type'] === 'essay' || $q['question_type'] === 'situational') {
            // Cannot auto-grade
            $is_correct = 0; // Default to 0, admin will update
            $needs_manual_review = true;
        }

        $insert_ans->execute([$submission_id, $qid, $student_ans, $is_correct]);
    }

    // Determine Status
    $final_status = $needs_manual_review ? 'submitted' : 'graded'; // 'submitted' implies pending review

    // Update Submission
    $update_sub = $pdo->prepare("UPDATE exam_submissions SET score = ?, total_items = ?, status = ?, end_time = CURRENT_TIMESTAMP WHERE id = ?");
    $update_sub->execute([$score, $total_items, $final_status, $submission_id]);

    // Update Application Status
    $app_status = 'Under Review';
    if (!$needs_manual_review) {
        // Calculate Pass/Fail
        $percentage = ($total_items > 0) ? ($score / $total_items) * 100 : 0;
        
        // Enforce 75% passing rate
        if ($percentage >= 75) {
            $app_status = 'Passed';
        } else {
            $app_status = 'Failed';
        }
    }

    $update_app = $pdo->prepare("UPDATE applications SET status = ? WHERE id = ?");
    $update_app->execute([$app_status, $application['id']]);

    // Insert Notification
    try {
        $notif_title = "Exam Submitted";
        $notif_msg = "You have completed the exam for " . $scholarship['name'] . ".";
        if ($final_status === 'graded') {
            $notif_msg .= " Your score: $score / $total_items. Result: " . ($app_status === 'Passed' ? 'Passed' : 'Failed');
        } else {
            $notif_msg .= " It is now under review.";
        }
        $stmt_notif = $pdo->prepare("INSERT INTO notifications (student_id, title, message) VALUES (?, ?, ?)");
        $stmt_notif->execute([$student_id, $notif_title, $notif_msg]);
    } catch (PDOException $e) {}

    // Redirect to self to show results
    header("Location: take_exam.php?id=" . $scholarship_id);
    exit();
}

// Fetch questions for display
$stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE scholarship_id = ? ORDER BY id ASC");
$stmt->execute([$scholarship_id]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Entrance Exam - " . $scholarship['name'];
include '../includes/header.php'; // Adjust path as needed
?>

<style>
    .timer-fixed {
        position: fixed;
        top: 80px; /* Adjust based on your navbar height */
        right: 20px;
        z-index: 1000;
        width: 150px;
    }
    .question-card {
        transition: all 0.3s;
    }
    .question-card:hover {
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
</style>

<div class="container mt-4 mb-5">
    <!-- Timer Display -->
    <div class="card shadow timer-fixed border-primary">
        <div class="card-body text-center p-2">
            <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Time Remaining</small>
            <div class="h4 mb-0 fw-bold text-primary" id="timer">00:00:00</div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="text-center mb-4">
                <h2 class="fw-bold"><?php echo htmlspecialchars($scholarship['name']); ?> Exam</h2>
                <p class="text-muted">Please answer all questions before the timer runs out.</p>
            </div>

            <form id="examForm" method="POST" action="take_exam.php?id=<?php echo $scholarship_id; ?>">
                <input type="hidden" name="submit_exam" value="1">

                <?php foreach ($questions as $index => $q): ?>
                    <div class="card mb-4 question-card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <h5 class="card-title fw-bold mb-3">
                                <span class="badge bg-secondary me-2"><?php echo $index + 1; ?></span>
                                <?php echo nl2br(htmlspecialchars($q['question_text'])); ?>
                            </h5>

                            <div class="mt-3">
                                <?php if ($q['question_type'] === 'multiple_choice'): ?>
                                    <?php 
                                    // Parse options (assuming one per line)
                                    $options = explode("\n", $q['options']);
                                    foreach ($options as $opt): 
                                        $opt = trim($opt);
                                        if (empty($opt)) continue;
                                        // Extract value (e.g., "A" from "A. Apple")
                                        // Simple heuristic: Get first letter if it looks like "A." or "A)"
                                        $val = substr($opt, 0, 1); 
                                    ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="answers[<?php echo $q['id']; ?>]" id="q<?php echo $q['id']; ?>_<?php echo $val; ?>" value="<?php echo htmlspecialchars($val); ?>">
                                            <label class="form-check-label" for="q<?php echo $q['id']; ?>_<?php echo $val; ?>">
                                                <?php echo htmlspecialchars($opt); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>

                                <?php elseif ($q['question_type'] === 'true_false'): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="answers[<?php echo $q['id']; ?>]" id="q<?php echo $q['id']; ?>_true" value="True">
                                        <label class="form-check-label" for="q<?php echo $q['id']; ?>_true">True</label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="answers[<?php echo $q['id']; ?>]" id="q<?php echo $q['id']; ?>_false" value="False">
                                        <label class="form-check-label" for="q<?php echo $q['id']; ?>_false">False</label>
                                    </div>

                                <?php elseif ($q['question_type'] === 'identification'): ?>
                                    <input type="text" class="form-control" name="answers[<?php echo $q['id']; ?>]" placeholder="Type your answer here..." autocomplete="off">

                                <?php elseif ($q['question_type'] === 'essay' || $q['question_type'] === 'situational'): ?>
                                    <textarea class="form-control" name="answers[<?php echo $q['id']; ?>]" rows="5" placeholder="Type your answer here..."></textarea>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="d-grid gap-2 mt-5">
                    <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('Are you sure you want to submit your exam?');">Submit Exam</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Timer Logic
    let remainingSeconds = <?php echo max(0, $remaining); ?>;
    const timerDisplay = document.getElementById('timer');
    const examForm = document.getElementById('examForm');

    function updateTimer() {
        if (remainingSeconds <= 0) {
            clearInterval(timerInterval);
            timerDisplay.innerText = "00:00:00";
            timerDisplay.classList.add('text-danger');
            alert("Time is up! Your exam will be submitted automatically.");
            examForm.submit();
            return;
        }

        const hours = Math.floor(remainingSeconds / 3600);
        const minutes = Math.floor((remainingSeconds % 3600) / 60);
        const seconds = remainingSeconds % 60;

        timerDisplay.innerText = 
            (hours < 10 ? "0" + hours : hours) + ":" + 
            (minutes < 10 ? "0" + minutes : minutes) + ":" + 
            (seconds < 10 ? "0" + seconds : seconds);

        // Visual warning when low
        if (remainingSeconds < 300) { // Less than 5 mins
            timerDisplay.classList.remove('text-primary');
            timerDisplay.classList.add('text-danger');
        }

        remainingSeconds--;
    }

    const timerInterval = setInterval(updateTimer, 1000);
    updateTimer(); // Initial call

    // Prevent accidental navigation
    window.onbeforeunload = function() {
        if (remainingSeconds > 0) {
            return "You have an exam in progress. Are you sure you want to leave?";
        }
    };
    
    // Remove warning on valid submit
    examForm.addEventListener('submit', function() {
        window.onbeforeunload = null;
    });
</script>

<?php include '../includes/footer.php'; ?>
