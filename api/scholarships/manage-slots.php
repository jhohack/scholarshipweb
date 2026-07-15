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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
$action = $_POST['action'] ?? '';

if (!$scholarship_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
    exit;
}

try {
    if ($action === 'add_slots') {
        $additional_slots = filter_input(INPUT_POST, 'additional_slots', FILTER_VALIDATE_INT);

        if (!$additional_slots || $additional_slots <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid number of slots']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, name, available_slots, status FROM scholarships WHERE id = ? LIMIT 1");
        $stmt->execute([$scholarship_id]);
        $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$scholarship) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Scholarship not found']);
            exit;
        }

        if (($scholarship['status'] ?? '') === 'archived') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Archived scholarships cannot be reopened']);
            exit;
        }

        $current_slots = max(0, (int) ($scholarship['available_slots'] ?? 0));
        $new_total_slots = $current_slots + (int) $additional_slots;

        $update_stmt = $pdo->prepare("
            UPDATE scholarships
            SET available_slots = ?, accepting_new_applicants = 1
            WHERE id = ?
        ");
        $update_stmt->execute([$new_total_slots, $scholarship_id]);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => "Added $additional_slots slot(s). New total: $new_total_slots.",
            'data' => [
                'previous_slots' => $current_slots,
                'additional_slots' => $additional_slots,
                'new_total_slots' => $new_total_slots
            ]
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
