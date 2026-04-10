<?php
$current_page = basename($_SERVER['PHP_SELF']);

// This check ensures that if an admin is viewing the public site (indicated by the presence
// of the admin session cookie or URL parameter), the site behaves as if no student is logged in.
// This prevents the admin from seeing a student's dashboard links.
$isAdminViewing = false;
// Only enable admin viewing mode if the user is NOT logged in as a student
if ((isset($_COOKIE['scholarship_admin']) || isset($_GET['scholarship_admin'])) && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student')) {
    $isAdminViewing = true;
}

$unread_messages = 0;
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
    $unread_messages = getUnreadMessageCount($pdo, $_SESSION['user_id']);
}
?>
<header id="main-header" class="sticky-top">
    <div class="nav-progress" aria-hidden="true"></div>
    <nav class="navbar navbar-expand-lg navbar-light py-2 site-nav" role="navigation" aria-label="Primary">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2 nav-brand" href="index.php">
                <div class="d-flex align-items-center justify-content-center bg-primary text-white rounded-circle nav-brand-logo" style="width: 40px; height: 40px;">
                    <i class="bi bi-mortarboard-fill fs-5"></i>
                </div>
                <div class="d-flex flex-column">
                    <span class="fw-bold text-primary lh-1">DVC Scholarship</span>
                    <span class="small text-muted fw-medium" style="font-size: 0.75rem; letter-spacing: 0.5px;">PORTAL</span>
                </div>
            </a>
            
            <button class="navbar-toggler border-0 p-0 shadow-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Open navigation menu">
                <span class="hamburger"></span>
            </button>

            <div class="offcanvas offcanvas-end offcanvas-lg" tabindex="-1" id="navbarNav" aria-labelledby="navbarNavLabel">
                <div class="offcanvas-header d-lg-none">
                    <h5 class="offcanvas-title fw-bold text-primary" id="navbarNavLabel">Navigation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
                </div>
                <div class="offcanvas-body">
                    <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center gap-lg-2 main-nav-list">
                        <li class="nav-item">
                            <a class="nav-link main-nav-link <?php echo ($current_page === 'index.php') ? 'active fw-bold' : ''; ?>" href="index.php" <?php if ($current_page === 'index.php') echo 'aria-current="page"'; ?>>
                                <i class="bi bi-house-door" aria-hidden="true"></i><span>Home</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link main-nav-link" href="index.php#how-it-works">
                                <i class="bi bi-compass" aria-hidden="true"></i><span>How It Works</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link main-nav-link <?php echo ($current_page === 'announcements.php') ? 'active fw-bold' : ''; ?>" href="announcements.php" <?php if ($current_page === 'announcements.php') echo 'aria-current="page"'; ?>>
                                <i class="bi bi-megaphone" aria-hidden="true"></i><span>Announcements</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link main-nav-link <?php echo ($current_page === 'scholarships.php') ? 'active fw-bold' : ''; ?>" href="scholarships.php" <?php if ($current_page === 'scholarships.php') echo 'aria-current="page"'; ?>>
                                <i class="bi bi-journal-check" aria-hidden="true"></i><span>Scholarships</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link main-nav-link" data-nav="contact" href="<?php echo ($current_page === 'index.php') ? '#index-faq' : 'index.php#index-faq'; ?>">
                                <i class="bi bi-telephone" aria-hidden="true"></i><span>Contact</span>
                            </a>
                        </li>
                    </ul>

                    <div class="d-flex align-items-center ms-lg-4 mt-3 mt-lg-0 nav-actions">
                        <?php if (isset($_SESSION['user_id']) && !$isAdminViewing): ?>
                            <?php
                            // This block is for logged-in students only.
                            $profile_pic_url = !empty($_SESSION['profile_picture_path'])
                                ? storedFilePathToUrl($_SESSION['profile_picture_path'])
                                : 'assets/images/default-avatar.png';
                            ?>
                            <a href="messages.php" class="btn btn-light rounded-circle me-3 position-relative border d-flex align-items-center justify-content-center nav-message-btn <?php echo ($unread_messages > 0) ? 'has-unread' : ''; ?>" style="width: 40px; height: 40px;" title="Messages" aria-label="Messages">
                                <i class="bi bi-chat-dots-fill text-primary"></i>
                                <span class="message-badge badge rounded-pill bg-danger <?php echo ($unread_messages > 0) ? '' : 'd-none'; ?>">
                                    <?php echo ($unread_messages > 99) ? '99+' : (int)$unread_messages; ?>
                                    <span class="visually-hidden">unread messages</span>
                                </span>
                            </a>
                            <div class="dropdown">
                                <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 p-1 pe-3 rounded-pill bg-light border" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <img src="<?php echo htmlspecialchars($profile_pic_url); ?>" alt="Profile" class="rounded-circle" width="32" height="32" style="object-fit: cover;">
                                    <span class="fw-semibold text-dark small"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2 p-2 rounded-3" aria-labelledby="navbarDropdown">
                                    <li><a class="dropdown-item rounded-2 py-2 d-flex align-items-center gap-2" href="../student/dashboard.php"><i class="bi bi-grid-fill text-primary"></i> Dashboard</a></li>
                                    <li><a class="dropdown-item rounded-2 py-2 d-flex align-items-center gap-2" href="messages.php"><i class="bi bi-chat-dots-fill text-primary"></i> Messages</a></li>
                                    <li><a class="dropdown-item rounded-2 py-2 d-flex align-items-center gap-2" href="../student/profile.php"><i class="bi bi-person-fill text-primary"></i> My Profile</a></li>
                                    <li><hr class="dropdown-divider my-2"></li>
                                    <li><a class="dropdown-item rounded-2 py-2 d-flex align-items-center gap-2 text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <?php // This block is for logged-out users OR an admin viewing the site. ?>
                            <div class="d-flex gap-2 w-100 w-lg-auto">
                                <a class="btn btn-outline-primary px-4 fw-semibold rounded-pill w-50 w-lg-auto" href="login.php">Login</a>
                                <a class="btn btn-primary px-4 fw-semibold rounded-pill w-50 w-lg-auto shadow-sm" href="register.php">Register</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.main-nav-link');
            if (window.location.hash === '#how-it-works') {
                navLinks.forEach(function(link) { link.classList.remove('active', 'fw-bold'); });
                const howItWorksLink = document.querySelector('.main-nav-link[href="index.php#how-it-works"]');
                if (howItWorksLink) {
                    howItWorksLink.classList.add('active', 'fw-bold');
                }
            }

            const badge = document.querySelector('.message-badge');
            const messageButton = document.querySelector('.nav-message-btn');
            if (!badge) return;

            const apiUrl = 'portal_api.php';
            
            function updateUnreadCount() {
                const formData = new FormData();
                formData.append('action', 'get_unread_count');

                fetch(apiUrl, { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.unread_count > 0) {
                                badge.innerHTML = (data.unread_count > 99 ? '99+' : data.unread_count) + '<span class="visually-hidden">unread messages</span>';
                                badge.classList.remove('d-none');
                                if (messageButton) {
                                    messageButton.classList.add('has-unread');
                                }
                            } else {
                                badge.classList.add('d-none');
                                if (messageButton) {
                                    messageButton.classList.remove('has-unread');
                                }
                            }
                        }
                    })
                    .catch(err => console.error('Notification poll error', err));
            }

            setInterval(updateUnreadCount, 5000); // Poll every 5 seconds
        });
    </script>
</header>
