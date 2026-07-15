<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(dirname(__DIR__));
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$scholarship_id = filter_input(INPUT_GET, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?? 1);
$per_page = 50;
$offset = ($page - 1) * $per_page;

if (!$scholarship_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or missing scholarship ID'
    ]);
    exit;
}

try {
    // Verify scholarship exists
    $scholarship_check = $pdo->prepare("SELECT id, name FROM scholarships WHERE id = ? LIMIT 1");
    $scholarship_check->execute([$scholarship_id]);
    $scholarship = $scholarship_check->fetch(PDO::FETCH_ASSOC);

    if (!$scholarship) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Scholarship not found'
        ]);
        exit;
    }

    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM exam_submissions
                  WHERE scholarship_id = ?";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute([$scholarship_id]);
    $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Get exam submissions with pagination
    $sql = "SELECT
                es.id,
                es.student_id,
                es.scholarship_id,
                es.score,
                es.total_items,
                es.status,
                es.start_time,
                es.end_time,
                s.student_name,
                s.school_id_number,
                s.email,
                sh.name as scholarship_name
            FROM exam_submissions es
            JOIN students s ON es.student_id = s.id
            JOIN scholarships sh ON es.scholarship_id = sh.id
            WHERE es.scholarship_id = ?
            ORDER BY es.end_time DESC
            LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$scholarship_id, $per_page, $offset]);
    $exam_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate stats
    $stats_sql = "SELECT
                    COUNT(*) as total_submissions,
                    COUNT(CASE WHEN status = 'graded' THEN 1 END) as graded_count,
                    COUNT(CASE WHEN status = 'submitted' THEN 1 END) as submitted_count,
                    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_count,
                    AVG(CASE WHEN status = 'graded' THEN score ELSE NULL END) as average_score
                FROM exam_submissions
                WHERE scholarship_id = ?";
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute([$scholarship_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'scholarship' => $scholarship,
            'exam_results' => $exam_results,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => ceil($total / $per_page)
            ],
            'stats' => $stats
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
