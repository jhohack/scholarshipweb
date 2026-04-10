<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/mailer.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require $base_path . '/vendor/autoload.php';

$message = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            // Check if the user exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                // Generate a secure, unique token
                $token = bin2hex(random_bytes(50));
                $expires = new DateTime('NOW');
                $expires->add(new DateInterval('PT1H')); // Token expires in 1 hour

                // Store the token in the database
                $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$email, $token, $expires->format('Y-m-d H:i:s')]);

                $reset_link = BASE_URL . '/public/reset-password.php?token=' . $token;

                // --- Send Email with PHPMailer ---
                $mail = new PHPMailer(true);
                try {
                    configureSmtpMailer($mail, 'DVC Scholarship Hub');
                    $mail->addAddress($email);

                    //Content
                    $mail->isHTML(true);
                    $mail->Subject = 'Password Reset Request';
                    $mail->Body    = "Hello,<br><br>You requested a password reset for your account. Please click the link below to set a new password. This link is valid for 1 hour.<br><br><a href='{$reset_link}'>Reset Password</a><br><br>If you did not request this, please ignore this email.<br><br>Thank you,<br>The DVC Scholarship Hub Team";
                    $mail->AltBody = "You requested a password reset. Copy and paste this URL into your browser: {$reset_link}";

                    $mail->send();
                    $message = "If an account with that email exists, a password reset link has been sent.";
                } catch (Exception $e) {
                    $error = mailConfigurationErrorMessage();
                    error_log("Mailer Error: " . ($mail->ErrorInfo ?: $e->getMessage()));
                }
            } else {
                // To prevent user enumeration, show the same message whether the user exists or not.
                $message = "If an account with that email exists, a password reset link has been sent.";
            }
        } catch (PDOException $e) {
            $error = "A database error occurred. Please try again.";
            // error_log($e->getMessage());
        }
    }
}

$page_title = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <a href="login.php" class="back-button" aria-label="Back to login">
        <i class="bi bi-arrow-left"></i>
    </a>
    <main>
        <section class="auth-section d-flex align-items-center" style="min-height: 100vh;">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-6">
                        <div class="card p-5 auth-card" style="border-radius: 1rem;">
                            <h2 class="text-center mb-4 fw-bold">Forgot Password</h2>
                            <p class="text-center text-muted mb-4">Enter your email address and we will send you a link to reset your password.</p>
                            
                            <?php if ($message): ?>
                                <div class="alert alert-info"><?php echo $message; // The message is safe as it's generated server-side ?></div>
                            <?php endif; ?>
                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>

                            <?php if (!$message): // Hide form after message is shown ?>
                            <form action="forgot-password.php" method="POST">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>
                                <div class="d-grid mt-4">
                                    <button type="submit" class="btn btn-primary btn-lg">Send Reset Link</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
