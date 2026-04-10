<?php
ob_start(); // Start output buffering to prevent whitespace/injections from breaking JSON
ini_set('display_errors', 0); // Disable error display for AJAX to prevent JSON breakage

if (session_status() === PHP_SESSION_NONE) {
    $is_admin_request = (isset($_GET['chat_context']) && $_GET['chat_context'] === 'sys_bridge') || (isset($_POST['chat_context']) && $_POST['chat_context'] === 'sys_bridge');
    if ($is_admin_request) {
        // Use the specific admin session name
        session_name('scholarship_admin');
    }
    session_start();
}

$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
ini_set('display_errors', 0); // Re-disable errors immediately after config.php enables them
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

if (!isLoggedIn()) {
    @ob_clean(); // Clean buffer before outputting JSON
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$is_admin = isAdmin();
$response = ['success' => false, 'message' => 'Invalid action.'];

try {
    switch ($action) {
        case 'get_or_create_student_conversation':
            if ($is_admin) {
                $response['message'] = 'Admins cannot use this action.';
                break;
            }

            $pdo->beginTransaction();

            // Find existing conversation
            $stmt = $pdo->prepare("SELECT * FROM conversations WHERE student_user_id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

            // If not found, create one
            if (!$conversation) {
                $subject = "Student Inquiry";
                $stmt_create = $pdo->prepare("INSERT INTO conversations (student_user_id, subject) VALUES (?, ?)");
                $stmt_create->execute([$user_id, $subject]);
                $conversation_id = $pdo->lastInsertId();
                
                // Fetch the newly created conversation
                $stmt->execute([$user_id]);
                $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $conversation_id = $conversation['id'];
            }

            // Mark messages as read
            $update_stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ?");
            $update_stmt->execute([$conversation_id, $user_id]);

            // Fetch messages for this conversation
            $msg_stmt = $pdo->prepare("
                    SELECT m.*, u.role, u.profile_picture_path
                FROM messages m 
                JOIN users u ON m.sender_id = u.id
                WHERE m.conversation_id = ? 
                ORDER BY m.created_at ASC
            ");
            $msg_stmt->execute([$conversation_id]);
            $messages = $msg_stmt->fetchAll(PDO::FETCH_ASSOC);

            $pdo->commit();

            $response = ['success' => true, 'conversation' => $conversation, 'messages' => $messages];
            break;

        case 'send_message':
            $conversation_id = filter_input(INPUT_POST, 'conversation_id', FILTER_SANITIZE_NUMBER_INT);
            $message = trim($_POST['message'] ?? '');
            $attachment_path = null;

            // If student is sending, we might need to find/create the conversation
            if (!$is_admin && !$conversation_id) {
                $stmt = $pdo->prepare("SELECT id FROM conversations WHERE student_user_id = ? LIMIT 1");
                $stmt->execute([$user_id]);
                $conversation_id = $stmt->fetchColumn();

                if (!$conversation_id) {
                    $stmt_create = $pdo->prepare("INSERT INTO conversations (student_user_id, subject) VALUES (?, 'Student Inquiry')");
                    $stmt_create->execute([$user_id]);
                    $conversation_id = $pdo->lastInsertId();
                }
            }

            if (!$conversation_id) {
                $response['message'] = 'Conversation ID is missing.';
                break;
            }

            // Verify user is part of this conversation
            $check_stmt = $pdo->prepare("SELECT student_user_id FROM conversations WHERE id = ?");
            $check_stmt->execute([$conversation_id]);
            $convo_student_id = $check_stmt->fetchColumn();

            if (!$is_admin && $convo_student_id != $user_id) {
                $response['message'] = 'Access denied.';
                break;
            }

            if (empty($message) && empty($_FILES['attachment'])) {
                $response['message'] = 'Message or attachment is required.';
                break;
            }

            // Handle file upload
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = $base_path . '/public/uploads/chat_attachments/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                if (in_array($_FILES['attachment']['type'], $allowed_types)) {
                    $safe_filename = preg_replace('/[^A-Za-z0-9.\-]/', '_', basename($_FILES['attachment']['name']));
                    $new_filename = 'chat_' . $conversation_id . '_' . uniqid() . '_' . $safe_filename;
                    $destination = $upload_dir . $new_filename;

                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $destination)) {
                        $attachment_path = 'uploads/chat_attachments/' . $new_filename;
                    } else {
                        $response['message'] = 'Failed to upload attachment.';
                        @ob_clean();
                        header('Content-Type: application/json');
                        echo json_encode($response);
                        exit();
                    }
                } else {
                    $response['message'] = 'Invalid file type. Only JPG, PNG, or GIF are allowed.';
                    @ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode($response);
                    exit();
                }
            }

            $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_text, attachment_path) VALUES (?, ?, ?, ?)");
            $stmt->execute([$conversation_id, $user_id, $message, $attachment_path]);

            // Update conversation timestamp
            $update_stmt = $pdo->prepare("UPDATE conversations SET updated_at = NOW(), status = ? WHERE id = ?");
            $new_status = $is_admin ? 'pending_student' : 'pending_admin';
            $update_stmt->execute([$new_status, $conversation_id]);

            $response = ['success' => true, 'message' => 'Message sent.', 'conversation_id' => $conversation_id];
            break;

        case 'get_messages':
            // Check both GET and POST for the ID to support WAF bypass
            $conversation_id = filter_input(INPUT_GET, 'conversation_id', FILTER_SANITIZE_NUMBER_INT) ?: filter_input(INPUT_POST, 'conversation_id', FILTER_SANITIZE_NUMBER_INT);
            if (!$conversation_id) {
                $response['message'] = 'Conversation ID is missing.';
                break;
            }

            // Verify user is part of this conversation
            $check_stmt = $pdo->prepare("SELECT student_user_id FROM conversations WHERE id = ?");
            $check_stmt->execute([$conversation_id]);
            $convo_student_id = $check_stmt->fetchColumn();

            if (!$is_admin && $convo_student_id != $user_id) {
                $response['message'] = 'Access denied.';
                break;
            }

            // Mark messages as read
            $update_stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ?");
            $update_stmt->execute([$conversation_id, $user_id]);

            // Fetch messages
            $stmt = $pdo->prepare("
                    SELECT m.*, u.role, u.profile_picture_path
                FROM messages m 
                JOIN users u ON m.sender_id = u.id
                WHERE m.conversation_id = ? 
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$conversation_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response = ['success' => true, 'messages' => $messages];
            break;

        case 'get_unread_count':
            $count = getUnreadMessageCount($pdo, $user_id);
            $response = ['success' => true, 'unread_count' => $count];
            break;

        case 'get_conversations':
            if (!$is_admin) {
                $response['message'] = 'Access denied for students.';
                break;
            }
            $stmt = $pdo->prepare("
                SELECT c.*, u.first_name, u.last_name, u.profile_picture_path,
                    (SELECT m.message_text FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message,
                    (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.sender_id = c.student_user_id) as unread_count
                FROM conversations c
                JOIN users u ON c.student_user_id = u.id
                ORDER BY c.updated_at DESC
            ");
            $stmt->execute();
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response = ['success' => true, 'conversations' => $conversations];
            break;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $response['message'] = 'Server error: ' . $e->getMessage();
    error_log('Chat Error: ' . $e->getMessage());
}

@ob_clean(); // Clean buffer to remove any previous output (like free hosting analytics)
header('Content-Type: application/json');
echo json_encode($response);
exit(); // Stop execution immediately to prevent hosting providers from injecting analytics/ads
?>