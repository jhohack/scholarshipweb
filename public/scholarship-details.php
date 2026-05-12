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
portalSendPageCacheHeaders(300, isLoggedIn());

$scholarship_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$scholarship_id) {
    header("Location: scholarships.php");
    exit();
}

// Check if user is a renewal applicant (to allow access to inactive/closed scholarships)
$is_renewal_applicant = false;
if (isset($_SESSION['user_id'])) {
    $sid = getCurrentStudentId($pdo, (int) $_SESSION['user_id']);
    if ($sid) {
        $renewalCacheKey = 'public.scholarship.renewal:' . (int) $sid . ':' . (int) $scholarship_id;
        $is_renewal_applicant = portalCacheRemember($renewalCacheKey, 300, function () use ($pdo, $sid, $scholarship_id) {
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
                AND a.status IN ('Active', 'Approved', 'Accepted', 'For Renewal')
            ");
            $r_stmt->execute([$sid, $scholarship_id]);
            return ($r_stmt->fetchColumn() > 0);
        });
    }
}

$scholarship = portalCacheRemember('public.scholarship:' . (int) $scholarship_id, 300, function () use ($pdo, $scholarship_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM scholarships WHERE id = ?");
        $stmt->execute([$scholarship_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    } catch (PDOException $e) {
        return [];
    }
});

if (!$scholarship || ($scholarship['status'] !== 'active' && !$is_renewal_applicant)) {
    header("Location: scholarships.php");
    exit();
}

$page_title = htmlspecialchars($scholarship['name']);

// Determine application status
$deadline = new DateTime($scholarship['deadline']);
$now = new DateTime();
$is_open = $now <= $deadline;
$accepting_new_applicants = !empty($scholarship['accepting_new_applicants']);
$accepting_renewal_applicants = !empty($scholarship['accepting_renewal_applicants']);
$capacity = getScholarshipCapacitySummary($pdo, (int) $scholarship_id, (int) ($scholarship['available_slots'] ?? 0));
$approved_count = (int) ($capacity['approved_count'] ?? 0);
$occupied_count = (int) ($capacity['occupied_count'] ?? 0);
$remaining_slots = (int) ($capacity['remaining_slots'] ?? 0);
$slots_full = !empty($capacity['is_full']);
$apply_button_label = $is_renewal_applicant ? 'Renew Scholarship' : 'Apply Now';
$apply_button_href = 'apply.php?id=' . $scholarship['id'];
$can_submit_current_user = false;
$application_notice = '';

if ($is_renewal_applicant) {
    $can_submit_current_user = $accepting_renewal_applicants;
    if (!$can_submit_current_user) {
        $application_notice = 'Renewal applications are currently closed for this scholarship.';
    }
} else {
    $can_submit_current_user = $scholarship['status'] === 'active' && $is_open && $accepting_new_applicants && !$slots_full;
    if ($slots_full) {
        $application_notice = 'This scholarship has reached its slot limit and is currently full.';
    } elseif (!$accepting_new_applicants) {
        $application_notice = 'This scholarship is not accepting new applicants right now.';
    } elseif (!$is_open) {
        $application_notice = 'The deadline for new applicants has already passed.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - DVC Scholarship Hub</title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .details-header {
            background:
                linear-gradient(135deg, rgba(0, 58, 112, 0.84), rgba(13, 110, 253, 0.88)),
                url('assets/images/hero-illustration.svg') no-repeat center center;
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
                            <span class="badge status-badge <?php echo $slots_full ? 'bg-danger-soft text-danger' : 'bg-success-soft text-success'; ?>">
                                <?php echo $slots_full ? 'Full' : 'Open'; ?>
                            </span>
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
                        <div class="p-3 rounded bg-light border mt-3 text-start">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold">Slot Usage</span>
                                <span class="badge <?php echo $slots_full ? 'bg-danger' : 'bg-primary'; ?>">
                                    <?php echo htmlspecialchars($occupied_count . '/' . (int) $scholarship['available_slots']); ?>
                                </span>
                            </div>
                            <div class="small text-muted mb-1">Approved scholars: <strong><?php echo (int) $approved_count; ?></strong></div>
                            <div class="small text-muted">Remaining slots: <strong><?php echo (int) $remaining_slots; ?></strong></div>
                        </div>
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
                            <?php if ($can_submit_current_user): ?>
                                <a href="<?php echo htmlspecialchars($apply_button_href); ?>" class="btn btn-primary btn-lg">
                                    <i class="bi bi-pencil-square me-2"></i><?php echo htmlspecialchars($apply_button_label); ?>
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn btn-primary btn-lg" disabled>
                                    <i class="bi bi-pencil-square me-2"></i><?php echo htmlspecialchars($apply_button_label); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if ($application_notice !== ''): ?>
                            <p class="small text-muted mt-3 mb-0"><?php echo htmlspecialchars($application_notice); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include $base_path . '/includes/footer.php'; ?>
    <script>
        AOS.init({ duration: 800, once: true });
    </script>
</body>
</html>
