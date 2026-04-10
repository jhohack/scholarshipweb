<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
try {
    $stmt = $pdo->query("SELECT id, name FROM scholarships ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "FOUND:" . $row['id'] . "|" . $row['name'];
    } else {
        echo "NONE";
    }
} catch (Exception $e) {
    echo "ERROR:" . $e->getMessage();
}
