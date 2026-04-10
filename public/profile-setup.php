<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Migration: Add profile_picture_path to users table ---
try {
    $pdo->query("SELECT profile_picture_path FROM users LIMIT 1");
} catch (PDOException $e) {
    // If it fails, the column doesn't exist. Add it.
    $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture_path VARCHAR(255) NULL DEFAULT NULL");
}

// Security check: ensure user has verified their email and has registration data.
if (!isset($_SESSION['is_verified_for_setup']) || !isset($_SESSION['registration_data'])) {
    header("Location: register.php");
    exit();
}

$errors = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $student_type = 'New Applicant'; // Automatically set to New Applicant
    $profile_picture = $_FILES['profile_picture'] ?? null;

    // --- Handle Profile Picture Upload ---
    $picture_path = null;
    if (empty($errors)) {
        if ($profile_picture && $profile_picture['error'] === UPLOAD_ERR_OK) {
            $upload_dir = $base_path . '/public/uploads/avatars/';
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0777, true)) {
                    $errors[] = "Failed to create upload directory. Please check server permissions.";
                }
            }
            
            if (empty($errors)) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                if (in_array($profile_picture['type'], $allowed_types)) {
                    // Generate a unique name but keep it somewhat identifiable
                    $safe_filename = preg_replace('/[^A-Za-z0-9.\-]/', '_', basename($profile_picture['name']));
                    // We don't have user_id yet, so we use a temp unique id
                    $new_filename = 'new_user_' . uniqid() . '_' . $safe_filename;
                    $destination = $upload_dir . $new_filename;

                    if (move_uploaded_file($profile_picture['tmp_name'], $destination)) {
                        $picture_path = 'uploads/avatars/' . $new_filename;
                    } else {
                        $errors[] = "Failed to upload profile picture.";
                    }
                } else {
                    $errors[] = "Invalid file type for profile picture. Please use JPG, PNG, or GIF.";
                }
            }
        }
    }

    if (empty($errors)) {
        // All data is collected. Now, save the complete user record to the database.
        try {
            $pdo->beginTransaction();

            $reg_data = $_SESSION['registration_data'];

            $school_id = $reg_data['school_id'] ?? null;
            $check_sql = "SELECT id FROM users WHERE email = ?";
            $check_params = [$reg_data['email']];

            if (!empty($school_id)) {
                $check_sql .= " OR school_id = ?";
                $check_params[] = $school_id;
            }

            // Check for existing user records to prevent duplicate errors on retry.
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute($check_params);
            
            while ($existing_user = $check_stmt->fetch()) {
                // Check if this user has a student record
                $check_student = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
                $check_student->execute([$existing_user['id']]);
                if (!$check_student->fetch()) {
                    // Partial registration detected (User exists, Student does not). Delete to allow retry.
                    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$existing_user['id']]);
                }
            }

            $insert_stmt = $pdo->prepare(
                "INSERT INTO users (first_name, middle_name, last_name, email, school_id, password, contact_number, birthdate, email_verified, role, status, student_type, profile_picture_path) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'student', 'active', ?, ?)"
            );
            
            $insert_stmt->execute([
                $reg_data['first_name'],
                $reg_data['middle_name'] ?? '',
                $reg_data['last_name'],
                $reg_data['email'],
                $school_id,
                $reg_data['password'], // Storing plain text password as requested
                null, // Contact number removed from this step
                $reg_data['birthdate'],
                $student_type,
                $picture_path
            ]);

            $user_id = $pdo->lastInsertId();

            // --- Step 3.5: Create the corresponding record in the `students` table ---
            $insert_student_stmt = $pdo->prepare(
                "INSERT INTO students (user_id, student_name, school_id_number, email, phone, date_of_birth) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $insert_student_stmt->execute([
                $user_id,
                trim("{$reg_data['first_name']} {$reg_data['middle_name']} {$reg_data['last_name']}"),
                $school_id,
                $reg_data['email'],
                null, // Contact number removed from this step
                $reg_data['birthdate']
            ]);

            $pdo->commit();

            // Clean up registration session data
            unset($_SESSION['registration_data']);
            unset($_SESSION['is_verified_for_setup']);
            
            // --- Step 4: Registration Confirmation & Auto-Login ---
            // Automatically log the user in by setting session variables
            session_regenerate_id(true); // Prevent session fixation
            $_SESSION['user_id'] = $user_id;
            $_SESSION['name'] = $reg_data['first_name'] . ' ' . $reg_data['last_name'];
            $_SESSION['role'] = 'student';
            $_SESSION['profile_picture_path'] = $picture_path;

            // Set a flash message to be displayed on the dashboard
            flashMessage('Your account has been successfully registered.');
            
            // Redirect to the student dashboard
            header("Location: ../student/dashboard.php");
            exit();

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Show the specific error message for debugging
            $errors[] = "Database Error: " . $e->getMessage();
            error_log("Profile Setup Error: " . $e->getMessage());
        }
    }
}

$page_title = 'Complete Your Profile';
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
                                <i class="bi bi-person-lines-fill display-3 text-primary"></i>
                                <h2 class="fw-bold mt-3">Almost There!</h2>
                            </div>
                            <p class="text-center text-muted mb-4">Your email is verified. Please complete your profile to finish creating your account.</p>
                            
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($errors as $error): ?>
                                        <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <form action="profile-setup.php" method="POST" id="profile-setup-form" novalidate enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label for="profile_picture" class="form-label">Profile Picture (Optional)</label>
                                    <input class="form-control" type="file" id="profile_picture" name="profile_picture" accept="image/png, image/jpeg, image/gif">
                                </div>
                                <div class="d-grid mt-4">
                                    <button type="submit" class="btn btn-primary btn-lg" id="complete-reg-btn">Complete Registration</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Simple client-side validation for a better UX
        document.addEventListener('DOMContentLoaded', function() {
            const submitBtn = document.getElementById('complete-reg-btn');
            if (submitBtn) {
                submitBtn.form.addEventListener('submit', function() {
                    if (submitBtn.form.checkValidity()) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = `
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            Saving...
                        `;
                    }
                });
            }
            const form = document.getElementById('profile-setup-form');
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }

                form.classList.add('was-validated');
            }, false);
        });
    </script>
</body>
</html>
