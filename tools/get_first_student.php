<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
try {
    $stmt = $pdo->query("SELECT id, email, password FROM users WHERE role = 'student' ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo json_encode($row);
    } else {
        echo json_encode(['error' => 'none found']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
