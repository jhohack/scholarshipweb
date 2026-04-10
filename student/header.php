<?php
// This header is for the protected student area. It should be included on all student-facing pages.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php'; // For BASE_URL and DB constants
require_once $base_path . '/includes/db.php';     // For $pdo

$current_page_student = basename($_SERVER['PHP_SELF']); // Used to set the 'active' class on nav links.

// Determine role and logout link dynamically
$user_role_label = isset($_SESSION['role']) ? ucfirst($_SESSION['role']) : 'Student';
$logout_link = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? '../admin/logout.php' : '../public/logout.php';
?>
<!DOCTYPE html>
<?php
// Check for new announcements (posted within the last 24 hours)
$has_new_announcements = false;
try {
    $recentAnnouncementSql = dbTimestampDaysAgoSql($pdo, 1);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE is_active = 1 AND created_at >= {$recentAnnouncementSql}");
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        $has_new_announcements = true;
    }
} catch (PDOException $e) {
    error_log("Error checking for new announcements: " . $e->getMessage());
} 

$unread_messages = 0;
if (isset($_SESSION['user_id'])) {
    $unread_messages = getUnreadMessageCount($pdo, $_SESSION['user_id']);
}
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - Student Area' : 'Student Dashboard'; ?></title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet"> <!-- Animate On Scroll library -->
    <link rel="stylesheet" href="../public/assets/css/dashboard.css"> <!-- Correct path to dashboard-specific styles -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const apiUrl = '../public/portal_api.php';
            
            function updateUnreadCount() {
                const formData = new FormData();
                formData.append('action', 'get_unread_count');

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
    <?php if (isset($_SESSION['admin_original_session'])): ?>
    <div class="alert alert-warning text-center mb-0 rounded-0 border-0 fw-bold" style="position: sticky; top: 0; z-index: 1050;">
        <div class="container d-flex justify-content-center align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            You are currently viewing as <strong><?php echo htmlspecialchars($_SESSION['name']); ?></strong>.
            <a href="stop_impersonating.php" class="btn btn-sm btn-dark ms-3">Return to Admin Dashboard</a>
        </div>
    </div>
    <?php endif; ?>
    <div class="dashboard-layout">
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="../public/index.php" class="sidebar-brand">
                    <i class="bi bi-mortarboard-fill"></i>
                    <span>DVC Scholarship</span>
                </a>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Dashboard">
                        <a class="nav-link <?php echo ($current_page_student === 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php">
                            <i class="bi bi-grid-1x2-fill"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="My Applications">
                        <a class="nav-link <?php echo ($current_page_student === 'applications.php') ? 'active' : ''; ?>" href="applications.php">
                            <i class="bi bi-file-earmark-text-fill"></i>
                            <span>My Applications</span>
                        </a>
                    </li>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Messages">
                        <a class="nav-link" href="../public/messages.php">
                            <i class="bi bi-chat-dots-fill"></i>
                            <span>Messages</span>
                            <span class="badge bg-danger ms-auto message-badge" style="<?php echo $unread_messages > 0 ? '' : 'display:none;'; ?>">
                                <?php echo $unread_messages > 0 ? ($unread_messages > 99 ? '99+' : $unread_messages) : ''; ?>
                            </span>
                        </a>
                    </li>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Announcements">
                        <a class="nav-link" href="../public/announcements.php" target="_blank">
                            <i class="bi bi-megaphone-fill"></i>
                            <span>Announcements</span>
                            <?php if ($has_new_announcements): ?>
                                <span class="badge text-bg-danger ms-2">New!</span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="My Profile">
                        <a class="nav-link <?php echo ($current_page_student === 'profile.php') ? 'active' : ''; ?>" href="profile.php">
                            <i class="bi bi-person-fill"></i>
                            <span>My Profile</span>
                        </a>
                    </li>
                    <li class="nav-item" data-bs-toggle="tooltip" data-bs-placement="right" title="Back to Website">
                        <a class="nav-link" href="../public/index.php">
                            <i class="bi bi-house-door-fill"></i>
                            <span>Back to Website</span>
                        </a>
                    </li>
                </ul>
            </nav>
            <div class="sidebar-footer" data-bs-toggle="tooltip" data-bs-placement="right" title="Logout">
                <a href="<?php echo $logout_link; ?>" class="nav-link text-danger">
                    <i class="bi bi-box-arrow-left"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>
        <div class="main-content">
            <header class="main-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <button class="btn sidebar-toggle-btn me-3" id="sidebar-toggle" type="button">
                        <i class="bi bi-list"></i>
                    </button>
                    <h1 class="page-title mb-0"><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></h1>
                </div>
                <div class="d-flex align-items-center">
                    <!-- User Dropdown -->
                    <div class="dropdown user-dropdown">
                        <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php
                                $profile_pic_path = !empty($_SESSION['profile_picture_path'])
                                    ? storedFilePathToUrl($_SESSION['profile_picture_path'])
                                    : 'https://i.pravatar.cc/40?u=' . rawurlencode((string) $_SESSION['user_id']);
                            ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" alt="User" width="40" height="40" class="rounded-circle me-2" style="object-fit: cover;">
                            <div class="d-none d-md-block">
                                <div class="user-name"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
                                <div class="user-role"><?php echo htmlspecialchars($user_role_label); ?></div>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?php echo $logout_link; ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </header>
            <main class="content-wrapper">
