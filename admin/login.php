<?php
if (session_status() === PHP_SESSION_NONE) {
    // Keep admin and student logins separate by using a dedicated admin session.
    session_name('scholarship_admin');
    session_start();
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/functions.php';
$base_path = dirname(__DIR__);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_SESSION['user_id']) && !isset($_SESSION['2fa_user_id']) && isset($_COOKIE[session_name()])) {
    clearCurrentSessionCookie();
}

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header("Location: dashboard.php");
        exit();
    } elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
        header("Location: dashboard.php");
        exit();
    } elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
        header("Location: ../student/dashboard.php");
        exit();
    }
}

// Include PHPMailer for 2FA email
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require $base_path . '/vendor/autoload.php';

$errors = [];

// Check for errors passed from the verification page
if (isset($_GET['error']) && $_GET['error'] === 'max_attempts') {
    $errors[] = "You have exceeded the maximum number of verification attempts. Please try again.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $errors[] = "Email and password are required.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, password, role, permissions FROM users WHERE email = ? AND (role = 'admin' OR role = 'staff')");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // 1. Check if user exists and has the admin or staff role
            if ($user) { 
                // Using plain password comparison as requested. WARNING: This is not secure.
                if ($password === $user['password']) { 
                    // --- 2FA Logic ---
                    $verification_code = random_int(100000, 999999);
                    $_SESSION['2fa_user_id'] = $user['id'];
                    $_SESSION['2fa_code'] = $verification_code;
                    $_SESSION['2fa_expires'] = time() + 300; // Code is valid for 5 minutes
                    $_SESSION['2fa_attempts'] = 0; // Initialize attempt counter

                    // Send the code via email
                    $mail = new PHPMailer(true);
                    try {
                        configureSmtpMailer($mail, 'DVC Scholarship Hub Security');
                        $mail->addAddress($user['email'], $user['first_name'] . ' ' . $user['last_name']);
                        $mail->isHTML(true);
                        $mail->Subject = 'Your Admin Login Verification Code';
                        $mail->Body    = "Your verification code is: <h2>{$verification_code}</h2>This code will expire in 5 minutes.";
                        $mail->send();

                        // Redirect to the verification page
                        header("Location: verify.php");
                        exit();

                    } catch (\Throwable $e) {
                        $errors[] = mailConfigurationErrorMessage();
                        error_log("Mailer Error: " . ($mail->ErrorInfo ?: $e->getMessage()));
                    }
                } else {
                    $errors[] = "Invalid credentials.";
                }
            } else {
                $errors[] = "Access denied. Invalid credentials or not an authorized admin account.";
            }
        } catch (PDOException $e) {
            $errors[] = "A database error occurred. Please try again later.";
            // In production, you would log the error: error_log($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Scholarship Hub</title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="admin_style.css">
    <style>
        body {
            background: #111827; /* Darker, more professional background */
            background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.05) 1px, transparent 0);
            background-size: 25px 25px;
            animation: bg-pan 120s linear infinite;
        }
        @keyframes bg-pan { 0% { background-position: 0% 0%; } 100% { background-position: 100% 100%; } }

        .login-card {
            max-width: 450px;
            margin: 8rem auto;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
            background: rgba(31, 41, 55, 0.6); /* Semi-transparent card */
            backdrop-filter: blur(10px);
            animation: fade-in 0.8s ease-out;
        }
        @keyframes fade-in { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .form-control { background-color: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.2); color: #fff; }
        .form-control:focus { background-color: rgba(255,255,255,0.1); border-color: var(--bs-primary); box-shadow: none; color: #fff; }
        .form-label { color: #adb5bd; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card login-card">
            <div class="card-body p-5">
                <div class="text-center mb-4">
                    <i class="bi bi-shield-lock-fill fs-1 text-primary"></i>
                    <h2 class="fw-bold mt-2 text-white">Admin Panel</h2>
                    <p class="text-muted">Please sign in to continue</p>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="post">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control form-control-lg" id="email" name="email" required>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control form-control-lg" id="password" name="password" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">Login</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
