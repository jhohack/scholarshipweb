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

$application_id = filter_input(INPUT_GET, 'application_id', FILTER_SANITIZE_NUMBER_INT);

if (!$application_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing application ID']);
    exit;
}

try {
    $application_stmt = $pdo->prepare("
        SELECT
            a.id,
            a.status,
            a.remarks,
            a.submitted_at,
            a.updated_at,
            s.student_name,
            sch.name AS scholarship_name
        FROM applications a
        JOIN students s ON s.id = a.student_id
        JOIN scholarships sch ON sch.id = a.scholarship_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $application_stmt->execute([$application_id]);
    $application = $application_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Application not found']);
        exit;
    }

    $history_stmt = $pdo->prepare("
        SELECT
            id,
            old_status,
            new_status,
            remarks,
            changed_by,
            changed_via,
            created_at
        FROM application_status_history
        WHERE application_id = ?
        ORDER BY created_at DESC, id DESC
    ");
    $history_stmt->execute([$application_id]);
    $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $latest_reupload_requests = getDocumentReuploadRequestRows($pdo, null, (int) $application_id);

    echo json_encode([
        'success' => true,
        'data' => [
            'application' => $application,
            'history' => $history,
            'latest_reupload_requests' => $latest_reupload_requests,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
    ]);
}
?>
