<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
$student_id = $argv[1] ?? null;
if (!$student_id) { echo "USAGE: php check_last_application.php {student_id}\n"; exit(1); }
try {
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE student_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$student_id]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "APPLICATION:" . json_encode($app) . "\n";
    if ($app) {
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE application_id = ?");
        $stmt->execute([$app['id']]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "DOCUMENTS:" . json_encode($docs) . "\n";
    }
} catch (Exception $e) {
    echo "ERROR:" . $e->getMessage() . "\n";
}
