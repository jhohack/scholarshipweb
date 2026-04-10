<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check if the user is an admin
if (!isAdmin()) {
    header("Location: " . BASE_URL . "/public/login.php");
    exit();
}

// Fetch applications from the database
$applications = [];
try {
    $stmt = $pdo->query("SELECT a.id, a.student_id, a.scholarship_id, a.status, s.name AS scholarship_name, u.name AS student_name FROM applications a JOIN scholarships s ON a.scholarship_id = s.id JOIN users u ON a.student_id = u.id");
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle database error
    $error = "Could not retrieve applications. Please try again later.";
}

// Include header
include_once __DIR__ . '/../../includes/header.php';
?>

<div class="admin-container">
    <h2>Applications List</h2>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Student Name</th>
                <th>Scholarship Name</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($applications as $application): ?>
                <tr>
                    <td><?php echo htmlspecialchars($application['id']); ?></td>
                    <td><?php echo htmlspecialchars($application['student_name']); ?></td>
                    <td><?php echo htmlspecialchars($application['scholarship_name']); ?></td>
                    <td><?php echo htmlspecialchars($application['status']); ?></td>
                    <td>
                        <a href="view.php?id=<?php echo htmlspecialchars($application['id']); ?>" class="button">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>