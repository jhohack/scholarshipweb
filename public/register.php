<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/mailer.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (file_exists($base_path . '/vendor/autoload.php')) {
    require_once $base_path . '/vendor/autoload.php';
}
$current_page = basename($_SERVER['PHP_SELF']);

$errors = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $school_id = null;
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $birth_day = $_POST['birth_day'] ?? '';
    $birth_month = $_POST['birth_month'] ?? '';
    $birth_year = $_POST['birth_year'] ?? '';

    // --- Validation ---
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $errors[] = "First Name, Last Name, Email, and Password fields are required.";
    }
    if (empty($birth_day) || empty($birth_month) || empty($birth_year)) {
        $errors[] = "Please select your complete birthdate.";
    } elseif (!checkdate($birth_month, $birth_day, $birth_year)) {
        $errors[] = "The selected birthdate is not valid.";
    } else {
        $birthdate = "$birth_year-$birth_month-$birth_day";
        $birth_date_obj = new DateTime($birthdate);
        $today = new DateTime();
        $age = $today->diff($birth_date_obj)->y;
        if ($age < 18) {
            $errors[] = "You must be at least 18 years old to register.";
        }
    }
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    
    if (empty($errors)) {
        try {
            // Check if the email has already been used
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = "An account with this email address already exists.";
            }

            // --- Create User if no errors ---
            if (empty($errors)) {
                $verification_code = random_int(100000, 999999); // Generate a 6-digit code
                $expires = new DateTime('NOW');
                $expires->add(new DateInterval('PT15M')); // Code expires in 15 minutes

                // Store registration data in session instead of database
                $_SESSION['registration_data'] = [
                    'first_name' => $first_name,
                    'middle_name' => $middle_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'school_id' => null,
                    'password' => $password, // Storing plain text password as requested
                    'birthdate' => $birthdate,
                    'verification_code' => $verification_code,
                    'expires_at' => $expires->format('Y-m-d H:i:s')
                ];

                // --- Send Verification Email ---
                $mail = new PHPMailer(true);
                try {
                    configureSmtpMailer($mail, 'DVC Scholarship Hub');
                    $mail->addAddress($email, "{$first_name} {$last_name}");

                    //Content
                    $mail->isHTML(true);
                    $mail->Subject = 'Verify Your Email Address';
                    $mail->Body    = "Hello {$first_name},<br><br>Thank you for registering. Please use the following code to verify your email address. The code is valid for 15 minutes.<br><br>Your verification code is: <h2>{$verification_code}</h2><br>Thank you,<br>The DVC Scholarship Hub Team";
                    $mail->AltBody = "Your verification code is: {$verification_code}";

                    $mail->send();
                    // Redirect to the verification page
                    header("Location: verify.php?email=" . urlencode($email));
                    exit();
                } catch (Exception $e) {
                    $errors[] = mailConfigurationErrorMessage();
                    error_log("Mailer Error: " . ($mail->ErrorInfo ?: $e->getMessage()));
                }
            }
        } catch (PDOException $e) {
            $errors[] = "Database error. Please try again.";
            // For debugging: error_log($e->getMessage());
        }
    }
}

$page_title = 'Register';
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
                                    <i class="bi bi-person-plus-fill display-3 mb-3"></i>
                                    <h2 class="fw-bold">Join Our Community</h2>
                                    <p class="lead">Create an account to start your journey towards academic excellence.</p>
                                </div>
                            </div>

                            <!-- Right Side: Form -->
                            <div class="col-lg-6 card p-5" data-aos="fade-left" id="auth-form-container">
                                <div>
                                    <h2 class="text-center mb-4 fw-bold">Create Your Account</h2>
                                    <?php if (!empty($errors)): ?>
                                        <div class="alert alert-danger">
                                            <?php foreach ($errors as $error): ?>
                                                <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <form action="register.php" method="POST" id="registration-form" novalidate>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="first_name" class="form-label">First Name</label>
                                                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required pattern="[A-Za-z\s\-]+">
                                                <div class="invalid-feedback">Please enter a valid first name.</div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="last_name" class="form-label">Last Name</label>
                                                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required pattern="[A-Za-z\s\-]+">
                                                <div class="invalid-feedback">Please enter a valid last name.</div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="middle_name" class="form-label">Middle Name <span class="text-muted">(Optional)</span></label>
                                            <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>" pattern="[A-Za-z\s\-]*">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Birthdate</label>
                                            <div class="row g-2">
                                                <div class="col-4">
                                                    <select class="form-select" id="birth_month" name="birth_month" required>
                                                        <option value="" disabled selected>Month</option>
                                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                                            <option value="<?php echo $m; ?>" <?php echo (isset($_POST['birth_month']) && $_POST['birth_month'] == $m) ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 10)); ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </div>
                                                <div class="col-4">
                                                    <select class="form-select" id="birth_day" name="birth_day" required>
                                                        <option value="" disabled selected>Day</option>
                                                        <?php for ($d = 1; $d <= 31; $d++): ?>
                                                            <option value="<?php echo $d; ?>" <?php echo (isset($_POST['birth_day']) && $_POST['birth_day'] == $d) ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </div>
                                                <div class="col-4">
                                                    <select class="form-select" id="birth_year" name="birth_year" required>
                                                        <option value="" disabled selected>Year</option>
                                                        <?php for ($y = date('Y') - 18; $y >= date('Y') - 100; $y--): ?>
                                                            <option value="<?php echo $y; ?>" <?php echo (isset($_POST['birth_year']) && $_POST['birth_year'] == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="invalid-feedback" id="birthdate-feedback">Please select a valid birthdate. You must be at least 18.</div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                                            <div class="invalid-feedback" id="email-feedback">Please enter a valid email address.</div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="password" class="form-label">Password</label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" id="password" name="password" required minlength="8">
                                                <button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Toggle password visibility">
                                                    <i class="bi bi-eye-slash"></i>
                                                </button>
                                            </div>
                                            <div id="password-strength-meter" class="progress mt-2" style="height: 5px; display: none;">
                                                <div id="password-strength-bar" class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            <small id="password-help" class="form-text text-muted">Must be at least 8 characters long.</small>
                                            <div class="invalid-feedback">Password must be at least 8 characters.</div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="confirm_password" class="form-label">Confirm Password</label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                                <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword" aria-label="Toggle password visibility">
                                                    <i class="bi bi-eye-slash"></i>
                                                </button>
                                            </div>
                                            <div class="invalid-feedback">Passwords do not match.</div>
                                        </div>
                                        <div class="d-grid mt-4">
                                            <button type="submit" class="btn btn-primary btn-lg" id="submit-btn">Create Account</button>
                                        </div>
                                    </form>
                                    <p class="text-center mt-4 text-muted small">
                                        Already have an account? <a href="login.php">Log in here</a>
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

        // --- User-Friendly Form Validation Script ---
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('registration-form');
            const firstName = document.getElementById('first_name');
            const lastName = document.getElementById('last_name');
            const email = document.getElementById('email');
            const birthDay = document.getElementById('birth_day');
            const birthMonth = document.getElementById('birth_month');
            const birthYear = document.getElementById('birth_year');
            const emailFeedback = document.getElementById('email-feedback');
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            const togglePassword = document.getElementById('togglePassword');
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            const strengthMeter = document.getElementById('password-strength-meter');
            const strengthBar = document.getElementById('password-strength-bar');
            const submitBtn = document.getElementById('submit-btn');
            const birthdateFeedback = document.getElementById('birthdate-feedback');

            let emailCheckTimeout;

            // Function to toggle password visibility
            const toggleVisibility = (inputField, icon) => {
                const type = inputField.getAttribute('type') === 'password' ? 'text' : 'password';
                inputField.setAttribute('type', type);
                icon.classList.toggle('bi-eye');
                icon.classList.toggle('bi-eye-slash');
            };

            togglePassword.addEventListener('click', () => toggleVisibility(password, togglePassword.querySelector('i')));
            toggleConfirmPassword.addEventListener('click', () => toggleVisibility(confirmPassword, toggleConfirmPassword.querySelector('i')));

            // Function to check password strength
            const checkPasswordStrength = (pass) => {
                let score = 0;
                if (pass.length >= 8) score++;
                if (pass.match(/[a-z]/)) score++;
                if (pass.match(/[A-Z]/)) score++;
                if (pass.match(/[0-9]/)) score++;
                if (pass.match(/[^A-Za-z0-9]/)) score++;
                
                strengthMeter.style.display = pass.length > 0 ? 'block' : 'none';
                const strength = {
                    0: { width: '0%', class: 'bg-danger' },
                    1: { width: '20%', class: 'bg-danger' },
                    2: { width: '40%', class: 'bg-warning' },
                    3: { width: '60%', class: 'bg-warning' },
                    4: { width: '80%', class: 'bg-success' },
                    5: { width: '100%', class: 'bg-success' }
                };
                
                strengthBar.style.width = strength[score].width;
                strengthBar.className = 'progress-bar ' + strength[score].class;
            };

            // Function to validate a single field
            const validateField = (field) => {
                if (field.checkValidity()) {
                    field.classList.remove('is-invalid');
                    field.classList.add('is-valid');
                    return true;
                } else {
                    field.classList.remove('is-valid');
                    field.classList.add('is-invalid');
                    return false;
                }
            };

            // Special validation for confirm password
            const validateConfirmPassword = () => {
                if (confirmPassword.value === password.value && confirmPassword.value.length > 0) {
                    confirmPassword.classList.remove('is-invalid');
                    confirmPassword.classList.add('is-valid');
                    return true;
                } else {
                    confirmPassword.classList.remove('is-valid');
                    confirmPassword.classList.add('is-invalid');
                    return false;
                }
            };

            // Special validation for birthdate
            const validateBirthdate = () => {
                const day = birthDay.value;
                const month = birthMonth.value;
                const year = birthYear.value;

                birthDay.classList.remove('is-invalid');
                birthMonth.classList.remove('is-invalid');
                birthYear.classList.remove('is-invalid');

                if (!day || !month || !year) {
                    birthYear.classList.add('is-invalid'); // Mark one for feedback
                    birthdateFeedback.textContent = 'Please select your full birthdate.';
                    return false;
                }

                const birthDate = new Date(year, month - 1, day);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }

                if (age < 18) {
                    birthYear.classList.add('is-invalid');
                    birthdateFeedback.textContent = 'You must be at least 18 years old to register.';
                    return false;
                }
                return true;
            };

            // AJAX email validation
            const checkEmailOnServer = async (emailValue) => {
                try {
                    const response = await fetch('check-email.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ email: emailValue })
                    });
                    const data = await response.json();
                    if (data.exists) {
                        email.classList.remove('is-valid');
                        email.classList.add('is-invalid');
                        emailFeedback.textContent = 'This email address is already registered.';
                        return false;
                    } else {
                        emailFeedback.textContent = 'Please enter a valid email address.';
                        return true;
                    }
                } catch (error) {
                    console.error('Email check failed:', error);
                    return true; // Fail open, allow submission
                }
            };

            // Event Listeners
            firstName.addEventListener('input', () => validateField(firstName));
            lastName.addEventListener('input', () => validateField(lastName));
            birthDay.addEventListener('change', validateBirthdate);
            birthMonth.addEventListener('change', validateBirthdate);
            birthYear.addEventListener('change', validateBirthdate);
            
            email.addEventListener('input', () => {
                clearTimeout(emailCheckTimeout);
                if (validateField(email)) {
                    emailCheckTimeout = setTimeout(() => {
                        checkEmailOnServer(email.value);
                    }, 500); // Debounce for 500ms
                }
            });

            password.addEventListener('input', () => {
                validateField(password);
                checkPasswordStrength(password.value);
                validateConfirmPassword(); // Re-validate confirm password field
            });

            confirmPassword.addEventListener('input', validateConfirmPassword);

            // Form submission handler
            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                event.stopPropagation();

                // Manually trigger validation on all fields
                const isFirstNameValid = validateField(firstName);
                const isLastNameValid = validateField(lastName);
                const isEmailValid = validateField(email);
                const isPasswordValid = validateField(password);
                const isConfirmPasswordValid = validateConfirmPassword();
                const isBirthdateValid = validateBirthdate();

                let isEmailAvailable = true;
                if (isEmailValid) {
                    // Final check before submitting
                    isEmailAvailable = await checkEmailOnServer(email.value);
                }

                if (isFirstNameValid && isLastNameValid && isEmailValid && isPasswordValid && isConfirmPasswordValid && isEmailAvailable && isBirthdateValid) {
                    // If all is good, submit the form
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = `
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        Creating Account...
                    `;
                    form.submit();
                } else {
                    // Add 'was-validated' to show all messages if needed, but our live validation is better
                    form.classList.add('was-validated');
                }
            });

            // Repopulate and re-validate on page load if there was a server-side error
            <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
                validateField(firstName);
                validateField(lastName);
                if(validateField(email)) {
                    checkEmailOnServer(email.value);
                }
                validateBirthdate();
                validateField(password);
                checkPasswordStrength(password.value);
                validateConfirmPassword(); // This was missing the closing parenthesis
            <?php endif; ?>
        });
    </script>
</body>
</html>
