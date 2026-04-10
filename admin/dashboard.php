<?php
if (session_status() === PHP_SESSION_NONE) {
    // Enable URL-based session IDs to allow multiple accounts in the same browser (multitasking)
    ini_set('session.use_trans_sid', 1);
    ini_set('session.use_only_cookies', 0);
    // Use a unique session name for Admin to allow simultaneous login with Student portal (multitasking)
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(__DIR__);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

checkSessionTimeout();

// Check if the user is logged in as an admin or staff
if (!isAdmin()) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
            header("Location: ../student/dashboard.php");
        } else {
            header("Location: login.php");
        }
        exit();
    }
    
    // Staff Permission Check
    $allowed_pages = $_SESSION['permissions'] ?? [];
    if (!in_array('dashboard.php', $allowed_pages)) {
         // If they don't have dashboard access, try to find the first page they DO have access to
         foreach ($allowed_pages as $page) {
             header("Location: $page");
             exit();
         }
         die("Access Denied: No permissions assigned.");
    }
}

// --- Fetch Enhanced Statistics for the Dashboard ---
$total_applicants = 0;
$new_applicants = 0;
$renewal_applicants = 0;
$total_scholarships = 0;
$status_summary = [
    'Approved' => 0,
    'Pending' => 0,
];
$recent_applications = [];
$application_trend_data = [];

try {
    // Fetch statistics based on unique students (latest application per student)
    $stats_overview = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN a.applicant_type = 'New' THEN 1 ELSE 0 END), 0) as new_apps,
            COALESCE(SUM(CASE WHEN a.applicant_type = 'Renewal' THEN 1 ELSE 0 END), 0) as renewal_apps
        FROM applications a
        INNER JOIN (
            SELECT student_id, MAX(id) as max_id
            FROM applications
            GROUP BY student_id
        ) latest ON a.id = latest.max_id
    ")->fetch(PDO::FETCH_ASSOC);

    $total_applicants = $stats_overview['total'] ?? 0;
    $new_applicants = $stats_overview['new_apps'] ?? 0;
    $renewal_applicants = $stats_overview['renewal_apps'] ?? 0;

    // Total available scholarships
    $total_scholarships = $pdo->query("SELECT COUNT(*) FROM scholarships WHERE status = 'active'")->fetchColumn();

    // Application status summary
    // Fix: Count unique students based on their latest application status
    $status_stmt = $pdo->query("
        SELECT a.status, COUNT(*) as count 
        FROM applications a
        INNER JOIN (
            SELECT student_id, MAX(id) as max_id
            FROM applications
            GROUP BY student_id
        ) latest ON a.id = latest.max_id
        GROUP BY a.status
    ");
    while ($row = $status_stmt->fetch(PDO::FETCH_ASSOC)) {
        // Map database statuses to dashboard categories
        $status = formatApplicationStatus($row['status']);
        if (isset($status_summary[$status])) {
            $status_summary[$status] += $row['count'];
        }
    }

    // Fetch last 5 recent applications for the activity feed
    $stmt = $pdo->query("SELECT a.id, a.status, st.student_name, s.name as scholarship_name, a.submitted_at as created_at FROM applications a JOIN students st ON a.student_id = st.id JOIN scholarships s ON a.scholarship_id = s.id ORDER BY a.submitted_at DESC LIMIT 5");
    $recent_applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch application counts for the last 7 days for the chart
    $trend_stmt = $pdo->query("
        SELECT DATE(submitted_at) as application_date, COUNT(*) as count
        FROM applications
        WHERE submitted_at >= CURDATE() - INTERVAL 6 DAY
        GROUP BY DATE(submitted_at)
        ORDER BY application_date ASC
    ");
    $daily_counts = $trend_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Prepare data for Chart.js, filling in days with 0 applications
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $application_trend_data['labels'][] = date('M d', strtotime($date));
        $application_trend_data['data'][] = $daily_counts[$date] ?? 0;
    }
} catch (PDOException $e) {
    // Silently fail on dashboard widgets, but log the error
    error_log("Admin Dashboard Error: " . $e->getMessage());
}
$page_title = 'Admin Dashboard';
include 'header.php';
?>

<!-- Internal CSS for new dashboard components -->
<style>
    .activity-feed .feed-item {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        padding: 1rem 0;
        border-bottom: 1px solid #e9ecef;
        transition: background-color 0.3s ease;
    }
    .activity-feed .feed-item:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }
    .activity-feed .feed-item:first-child {
        padding-top: 0;
    }
    .activity-feed .feed-item:hover {
        background-color: #f8f9fa;
    }
    .activity-feed .feed-icon {
        width: 40px;
        height: 40px;
        flex-shrink: 0;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #e7f5ff;
        color: #0d6efd;
    }
    .activity-feed .feed-content p {
        margin-bottom: 0.25rem;
        color: #212529;
    }
    .activity-feed .feed-content .text-muted {
        font-size: 0.85rem;
    }
    .chart-container {
        position: relative;
        height: 350px;
        width: 100%;
    }
</style>

<!-- Page Header -->
<div class="dashboard-header mb-5" data-aos="fade-down">
    <div class="d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h1 class="fw-bold">Dashboard</h1>
            <p class="text-muted mb-0">Welcome back, <strong><?php echo htmlspecialchars($_SESSION['name']); ?></strong>! Here's your system overview.</p>
        </div>
        <div class="dashboard-date text-muted fs-5 mt-2 mt-md-0">
            <i class="bi bi-calendar-event me-2"></i><span><?php echo date("l, F j, Y"); ?></span>
        </div>
    </div>
</div>

<!-- Statistics Cards Section -->
<div class="row g-4 mb-5" data-aos="fade-up">
    <!-- Total Applicants Card -->
    <div class="col-lg-3 col-md-6" data-aos-delay="100">
        <div class="stat-card stat-card-primary">
            <div class="stat-icon bg-primary"><i class="bi bi-people-fill"></i></div>
            <div class="stat-card-body">
                <div class="stat-label">Total Applicants</div>
                <div class="stat-value"><?php echo htmlspecialchars($total_applicants); ?></div>
                <p class="stat-description mb-0">New: <?php echo htmlspecialchars($new_applicants); ?> | Renewal: <?php echo htmlspecialchars($renewal_applicants); ?></p>
            </div>
        </div>
    </div>

    <!-- Available Scholarships Card -->
    <div class="col-lg-3 col-md-6" data-aos-delay="200">
        <div class="stat-card stat-card-success">
            <div class="stat-icon bg-success"><i class="bi bi-award-fill"></i></div>
            <div class="stat-card-body">
                <div class="stat-label">Available Scholarships</div>
                <div class="stat-value"><?php echo htmlspecialchars($total_scholarships); ?></div>
                <?php if (canAccess('scholarships.php')): ?>
                <a href="scholarships.php" class="stat-link mt-1">Manage Programs <i class="bi bi-arrow-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Pending Applications Card -->
    <div class="col-lg-3 col-md-6" data-aos-delay="300">
        <div class="stat-card stat-card-warning">
            <div class="stat-icon bg-warning"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-card-body">
                <div class="stat-label">Pending Applications</div>
                <div class="stat-value"><?php echo htmlspecialchars($status_summary['Pending']); ?></div>
                <div class="small text-white-50 mb-1">
                    <?php 
                    $ungraded = $pdo->query("SELECT COUNT(*) FROM application_exams WHERE is_graded = 0")->fetchColumn();
                    if($ungraded > 0) echo "$ungraded need grading";
                    ?>
                </div>
                <?php if (canAccess('applications.php')): ?>
                <a href="applications.php?status=Pending" class="stat-link mt-1">Review Now <i class="bi bi-arrow-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Status Summary Card -->
    <div class="col-lg-3 col-md-6" data-aos-delay="400">
        <div class="stat-card">
            <div class="stat-icon" style="background-color: #6c757d;"><i class="bi bi-card-checklist"></i></div>
            <div class="stat-card-body">
                <div class="stat-label">Application Status</div>
                <div class="small text-muted">Approved</div>
                <div class="fw-bold fs-4 text-success"><?php echo htmlspecialchars($status_summary['Approved']); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions Section -->
<div class="row g-4 mb-5">
    <div class="col-12" data-aos="fade-up" data-aos-delay="400">
        <div class="mb-4">
            <h2 class="section-title">Quick Actions</h2>
            <p class="section-subtitle">Fast access to your most important tasks</p>
        </div>
    </div>
    <!-- Action Card 1: Manage Scholarships -->
    <?php if (canAccess('scholarships.php')): ?>
    <div class="col-lg-4" data-aos="fade-up" data-aos-delay="500">
        <a href="scholarships.php" class="action-card action-card-primary h-100">
            <div class="action-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="action-content">
                <h5>Manage Scholarships</h5>
                <p>Create, edit, or archive scholarship programs.</p>
            </div>
            <i class="bi bi-chevron-right action-arrow"></i>
        </a>
    </div>
    <?php endif; ?>
    <!-- Action Card 2: Review Applications -->
    <?php if (canAccess('applications.php')): ?>
    <div class="col-lg-4" data-aos="fade-up" data-aos-delay="600">
        <a href="applications.php" class="action-card action-card-warning h-100">
            <div class="action-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
            <div class="action-content">
                <h5>Review Applications</h5>
                <p>Process and update the status of student applications.</p>
            </div>
            <i class="bi bi-chevron-right action-arrow"></i>
        </a>
    </div>
    <?php endif; ?>
    <!-- Action Card 3: Manage Users -->
    <?php if (canAccess('users.php')): ?>
    <div class="col-lg-4" data-aos="fade-up" data-aos-delay="700">
        <a href="users.php" class="action-card action-card-info h-100">
            <div class="action-icon"><i class="bi bi-people-fill"></i></div>
            <div class="action-content">
                <h5>Manage Users</h5>
                <p>View and manage student and admin accounts.</p>
            </div>
            <i class="bi bi-chevron-right action-arrow"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Data Visualization & Recent Activity Section -->
<div class="row g-4">
    <!-- Application Trends Chart -->
    <div class="col-lg-8" data-aos="fade-up" data-aos-delay="800">
        <div class="content-block h-100">
            <h3>Application Trends (Last 7 Days)</h3>
            <div class="chart-container">
                <canvas id="applicationTrendsChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Recent Activity Feed -->
    <div class="col-lg-4" data-aos="fade-up" data-aos-delay="900">
        <div class="content-block h-100">
            <h3>Recent Activity</h3>
            <div class="activity-feed">
                <?php if (empty($recent_applications)): ?>
                    <p class="text-muted">No recent activity to display.</p>
                <?php else: ?>
                    <?php foreach ($recent_applications as $app): ?>
                        <a href="applications.php?action=view&id=<?php echo $app['id']; ?>" class="feed-item text-decoration-none">
                            <div class="feed-icon">
                                <i class="bi bi-file-earmark-check-fill"></i>
                            </div>
                            <div class="feed-content">
                                <p class="mb-1">
                                    New application from <strong><?php echo htmlspecialchars($app['student_name']); ?></strong> for "<?php echo htmlspecialchars($app['scholarship_name']); ?>".
                                </p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">
                                        <i class="bi bi-clock me-1"></i>
                                        <?php echo htmlspecialchars(date("M d, Y, g:i A", strtotime($app['created_at']))); ?>
                                    </span>
                                    <?php
                                    $display_status = formatApplicationStatus($app['status']);
                                    $badge_bg = match($display_status) {
                                        'Approved' => 'success',
                                        'Rejected' => 'danger',
                                        'Pending' => 'secondary',
                                        'Under Review' => 'info',
                                        'Renewal Request' => 'primary',
                                        'Drop Requested' => 'warning text-dark',
                                        'Pending Exam' => 'dark',
                                        'Dropped' => 'secondary',
                                        default => 'light text-dark',
                                    };
                                    ?>
                                    <span class="badge bg-<?php echo $badge_bg; ?>"><?php echo htmlspecialchars($display_status); ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Application Trends Chart ---
    const ctx = document.getElementById('applicationTrendsChart');
    if (ctx) {
        const applicationData = <?php echo json_encode($application_trend_data['data'] ?? []); ?>;
        const applicationLabels = <?php echo json_encode($application_trend_data['labels'] ?? []); ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: applicationLabels,
                datasets: [{
                    label: 'New Applications',
                    data: applicationData,
                    backgroundColor: 'rgba(13, 110, 253, 0.6)',
                    borderColor: 'rgba(13, 110, 253, 1)',
                    borderWidth: 2,
                    borderRadius: 5,
                    hoverBackgroundColor: 'rgba(13, 110, 253, 0.8)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e9ecef',
                            borderDash: [2, 4],
                        },
                        ticks: {
                            stepSize: 1 // Ensure y-axis only shows whole numbers for counts
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    }
});
</script>

<?php include 'footer.php'; ?>