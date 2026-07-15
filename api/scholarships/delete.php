<?php
/**
 * Delete a scholarship (portal admin module).
 * Guarded hard delete: only permitted when the scholarship has zero
 * applications. Otherwise the client is instructed to archive instead,
 * so applicant history and documents are never orphaned (business rule B7).
 */
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

if (!$scholarship_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name FROM scholarships WHERE id = ? LIMIT 1");
    $stmt->execute([$scholarship_id]);
    $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scholarship) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Scholarship not found']);
        exit;
    }

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE scholarship_id = ?");
    $count_stmt->execute([$scholarship_id]);
    $application_count = (int) $count_stmt->fetchColumn();

    if ($application_count > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => "Cannot delete a scholarship that has {$application_count} application(s). Archive it instead to preserve applicant history.",
        ]);
        exit;
    }

    // Zero applications: safe to hard delete. FK cascades remove forms,
    // form_fields, and exam_questions tied to this scholarship.
    $delete_stmt = $pdo->prepare("DELETE FROM scholarships WHERE id = ?");
    $delete_stmt->execute([$scholarship_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Scholarship deleted successfully',
        'data' => [
            'scholarship_id' => $scholarship_id,
            'scholarship_name' => $scholarship['name'],
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
