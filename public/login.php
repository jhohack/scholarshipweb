<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Migration: Add profile_picture_path to users table ---
try {
    $pdo->query("SELECT profile_picture_path FROM users LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture_path VARCHAR(255) NULL DEFAULT NULL");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Email and password are required.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, password, role, email_verified, profile_picture_path FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Using plain password comparison as requested.
            // WARNING: This is not secure.
            if ($user && $password === $user['password'] && $user['role'] === 'student') {
                // Check if the student's account is verified
                 if ($user['email_verified'] == 0) {
                     $error = 'Your account is not verified. Please <a href="verify.php?email=' . urlencode($email) . '">enter your verification code</a>.';
                     // We stop here so the user cannot log in.
                 } else {
                     // All checks passed, start session for the student
                     session_regenerate_id(true);
                     $_SESSION['user_id'] = $user['id'];
                     $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
                     $_SESSION['role'] = $user['role'];
                     $_SESSION['last_activity'] = time(); // Reset session timer to prevent immediate timeout
                     $_SESSION['profile_picture_path'] = $user['profile_picture_path'];
     
                     // Redirect to the intended page or the student dashboard
                     $redirect_url = $_SESSION['redirect_url'] ?? '../student/dashboard.php';
                     unset($_SESSION['redirect_url']); // Clear the stored URL
                     header("Location: " . $redirect_url);
                     exit();
                 }
            } else {
                // Generic error for non-existent users, wrong password, or non-student roles
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $error = "A database error occurred. Please try again later.";
            // In production, you would log the error: error_log($e->getMessage());
        }
    }
}

$page_title = 'Login';
// The header is now inlined as per the request.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'DVC Scholarship Hub'; ?></title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- AOS (Animate on Scroll) CSS -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <a href="index.php" class="back-button" aria-label="Go back to homepage" data-aos="zoom-in" data-aos-delay="200">
        <i class="bi bi-arrow-left"></i>
    </a>
    <main>
        <section class="auth-section d-flex align-items-center" style="min-height: 100vh;">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-10 col-xl-9">
                        <div class="card-group auth-card">
                            <!-- Left Side: Branding -->
                            <div class="col-lg-6 card p-5 d-none d-lg-flex flex-column justify-content-center auth-card-branding" data-aos="fade-right" id="auth-branding">
                                <div class="text-center text-white">
                                    <i class="bi bi-mortarboard-fill display-3 mb-3"></i>
                                    <h2 class="fw-bold">DVC Scholarship Hub</h2>
                                    <p class="lead">Your gateway to new opportunities and a brighter future.</p>
                                </div>
                            </div>
                            <!-- Right Side: Form -->
                            <div class="col-lg-6 card p-5" data-aos="fade-left" id="auth-form-container">
                                <h2 class="text-center mb-4 fw-bold">Welcome Back</h2>
                                <?php if (isset($_GET['registered']) && $_GET['registered'] === 'success'): ?>
                                    <div class="alert alert-success">
                                        Registration successful! You can now log in.
                                    </div>
                                <?php endif; ?>
                                <?php if (isset($_GET['verified']) && $_GET['verified'] === 'success'): ?>
                                    <div class="alert alert-success">
                                        Your account has been verified! You can now log in.
                                    </div>
                                <?php endif; ?>
                                <?php if (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
                                    <div class="alert alert-success">
                                        Your password has been reset successfully! You can now log in with your new password.
                                    </div>
                                <?php endif; ?>
                                <?php if ($error): ?>
                                    <div class="alert alert-danger">
                                        <?php echo $error; // Allow HTML for the verification link ?>
                                    </div>
                                <?php endif; ?>
                                <form action="login.php" method="POST">
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Password</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="password" name="password" required>
                                            <button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Toggle password visibility">
                                                <i class="bi bi-eye-slash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="d-grid mt-4">
                                        <button type="submit" class="btn btn-primary btn-lg" id="submit-btn">Login</button>
                                    </div>
                                </form>
                                <div class="text-center mt-4 text-muted small">
                                    <p class="mb-1">
                                        <a href="forgot-password.php">Forgot Password?</a>
                                    </p>
                                    <p class="mb-0">
                                        Don't have an account? <a href="register.php">Sign up here</a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- AOS JS -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({
            duration: 800,
            once: true,
        });

        // --- Password Visibility Toggle ---
        document.addEventListener('DOMContentLoaded', function() {
            const password = document.getElementById('password');
            const togglePassword = document.getElementById('togglePassword');

            togglePassword.addEventListener('click', () => {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                togglePassword.querySelector('i').classList.toggle('bi-eye');
                togglePassword.querySelector('i').classList.toggle('bi-eye-slash');
            });
        });
    </script>
</body>
</html>