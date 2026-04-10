<?php
if (session_status() === PHP_SESSION_NONE) {
    // Enable URL-based session IDs to allow multiple accounts in the same browser (multitasking)
    ini_set('session.use_trans_sid', 1);
    ini_set('session.use_only_cookies', 0);
    // Use a unique session name for Admin to allow simultaneous login with Student portal (multitasking)
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/functions.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/auth.php';

checkSessionTimeout();

// --- Database Migration: Add permissions column to users table ---
try {
    $pdo->query("SELECT permissions FROM users LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE users ADD COLUMN permissions TEXT NULL DEFAULT NULL");
}

// Access control for User Management
if (!isAdmin()) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
        $perms = $_SESSION['permissions'] ?? [];
        if (!in_array('users.php', $perms)) {
            header("Location: dashboard.php");
            exit();
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            die("Action Restricted: Staff accounts are View Only.");
        }
    } else {
        header("Location: " . (isset($_SESSION['role']) && $_SESSION['role'] === 'student' ? "../student/dashboard.php" : "login.php"));
        exit();
    }
}

$action = $_GET['action'] ?? 'list';

// --- Handle AJAX request for user data ---
if ($action === 'get_user' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $user_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.middle_name, u.last_name, u.email, u.school_id, u.role, u.permissions, u.created_at, u.profile_picture_path,
                   s.id as student_id, s.phone, s.date_of_birth
            FROM users u
            LEFT JOIN students s ON u.id = s.user_id
            WHERE u.id = ?
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $user['profile_picture_url'] = storedFilePathToUrl($user['profile_picture_path'] ?? '');
            $response = ['user' => $user, 'application' => null, 'responses' => []];

            // If user is a student, fetch latest application info
            if ($user['role'] === 'student' && !empty($user['student_id'])) {
                $stmt_app = $pdo->prepare("
                    SELECT a.*, s.name as scholarship_name 
                    FROM applications a
                    JOIN scholarships s ON a.scholarship_id = s.id
                    WHERE a.student_id = ?
                    ORDER BY a.submitted_at DESC
                    LIMIT 1
                ");
                $stmt_app->execute([$user['student_id']]);
                $app = $stmt_app->fetch(PDO::FETCH_ASSOC);

                if ($app) {
                    $response['application'] = $app;

                    // Fetch Q&A Responses
                    $stmt_responses = $pdo->prepare("
                        SELECT ff.field_label, ar.response_value
                        FROM application_responses ar
                        JOIN form_fields ff ON ar.form_field_id = ff.id
                        WHERE ar.application_id = ?
                        ORDER BY ff.field_order ASC
                    ");
                    $stmt_responses->execute([$app['id']]);
                    $response['responses'] = $stmt_responses->fetchAll(PDO::FETCH_ASSOC);

                    // If no responses found (e.g., Renewal application without new form data), 
                    // fetch responses from the most recent application that has them.
                    if (empty($response['responses'])) {
                        $stmt_fallback = $pdo->prepare("
                            SELECT a.id 
                            FROM applications a
                            WHERE a.student_id = ? 
                            AND EXISTS (SELECT 1 FROM application_responses ar WHERE ar.application_id = a.id)
                            ORDER BY a.submitted_at DESC 
                            LIMIT 1
                        ");
                        $stmt_fallback->execute([$user['student_id']]);
                        $fallback_id = $stmt_fallback->fetchColumn();
                        if ($fallback_id) {
                            $stmt_responses->execute([$fallback_id]);
                            $response['responses'] = $stmt_responses->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                }
            }
            echo json_encode($response);
        } else {
            echo json_encode(['error' => 'User not found.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Database error.']);
    }
    exit();
}

// --- Handle AJAX request for application status check ---
if ($action === 'check_app_status' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $user_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
    
    try {
        // Get student ID
        $stmt = $pdo->prepare("SELECT id, school_id_number, student_name FROM students WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            echo json_encode(['status' => 'alert', 'message' => 'This user is not registered as a student.']);
            exit;
        }

        // Get latest application
        $stmt = $pdo->prepare("
            SELECT a.status, s.id as scholarship_id, s.name as scholarship_name
            FROM applications a
            JOIN scholarships s ON a.scholarship_id = s.id
            WHERE a.student_id = ?
            ORDER BY a.submitted_at DESC
            LIMIT 1
        ");
        $stmt->execute([$student['id']]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            echo json_encode(['status' => 'alert', 'message' => 'No scholarship applications found for this student.']);
        } else {
            if (in_array($app['status'], ['Approved', 'Active'])) {
                $url = "applications.php?scholarship_id=" . $app['scholarship_id'] . "&search=" . urlencode($student['school_id_number']);
                echo json_encode(['status' => 'redirect', 'url' => $url]);
            } else {
                $msg = "Student " . $student['student_name'] . " has a " . $app['status'] . " application for " . $app['scholarship_name'] . ".";
                echo json_encode(['status' => 'alert', 'message' => $msg]);
            }
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'alert', 'message' => 'Database error.']);
    }
    exit;
}

// --- Handle Export Request ---
if ($action === 'export_users') {
    $filename = "users_export_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'First Name', 'Last Name', 'Email', 'School ID', 'Role', 'Status', 'Date Created']);

    // Re-use search logic or fetch all
    // For simplicity in this context, we export all active users or filter if needed. 
    // Here we export all non-deleted users for simplicity.
    $stmt = $pdo->query("SELECT id, first_name, last_name, email, school_id, role, status, created_at FROM users ORDER BY created_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

$errors = [];
$success = '';

// --- Handle POST Requests ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid request. Please try again.";
    } else {
        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_SANITIZE_NUMBER_INT);
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save_user') {
                $user_id = filter_input(INPUT_POST, 'user_id', FILTER_SANITIZE_NUMBER_INT);
                $first_name = trim($_POST['first_name'] ?? '');
                $middle_name = trim($_POST['middle_name'] ?? '');
                $last_name = trim($_POST['last_name'] ?? '');
                $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
                $school_id = trim($_POST['school_id'] ?? '');
                $role = $_POST['role'];
                $password = $_POST['password'];
                $permissions = ($role === 'staff' && isset($_POST['permissions'])) ? json_encode($_POST['permissions']) : null;

                // --- 1. Validation ---
                // --- Validation ---
                if (empty($first_name) || empty($last_name) || empty($email) || !in_array($role, ['student', 'admin', 'staff'])) {
                    $errors[] = "First Name, Last Name, Email, and valid Role are required.";
                }
                if ($role === 'student' && empty($school_id)) {
                    $errors[] = "School ID is required for students.";
                }

                // Check Email Uniqueness
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $user_id ?: 0]);
                if ($stmt->fetch()) {
                    $errors[] = "Email address is already in use.";
                }

                // Check School ID Uniqueness (if student)
                if ($role === 'student' && !empty($school_id)) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE school_id = ? AND id != ?");
                    $stmt->execute([$school_id, $user_id ?: 0]);
                    if ($stmt->fetch()) {
                        $errors[] = "School ID is already registered.";
                    }
                }

                if (empty($errors)) {
                    $full_name = trim("$first_name $middle_name $last_name");
                    
                    if ($user_id) { // Update
                        if (!empty($password)) {
                            $sql = "UPDATE users SET first_name=?, middle_name=?, last_name=?, email=?, school_id=?, role=?, permissions=?, password=? WHERE id=?";
                            $params = [$first_name, $middle_name, $last_name, $email, !empty($school_id) ? $school_id : null, $role, $permissions, $password, $user_id];
                        } else {
                            $sql = "UPDATE users SET first_name=?, middle_name=?, last_name=?, email=?, school_id=?, role=?, permissions=? WHERE id=?";
                            $params = [$first_name, $middle_name, $last_name, $email, !empty($school_id) ? $school_id : null, $role, $permissions, $user_id];
                        }
                        $pdo->prepare($sql)->execute($params);
                        
                        // Sync with students table if role is student
                        if ($role === 'student') {
                            $check_student = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
                            $check_student->execute([$user_id]);
                            if ($check_student->fetch()) {
                                $update_student = $pdo->prepare("UPDATE students SET student_name = ?, school_id_number = ?, email = ? WHERE user_id = ?");
                                $update_student->execute([$full_name, $school_id, $email, $user_id]);
                            } else {
                                // Create if missing
                                $pdo->prepare("INSERT INTO students (user_id, student_name, school_id_number, email) VALUES (?, ?, ?, ?)")->execute([$user_id, $full_name, $school_id, $email]);
                            }
                        }
                        $success = "User updated successfully.";
                    } else { // Create
                        if (empty($password)) {
                            $errors[] = "Password is required for new users.";
                        } else {
                            // Storing plain text password as requested.
                            $sql = "INSERT INTO users (first_name, middle_name, last_name, email, school_id, role, permissions, password, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
                            $user_id = dbExecuteInsert(
                                $pdo,
                                $sql,
                                [
                                    $first_name,
                                    $middle_name,
                                    $last_name,
                                    $email,
                                    !empty($school_id) ? $school_id : null,
                                    $role,
                                    $permissions,
                                    $password
                                ]
                            );
                            $success = "User created successfully.";

                            // If the new user is a student, create a corresponding entry in the students table
                            if ($role === 'student') {
                                $student_insert_stmt = $pdo->prepare(
                                    "INSERT INTO students (user_id, student_name, school_id_number, email, phone, date_of_birth) VALUES (?, ?, ?, ?, ?, ?)"
                                );
                                // We may not have phone/dob, so we insert what we have.
                                $student_insert_stmt->execute([
                                    $user_id, $full_name, $school_id, $email, null, null
                                ]);
                            }
                        }
                    }
                }
            } elseif ($action === 'archive_user' || $action === 'restore_user') {
                if ($user_id) {
                    // Prevent admin from archiving their own account
                    if ($user_id == $_SESSION['user_id']) {
                        $errors[] = "You cannot archive your own account.";
                    } else {
                        $new_status = ($action === 'archive_user') ? 'archived' : 'active';
                        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                        $stmt->execute([$new_status, $user_id]);
                        $success = "User has been successfully " . rtrim($new_status, 'd') . "ed.";
                    }
                }
            } elseif ($action === 'delete_user_permanently') {
                if ($user_id) {
                    if ($user_id == $_SESSION['user_id']) {
                        $errors[] = "You cannot delete your own account.";
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $success = "User has been permanently deleted.";
                    }
                }
            } elseif ($action === 'bulk_archive' || $action === 'bulk_restore' || $action === 'bulk_delete') {
                $selected_ids = $_POST['selected_users'] ?? [];
                if (!empty($selected_ids)) {
                    $count = 0;
                    if ($action === 'bulk_delete') {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND id != ?");
                        foreach ($selected_ids as $id) {
                            $stmt->execute([$id, $_SESSION['user_id']]); // Prevent self-delete
                            $count += $stmt->rowCount();
                        }
                        $success = "$count users permanently deleted.";
                    } else {
                        $new_status = ($action === 'bulk_archive') ? 'archived' : 'active';
                        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND id != ?");
                        foreach ($selected_ids as $id) {
                            $stmt->execute([$new_status, $id, $_SESSION['user_id']]); // Prevent self-archive
                            $count += $stmt->rowCount();
                        }
                        $success = "$count users updated to $new_status.";
                    }
                } else {
                    $errors[] = "No users selected.";
                }
            }
        } catch (PDOException $e) {
            $errors[] = "A database error occurred. Please try again later.";
            // error_log($e->getMessage());
        }
    }
}

// --- Fetching Logic with Filtering and Searching ---
$search_query = $_GET['search'] ?? '';
$filter_role = $_GET['role'] ?? '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$active_users = [];
$archived_users = [];
$total_active = 0;
$total_archived = 0;

try {
    $base_sql = " FROM users";
    $whereClauses = [];
    $params = [];

    if (!empty($search_query)) {
        $whereClauses[] = "(first_name LIKE :search OR last_name LIKE :search OR school_id LIKE :search)";
        $params['search'] = '%' . $search_query . '%';
    }
    if (!empty($filter_role)) {
        $whereClauses[] = "role = :role";
        $params['role'] = $filter_role;
    }

    $where_sql = !empty($whereClauses) ? " AND " . implode(' AND ', $whereClauses) : '';

    // Fetch Active Users
    $count_active_stmt = $pdo->prepare("SELECT COUNT(*) " . $base_sql . " WHERE status = 'active'" . $where_sql);
    $count_active_stmt->execute($params);
    $total_active = (int) $count_active_stmt->fetchColumn();

    $active_stmt = $pdo->prepare("SELECT id, first_name, middle_name, last_name, email, school_id, role, status, created_at, profile_picture_path " . $base_sql . " WHERE status = 'active'" . $where_sql . " ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $active_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $active_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => &$val) {
        $active_stmt->bindParam(':' . $key, $val);
    }
    $active_stmt->execute();
    $active_users = $active_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Archived Users
    $count_archived_stmt = $pdo->prepare("SELECT COUNT(*) " . $base_sql . " WHERE status = 'archived'" . $where_sql);
    $count_archived_stmt->execute($params);
    $total_archived = (int) $count_archived_stmt->fetchColumn();

    $archived_stmt = $pdo->prepare("SELECT id, first_name, middle_name, last_name, email, school_id, role, status, created_at, profile_picture_path " . $base_sql . " WHERE status = 'archived'" . $where_sql . " ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $archived_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $archived_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => &$val) {
        $archived_stmt->bindParam(':' . $key, $val);
    }
    $archived_stmt->execute();
    $archived_users = $archived_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errors[] = "A database error occurred while fetching users. Please try again later.";
    error_log($e->getMessage());
}

$page_title = 'User Management';
$csrf_token = generate_csrf_token();

// --- AJAX Response for Live Search ---
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Generate Active Users Table HTML
    ob_start();
    if (empty($active_users)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No active users found.</td></tr>
    <?php else: ?>
        <?php foreach ($active_users as $user): ?>
            <tr>
                <td><input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" class="form-check-input user-checkbox"></td>
                <td>
                    <div class="d-flex align-items-center">
                        <?php if (!empty($user['profile_picture_path'])): ?>
                            <img src="<?php echo htmlspecialchars(storedFilePathToUrl($user['profile_picture_path'])); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32" style="object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-secondary bg-opacity-10 d-flex align-items-center justify-content-center me-2 text-secondary" style="width: 32px; height: 32px;"><i class="bi bi-person-fill"></i></div>
                        <?php endif; ?>
                        <div class="fw-bold"><?php echo htmlspecialchars(strtoupper(trim($user['last_name'] . ', ' . $user['first_name'] . ' ' . $user['middle_name']))); ?></div>
                    </div>
                </td>
                <td><span class="font-monospace"><?php echo htmlspecialchars($user['school_id'] ?? ''); ?></span></td>
                <td><span class="text-muted"><?php echo htmlspecialchars($user['email']); ?></span></td>
                <td><span class="badge bg-info-soft text-info text-uppercase"><?php echo htmlspecialchars($user['role']); ?></span></td>
                <td class="text-end" style="width: 120px;">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-strategy="fixed">
                            Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($user['role'] === 'student'): ?>
                                <li><button type="button" class="dropdown-item view-profile-btn" data-user-id="<?php echo $user['id']; ?>"><i class="bi bi-person-lines-fill me-2"></i>View Profile</button></li>
                                <li><a class="dropdown-item view-app-btn" href="#" data-user-id="<?php echo $user['id']; ?>"><i class="bi bi-file-earmark-text me-2"></i>View Applications</a></li>
                            <?php endif; ?>
                            <li><button type="button" class="dropdown-item edit-user-btn" data-user-id="<?php echo $user['id']; ?>"><i class="bi bi-pencil-fill me-2"></i>Edit</button></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form action="users.php" method="post" onsubmit="return confirm('Are you sure you want to archive this user?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" name="action" value="archive_user" class="dropdown-item text-warning" <?php if ($user['id'] == $_SESSION['user_id']) echo 'disabled'; ?>><i class="bi bi-archive-fill me-2"></i>Archive</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    $active_users_html = ob_get_clean();

    // Generate Archived Users Table HTML
    ob_start();
    if (empty($archived_users)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No archived users found.</td></tr>
    <?php else: ?>
        <?php foreach ($archived_users as $user): ?>
            <tr class="text-muted">
                <td><input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" class="form-check-input user-checkbox"></td>
                <td>
                    <div class="d-flex align-items-center">
                        <?php if (!empty($user['profile_picture_path'])): ?>
                            <img src="<?php echo htmlspecialchars(storedFilePathToUrl($user['profile_picture_path'])); ?>" alt="Profile" class="rounded-circle me-2 grayscale" width="32" height="32" style="object-fit: cover; filter: grayscale(100%);">
                        <?php else: ?>
                            <div class="rounded-circle bg-secondary bg-opacity-10 d-flex align-items-center justify-content-center me-2 text-secondary" style="width: 32px; height: 32px;"><i class="bi bi-person-fill"></i></div>
                        <?php endif; ?>
                        <div class="fw-bold"><?php echo htmlspecialchars(strtoupper(trim($user['last_name'] . ', ' . $user['first_name'] . ' ' . $user['middle_name']))); ?></div>
                    </div>
                </td>
                <td><span class="font-monospace"><?php echo htmlspecialchars($user['school_id'] ?? ''); ?></span></td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td><span class="badge bg-secondary-soft text-secondary text-uppercase"><?php echo htmlspecialchars($user['role']); ?></span></td>
                <td class="text-end">
                    <form action="users.php" method="post" onsubmit="return confirm('Are you sure you want to restore this user?');" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        <input type="hidden" name="action" value="restore_user">
                        <button type="submit" class="btn btn-sm btn-outline-success" title="Restore User"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                    </form>
                    <form action="users.php" method="post" onsubmit="return confirm('PERMANENTLY DELETE? This action cannot be undone.');" style="display:inline;" class="ms-1">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        <input type="hidden" name="action" value="delete_user_permanently">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete Permanently"><i class="bi bi-trash-fill"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    $archived_users_html = ob_get_clean();

    // Generate Pagination HTML
    ob_start();
    $total_pages = ceil($total_active / $per_page);
    if ($total_pages > 1) {
        echo '<nav class="mt-4" aria-label="Page navigation"><ul class="pagination justify-content-center">';
        $prev_disabled = $page <= 1 ? "disabled" : "";
        echo "<li class='page-item {$prev_disabled}'><a class='page-link' href='#' onclick='changePage(".($page - 1)."); return false;'>Previous</a></li>";
        for ($i = 1; $i <= $total_pages; $i++) {
            $active_class = $page == $i ? "active" : "";
            echo "<li class='page-item {$active_class}'><a class='page-link' href='#' onclick='changePage({$i}); return false;'>{$i}</a></li>";
        }
        $next_disabled = $page >= $total_pages ? "disabled" : "";
        echo "<li class='page-item {$next_disabled}'><a class='page-link' href='#' onclick='changePage(".($page + 1)."); return false;'>Next</a></li>";
        echo '</ul></nav>';
    }
    $pagination_html = ob_get_clean();

    echo json_encode([
        'active_users_html' => $active_users_html,
        'archived_users_html' => $archived_users_html,
        'pagination_html' => $pagination_html,
        'total_active' => $total_active,
        'total_archived' => $total_archived
    ]);
    exit;
}

include 'header.php'; // This includes the sidebar and main layout
?>

<div class="page-header d-flex justify-content-between align-items-center" data-aos="fade-down">
    <div>
        <h1 class="fw-bold">User Management</h1>
        <p class="text-muted">View, search, and manage all user accounts.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="users.php?action=export_users" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet-fill me-2"></i>Export CSV</a>
        <?php if (isAdmin()): ?>
        <button class="btn btn-primary" id="createUserBtn"><i class="bi bi-plus-circle-fill me-2"></i>Create User</button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" data-aos="fade-up">
        <?php foreach ($errors as $error): ?>
            <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success" data-aos="fade-up">
        <p class="mb-0"><?php echo htmlspecialchars($success); ?></p>
    </div>
<?php endif; ?>

<!-- Filter and Search Form -->
<div class="content-block" data-aos="fade-up">
    <form onsubmit="return false;" class="row g-3 align-items-end">
        <div class="col-md-6">
            <label for="search" class="form-label">Search by Name or School ID</label>
            <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
        </div>
        <div class="col-md-4">
            <label for="role" class="form-label">Filter by Role</label>
            <select id="role" name="role" class="form-select">
                <option value="">All Roles</option>
                <option value="student" <?php if ($filter_role === 'student') echo 'selected'; ?>>Student</option>
                <option value="admin" <?php if ($filter_role === 'admin') echo 'selected'; ?>>Admin</option>
                <option value="staff" <?php if ($filter_role === 'staff') echo 'selected'; ?>>Staff</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="button" id="filterBtn" class="btn btn-primary w-100"><i class="bi bi-funnel-fill"></i> Filter</button>
        </div>
    </form>
</div>

<!-- Bulk Actions Toolbar (Hidden by default) -->
<?php if (isAdmin()): ?>
<div id="bulkActionsToolbar" class="alert alert-info d-none d-flex justify-content-between align-items-center mt-3" data-aos="fade-up">
    <div>
        <i class="bi bi-check-square-fill me-2"></i> <span id="selectedCount">0</span> users selected
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-warning text-dark" onclick="submitBulkAction('bulk_archive')"><i class="bi bi-archive-fill me-1"></i> Archive</button>
        <button type="button" class="btn btn-sm btn-danger" onclick="submitBulkAction('bulk_delete')"><i class="bi bi-trash-fill me-1"></i> Delete</button>
    </div>
</div>
<?php endif; ?>

<form id="bulkActionForm" action="users.php" method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    <input type="hidden" name="action" id="bulkActionInput">

<!-- Active Users Table -->
<div class="content-block mt-4" data-aos="fade-up" data-aos-delay="100">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">Active Users</h3>
        <span id="activeUsersCount" class="badge bg-primary-soft text-primary fs-6 rounded-pill"><?php echo $total_active; ?> Active</span>
    </div>
    <div class="table-responsive pb-5" style="min-height: 500px;">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 40px;"><input type="checkbox" class="form-check-input" id="selectAllActive"></th>
                    <th>Name</th>
                    <th>School ID</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="activeUsersTableBody">
                <?php if (empty($active_users)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No active users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($active_users as $user): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" class="form-check-input user-checkbox"></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($user['profile_picture_path'])): ?>
                                        <img src="<?php echo htmlspecialchars(storedFilePathToUrl($user['profile_picture_path'])); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32" style="object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary bg-opacity-10 d-flex align-items-center justify-content-center me-2 text-secondary" style="width: 32px; height: 32px;"><i class="bi bi-person-fill"></i></div>
                                    <?php endif; ?>
                        <div class="fw-bold"><?php echo htmlspecialchars(strtoupper(trim($user['last_name'] . ', ' . $user['first_name'] . ' ' . $user['middle_name']))); ?></div>
                                </div>
                            </td>
                            <td><span class="font-monospace"><?php echo htmlspecialchars($user['school_id'] ?? ''); ?></span></td>
                            <td><span class="text-muted"><?php echo htmlspecialchars($user['email']); ?></span></td>
                            <td><span class="badge bg-info-soft text-info text-uppercase"><?php echo htmlspecialchars($user['role']); ?></span></td>
                            <td class="text-end" style="width: 120px;">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-strategy="fixed">
                                        Actions
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <?php if ($user['role'] === 'student'): ?>
                                            <li><button type="button" class="dropdown-item view-profile-btn" data-user-id="<?php echo $user['id']; ?>"><i class="bi bi-person-lines-fill me-2"></i>View Profile</button></li>
                                            <li><a class="dropdown-item view-app-btn" href="#" data-user-id="<?php echo $user['id']; ?>"><i class="bi bi-file-earmark-text me-2"></i>View Applications</a></li>
                                        <?php endif; ?>
                                        <?php if (isAdmin()): ?>
                                        <li><button type="button" class="dropdown-item edit-user-btn" data-user-id="<?php echo $user['id']; ?>"><i class="bi bi-pencil-fill me-2"></i>Edit</button></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form action="users.php" method="post" onsubmit="return confirm('Are you sure you want to archive this user?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="action" value="archive_user" class="dropdown-item text-warning" <?php if ($user['id'] == $_SESSION['user_id']) echo 'disabled'; ?>><i class="bi bi-archive-fill me-2"></i>Archive</button>
                                            </form>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Pagination for Active Users -->
    <div id="paginationContainer">
        <?php echo generatePagination($page, ceil($total_active / $per_page), ['search' => $search_query, 'role' => $filter_role]); ?>
    </div>
</div>

<!-- Archived Users Table -->
<div class="content-block mt-5" data-aos="fade-up" data-aos-delay="200">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">Archived Users</h3>
        <span id="archivedUsersCount" class="badge bg-secondary-soft text-secondary fs-6 rounded-pill"><?php echo $total_archived; ?> Archived</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 40px;"><input type="checkbox" class="form-check-input" id="selectAllArchived"></th>
                    <th>Name</th>
                    <th>School ID</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="archivedUsersTableBody">
                <?php if (empty($archived_users)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No archived users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($archived_users as $user): ?>
                        <tr class="text-muted">
                            <td><input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" class="form-check-input user-checkbox"></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($user['profile_picture_path'])): ?>
                                        <img src="<?php echo htmlspecialchars(storedFilePathToUrl($user['profile_picture_path'])); ?>" alt="Profile" class="rounded-circle me-2 grayscale" width="32" height="32" style="object-fit: cover; filter: grayscale(100%);">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary bg-opacity-10 d-flex align-items-center justify-content-center me-2 text-secondary" style="width: 32px; height: 32px;"><i class="bi bi-person-fill"></i></div>
                                    <?php endif; ?>
                        <div class="fw-bold"><?php echo htmlspecialchars(strtoupper(trim($user['last_name'] . ', ' . $user['first_name'] . ' ' . $user['middle_name']))); ?></div>
                                </div>
                            </td>
                            <td><span class="font-monospace"><?php echo htmlspecialchars($user['school_id'] ?? ''); ?></span></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><span class="badge bg-secondary-soft text-secondary text-uppercase"><?php echo htmlspecialchars($user['role']); ?></span></td>
                            <td class="text-end">
                                <?php if (isAdmin()): ?>
                                <form action="users.php" method="post" onsubmit="return confirm('Are you sure you want to restore this user?');" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <input type="hidden" name="action" value="restore_user">
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Restore User">
                                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                                    </button>
                                </form>
                                <form action="users.php" method="post" onsubmit="return confirm('PERMANENTLY DELETE? This action cannot be undone.');" style="display:inline;" class="ms-1">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <input type="hidden" name="action" value="delete_user_permanently">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete Permanently">
                                        <i class="bi bi-trash-fill"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Pagination for Archived Users could be added here if needed -->
</div>
</form>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="users.php" method="POST" id="userForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel">Create New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="user_id" id="user_id">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" required>
                        </div>
                        <div class="col-md-4">
                            <label for="middle_name" class="form-label">Middle Name</label>
                            <input type="text" class="form-control" id="middle_name" name="middle_name">
                        </div>
                        <div class="col-md-4">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="col-md-6">
                            <label for="school_id" class="form-label">School ID</label>
                            <input type="text" class="form-control" id="school_id" name="school_id" pattern="^(20)\d{9}$" title="Format: YYYY0000000 (e.g., 20240000000)">
                            <div class="form-text">Required for students.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="student">Student</option>
                                <option value="admin">Admin</option>
                                <option value="staff">Staff</option>
                            </select>
                        </div>
                        <div class="col-12" id="password-field-container">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password">
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="form-text" id="password-help-text">Leave blank to keep current password.</div>
                        </div>
                        
                        <!-- Staff Permissions Checklist -->
                        <div class="col-12" id="staff-permissions-container" style="display: none;">
                            <label class="form-label fw-bold">Staff Access Permissions (View Only)</label>
                            <div class="card p-3 bg-light">
                                <div class="row g-2">
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input permission-cb" type="checkbox" name="permissions[]" value="dashboard.php" id="perm_dashboard"><label class="form-check-label" for="perm_dashboard">Dashboard</label></div></div>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input permission-cb" type="checkbox" name="permissions[]" value="scholarships.php" id="perm_scholarships"><label class="form-check-label" for="perm_scholarships">Scholarships</label></div></div>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input permission-cb" type="checkbox" name="permissions[]" value="applications.php" id="perm_applications"><label class="form-check-label" for="perm_applications">Applications</label></div></div>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input permission-cb" type="checkbox" name="permissions[]" value="exam-results.php" id="perm_exam"><label class="form-check-label" for="perm_exam">Exam Results</label></div></div>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input permission-cb" type="checkbox" name="permissions[]" value="messages.php" id="perm_messages"><label class="form-check-label" for="perm_messages">Messages</label></div></div>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input permission-cb" type="checkbox" name="permissions[]" value="announcements.php" id="perm_announcements"><label class="form-check-label" for="perm_announcements">Announcements</label></div></div>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input permission-cb" type="checkbox" name="permissions[]" value="exports.php" id="perm_exports"><label class="form-check-label" for="perm_exports">Reports & Exports</label></div></div>
                                    <div class="col-md-6"><div class="form-check"><input class="form-check-input permission-cb" type="checkbox" name="permissions[]" value="users.php" id="perm_users"><label class="form-check-label" for="perm_users">User Management</label></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View User Profile Modal -->
<div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content border-0 shadow-lg overflow-hidden" style="height: 85vh;">
            <!-- Close button positioned absolutely -->
            <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3 z-3" data-bs-dismiss="modal" aria-label="Close"></button>
            
            <div class="modal-body p-0 h-100">
                <div class="row g-0 h-100">
                    <!-- Left Sidebar: Student Identity -->
                    <div class="col-lg-3 bg-primary text-white p-4 text-center d-flex flex-column h-100 overflow-auto">
                        <div class="mt-5 mb-4">
                            <div class="bg-white text-primary mx-auto mb-3 shadow d-flex align-items-center justify-content-center rounded-circle" style="width: 120px; height: 120px; font-size: 3.5rem;" id="view_profile_picture_container">
                            </div>
                            <h4 class="fw-bold mb-1 text-uppercase" id="view_full_name"></h4>
                            <div class="badge bg-white text-primary text-uppercase px-3 py-2 mt-2 rounded-pill" id="view_role"></div>
                        </div>
                        
                        <div class="text-start px-2 mt-2">
                            <div class="mb-4 p-3 bg-white bg-opacity-10 rounded">
                                <label class="small text-uppercase opacity-75 fw-bold d-block mb-1">School ID</label>
                                <div class="fs-5 font-monospace" id="view_school_id"></div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="small text-uppercase opacity-50 fw-bold d-block mb-2">Contact Info</label>
                                <div class="mb-2 d-flex align-items-center"><i class="bi bi-envelope me-3 opacity-75 fs-5"></i><span id="view_email" class="text-break small"></span></div>
                                <div class="d-flex align-items-center"><i class="bi bi-telephone me-3 opacity-75 fs-5"></i><span id="view_phone" class="small"></span></div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="small text-uppercase opacity-50 fw-bold d-block mb-2">Personal Details</label>
                                <div class="mb-2 d-flex align-items-center"><i class="bi bi-calendar-event me-3 opacity-75 fs-5"></i><span class="small">Born: <span id="view_dob"></span></span></div>
                                <div class="d-flex align-items-center"><i class="bi bi-clock-history me-3 opacity-75 fs-5"></i><span class="small">Joined: <span id="view_created_at"></span></span></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Content: Application Data -->
                    <div class="col-lg-9 bg-light d-flex flex-column h-100">
                        <!-- Header Area -->
                        <div class="px-4 py-3 bg-white border-bottom d-flex justify-content-between align-items-center">
                            <h5 class="fw-bold text-primary mb-0"><i class="bi bi-file-text-fill me-2"></i>Application Profile</h5>
                        </div>

                        <!-- Scrollable Q&A Area -->
                        <div class="p-4 flex-grow-1 overflow-auto">
                            <h6 class="text-muted text-uppercase fw-bold small mb-3">Questionnaire Responses</h6>
                            <div id="qa_responses_container" class="row g-3">
                                <!-- JS Populated -->
                            </div>
                        </div>
                        
                        <!-- Bottom Fixed: Latest Application Context -->
                        <div class="p-4 bg-white border-top shadow-sm mt-auto z-2">
                            <h6 class="fw-bold text-muted text-uppercase small mb-3"><i class="bi bi-info-circle-fill me-2"></i>Latest Application Status</h6>
                            <div id="application_details_container">
                                <!-- JS Populated -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Fix for modal backdrop freezing issue (move modals to body)
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        document.body.appendChild(modal);
    });

    const viewUserModal = new bootstrap.Modal(document.getElementById('viewUserModal'));
    const userModal = new bootstrap.Modal(document.getElementById('userModal'));
    const userForm = document.getElementById('userForm');
    const modalLabel = document.getElementById('userModalLabel');
    const userIdInput = document.getElementById('user_id');
    const firstNameInput = document.getElementById('first_name');
    const middleNameInput = document.getElementById('middle_name');
    const lastNameInput = document.getElementById('last_name');
    const emailInput = document.getElementById('email');
    const schoolIdInput = document.getElementById('school_id');
    const roleInput = document.getElementById('role');
    const passwordContainer = document.getElementById('password-field-container');
    const passwordInput = document.getElementById('password');
    const passwordHelpText = document.getElementById('password-help-text');
    const staffPermissionsContainer = document.getElementById('staff-permissions-container');


    // Handle role change to toggle school_id requirement
    roleInput.addEventListener('change', function() {
        schoolIdInput.required = this.value === 'student';
        // Show permissions if staff
        staffPermissionsContainer.style.display = (this.value === 'staff') ? 'block' : 'none';
    });

    // Handle "Create User" button click
    document.getElementById('createUserBtn').addEventListener('click', function() {
        userForm.reset();
        userIdInput.value = '';
        modalLabel.textContent = 'Create New User';
        passwordInput.required = true;
        passwordHelpText.style.display = 'none';
        schoolIdInput.required = roleInput.value === 'student'; // Set requirement based on default role
        staffPermissionsContainer.style.display = 'none';
        document.querySelectorAll('.permission-cb').forEach(cb => cb.checked = false);
        userModal.show();
    });

    // --- Event Delegation for dynamically loaded content ---
    document.body.addEventListener('click', function(event) {
        const editBtn = event.target.closest('.edit-user-btn');
        if (editBtn) {
            event.preventDefault();
            const userId = editBtn.getAttribute('data-user-id');
            // Fetch user data via AJAX
            fetch(`users.php?action=get_user&id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    // Populate the modal
                    userForm.reset();
                    modalLabel.textContent = `Edit User: ${data.user.first_name} ${data.user.last_name}`;
                    userIdInput.value = data.user.id;
                    firstNameInput.value = data.user.first_name;
                    middleNameInput.value = data.user.middle_name;
                    lastNameInput.value = data.user.last_name;
                    emailInput.value = data.user.email;
                    schoolIdInput.value = data.user.school_id;
                    roleInput.value = data.user.role;
                    passwordContainer.style.display = 'block'; // Or you can hide it for edits
                    passwordInput.required = false;
                    passwordHelpText.style.display = 'block';
                    schoolIdInput.required = data.user.role === 'student';
                    
                    // Handle Permissions
                    staffPermissionsContainer.style.display = (data.user.role === 'staff') ? 'block' : 'none';
                    document.querySelectorAll('.permission-cb').forEach(cb => cb.checked = false);
                    if (data.user.permissions) {
                        try {
                            const perms = JSON.parse(data.user.permissions);
                            perms.forEach(p => {
                                const cb = document.querySelector(`.permission-cb[value="${p}"]`);
                                if(cb) cb.checked = true;
                            });
                        } catch(e) {}
                    }

                    userModal.show();
                });
        }

        const viewProfileBtn = event.target.closest('.view-profile-btn');
        if (viewProfileBtn) {
            event.preventDefault();
            const userId = viewProfileBtn.getAttribute('data-user-id');
            // Fetch user data via AJAX
            fetch(`users.php?action=get_user&id=${userId}&_=${new Date().getTime()}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    
                    const user = data.user;
                    const app = data.application;
                    const responses = data.responses;

                    // Populate profile picture
                    const picContainer = document.getElementById('view_profile_picture_container');
                    if (user.profile_picture_url) {
                        picContainer.innerHTML = `<img src="${user.profile_picture_url}?t=${new Date().getTime()}" alt="Profile" class="rounded-circle d-block" style="width: 100%; height: 100%; object-fit: cover;">`;
                    } else {
                        picContainer.innerHTML = `<i class="bi bi-person-fill"></i>`;
                    }


                    // Populate the view modal
                    document.getElementById('view_full_name').textContent = `${user.first_name} ${user.middle_name || ''} ${user.last_name}`;
                    document.getElementById('view_role').textContent = user.role;
                    document.getElementById('view_school_id').textContent = user.school_id || 'N/A';
                    document.getElementById('view_created_at').textContent = new Date(user.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                    document.getElementById('view_email').textContent = user.email;
                    document.getElementById('view_phone').textContent = user.phone || 'Not provided';
                    document.getElementById('view_dob').textContent = user.date_of_birth ? new Date(user.date_of_birth).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'Not provided';
                    
                    // Populate Application Details
                    const appContainer = document.getElementById('application_details_container');
                    if (app) {
                        const statusColors = {
                            'Approved': 'success', 'Active': 'success',
                            'Rejected': 'danger', 'Dropped': 'secondary',
                            'Pending': 'warning text-dark', 'Under Review': 'info text-dark',
                            'Renewal Request': 'primary', 'For Renewal': 'warning text-dark'
                        };
                        const badgeColor = statusColors[app.status] || 'secondary';

                        appContainer.innerHTML = `
                            <div class="row g-3 align-items-center">
                                <div class="col-md-5">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-${badgeColor} bg-opacity-10 text-${badgeColor} rounded p-3 me-3">
                                            <i class="bi bi-mortarboard-fill fs-4"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark">${app.scholarship_name}</div>
                                            <span class="badge bg-${badgeColor}">${app.status}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-2 text-muted small">
                                        <div class="col-4">
                                            <strong class="d-block text-dark">Type</strong> ${app.applicant_type}
                                        </div>
                                        <div class="col-4">
                                            <strong class="d-block text-dark">Applied</strong> ${new Date(app.submitted_at).toLocaleDateString()}
                                        </div>
                                        <div class="col-4">
                                            <strong class="d-block text-dark">GWA</strong> ${app.gwa || 'N/A'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        appContainer.innerHTML = '<div class="text-center text-muted py-3 fst-italic">No application history found.</div>';
                    }

                    // Populate Q&A Responses
                    const qaContainer = document.getElementById('qa_responses_container');
                    if (responses && responses.length > 0) {
                        let qaHtml = '<div class="row g-3">';
                        responses.forEach(item => {
                            qaHtml += `
                                <div class="col-md-6">
                                    <div class="p-3 border rounded bg-white h-100 shadow-sm">
                                        <div class="fw-bold text-secondary mb-2 small text-uppercase" style="font-size: 0.75rem;">${item.field_label}</div>
                                        <div class="text-dark text-break fw-medium">${item.response_value || '<em class="text-muted fw-normal">No answer</em>'}</div>
                                    </div>
                                </div>
                            `;
                        });
                        qaHtml += '</div>';
                        qaContainer.innerHTML = qaHtml;
                    } else {
                        qaContainer.innerHTML = '<div class="alert alert-light text-center text-muted">No Q&A responses available.</div>';
                    }
                    
                    viewUserModal.show();
                });
        }

        const viewAppBtn = event.target.closest('.view-app-btn');
        if (viewAppBtn) {
            event.preventDefault();
            const userId = this.getAttribute('data-user-id');
            
            fetch(`users.php?action=check_app_status&id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'redirect') {
                        window.location.href = data.url;
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while checking application status.');
                });
        }
    });

    // Toggle password visibility
    document.getElementById('togglePassword').addEventListener('click', function() {
        const icon = this.querySelector('i');
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        icon.classList.toggle('bi-eye');
        icon.classList.toggle('bi-eye-slash');
    });

    // --- Live Search & Pagination Logic ---
    const searchInput = document.getElementById('search');
    const filterBtn = document.getElementById('filterBtn');
    let debounceTimer;
    let currentPage = 1;

    window.changePage = function(page) {
        if (page < 1) return;
        currentPage = page;
        performLiveSearch();
    }

    function performLiveSearch() {
        const search = searchInput.value;
        const role = roleInput.value;
        const url = `users.php?ajax=1&search=${encodeURIComponent(search)}&role=${encodeURIComponent(role)}&page=${currentPage}`;

        // Show loading spinner
        document.getElementById('activeUsersTableBody').innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';
        document.getElementById('archivedUsersTableBody').innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border text-secondary" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>';

        fetch(url)
            .then(response => response.json())
            .then(data => {
                document.getElementById('activeUsersTableBody').innerHTML = data.active_users_html;
                document.getElementById('archivedUsersTableBody').innerHTML = data.archived_users_html;
                document.getElementById('paginationContainer').innerHTML = data.pagination_html;
                document.getElementById('activeUsersCount').textContent = `${data.total_active} Active`;
                document.getElementById('archivedUsersCount').textContent = `${data.total_archived} Archived`;
                updateToolbar(); // Reset bulk action toolbar
            })
            .catch(error => console.error('Error:', error));
    }

    searchInput.addEventListener('input', function() {
        currentPage = 1;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(performLiveSearch, 350);
    });

    roleInput.addEventListener('change', function() {
        currentPage = 1;
        performLiveSearch();
    });

    filterBtn.addEventListener('click', function() {
        currentPage = 1;
        performLiveSearch();
    });

    // --- Bulk Actions Logic ---
    const toolbar = document.getElementById('bulkActionsToolbar');
    const selectedCountSpan = document.getElementById('selectedCount');

    function updateToolbar() {
        const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
        selectedCountSpan.textContent = checkedCount;
        toolbar.classList.toggle('d-none', checkedCount === 0);
    }

    // Use event delegation for checkboxes as well
    document.body.addEventListener('change', function(event) {
        if (event.target.matches('.user-checkbox')) {
            updateToolbar();
        }
        if (event.target.matches('#selectAllActive')) {
            const table = event.target.closest('table');
            if (table) {
                table.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = event.target.checked);
                updateToolbar();
            }
        }
        if (event.target.matches('#selectAllArchived')) {
            const table = event.target.closest('table');
            if (table) {
                table.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = event.target.checked);
                updateToolbar();
            }
        }
    });

    // Initial call to set up toolbar state
    updateToolbar();

    window.submitBulkAction = function(action) {
        if (document.querySelectorAll('.user-checkbox:checked').length === 0) {
            alert('Please select at least one user.');
            return;
        }
        if (confirm('Are you sure you want to perform this action on the selected users?')) {
            document.getElementById('bulkActionInput').value = action;
            document.getElementById('bulkActionForm').submit();
        }
    };

    // Override default pagination link behavior
    document.getElementById('paginationContainer').addEventListener('click', function(e) {
        if (e.target.tagName === 'A') {
            e.preventDefault();
            // Try to get page number from href or data attribute for initial load links
            const href = e.target.getAttribute('href');
            if (href && href.includes('page=')) {
                const match = href.match(/page=(\d+)/);
                if (match) {
                    changePage(parseInt(match[1]));
                }
            }
        }
    });
});
</script>
