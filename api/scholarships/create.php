<?php
/**
 * Create a new scholarship (portal admin module).
 * POST (FormData) mirroring edit.php's field set, plus exam settings.
 * Creates the scholarship and a default form row in one transaction and
 * returns the new scholarship id so the portal can navigate to it.
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

$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$benefits = trim($_POST['benefits'] ?? '');
$requirements = trim($_POST['requirements'] ?? '');
$application_requirements = trim($_POST['application_requirements'] ?? '');
$amount = filter_var($_POST['amount'] ?? 0, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
$amount_type = $_POST['amount_type'] ?? 'Peso';
$deadline = trim($_POST['deadline'] ?? '');
$available_slots = filter_input(INPUT_POST, 'available_slots', FILTER_SANITIZE_NUMBER_INT);
$category = trim($_POST['category'] ?? '');
$end_of_term = !empty($_POST['end_of_term']) ? trim($_POST['end_of_term']) : null;
$accepting_new = isset($_POST['accepting_new_applicants']) ? 1 : 0;
$accepting_renewal = isset($_POST['accepting_renewal_applicants']) ? 1 : 0;
$requires_exam = isset($_POST['requires_exam']) ? 1 : 0;
$passing_score = filter_input(INPUT_POST, 'passing_score', FILTER_SANITIZE_NUMBER_INT);
$exam_duration = filter_input(INPUT_POST, 'exam_duration', FILTER_SANITIZE_NUMBER_INT);

if ($amount_type === 'None') {
    $amount = 0;
}

$available_slots = ($available_slots === false || $available_slots === null || $available_slots === '')
    ? 10
    : (int) $available_slots;
$passing_score = ($passing_score === false || $passing_score === null || $passing_score === '')
    ? 75
    : (int) $passing_score;
$exam_duration = ($exam_duration === false || $exam_duration === null || $exam_duration === '')
    ? 60
    : (int) $exam_duration;

// Server-side validation (mirrors the portal form).
$validation_errors = [];
if (mb_strlen($name) < 3) {
    $validation_errors[] = 'Name must be at least 3 characters';
}
if ($description === '') {
    $validation_errors[] = 'Description is required';
}
if ($deadline === '') {
    $validation_errors[] = 'Deadline is required';
}
if ($amount_type !== 'None' && (float) $amount <= 0) {
    $validation_errors[] = 'Amount must be greater than zero';
}
if ($available_slots < 1) {
    $validation_errors[] = 'Available slots must be at least 1';
}
if ($deadline !== '' && strtotime($deadline) !== false && strtotime($deadline) < strtotime(date('Y-m-d'))) {
    $validation_errors[] = 'Deadline cannot be in the past';
}
if ($end_of_term !== null && strtotime($end_of_term) !== false && strtotime($deadline) !== false
    && strtotime($end_of_term) < strtotime($deadline)) {
    $validation_errors[] = 'End of term cannot be before the deadline';
}

if (!empty($validation_errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode('. ', $validation_errors)]);
    exit;
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

try {
    $pdo->beginTransaction();

    $insert_sql = "
        INSERT INTO scholarships (
            name, description, benefits, requirements, application_requirements,
            amount, amount_type, deadline, available_slots, category,
            end_of_term, accepting_new_applicants, accepting_renewal_applicants,
            requires_exam, passing_score, passing_grade, exam_duration, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
    ";

    $params = [
        $name,
        $description,
        $benefits,
        $requirements,
        $application_requirements,
        $amount,
        $amount_type,
        $deadline,
        $available_slots,
        $category,
        $end_of_term,
        $accepting_new,
        $accepting_renewal,
        $requires_exam,
        $passing_score,
        $passing_score,
        $exam_duration,
    ];

    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare($insert_sql . ' RETURNING id');
        $stmt->execute($params);
        $new_id = (int) $stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare($insert_sql);
        $stmt->execute($params);
        $new_id = (int) $pdo->lastInsertId();
    }

    // Create a default form so form management works immediately.
    $form_stmt = $pdo->prepare("INSERT INTO forms (scholarship_id, title, description) VALUES (?, ?, ?)");
    $form_stmt->execute([$new_id, $name . ' Application Form', 'Default application form']);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Scholarship created successfully',
        'data' => [
            'scholarship_id' => $new_id,
            'id' => $new_id,
            'name' => $name,
        ],
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
