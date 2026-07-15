<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(dirname(__DIR__));
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$scholarship_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$scholarship_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or missing scholarship ID'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM scholarships WHERE id = ? LIMIT 1");
    $stmt->execute([$scholarship_id]);
    $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scholarship) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Scholarship not found'
        ]);
        exit;
    }

    // Get capacity summary
    $capacity = getScholarshipCapacitySummary(
        $pdo,
        (int)$scholarship['id'],
        (int)($scholarship['available_slots'] ?? 0)
    );

    // Add capacity data to scholarship
    $scholarship['approved_count'] = $capacity['approved_count'];
    $scholarship['occupied_count'] = $capacity['occupied_count'];
    $scholarship['remaining_slots'] = $capacity['remaining_slots'];
    $scholarship['is_full'] = $capacity['is_full'];
    $scholarship['approved_students'] = $capacity['approved_students'];

    // Get exam question count
    $exam_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM exam_questions WHERE scholarship_id = ?");
    $exam_stmt->execute([$scholarship_id]);
    $exam_count = $exam_stmt->fetch(PDO::FETCH_ASSOC);
    $scholarship['exam_question_count'] = $exam_count['count'] ?? 0;

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $scholarship
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
