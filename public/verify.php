<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/mailer.php';

// Include PHPMailer for resending email
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $base_path . '/vendor/autoload.php';

$errors = [];
$success_message = '';
$email = $_GET['email'] ?? '';

if (empty($email)) {
    header("Location: register.php");
    exit();
}

// Handle "Resend Code" action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'resend') {
    $now = time();
    $last_sent = $_SESSION['last_resend_time'] ?? 0;

    if (($now - $last_sent) < 60) {
        $errors[] = "Please wait at least 60 seconds before requesting a new code.";
    } elseif (isset($_SESSION['registration_data']) && $_SESSION['registration_data']['email'] === $email) {
        $reg_data = &$_SESSION['registration_data']; // Use reference to update session

        // Generate new code and expiry
        $reg_data['verification_code'] = random_int(100000, 999999);
        $expires = new DateTime('NOW');
        $expires->add(new DateInterval('PT15M'));
        $reg_data['expires_at'] = $expires->format('Y-m-d H:i:s');

        // Resend the email
        $mail = new PHPMailer(true);
        try {
            configureSmtpMailer($mail, 'DVC Scholarship Hub');
            $mail->addAddress($reg_data['email'], "{$reg_data['first_name']} {$reg_data['last_name']}");

            //Content
            $mail->isHTML(true);
            $mail->Subject = 'Your New Verification Code';
            $mail->Body    = "Hello {$reg_data['first_name']},<br><br>As requested, here is your new verification code. It is valid for 15 minutes.<br><br>Your new code is: <h2>{$reg_data['verification_code']}</h2><br>Thank you,<br>The DVC Scholarship Hub Team";
            $mail->AltBody = "Your new verification code is: {$reg_data['verification_code']}";

            $mail->send();

            $_SESSION['last_resend_time'] = time();
            $success_message = "A new verification code has been sent to your email.";
        } catch (\Throwable $e) {
            $errors[] = mailConfigurationErrorMessage();
            error_log("Mailer Error: " . ($mail->ErrorInfo ?: $e->getMessage()));
        }
    } else {
        $errors[] = "No pending registration found. Please register again.";
    }
}
// Handle code verification
elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
    $verification_code = $_POST['verification_code'] ?? '';
    $posted_email = $_POST['email'] ?? '';

    if (empty($verification_code)) {
        $errors[] = "Please enter your verification code.";
    } elseif ($email !== $posted_email) {
        $errors[] = "An error occurred. Please try again.";
    } else {
        // Check against session data instead of database
        if (isset($_SESSION['registration_data']) && $_SESSION['registration_data']['email'] === $email) {
            $reg_data = $_SESSION['registration_data'];
            $expires_at = new DateTime($reg_data['expires_at']);
            $now = new DateTime();

            if ($now > $expires_at) {
                $errors[] = "Your verification code has expired. Please register again.";
                unset($_SESSION['registration_data']); // Clear expired data
            } elseif ($verification_code == $reg_data['verification_code']) {
                // Success! Code is correct. Mark as verified and redirect to profile setup.
                $_SESSION['is_verified_for_setup'] = true;
                header("Location: profile-setup.php");
                exit();
            } else {
                $errors[] = "Invalid verification code.";
            }
        } else {
            $errors[] = "No pending registration found for this email. Please register again.";
        }
    }
}

$page_title = 'Verify Your Account';
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
    <main>
        <section class="auth-section d-flex align-items-center" style="min-height: 100vh;">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-6">
                        <div class="card p-5 auth-card" style="border-radius: 1rem;">
                            <div class="text-center mb-4">
                                <i class="bi bi-envelope-check-fill display-3 text-primary"></i>
                                <h2 class="fw-bold mt-3">Check Your Email</h2>
                            </div>
                            <p class="text-center text-muted mb-4">We've sent a 6-digit verification code to <strong><?php echo htmlspecialchars($email); ?></strong>. Please enter it below to activate your account.</p>
                            
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

                            <form action="verify.php?email=<?php echo urlencode($email); ?>" method="POST">
                                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                                <div class="mb-3">
                                    <label for="verification_code" class="form-label">Verification Code</label>
                                    <input type="text" class="form-control form-control-lg text-center" id="verification_code" name="verification_code" required maxlength="6" pattern="\d{6}" title="Enter the 6-digit code.">
                                </div>
                                <div class="d-grid mt-4">
                                    <button type="submit" class="btn btn-primary btn-lg">Verify Account</button>
                                </div>
                            </form>
                            <div class="text-center mt-4 text-muted small">
                                <p>Didn't receive the code?</p>
                                <form action="verify.php?email=<?php echo urlencode($email); ?>" method="POST" id="resend-form">
                                    <input type="hidden" name="action" value="resend">
                                    <button type="submit" class="btn btn-link" id="resend-btn">Resend Code</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add a cooldown to the resend button to prevent spam
        document.getElementById('resend-form').addEventListener('submit', function() {
            const btn = document.getElementById('resend-btn');
            btn.disabled = true;
            let seconds = 60;
            btn.textContent = `Resend in ${seconds}s`;

            const interval = setInterval(() => {
                seconds--;
                btn.textContent = `Resend in ${seconds}s`;
                if (seconds <= 0) { clearInterval(interval); btn.textContent = 'Resend Code'; btn.disabled = false; }
            }, 1000);
        });
    </script>
</body>
</html>
