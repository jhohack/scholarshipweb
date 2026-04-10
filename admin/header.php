<?php
if (session_status() === PHP_SESSION_NONE) {
    // Enable URL-based session IDs to allow multiple accounts in the same browser (multitasking)
    ini_set('session.use_trans_sid', 1);
    ini_set('session.use_only_cookies', 0);
    session_name('scholarship_admin');
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';

// --- Automatic Scholarship Expiration Processing ---
// This runs on admin page loads to check for and process expired scholarships.
// To avoid running on every single page load, we use a session variable as a simple throttle.
if (!isset($_SESSION['last_expiration_check']) || (time() - $_SESSION['last_expiration_check']) > 3600) { // Check once per hour
    $expiration_results = processExpiredScholarships($pdo);
    if ($expiration_results['scholarships'] > 0) {
        flashMessage("{$expiration_results['scholarships']} scholarship(s) expired and were processed. {$expiration_results['students']} student(s) set to 'For Renewal'.");
    }
    $_SESSION['last_expiration_check'] = time();
}

// Authentication check for admin pages
if (!isAdmin() && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff')) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
        header("Location: ../student/dashboard.php");
    } else {
        header("Location: login.php");
    }
    exit();
}

// Helper to check permissions for sidebar
function canAccess($page) {
    if (isAdmin()) return true;
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
        $perms = $_SESSION['permissions'] ?? [];
        return in_array($page, $perms);
    }
    return false;
}

$current_page_admin = basename($_SERVER['PHP_SELF']);
$unread_messages = getUnreadMessageCount($pdo, $_SESSION['user_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - Admin' : 'Admin Dashboard'; ?></title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="dashboard-styles.css">
    <style>
        /* Sidebar Section Headers */
        .sidebar-section-header {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            color: #6c757d; /* Muted text color */
            padding: 1.5rem 1.5rem 0.5rem;
        }
        /* Hide headers when sidebar is collapsed */
        .sidebar-collapsed .sidebar-section-header { display: none; }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const apiUrl = '../public/portal_api.php';
            
            function updateUnreadCount() {
                const formData = new FormData();
                formData.append('action', 'get_unread_count');
                formData.append('chat_context', 'sys_bridge'); // Required for admin session access

                fetch(apiUrl, { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const badge = document.querySelector('.message-badge');
                            if (!badge) return;
                            
                            if (data.unread_count > 0) {
                                badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                                badge.style.display = 'inline-block';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                    })
                    .catch(err => console.error('Notification poll error', err));
            }

            // Poll every 5 seconds
            setInterval(updateUnreadCount, 5000);
        });
    </script>
</head>
<body>
    <div class="admin-layout">
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand">
                    <i class="bi bi-shield-lock-fill"></i>
                    <span>Admin Panel</span>
                </a>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="sidebar-section-header">Overview</li>
                    <?php if (canAccess('dashboard.php')): ?>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Dashboard">
                        <a class="nav-link <?php echo ($current_page_admin === 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> <span>Dashboard</span></a>
                    </li>
                    <?php endif; ?>
                    
                    <li class="sidebar-section-header">Program Management</li>
                    <?php if (canAccess('scholarships.php')): ?>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Scholarships">
                        <a class="nav-link <?php echo ($current_page_admin === 'scholarships.php' || $current_page_admin === 'manage_form.php') ? 'active' : ''; ?>" href="scholarships.php"><i class="bi bi-mortarboard-fill"></i> <span>Scholarships</span></a>
                    </li>
                    <?php endif; ?>
                    <?php if (canAccess('applications.php')): ?>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Applications">
                        <a class="nav-link <?php echo ($current_page_admin === 'applications.php') ? 'active' : ''; ?>" href="applications.php"><i class="bi bi-file-earmark-text-fill"></i> <span>Applications</span></a>
                    </li>
                    <?php endif; ?>
                    <?php if (canAccess('exam-results.php')): ?>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Exam Results">
                        <a class="nav-link <?php echo ($current_page_admin === 'exam-results.php' || $current_page_admin === 'view-exam.php') ? 'active' : ''; ?>" href="exam-results.php"><i class="bi bi-pencil-square"></i> <span>Exam Results</span></a>
                    </li>
                    <?php endif; ?>

                    <li class="sidebar-section-header">Administration</li>
                    <?php if (canAccess('users.php')): ?>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Users">
                        <a class="nav-link <?php echo ($current_page_admin === 'users.php') ? 'active' : ''; ?>" href="users.php"><i class="bi bi-people-fill"></i> <span>Users</span></a>
                    </li>
                    <?php endif; ?>
                    <?php if (canAccess('exports.php')): ?>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Reports & Exports">
                        <a class="nav-link <?php echo ($current_page_admin === 'exports.php') ? 'active' : ''; ?>" href="exports.php"><i class="bi bi-file-earmark-spreadsheet-fill"></i> <span>Reports & Exports</span></a>
                    </li>
                    <?php endif; ?>

                    <li class="sidebar-section-header">Communication</li>
                    <?php if (canAccess('messages.php')): ?>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Messages">
                        <a class="nav-link <?php echo ($current_page_admin === 'messages.php') ? 'active' : ''; ?>" href="messages.php">
                            <i class="bi bi-chat-dots-fill"></i> <span>Messages</span>
                            <span class="badge bg-danger rounded-pill ms-auto message-badge" style="<?php echo $unread_messages > 0 ? '' : 'display:none;'; ?>">
                                <?php echo $unread_messages > 0 ? ($unread_messages > 99 ? '99+' : $unread_messages) : ''; ?>
                            </span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (canAccess('announcements.php')): ?>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Announcements">
                        <a class="nav-link <?php echo ($current_page_admin === 'announcements.php') ? 'active' : ''; ?>" href="announcements.php"><i class="bi bi-megaphone-fill"></i> <span>Announcements</span></a>
                    </li>
                    <?php endif; ?>
                    
                    <li class="sidebar-section-header">System</li>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="View Public Site">
                        <a class="nav-link" href="../public/index.php" target="_blank"><i class="bi bi-box-arrow-up-right"></i> <span>View Public Site</span></a>
                    </li>
                </ul>
            </nav>
            <div class="sidebar-footer" data-bs-toggle="tooltip" data-bs-placement="right" title="Logout">
                <a href="logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-left"></i> <span>Logout</span></a>
            </div>
        </aside>
        <div class="main-content">
            <header class="main-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <button class="btn sidebar-toggle-btn me-3" id="sidebar-toggle">
                        <i class="bi bi-list"></i>
                    </button>
                    <h1 class="page-title mb-0 d-none d-md-block"><?php echo htmlspecialchars($page_title ?? 'Admin Panel'); ?></h1>
                </div>
                <div class="user-dropdown dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="../public/assets/images/dvclogo.png" alt="Admin" width="40" height="40" class="rounded-circle me-2">
                        <div class="d-none d-md-block">
                            <div class="user-name"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></div>
                            <div class="user-role"><?php echo ucfirst(htmlspecialchars($_SESSION['role'] ?? 'Admin')); ?></div>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="#"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="#"><i class="bi bi-gear-fill me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </header>
            <main class="content-wrapper" data-aos="fade-up" data-aos-delay="100">