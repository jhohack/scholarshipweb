<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(dirname(__DIR__));
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

// GET: fetch exam questions
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $scholarship_id = filter_input(INPUT_GET, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);

    if (!$scholarship_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE scholarship_id = ? ORDER BY id ASC");
        $stmt->execute([$scholarship_id]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $scholarship_stmt = $pdo->prepare("SELECT id, name, requires_exam, passing_score FROM scholarships WHERE id = ?");
        $scholarship_stmt->execute([$scholarship_id]);
        $scholarship = $scholarship_stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'scholarship' => $scholarship,
                'questions' => $questions
            ]
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// POST: add/delete questions, set passing score
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    $scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);

    if (!$scholarship_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
        exit;
    }

    try {
        if ($post_action === 'add_question') {
            $question_text = trim($_POST['question_text'] ?? '');
            $question_type = $_POST['question_type'] ?? 'multiple_choice';
            $options = $_POST['options'] ?? '';
            $correct_answer = $_POST['correct_answer'] ?? '';

            if (empty($question_text)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Question text is required']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO exam_questions (scholarship_id, question_text, question_type, options, correct_answer)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$scholarship_id, $question_text, $question_type, $options, $correct_answer]);

            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Question added']);
        } elseif ($post_action === 'delete_question') {
            $question_id = filter_input(INPUT_POST, 'question_id', FILTER_SANITIZE_NUMBER_INT);

            if (!$question_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid question ID']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM exam_questions WHERE id = ? AND scholarship_id = ?");
            $stmt->execute([$question_id, $scholarship_id]);

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Question deleted']);
        } elseif ($post_action === 'set_passing_score') {
            $passing_score = filter_input(INPUT_POST, 'passing_score', FILTER_SANITIZE_NUMBER_INT);
            $passing_grade = $_POST['passing_grade'] ?? '';

            if ($passing_score === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid passing score']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE scholarships
                SET passing_score = ?, passing_grade = ?, requires_exam = 1
                WHERE id = ?
            ");
            $stmt->execute([$passing_score, $passing_grade, $scholarship_id]);

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Passing score updated']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
?>
