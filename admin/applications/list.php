<?php
$base_path = dirname(__DIR__, 2);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';
require_once $base_path . '/includes/auth.php';

// Check if the user is an admin
if (!isAdmin()) {
    header("Location: " . BASE_URL . "/public/login.php");
    exit();
}

// Fetch applications from the database
$applications = [];
try {
    $stmt = $pdo->query("
        SELECT a.id, a.student_id, a.scholarship_id, a.status, a.submitted_at,
               s.name AS scholarship_name,
               st.student_name
        FROM applications a
        JOIN scholarships s ON a.scholarship_id = s.id
        JOIN students st ON a.student_id = st.id
        ORDER BY a.submitted_at DESC, a.id DESC
    ");
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle database error
    $error = "Could not retrieve applications. Please try again later.";
}

// Include header
include_once $base_path . '/includes/header.php';
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

<?php include_once $base_path . '/includes/footer.php'; ?>
