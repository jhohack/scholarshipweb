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
$amount_type = trim((string) ($_POST['amount_type'] ?? ''));
$amount_raw = $_POST['amount'] ?? null;
$amount = $amount_raw !== null && $amount_raw !== ''
    ? filter_var($amount_raw, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION)
    : null;

if (!$scholarship_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
    exit;
}

if (!in_array($amount_type, ['Percentage', 'Peso'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Amount type must be Percentage or Peso']);
    exit;
}

if ($amount === null || !is_numeric((string) $amount) || (float) $amount <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero']);
    exit;
}

$normalized_amount = round((float) $amount, 2);

if ($amount_type === 'Percentage' && $normalized_amount > 100) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Percentage cannot exceed 100']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE scholarships
        SET amount = ?, amount_type = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $normalized_amount,
        $amount_type,
        (int) $scholarship_id,
    ]);

    if ($stmt->rowCount() === 0) {
        $verify_stmt = $pdo->prepare("SELECT id, name FROM scholarships WHERE id = ? LIMIT 1");
        $verify_stmt->execute([(int) $scholarship_id]);
        $scholarship = $verify_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$scholarship) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Scholarship not found']);
            exit;
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Scholarship amount was already up to date',
            'data' => [
                'scholarship_id' => (int) $scholarship_id,
                'amount' => $normalized_amount,
                'amount_type' => $amount_type,
            ],
        ]);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Scholarship amount updated successfully',
        'data' => [
            'scholarship_id' => (int) $scholarship_id,
            'amount' => $normalized_amount,
            'amount_type' => $amount_type,
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
