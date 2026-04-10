<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

checkSessionTimeout();

// Ensure the user is logged in as a student
if (!isStudent()) {
    header("Location: ../public/login.php");
    exit();
}

// Get the current user's ID
$user_id = $_SESSION['user_id'];
$student_id = null;

// Fetch applications for the logged-in student
try {
    // First, get the student_id from the user_id
    $student_stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $student_stmt->execute([$user_id]);
    $student = $student_stmt->fetch();

    if ($student) {
        $student_id = $student['id'];
        $stmt = $pdo->prepare("
            SELECT s.name AS scholarship_name, a.status, a.submitted_at 
            FROM applications a 
            JOIN scholarships s ON a.scholarship_id = s.id 
            WHERE a.student_id = ? 
            ORDER BY COALESCE(a.submitted_at, a.updated_at) DESC
        ");
        $stmt->execute([$student_id]);
        $applications = $stmt->fetchAll();
    } else {
        $applications = [];
    }
} catch (PDOException $e) {
    $applications = [];
    // Log the error in production
    // error_log($e->getMessage());
}

$page_title = 'My Applications';
include 'header.php';
?>

<div class="page-header" data-aos="fade-down">
    <h1 class="fw-bold">My Applications</h1>
    <p class="text-muted">Track the status of all your scholarship submissions.</p>
</div>

<div class="content-block" data-aos="fade-up" data-aos-delay="100">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Application History</h3>
        <span class="badge bg-primary-soft text-primary fs-6 rounded-pill"><?php echo count($applications); ?> Applications</span>
    </div>
    <div class="table-responsive">
        <?php if (empty($applications)): ?>
            <div class="text-center py-5">
                <i class="bi bi-folder2-open fs-1 text-muted"></i>
                <h5 class="mt-3">No Applications Found</h5>
                <p class="text-muted">You haven't applied for any scholarships yet. Start exploring to find your match!</p>
                <a href="../public/scholarships.php" class="btn btn-primary mt-2">Find Scholarships</a>
            </div>
        <?php else: ?>
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Scholarship Name</th>
                        <th>Submitted On</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applications as $application): ?>
                        <tr>
                            <?php
                            // Standardize the status display for the user using the global function
                            $display_status = formatApplicationStatus($application['status']);

                            // Map status to a specific Bootstrap background color for highlighting
                            $badge_bg = match($display_status) {
                                'Approved' => 'bg-success',
                                'Rejected' => 'bg-danger',
                                'Pending' => 'bg-secondary',
                                'Under Review' => 'bg-info',
                                'Renewal Request' => 'bg-primary', // Blue highlight
                                'Drop Requested' => 'bg-warning text-dark', // Yellow highlight
                                'Pending Exam' => 'bg-dark',
                                'Passed' => 'bg-success',
                                'Failed' => 'bg-danger',
                                default => 'bg-light text-dark',
                            };

                            // Map status to a specific icon for better visual feedback
                            $status_icons = [
                                'Approved' => 'bi-patch-check-fill',
                                'Rejected' => 'bi-x-circle-fill',
                                'Under Review' => 'bi-search',
                                'Pending' => 'bi-hourglass-split',
                                'Renewal Request' => 'bi-arrow-repeat',
                                'Drop Requested' => 'bi-exclamation-triangle-fill',
                                'Pending Exam' => 'bi-pencil-square',
                            ];
                            $icon_class = $status_icons[$display_status] ?? 'bi-question-circle-fill';
                            ?>
                            <td><div class="fw-bold"><?php echo htmlspecialchars($application['scholarship_name']); ?></div></td>
                            <td class="text-muted"><?php echo htmlspecialchars(date('F j, Y', strtotime($application['submitted_at']))); ?></td>
                            <td class="text-center">
                                <span class="badge <?php echo $badge_bg; ?>"><i class="bi <?php echo $icon_class; ?> me-1"></i><?php echo htmlspecialchars($display_status); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>