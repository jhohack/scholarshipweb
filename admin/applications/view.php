<?php
$base_path = dirname(__DIR__, 2);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';
require_once $base_path . '/includes/auth.php';

// Check if the user is logged in and has admin privileges
if (!isAdmin()) {
    header("Location: ../../public/login.php");
    exit();
}

$applicationId = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
$application = null;
$errors = [];

if ($applicationId) {
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, st.student_name, st.email AS student_email, sch.name AS scholarship_name
            FROM applications a
            JOIN students st ON a.student_id = st.id
            JOIN scholarships sch ON a.scholarship_id = sch.id
            WHERE a.id = ?
        ");
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$application) {
            $errors[] = "Application not found.";
        } else {
            $stmt = $pdo->prepare("SELECT file_name, file_path FROM documents WHERE application_id = ? ORDER BY id ASC");
            $stmt->execute([$applicationId]);
            $application['documents_list'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $errors[] = "A database error occurred. Please try again later.";
        // Log the error in production
    }
} else {
    $errors[] = "Invalid application ID.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Application</title>
    <link rel="stylesheet" href="../../public/assets/css/style.css">
</head>
<body>
    <div class="container">
        <h2>Application Details</h2>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($application): ?>
            <div class="application-details">
                <h3>Student Information</h3>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($application['student_name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($application['student_email']); ?></p>

                <h3>Application Information</h3>
                <p><strong>Scholarship:</strong> <?php echo htmlspecialchars($application['scholarship_name']); ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars($application['status']); ?></p>
                <p><strong>Submitted On:</strong> <?php echo htmlspecialchars($application['submitted_at'] ?? ''); ?></p>

                <h3>Uploaded Documents</h3>
                <?php if (!empty($application['documents_list'])): ?>
                    <ul>
                        <?php foreach ($application['documents_list'] as $document): ?>
                            <li>
                                <a href="<?php echo htmlspecialchars(storedFilePathToUrl($document['file_path'] ?? '')); ?>" target="_blank">
                                    <?php echo htmlspecialchars($document['file_name'] ?? 'Document'); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No documents uploaded.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <a href="list.php" class="button-secondary">Back to Applications</a>
    </div>
</body>
</html>
