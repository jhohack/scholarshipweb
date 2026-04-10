<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

checkSessionTimeout();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$application_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$application_id) {
    header("Location: ../student/dashboard.php");
    exit();
}

// Verify application belongs to user (Status check moved to PHP for better error feedback)
try {
    $stmt = $pdo->prepare("
        SELECT a.*, s.name as scholarship_name 
        FROM applications a 
        JOIN students st ON a.student_id = st.id 
        JOIN scholarships s ON a.scholarship_id = s.id
        WHERE a.id = ? AND st.user_id = ?
    ");
    $stmt->execute([$application_id, $_SESSION['user_id']]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        $_SESSION['flash_message'] = "Application not found.";
        header("Location: ../student/dashboard.php");
        exit();
    }

    // Check status specifically
    if ($application['status'] !== 'Pending Exam') {
        $_SESSION['flash_message'] = "Cannot access exam. Current status: " . ($application['status'] ?: 'Invalid/Empty');
        header("Location: ../student/dashboard.php");
        exit();
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Fetch Exam Questions
$q_stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE scholarship_id = ? ORDER BY id ASC");
$q_stmt->execute([$application['scholarship_id']]);
$questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Passing Score
$auto_graded_questions_count = count(array_filter($questions, function($q) {
    return in_array($q['question_type'], ['multiple_choice', 'identification', 'true_or_false']);
}));
$passing_score = $auto_graded_questions_count > 0 ? ceil($auto_graded_questions_count * 0.5) : 0; // 50% passing rate


// --- Exam Timer Logic ---
$exam_duration = 60 * 60; // 60 minutes in seconds
$timer_session_key = 'exam_start_time_' . $application_id;
if (!isset($_SESSION[$timer_session_key])) {
    $_SESSION[$timer_session_key] = time();
}
$elapsed_time = time() - $_SESSION[$timer_session_key];
$remaining_seconds = max(0, $exam_duration - $elapsed_time);

// Ensure exam tables exist (Auto-create for this feature)
try {
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
    // Ignore if table exists or permission denied (assuming table exists in prod)
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $score = 0;
    $total_items = count($questions);
    $has_manual_grading = false;
    $answers_to_save = [];

    foreach ($questions as $q) {
        $input_name = 'q_' . $q['id'];
        $user_answer = trim($_POST[$input_name] ?? '');
        $is_correct = 0;

        if (in_array($q['question_type'], ['multiple_choice', 'identification', 'true_or_false'])) {
            // Auto-grade MC
            // Compare user answer (e.g., "C") with correct answer in DB
            if (strcasecmp($user_answer, trim($q['correct_answer'])) === 0) {
                $score++;
                $is_correct = 1;
            }
        } else {
            $has_manual_grading = true;
            if (empty($user_answer)) $error = "Please answer all situational questions.";
        }
        
        $answers_to_save[] = [
            'question_id' => $q['id'],
            'student_answer' => $user_answer,
            'is_correct' => $is_correct
        ];
    }

    $status = $has_manual_grading ? 'submitted' : 'graded';
    $start_time = date('Y-m-d H:i:s', $_SESSION[$timer_session_key]);
    $end_time = date('Y-m-d H:i:s');

    if (empty($error)) {
        try {
            $pdo->beginTransaction();

            // 1. Insert Submission
            $stmt = $pdo->prepare("INSERT INTO exam_submissions (student_id, scholarship_id, score, total_items, status, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$application['student_id'], $application['scholarship_id'], $score, $total_items, $status, $start_time, $end_time]);
            $submission_id = $pdo->lastInsertId();

            // 2. Insert Answers
            $ans_stmt = $pdo->prepare("INSERT INTO exam_answers (submission_id, question_id, student_answer, is_correct) VALUES (?, ?, ?, ?)");
            foreach ($answers_to_save as $ans) {
                $ans_stmt->execute([$submission_id, $ans['question_id'], $ans['student_answer'], $ans['is_correct']]);
            }

            // 3. Update Application Status to 'Pending' (Final Submission to Admin)
            $update_stmt = $pdo->prepare("UPDATE applications SET status = 'Pending' WHERE id = ?");
            $update_stmt->execute([$application_id]);

            $pdo->commit();

            // Clear timer session
            unset($_SESSION[$timer_session_key]);

            // Redirect to success page or dashboard with message
            $_SESSION['flash_message'] = "Application and Exam submitted successfully!";
            header("Location: ../student/dashboard.php?success=exam_submitted");
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Failed to submit exam. Please try again.";
        }
    }
}

$page_title = 'Qualifying Test';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .exam-card { border-left: 5px solid var(--bs-primary); }
        .question-text { font-weight: 600; font-size: 1.1rem; margin-bottom: 1rem; }
        .form-check-label { cursor: pointer; }
        .timer-critical { background-color: #dc3545 !important; animation: pulse 1s infinite; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <!-- Floating Timer -->
    <div class="position-fixed bottom-0 end-0 p-4" style="z-index: 1050;">
        <div id="timer-card" class="card text-white bg-primary shadow-lg" style="min-width: 200px;">
            <div class="card-header text-center fw-bold py-1 small text-uppercase">Time Remaining</div>
            <div class="card-body p-2 text-center">
                <h2 class="mb-0 font-monospace fw-bold" id="time-display">--:--</h2>
            </div>
        </div>
    </div>

    <main class="container py-5 mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-5">
                    <h1 class="fw-bold">Qualifying Test</h1>
                    <p class="lead text-muted"><?php echo htmlspecialchars($application['scholarship_name']); ?></p>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        Please complete this exam to finalize your application for <strong><?php echo htmlspecialchars($application['scholarship_name']); ?></strong>.
                        <?php if ($auto_graded_questions_count > 0): ?>
                        <br><i class="bi bi-check-circle-fill me-2"></i>
                        The passing score for auto-graded items is <strong><?php echo $passing_score; ?> out of <?php echo $auto_graded_questions_count; ?></strong>.
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form action="entrance-exam.php?id=<?php echo $application_id; ?>" method="POST">
                    <?php if (empty($questions)): ?>
                        <div class="alert alert-warning">No exam questions found for this scholarship. Please contact the administrator.</div>
                        <div class="d-grid">
                            <!-- Allow bypass if no exam exists, or block? Assuming block/contact admin -->
                            <a href="../student/dashboard.php" class="btn btn-secondary">Return to Dashboard</a>
                        </div>
                    <?php else: ?>
                        
                        <!-- Loop through questions -->
                        <?php foreach ($questions as $index => $q): ?>
                            <div class="card shadow-sm mb-4 exam-card">
                                <div class="card-body p-4">
                                    <p class="question-text">
                                        <?php echo ($index + 1) . '. ' . nl2br(htmlspecialchars($q['question_text'])); ?>
                                    </p>

                                    <?php if ($q['question_type'] === 'multiple_choice'): ?>
                                        <!-- Multiple Choice Options -->
                                        <?php 
                                            $options = explode("\n", $q['options']); 
                                            foreach ($options as $opt): 
                                                $opt = trim($opt);
                                                if (empty($opt)) continue;
                                                // Extract letter (A.) to use as value
                                                $value = strtoupper(substr($opt, 0, 1)); 
                                        ?>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="radio" name="q_<?php echo $q['id']; ?>" id="opt_<?php echo $q['id'] . $value; ?>" value="<?php echo $value; ?>" required>
                                                <label class="form-check-label" for="opt_<?php echo $q['id'] . $value; ?>">
                                                    <?php echo htmlspecialchars($opt); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php elseif($q['question_type'] === 'situational' || $q['question_type'] === 'essay'): ?>
                                        <!-- Situational / Essay -->
                                        <textarea class="form-control" name="q_<?php echo $q['id']; ?>" rows="4" required placeholder="Type your answer here..."></textarea>
                                    <?php else: ?>
                                        <!-- Identification / True or False -->
                                        <input type="text" class="form-control" name="q_<?php echo $q['id']; ?>" required placeholder="Type your answer here...">
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="d-grid gap-2 col-lg-6 mx-auto mb-5">
                            <button type="submit" class="btn btn-primary btn-lg fw-bold">Submit Exam & Finalize Application</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let remaining = <?php echo $remaining_seconds; ?>;
            const display = document.getElementById('time-display');
            const timerCard = document.getElementById('timer-card');
            const form = document.querySelector('form');
            let submitted = false;

            function updateTimer() {
                if (remaining <= 0) {
                    if (!submitted) {
                        submitted = true;
                        alert("Time is up! Your exam will be submitted automatically.");
                        form.submit();
                    }
                    return;
                }

                remaining--;
                
                // Critical warning (last 5 mins)
                if (remaining <= 300) {
                    timerCard.classList.remove('bg-primary');
                    timerCard.classList.add('timer-critical');
                }

                const h = Math.floor(remaining / 3600);
                const m = Math.floor((remaining % 3600) / 60);
                const s = remaining % 60;

                // Format: HH:MM:SS or MM:SS if < 1 hour
                let timeStr = "";
                if (h > 0) timeStr += (h < 10 ? "0" + h : h) + ":";
                timeStr += (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
                
                display.textContent = timeStr;
            }

            updateTimer();
            setInterval(updateTimer, 1000);

            // Prevent accidental navigation
            window.onbeforeunload = function() {
                if (!submitted && remaining > 0) {
                    return "Are you sure you want to leave? Your exam progress may be lost.";
                }
            };
            
            form.addEventListener('submit', function() {
                submitted = true;
            });
        });
    </script>
</body>
</html>