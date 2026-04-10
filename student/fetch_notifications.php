<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $base_path . '/includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch student_id associated with this user
$stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
$stmt->execute([$user_id]);
$student_id = $stmt->fetchColumn();

if (!$student_id) {
    echo json_encode(['notifications' => [], 'unread_count' => 0]);
    exit;
}

try {
    // Handle "Mark as Read"
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
        $notif_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($notif_id) {
            $update = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND student_id = ?");
            $update->execute([$notif_id, $student_id]);
        } else {
            // Mark ALL as read for this student
            $update = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE student_id = ?");
            $update->execute([$student_id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // Fetch Notifications
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE student_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$student_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add title if missing (since schema doesn't have it)
    foreach ($notifications as &$notif) {
        if (!isset($notif['title']) || $notif['title'] === null) {
            $notif['title'] = 'System Notification';
        }
        if (!isset($notif['message']) || $notif['message'] === null) {
            $notif['message'] = '';
        }
    }

    // Count Unread
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE student_id = ? AND is_read = 0");
    $countStmt->execute([$student_id]);
    $unread_count = $countStmt->fetchColumn();

    echo json_encode([
        'notifications' => $notifications,
        'unread_count' => $unread_count
    ]);

} catch (PDOException $e) {
    error_log("Notification Error: " . $e->getMessage());
    echo json_encode(['notifications' => [], 'unread_count' => 0]);
}
