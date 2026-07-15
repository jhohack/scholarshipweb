<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(dirname(__DIR__));
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// GET: fetch form fields
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $scholarship_id = filter_input(INPUT_GET, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);

    if (!$scholarship_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
        exit;
    }

    try {
        // Get or create form for this scholarship
        $form_stmt = $pdo->prepare("SELECT * FROM forms WHERE scholarship_id = ? LIMIT 1");
        $form_stmt->execute([$scholarship_id]);
        $form = $form_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$form) {
            $insert_stmt = $pdo->prepare("
                INSERT INTO forms (scholarship_id, title, description)
                VALUES (?, ?, ?)
            ");
            $insert_stmt->execute([$scholarship_id, 'Application Form', '']);
            $form_id = $pdo->lastInsertId();

            $form = [
                'id' => $form_id,
                'scholarship_id' => $scholarship_id,
                'title' => 'Application Form',
                'description' => ''
            ];
        }

        // Get form fields
        $fields_stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY field_order ASC");
        $fields_stmt->execute([$form['id']]);
        $fields = $fields_stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'form' => $form,
                'fields' => $fields
            ]
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// POST: add/delete/update form fields
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    $scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);

    if (!$scholarship_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
        exit;
    }

    try {
        // Get form for this scholarship
        $form_stmt = $pdo->prepare("SELECT id FROM forms WHERE scholarship_id = ? LIMIT 1");
        $form_stmt->execute([$scholarship_id]);
        $form = $form_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$form) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Form not found']);
            exit;
        }

        if ($post_action === 'add_field') {
            $field_label = trim($_POST['field_label'] ?? '');
            $field_type = $_POST['field_type'] ?? 'text';
            $is_required = isset($_POST['is_required']) ? 1 : 0;
            $field_options = $_POST['field_options'] ?? '';

            if (empty($field_label)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Field label is required']);
                exit;
            }

            // Get max field order
            $order_stmt = $pdo->prepare("SELECT MAX(field_order) as max_order FROM form_fields WHERE form_id = ?");
            $order_stmt->execute([$form['id']]);
            $order_result = $order_stmt->fetch(PDO::FETCH_ASSOC);
            $field_order = ($order_result['max_order'] ?? 0) + 1;

            $stmt = $pdo->prepare("
                INSERT INTO form_fields (form_id, field_label, field_type, is_required, field_options, field_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$form['id'], $field_label, $field_type, $is_required, $field_options, $field_order]);

            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Field added']);
        } elseif ($post_action === 'delete_field') {
            $field_id = filter_input(INPUT_POST, 'field_id', FILTER_SANITIZE_NUMBER_INT);

            if (!$field_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid field ID']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM form_fields WHERE id = ? AND form_id = ?");
            $stmt->execute([$field_id, $form['id']]);

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Field deleted']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
?>
