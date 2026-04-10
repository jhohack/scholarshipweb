<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

checkSessionTimeout();

// Ensure the user is logged in as a student. This is the correct check.
if (!isStudent()) { 
    header("Location: ../public/login.php");
    exit();
}

// --- Migration: Add profile_picture_path to users table ---
try {
    $pdo->query("SELECT profile_picture_path FROM users LIMIT 1");
} catch (PDOException $e) {
    // If it fails, the column doesn't exist. Add it.
    $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture_path VARCHAR(255) NULL DEFAULT NULL");
}

// Fetch user profile information
$user_id = $_SESSION['user_id'];
$student_stmt = $pdo->prepare("
    SELECT s.id as student_id, u.first_name, u.middle_name, u.last_name, u.contact_number, u.birthdate, u.email as user_email, u.school_id, u.profile_picture_path
    FROM users u
    LEFT JOIN students s ON u.id = s.user_id
    WHERE u.id = ?
");
$student_stmt->execute([$user_id]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $school_id = trim($_POST['school_id'] ?? '');
    $profile_picture = $_FILES['profile_picture'] ?? null;
    $current_picture_path = $student['profile_picture_path'] ?? null;

    if (empty($first_name) || empty($last_name)) {
        $errors[] = "First Name and Last Name are required.";
    } else {
        // --- Handle Profile Picture Upload ---
        $new_picture_path = $current_picture_path;
        if ($profile_picture && $profile_picture['error'] === UPLOAD_ERR_OK) {
            $upload_result = storeUploadedFile(
                $pdo,
                $profile_picture,
                'avatars',
                'user_' . $user_id . '_',
                ['image/jpeg', 'image/png', 'image/gif'],
                appUploadMaxBytes(),
                $base_path
            );

            if ($upload_result['success']) {
                deleteStoredFileByPath($pdo, $current_picture_path, $base_path);
                $new_picture_path = $upload_result['path'];
            } else {
                $errors[] = $upload_result['error'] ?? "Failed to upload profile picture.";
            }
        }
        if (empty($errors)) { try {
            $pdo->beginTransaction();

            // Check if a student record exists. If not, create one.
            if (empty($student['student_id'])) {
                // Fetch full user data to populate the new student record completely
                $user_stmt = $pdo->prepare("SELECT school_id, email FROM users WHERE id = ?");
                $user_stmt->execute([$user_id]);
                $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

                if ($user_data) {
                    $school_id_for_student = !empty($user_data['school_id']) ? $user_data['school_id'] : null;
                    $student['student_id'] = dbExecuteInsert(
                        $pdo,
                        "INSERT INTO students (user_id, student_name, school_id_number, email, phone, date_of_birth) VALUES (?, ?, ?, ?, ?, ?)",
                        [
                            $user_id,
                            trim("$first_name $middle_name $last_name"),
                            $school_id_for_student,
                            $user_data['email'],
                            $contact_number,
                            $birthdate
                        ]
                    );
                }
            }

            // Update the users table
            $update_user_stmt = $pdo->prepare(
                "UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, contact_number = ?, birthdate = ?, school_id = ?, profile_picture_path = ? WHERE id = ?"
            );
            $update_user_stmt->execute([
                $first_name,
                $middle_name,
                $last_name,
                $contact_number,
                $birthdate,
                $school_id !== '' ? $school_id : null,
                $new_picture_path,
                $user_id
            ]);

            // Also update the students table to keep it in sync
            $update_student_stmt = $pdo->prepare(
                "UPDATE students SET student_name = ?, school_id_number = ?, phone = ?, date_of_birth = ? WHERE id = ?"
            );
            $update_student_stmt->execute([
                trim("$first_name $middle_name $last_name"),
                $school_id !== '' ? $school_id : null,
                $contact_number,
                $birthdate,
                $student['student_id']
            ]);

            $pdo->commit();
            $success = "Profile updated successfully.";

            // Refresh the data to show the update immediately
            $student_stmt->execute([$user_id]);
            $student = $student_stmt->fetch(PDO::FETCH_ASSOC);

            // Update the name in the session to reflect the change immediately in the header
            $_SESSION['name'] = trim($first_name . ' ' . $last_name);
            $_SESSION['profile_picture_path'] = $new_picture_path;


        } catch (PDOException $e) {
            $errors[] = "A database error occurred. Please try again.";
            error_log($e->getMessage());
            $pdo->rollBack();
    }
    }
    }}

$page_title = 'My Profile';
include 'header.php';
?>

<div class="page-header" data-aos="fade-down">
    <h1 class="fw-bold">My Profile</h1>
    <p class="text-muted">Keep your personal and contact information up to date.</p>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" data-aos="fade-up"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success" data-aos="fade-up"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="content-block" data-aos="fade-up" data-aos-delay="100">
    <form action="profile.php" method="post" enctype="multipart/form-data">
        <div class="row g-4">
            <div class="col-12 text-center mb-3">
                <?php
                    $avatar_path = defaultAvatarUrl(trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')));
                    if (!empty($student['profile_picture_path'])) {
                        $profile_image = describeStoredFile($pdo, $student['profile_picture_path'], $base_path, $avatar_path);
                        if (!empty($profile_image['url'])) {
                            $avatar_path = $profile_image['url'];
                        }
                    }
                ?>
                <img src="<?php echo htmlspecialchars($avatar_path); ?>" alt="Profile Picture" class="rounded-circle mb-3" width="150" height="150" style="object-fit: cover; border: 4px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                <div class="col-md-6 mx-auto">
                    <label for="profile_picture" class="form-label">Change Profile Picture</label>
                    <input class="form-control" type="file" id="profile_picture" name="profile_picture" accept="image/png, image/jpeg, image/gif">
                </div>
            </div>
            <div class="col-md-4">
                <label for="first_name" class="form-label">First Name</label>
                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>" required>
            </div>
            <div class="col-md-4">
                <label for="middle_name" class="form-label">Middle Name</label>
                <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label for="last_name" class="form-label">Last Name</label>
                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>" required>
            </div>
            <div class="col-md-6">
                <label for="user_email" class="form-label">Account Email (Login)</label>
                <input type="email" class="form-control" id="user_email" name="user_email" value="<?php echo htmlspecialchars($student['user_email'] ?? ''); ?>" disabled readonly>
                <div class="form-text">This is your login email and cannot be changed.</div>
            </div>
            <div class="col-md-6">
                <label for="contact_number" class="form-label">Phone Number</label>
                <input type="tel" class="form-control" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($student['contact_number'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label for="birthdate" class="form-label">Date of Birth</label>
                <input type="date" class="form-control" id="birthdate" name="birthdate" value="<?php echo htmlspecialchars($student['birthdate'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label for="school_id" class="form-label">School ID</label>
                <input type="text" class="form-control" id="school_id" name="school_id" value="<?php echo htmlspecialchars($student['school_id'] ?? ''); ?>" placeholder="Enter your school ID once available">
                <div class="form-text">Incoming students can leave this blank for now and update it later after enrollment.</div>
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Update Profile</button>
        </div>
    </form>
</div>

<?php include 'footer.php'; ?>
