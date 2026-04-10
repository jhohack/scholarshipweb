<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

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
        $stmt = $pdo->prepare("SELECT a.*, s.name AS student_name, s.email AS student_email FROM applications a JOIN students s ON a.student_id = s.id WHERE a.id = ?");
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$application) {
            $errors[] = "Application not found.";
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
                <p><strong>Submitted On:</strong> <?php echo htmlspecialchars($application['submitted_on']); ?></p>

                <h3>Uploaded Documents</h3>
                <?php if ($application['documents']): ?>
                    <ul>
                        <?php foreach (explode(',', $application['documents']) as $document): ?>
                            <li><a href="../../public/assets/uploads/<?php echo htmlspecialchars($document); ?>" target="_blank"><?php echo htmlspecialchars($document); ?></a></li>
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