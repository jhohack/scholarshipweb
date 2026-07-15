<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(dirname(__DIR__));
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
$action = $_POST['action'] ?? 'archive'; // archive or restore

if (!$scholarship_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, status FROM scholarships WHERE id = ? LIMIT 1");
    $stmt->execute([$scholarship_id]);
    $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scholarship) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Scholarship not found']);
        exit;
    }

    if ($action === 'archive') {
        $new_status = 'archived';
        $message = 'Scholarship archived successfully';
    } elseif ($action === 'restore') {
        $new_status = 'inactive';
        $message = 'Scholarship restored to draft status';
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
    }

    $update_stmt = $pdo->prepare("UPDATE scholarships SET status = ? WHERE id = ?");
    $update_stmt->execute([$new_status, $scholarship_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'scholarship_id' => $scholarship_id,
            'scholarship_name' => $scholarship['name'],
            'previous_status' => $scholarship['status'],
            'new_status' => $new_status
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
