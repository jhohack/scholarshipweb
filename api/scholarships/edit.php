<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(dirname(__DIR__));
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// GET: fetch scholarship for editing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $scholarship_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

    if (!$scholarship_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM scholarships WHERE id = ? LIMIT 1");
        $stmt->execute([$scholarship_id]);
        $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$scholarship) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Scholarship not found']);
            exit;
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $scholarship
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// POST: update scholarship
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scholarship_id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);

    if (!$scholarship_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
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

    if ($amount_type === 'None') {
        $amount = 0;
    }

    if (empty($name) || empty($description) || ($amount_type !== 'None' && empty($amount)) || empty($deadline)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Required fields missing: name, description, amount, deadline']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE scholarships SET
                name = ?,
                description = ?,
                benefits = ?,
                requirements = ?,
                application_requirements = ?,
                amount = ?,
                amount_type = ?,
                deadline = ?,
                available_slots = ?,
                category = ?,
                end_of_term = ?,
                accepting_new_applicants = ?,
                accepting_renewal_applicants = ?
            WHERE id = ?
        ");

        $stmt->execute([
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
            $scholarship_id
        ]);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Scholarship updated successfully',
            'data' => [
                'scholarship_id' => $scholarship_id,
                'name' => $name
            ]
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
?>
