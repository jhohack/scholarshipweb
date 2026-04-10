<?php
if (session_status() === PHP_SESSION_NONE) {
    // Enable URL-based session IDs to allow multiple accounts in the same browser (multitasking)
    ini_set('session.use_trans_sid', 1);
    ini_set('session.use_only_cookies', 0);
    session_name('scholarship_admin');
    session_start();
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
$base_path = dirname(__DIR__);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once $base_path . '/vendor/autoload.php';

// If already logged in as admin, skip verification
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff')) {
    header("Location: dashboard.php");
    exit();
}

// If the user hasn't passed the first step, redirect them to login.
if (!isset($_SESSION['2fa_user_id'])) {
    header("Location: login.php");
    exit();
}

$errors = [];
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'resend') {
    $now = time();
    $last_sent = $_SESSION['last_resend_time'] ?? 0;

    if (($now - $last_sent) < 60) {
        $errors[] = "Please wait at least 60 seconds before requesting a new code.";
    } elseif (isset($_SESSION['2fa_user_id'])) {
        // Fetch user email
        $stmt = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['2fa_user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            $verification_code = random_int(100000, 999999);
            $_SESSION['2fa_code'] = $verification_code;
            $_SESSION['2fa_expires'] = time() + 300;
            $_SESSION['last_resend_time'] = time();

            // Send email
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = SMTP_SECURE;
                $mail->Port       = SMTP_PORT;
                
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                $mail->setFrom(SMTP_USER, 'DVC Scholarship Hub Security');
                $mail->addAddress($user['email'], $user['first_name'] . ' ' . $user['last_name']);
                $mail->isHTML(true);
                $mail->Subject = 'Your New Admin Login Verification Code';
                $mail->Body    = "Your new verification code is: <h2>{$verification_code}</h2>This code will expire in 5 minutes.";
                $mail->send();
                
                $success_message = "A new verification code has been sent.";
            } catch (Exception $e) {
                $errors[] = "Could not send email. Mailer Error: " . $mail->ErrorInfo;
            }
        } else {
             $errors[] = "User not found.";
        }
    }
} elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
    $code = $_POST['code'] ?? '';
    $max_attempts = 5;

    // Use a sequential if/elseif structure to prevent multiple errors and ensure logical flow.
    if (isset($_SESSION['2fa_attempts']) && $_SESSION['2fa_attempts'] >= $max_attempts) {
        $errors[] = "You have exceeded the maximum number of verification attempts. Please log in again.";
        // Clear all 2FA session data to force a new login
        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires'], $_SESSION['2fa_attempts']);
    } elseif (time() > ($_SESSION['2fa_expires'] ?? 0)) {
        $errors[] = "The verification code has expired. Please log in again.";
        // Clear the expired 2FA session data
        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires'], $_SESSION['2fa_attempts']);
    } elseif (empty($code)) {
        $errors[] = "Please enter the verification code.";
    } elseif ($code != $_SESSION['2fa_code']) {
        // Increment attempt counter on failure
        $_SESSION['2fa_attempts'] = ($_SESSION['2fa_attempts'] ?? 0) + 1;
        $remaining_attempts = $max_attempts - $_SESSION['2fa_attempts'];
        
        $errors[] = "Invalid verification code. You have {$remaining_attempts} attempts remaining.";
        if ($remaining_attempts <= 0) {
             // On the final failed attempt, clear the session and redirect to login.
             unset($_SESSION['2fa_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires'], $_SESSION['2fa_attempts']);
             header("Location: login.php?error=max_attempts");
             exit();
        }
    } else {
        // --- Verification Successful ---
        try {
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, role, permissions FROM users WHERE id = ? AND (role = 'admin' OR role = 'staff')");
            $stmt->execute([$_SESSION['2fa_user_id']]);
            $user = $stmt->fetch();

            if ($user) {
                // Clean up 2FA session variables
                unset($_SESSION['2fa_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires'], $_SESSION['2fa_attempts']);
                // Set final login session
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['is_admin'] = ($user['role'] === 'admin');
                $_SESSION['permissions'] = ($user['role'] === 'staff' && !empty($user['permissions'])) ? json_decode($user['permissions'], true) : [];
                $_SESSION['last_activity'] = time(); // Initialize session timer
                header("Location: dashboard.php");
                exit();
            } else {
                $errors[] = "Could not finalize login. User not found.";
            }
        } catch (PDOException $e) {
            $errors[] = "A database error occurred.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Verification - Scholarship Hub</title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin_style.css">
    <style>
        body {
            background: #111827;
            background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.05) 1px, transparent 0);
            background-size: 25px 25px;
        }
        .login-card {
            max-width: 450px;
            margin: 8rem auto;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
            background: rgba(31, 41, 55, 0.6);
            backdrop-filter: blur(10px);
        }
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
                    <i class="bi bi-envelope-check-fill fs-1 text-primary"></i>
                    <h2 class="fw-bold mt-2 text-white">Check Your Email</h2>
                    <p class="text-muted">We've sent a 6-digit verification code to your email address.</p>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <p class="mb-0"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>

                <form action="verify.php" method="post">
                    <div class="mb-4">
                        <label for="code" class="form-label">Verification Code</label>
                        <input type="text" class="form-control form-control-lg text-center" id="code" name="code" required maxlength="6" pattern="\d{6}" title="Enter the 6-digit code">
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">Verify & Login</button>
                    </div>
                </form>
                
                <div class="d-flex justify-content-between mt-4">
                    <a href="login.php" class="text-decoration-none text-white-50 small"><i class="bi bi-arrow-left"></i> Back to Login</a>
                    <form action="verify.php" method="post" id="resend-form" class="d-inline">
                        <input type="hidden" name="action" value="resend">
                        <button type="submit" class="btn btn-link text-white-50 small p-0 text-decoration-none" id="resend-btn">Resend Code</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add a cooldown to the resend button to prevent spam
        const resendForm = document.getElementById('resend-form');
        if (resendForm) {
            resendForm.addEventListener('submit', function() {
                const btn = document.getElementById('resend-btn');
                btn.disabled = true;
                let seconds = 60;
                const originalText = btn.textContent;
                btn.textContent = `Resend in ${seconds}s`;
                btn.style.cursor = 'not-allowed';
                btn.style.opacity = '0.5';

                const interval = setInterval(() => {
                    seconds--;
                    btn.textContent = `Resend in ${seconds}s`;
                    if (seconds <= 0) { 
                        clearInterval(interval); 
                        btn.textContent = originalText; 
                        btn.disabled = false; 
                        btn.style.cursor = 'pointer';
                        btn.style.opacity = '1';
                    }
                }, 1000);
            });
        }
    </script>
</body>
</html>