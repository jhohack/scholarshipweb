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
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.student_id,
            a.scholarship_id,
            a.status,
            a.applicant_type,
            a.submitted_at,
            a.updated_at,
            a.remarks,
            a.scholarship_percentage,
            a.scholarship_amount,
            a.gwa,
            a.program,
            a.year_level,
            a.units_enrolled,
            a.student_status,
            a.year_program,
            s.student_name,
            s.school_id_number,
            s.email,
            s.phone,
            s.date_of_birth,
            s.address,
            s.student_type,
            s.program AS student_program,
            s.year_level AS student_year_level,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.contact_number,
            u.birthdate,
            u.school_id AS user_school_id,
            sch.name AS scholarship_name,
            sch.amount AS base_amount,
            sch.amount_type,
            sch.category,
            sch.deadline,
            sch.end_of_term,
            sch.requires_exam,
            sch.passing_score
        FROM applications a
        JOIN students s ON s.id = a.student_id
        LEFT JOIN users u ON u.id = s.user_id
        JOIN scholarships sch ON sch.id = a.scholarship_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$application_id]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Application not found']);
        exit;
    }

    $responses_stmt = $pdo->prepare("
        SELECT
            ar.id AS response_id,
            ff.field_label,
            ff.field_type,
            ar.response_value
        FROM application_responses ar
        JOIN form_fields ff ON ff.id = ar.form_field_id
        WHERE ar.application_id = ?
        ORDER BY ff.field_order ASC, ar.id ASC
    ");
    $responses_stmt->execute([$application_id]);
    $responses = $responses_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $documents_stmt = $pdo->prepare("
        SELECT
            id,
            file_name,
            file_path,
            uploaded_at
        FROM documents
        WHERE application_id = ?
        ORDER BY uploaded_at DESC, id DESC
    ");
    $documents_stmt->execute([$application_id]);
    $documents = [];
    foreach ($documents_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $document) {
        $file_path = trim((string) ($document['file_path'] ?? ''));
        $documents[] = [
            'id' => (int) ($document['id'] ?? 0),
            'file_name' => $document['file_name'] ?? '',
            'display_name' => formatDocumentDisplayName($document['file_name'] ?? ''),
            'file_key' => $file_path,
            'uploaded_at' => $document['uploaded_at'] ?? null,
        ];
    }

    $exam_stmt = $pdo->prepare("
        SELECT
            id,
            score,
            total_items,
            status,
            start_time,
            end_time
        FROM exam_submissions
        WHERE student_id = ? AND scholarship_id = ?
        ORDER BY COALESCE(end_time, start_time) DESC, id DESC
        LIMIT 1
    ");
    $exam_stmt->execute([
        (int) ($application['student_id'] ?? 0),
        (int) ($application['scholarship_id'] ?? 0),
    ]);
    $exam_summary = $exam_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $latest_reupload_requests = getDocumentReuploadRequestRows($pdo, null, (int) $application_id);

    echo json_encode([
        'success' => true,
        'data' => [
            'application' => $application,
            'responses' => $responses,
            'documents' => $documents,
            'exam_summary' => $exam_summary,
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
