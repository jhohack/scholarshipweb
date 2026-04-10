<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

checkSessionTimeout();

$scholarship_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$scholarship_id) {
    header("Location: scholarships.php");
    exit();
}

// Check if user is a renewal applicant (to allow access to inactive/closed scholarships)
$is_renewal_applicant = false;
if (isset($_SESSION['user_id'])) {
    $u_stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $u_stmt->execute([$_SESSION['user_id']]);
    $sid = $u_stmt->fetchColumn();
    if ($sid) {
        // Only consider the student a 'Renewal Applicant' if their LATEST application is Active/Approved.
        // If they were Dropped/Rejected, they should start over as a New Applicant.
        $r_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM applications a
            WHERE a.student_id = ? 
            AND a.scholarship_id = ? 
            AND a.id = (
                SELECT MAX(sub.id) 
                FROM applications sub 
                WHERE sub.student_id = a.student_id 
                AND sub.scholarship_id = a.scholarship_id
            )
            AND a.status IN ('Active', 'Approved', 'For Renewal')
        ");
        $r_stmt->execute([$sid, $scholarship_id]);
        $is_renewal_applicant = ($r_stmt->fetchColumn() > 0);
    }
}

try {
    $stmt = $pdo->prepare("SELECT * FROM scholarships WHERE id = ?");
    $stmt->execute([$scholarship_id]);
    $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scholarship || ($scholarship['status'] !== 'active' && !$is_renewal_applicant)) {
        // Scholarship not found, or inactive and user is NOT a renewal applicant
        header("Location: scholarships.php");
        exit();
    }
} catch (PDOException $e) {
    // In a real app, log this error
    header("Location: scholarships.php?error=db");
    exit();
}

$page_title = htmlspecialchars($scholarship['name']);

// Determine application status
$deadline = new DateTime($scholarship['deadline']);
$now = new DateTime();
$is_open = $now <= $deadline;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - DVC Scholarship Hub</title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .details-header {
            background: linear-gradient(135deg, rgba(0, 58, 112, 0.8), rgba(13, 110, 253, 0.9)), url('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?q=80&w=2070&auto=format&fit=crop') no-repeat center center;
            background-size: cover;
            color: white;
            padding: 4rem 0;
            border-bottom: 8px solid var(--bs-primary);
        }
        .details-header h1 { font-weight: 700; }
        .details-header .lead { font-weight: 300; opacity: 0.9; }
        .detail-card {
            background: #fff;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e9ecef;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .detail-card h3 {
            font-weight: 600;
            color: var(--bs-primary);
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 0.75rem;
            margin-bottom: 1.5rem;
        }
        .requirements-list ul { padding-left: 1.2rem; }
        .requirements-list li { margin-bottom: 0.5rem; }
        .status-badge { font-size: 1rem; padding: 0.6em 1em; }
        .sidebar-card { position: sticky; top: 2rem; }
    </style>
</head>
<body>
    <?php include $base_path . '/public/header.php'; ?>

    <header class="details-header text-center" data-aos="fade-in">
        <div class="container">
            <h1 class="display-4"><?php echo htmlspecialchars($scholarship['name']); ?></h1>
            <p class="lead col-lg-8 mx-auto"><?php echo htmlspecialchars($scholarship['description']); ?></p>
        </div>
    </header>

    <main class="container py-5">
        <div class="row g-5">
            <div class="col-lg-8">
                <!-- Scholarship Benefits -->
                <div class="detail-card" data-aos="fade-up" data-aos-delay="100">
                    <h3><i class="bi bi-gem me-2"></i>Scholarship Benefits</h3>
                    <?php
                    $amt_type = $scholarship['amount_type'] ?? 'Peso';
                    $amt_val = $scholarship['amount'];
                    if ($amt_type === 'Percentage') {
                        $amt_display = number_format($amt_val, 2) . '%';
                    } elseif ($amt_type === 'None') {
                        $amt_display = 'None';
                    } else {
                        $amt_display = '₱' . number_format($amt_val, 2);
                    }
                    ?>
                    <p class="fs-4 mb-4">Financial Award: <strong class="text-success"><?php echo htmlspecialchars($amt_display); ?></strong></p>
                    <div class="requirements-list">
                        <?php echo !empty($scholarship['benefits']) ? nl2br(htmlspecialchars($scholarship['benefits'])) : '<p>Details about the benefits for this scholarship are coming soon.</p>'; ?>
                    </div>
                </div>

                <!-- Qualifications -->
                <div class="detail-card" data-aos="fade-up" data-aos-delay="200">
                    <h3><i class="bi bi-person-check-fill me-2"></i>Qualifications</h3>
                    <div class="requirements-list">
                        <?php echo !empty($scholarship['requirements']) ? nl2br(htmlspecialchars($scholarship['requirements'])) : '<p>Specific qualifications for this scholarship are not yet listed.</p>'; ?>
                    </div>
                </div>

                <!-- List of Requirements (from form) -->
                <div class="detail-card" data-aos="fade-up" data-aos-delay="300">
                    <h3><i class="bi bi-list-check me-2"></i>Application Requirements</h3>
                    <div class="requirements-list">
                        <?php echo !empty($scholarship['application_requirements']) ? nl2br(htmlspecialchars($scholarship['application_requirements'])) : '<p>The list of required documents and items for the application is not yet available.</p>'; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="sidebar-card" data-aos="fade-left" data-aos-delay="400">
                    <div class="detail-card text-center">
                        <h3 class="mt-0">Application Status</h3>
                        <?php if ($is_open): ?>
                            <span class="badge status-badge bg-success-soft text-success">Open</span>
                            <p class="text-muted mt-3 mb-1">Deadline:</p>
                            <p class="fw-bold fs-5"><?php echo date("F j, Y", strtotime($scholarship['deadline'])); ?></p>
                            <?php if (!empty($scholarship['end_of_term'])): ?>
                                <p class="text-muted mt-3 mb-1">End of Term:</p>
                                <p class="fw-bold fs-5 text-info"><?php echo date("F j, Y", strtotime($scholarship['end_of_term'])); ?></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge status-badge bg-danger-soft text-danger">Closed</span>
                            <p class="text-muted mt-3">The application deadline has passed.</p>
                        <?php endif; ?>
                        <hr>
                        <h5 class="fw-bold">Applicant Types</h5>
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <?php echo $scholarship['accepting_new_applicants'] ? '<i class="bi bi-check-circle-fill text-success"></i> New Applicants' : '<i class="bi bi-x-circle-fill text-muted"></i> Not Accepting New'; ?>
                            </li>
                            <li>
                                <?php echo $scholarship['accepting_renewal_applicants'] ? '<i class="bi bi-check-circle-fill text-success"></i> Renewal Applicants' : '<i class="bi bi-x-circle-fill text-muted"></i> Not Accepting Renewal'; ?>
                            </li>
                        </ul>
                        <div class="d-grid mt-4">
                            <a href="apply.php?id=<?php echo $scholarship['id']; ?><?php echo $is_renewal_applicant ? '&type=Renewal' : ''; ?>" class="btn btn-primary btn-lg <?php echo (!$is_open && !$is_renewal_applicant) ? 'disabled' : ''; ?>">
                                <i class="bi bi-pencil-square me-2"></i>Apply Now
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include $base_path . '/includes/footer.php'; ?>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({ duration: 800, once: true });
    </script>
</body>
</html>