<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_trans_sid', 1);
    ini_set('session.use_only_cookies', 0);
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';
require_once $base_path . '/includes/auth.php';

checkSessionTimeout();

if (!isAdmin()) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
        $perms = $_SESSION['permissions'] ?? [];
        if (!in_array('exam-results.php', $perms)) {
            header("Location: dashboard.php");
            exit();
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            die("Action Restricted: Staff accounts are View Only.");
        }
    } else {
        header("Location: login.php");
        exit();
    }
}

$page_title = 'Exam Results';
include 'header.php';

// Function to check if score meets the 75% passing requirement
function checkExamPassStatus($score, $total_items) {
    if ($total_items <= 0) {
        return false;
    }
    $percentage = ($score / $total_items) * 100;
    return $percentage >= 75;
}

// Fetch scholarships for filter
$scholarships_list = $pdo->query("SELECT id, name FROM scholarships ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

// Build Query with Filters
$query = "
    SELECT es.*, s.name as scholarship_name, st.student_name, s.passing_score, u.first_name, u.middle_name, u.last_name
    FROM exam_submissions es
    JOIN scholarships s ON es.scholarship_id = s.id
    JOIN students st ON es.student_id = st.id
    JOIN users u ON st.user_id = u.id
    WHERE 1=1
";
$params = [];

if (!empty($_GET['search'])) {
    $query .= " AND st.student_name LIKE ?";
    $params[] = "%" . $_GET['search'] . "%";
}
if (!empty($_GET['scholarship_id'])) {
    $query .= " AND es.scholarship_id = ?";
    $params[] = $_GET['scholarship_id'];
}

$query .= " ORDER BY es.start_time DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Statistics
$total_exams = count($submissions);
$passed_count = 0;
$graded_count = 0;
$sum_percentage = 0;

foreach ($submissions as $sub) {
    if ($sub['status'] === 'graded') {
        $graded_count++;
        $pct = ($sub['total_items'] > 0) ? ($sub['score'] / $sub['total_items']) * 100 : 0;
        $sum_percentage += $pct;
        if (checkExamPassStatus($sub['score'], $sub['total_items'])) {
            $passed_count++;
        }
    }
}

$avg_score = $graded_count > 0 ? round($sum_percentage / $graded_count, 1) : 0;
$pass_rate = $graded_count > 0 ? round(($passed_count / $graded_count) * 100, 1) : 0;
?>

<div class="page-header" data-aos="fade-down">
    <h1 class="fw-bold">Exam Results</h1>
    <p class="text-muted">Review and grade student entrance exams.</p>
</div>

<!-- Statistics Cards -->
<div class="row g-4 mb-4" data-aos="fade-up">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body">
                <h6 class="text-muted text-uppercase mb-2">Total Submissions</h6>
                <h2 class="fw-bold mb-0 text-primary"><?php echo $total_exams; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body">
                <h6 class="text-muted text-uppercase mb-2">Pass Rate</h6>
                <h2 class="fw-bold mb-0 text-success"><?php echo $pass_rate; ?>%</h2>
                <small class="text-muted"><?php echo $passed_count; ?> passed out of <?php echo $graded_count; ?> graded</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
            <div class="card-body">
                <h6 class="text-muted text-uppercase mb-2">Average Score</h6>
                <h2 class="fw-bold mb-0 text-info"><?php echo $avg_score; ?>%</h2>
            </div>
        </div>
    </div>
</div>

<!-- Filters & Actions -->
<div class="content-block mb-4" data-aos="fade-up" data-aos-delay="100">
    <form method="GET" id="filterForm" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label fw-bold">Search Student</label>
            <input type="text" name="search" class="form-control" placeholder="Enter name..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold">Filter by Scholarship</label>
            <select name="scholarship_id" class="form-select">
                <option value="">All Scholarships</option>
                <?php foreach ($scholarships_list as $id => $name): ?>
                    <option value="<?php echo $id; ?>" <?php echo (isset($_GET['scholarship_id']) && $_GET['scholarship_id'] == $id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-filter"></i> Filter</button>
                <a href="exam-results.php" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </div>
    </form>
</div>

<div class="content-block" data-aos="fade-up" data-aos-delay="200">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Student</th>
                    <th>Scholarship</th>
                    <th style="width: 25%;">Score</th>
                    <th>Status</th>
                    <th>Date Taken</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No exam submissions found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $sub): ?>
                        <?php 
                            $percentage = ($sub['total_items'] > 0) ? round(($sub['score'] / $sub['total_items']) * 100) : 0;
                            $passed = checkExamPassStatus($sub['score'], $sub['total_items']);
                            $status_color = match($sub['status']) {
                                'graded' => $passed ? 'success' : 'danger',
                                'submitted' => 'warning',
                                default => 'secondary'
                            };
                            $status_text = match($sub['status']) {
                                'graded' => $passed ? 'Passed' : 'Failed',
                                'submitted' => 'Needs Review',
                                default => 'In Progress'
                            };
                        ?>
                        <tr>
                            <td class="fw-bold">
                                <?php 
                                    $displayName = $sub['student_name'];
                                    if (!empty($sub['last_name'])) {
                                        $displayName = $sub['last_name'] . ', ' . $sub['first_name'] . ' ' . $sub['middle_name'];
                                    }
                                    echo htmlspecialchars(strtoupper(trim($displayName))); 
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($sub['scholarship_name']); ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1 me-2">
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar bg-<?php echo $percentage >= 75 ? 'success' : 'danger'; ?>" role="progressbar" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                    </div>
                                    <span class="small fw-bold"><?php echo $sub['score']; ?>/<?php echo $sub['total_items']; ?></span>
                                </div>
                                <small class="text-muted"><?php echo $percentage; ?>%</small>
                            </td>
                            <td><span class="badge bg-<?php echo $status_color; ?>"><?php echo $status_text; ?></span></td>
                            <td><?php echo date("M d, Y", strtotime($sub['start_time'])); ?></td>
                            <td class="text-end">
                                <a href="view-exam.php?id=<?php echo $sub['id']; ?>" class="btn btn-primary btn-sm">
                                    <i class="bi bi-eye-fill"></i> Review
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>