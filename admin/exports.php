<?php
if (session_status() === PHP_SESSION_NONE) {
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
        if (!in_array('exports.php', $perms)) {
            header("Location: dashboard.php");
            exit();
        }
    } else {
        header("Location: login.php");
        exit();
    }
}

// --- Handle Export Actions (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_action'])) {
    $action = $_POST['export_action'];
    $filename = "export_" . $action . "_" . date('Y-m-d') . ".csv";
    
    // Headers for download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');

    if ($action === 'students') {
        // Export Student Data
        fputcsv($output, ['ID', 'Student Name', 'School ID', 'Email', 'Phone', 'Date of Birth', 'Date Registered']);
        $stmt = $pdo->query("SELECT id, student_name, school_id_number, email, phone, date_of_birth, created_at FROM students ORDER BY student_name ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($action === 'scholarships') {
        // Export Scholarship Data
        fputcsv($output, ['ID', 'Name', 'Category', 'Amount', 'Slots', 'Status', 'Deadline']);
        $stmt = $pdo->query("SELECT id, name, category, amount, available_slots, status, deadline FROM scholarships ORDER BY name ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($action === 'stats') {
        // Export Statistics
        fputcsv($output, ['Metric', 'Count']);
        
        $total_students = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $total_apps = $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn();
        $approved_apps = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'Approved' OR status = 'Active'")->fetchColumn();
        $pending_apps = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'Pending'")->fetchColumn();
        $rejected_apps = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'Rejected'")->fetchColumn();
        $total_scholarships = $pdo->query("SELECT COUNT(*) FROM scholarships")->fetchColumn();

        fputcsv($output, ['Total Registered Students', $total_students]);
        fputcsv($output, ['Total Applications', $total_apps]);
        fputcsv($output, ['Approved/Active Scholars', $approved_apps]);
        fputcsv($output, ['Pending Applications', $pending_apps]);
        fputcsv($output, ['Rejected Applications', $rejected_apps]);
        fputcsv($output, ['Total Scholarship Programs', $total_scholarships]);
    }

    fclose($output);
    exit();
}

$page_title = 'Reports & Exports';
$view = $_GET['view'] ?? 'main';

// If generating a letter, fetch data before header to handle logic
$letter_data = null;
if ($view === 'letter' && isset($_GET['application_id'])) {
    $app_id = filter_input(INPUT_GET, 'application_id', FILTER_SANITIZE_NUMBER_INT);
    $stmt = $pdo->prepare("
        SELECT a.*, s.student_name, s.school_id_number, sch.name as scholarship_name, sch.amount, sch.category
        FROM applications a
        JOIN students s ON a.student_id = s.id
        JOIN scholarships sch ON a.scholarship_id = sch.id
        WHERE a.id = ?
    ");
    $stmt->execute([$app_id]);
    $letter_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

include 'header.php';
?>

<style>
    .export-card {
        transition: transform 0.2s, box-shadow 0.2s;
        cursor: pointer;
        height: 100%;
        border: none;
        border-radius: 10px;
        overflow: hidden;
    }
    .export-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
    }
    .export-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
    }
    
    /* Print Styles for Letter */
    @media print {
        .sidebar, .main-header, .page-header, .no-print {
            display: none !important;
        }
        .main-content {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }
        .letter-container {
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        body {
            background: white !important;
            font-family: 'Times New Roman', Times, serif;
        }
    }
    
    .letter-container {
        background: white;
        padding: 2in;
        max-width: 8.5in;
        margin: 0 auto;
        box-shadow: 0 0 15px rgba(0,0,0,0.1);
        min-height: 11in;
        font-family: 'Times New Roman', Times, serif;
        line-height: 1.6;
    }
    .letter-header {
        text-align: center;
        margin-bottom: 2rem;
        border-bottom: 2px solid #000;
        padding-bottom: 1rem;
    }
    .letter-body {
        font-size: 12pt;
        text-align: justify;
    }
    .letter-footer {
        margin-top: 3rem;
    }
    .signature-line {
        border-top: 1px solid #000;
        width: 250px;
        margin-top: 3rem;
        padding-top: 0.5rem;
    }
</style>

<div class="container-fluid">
    
    <?php if ($view === 'main'): ?>
        <!-- MAIN VIEW: Export Cards -->
        <div class="page-header mb-4" data-aos="fade-down">
            <h1 class="fw-bold">Reports & Exports</h1>
            <p class="text-muted">Select a category to export data or generate documents.</p>
        </div>

        <div class="row g-4" data-aos="fade-up">
            <!-- Card 1: Student Data -->
            <div class="col-md-6 col-xl-3">
                <div class="card export-card shadow-sm" onclick="location.href='exports.php?view=students'">
                    <div class="card-body text-center p-4">
                        <div class="export-icon text-primary"><i class="bi bi-people-fill"></i></div>
                        <h5 class="fw-bold">Student Data</h5>
                        <p class="text-muted small">Export master list of registered students and their details.</p>
                        <button class="btn btn-outline-primary btn-sm w-100 mt-2">View Options</button>
                    </div>
                </div>
            </div>

            <!-- Card 2: Scholarship Data -->
            <div class="col-md-6 col-xl-3">
                <div class="card export-card shadow-sm" onclick="location.href='exports.php?view=scholarships'">
                    <div class="card-body text-center p-4">
                        <div class="export-icon text-success"><i class="bi bi-mortarboard-fill"></i></div>
                        <h5 class="fw-bold">Scholarship Data</h5>
                        <p class="text-muted small">Export list of scholarship programs and details.</p>
                        <button class="btn btn-outline-success btn-sm w-100 mt-2">View Options</button>
                    </div>
                </div>
            </div>

            <!-- Card 3: Statistics -->
            <div class="col-md-6 col-xl-3">
                <div class="card export-card shadow-sm" onclick="location.href='exports.php?view=stats'">
                    <div class="card-body text-center p-4">
                        <div class="export-icon text-warning"><i class="bi bi-bar-chart-fill"></i></div>
                        <h5 class="fw-bold">Results & Stats</h5>
                        <p class="text-muted small">Export summary counts, application results, and trends.</p>
                        <button class="btn btn-outline-warning btn-sm w-100 mt-2">View Options</button>
                    </div>
                </div>
            </div>

            <!-- Card 4: Official Letter -->
            <div class="col-md-6 col-xl-3">
                <div class="card export-card shadow-sm" onclick="location.href='exports.php?view=letter_select'">
                    <div class="card-body text-center p-4">
                        <div class="export-icon text-danger"><i class="bi bi-file-earmark-richtext-fill"></i></div>
                        <h5 class="fw-bold">Official Letter</h5>
                        <p class="text-muted small">Generate and print official scholarship award letters.</p>
                        <button class="btn btn-outline-danger btn-sm w-100 mt-2">Create Template</button>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($view === 'students'): ?>
        <!-- VIEW: Student Exports -->
        <div class="page-header mb-4" data-aos="fade-down">
            <a href="exports.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
            <h1 class="fw-bold">Export Student Data</h1>
        </div>
        <div class="card shadow-sm" data-aos="fade-up">
            <div class="card-body p-4">
                <h5><i class="bi bi-filetype-csv me-2"></i>Master List Export</h5>
                <p class="text-muted">This will download a CSV file containing all registered students.</p>
                <form method="POST">
                    <input type="hidden" name="export_action" value="students">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-download me-2"></i>Download CSV</button>
                </form>
            </div>
        </div>

    <?php elseif ($view === 'scholarships'): ?>
        <!-- VIEW: Scholarship Exports -->
        <div class="page-header mb-4" data-aos="fade-down">
            <a href="exports.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
            <h1 class="fw-bold">Export Scholarship Data</h1>
        </div>
        <div class="card shadow-sm" data-aos="fade-up">
            <div class="card-body p-4">
                <h5><i class="bi bi-filetype-csv me-2"></i>Programs List Export</h5>
                <p class="text-muted">This will download a CSV file containing all scholarship programs and their details.</p>
                <form method="POST">
                    <input type="hidden" name="export_action" value="scholarships">
                    <button type="submit" class="btn btn-success"><i class="bi bi-download me-2"></i>Download CSV</button>
                </form>
            </div>
        </div>

    <?php elseif ($view === 'stats'): ?>
        <!-- VIEW: Stats Exports -->
        <div class="page-header mb-4" data-aos="fade-down">
            <a href="exports.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
            <h1 class="fw-bold">Export Statistics</h1>
        </div>
        <div class="card shadow-sm" data-aos="fade-up">
            <div class="card-body p-4">
                <h5><i class="bi bi-filetype-csv me-2"></i>System Summary Report</h5>
                <p class="text-muted">This will download a CSV file containing total counts of students, applications by status, and programs.</p>
                <form method="POST">
                    <input type="hidden" name="export_action" value="stats">
                    <button type="submit" class="btn btn-warning text-dark"><i class="bi bi-download me-2"></i>Download Report</button>
                </form>
            </div>
        </div>

    <?php elseif ($view === 'letter_select'): ?>
        <!-- VIEW: Letter Selection Form -->
        <div class="page-header mb-4" data-aos="fade-down">
            <a href="exports.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
            <h1 class="fw-bold">Generate Official Letter</h1>
            <p class="text-muted">Select an approved scholar to generate their Notice of Award.</p>
        </div>
        
        <div class="card shadow-sm" data-aos="fade-up">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Student Name</th>
                                <th>Scholarship</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Fetch only Approved or Active applications
                            $stmt = $pdo->query("
                                SELECT a.id, s.student_name, s.school_id_number, sch.name as scholarship_name, a.status 
                                FROM applications a
                                JOIN students s ON a.student_id = s.id
                                JOIN scholarships sch ON a.scholarship_id = sch.id
                                WHERE a.status IN ('Approved', 'Active')
                                ORDER BY a.updated_at DESC
                            ");
                            $scholars = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <?php if (empty($scholars)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No approved scholars found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($scholars as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($row['student_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($row['school_id_number']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['scholarship_name']); ?></td>
                                        <td><span class="badge bg-success"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                        <td class="text-end">
                                            <a href="exports.php?view=letter&application_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-file-text"></i> Generate Letter
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif ($view === 'letter' && $letter_data): ?>
        <!-- VIEW: The Actual Letter Template -->
        <div class="no-print mb-4 d-flex justify-content-between align-items-center" data-aos="fade-down">
            <div>
                <a href="exports.php?view=letter_select" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
                <h4 class="d-inline-block ms-3 mb-0">Preview Letter</h4>
            </div>
            <button onclick="window.print()" class="btn btn-primary btn-lg"><i class="bi bi-printer-fill me-2"></i>Print / Save as PDF</button>
        </div>

        <div class="letter-container" data-aos="fade-up">
            <div class="letter-header">
                <!-- You can add an <img> tag here for a logo -->
                <h2 style="margin-bottom: 0; font-weight: bold; text-transform: uppercase;">Davao Vision College</h2>
                <p style="margin: 0; font-size: 11pt;">Davao City, Philippines</p>
                <h3 style="margin-top: 1.5rem; font-weight: bold; text-decoration: underline;">OFFICE OF THE SCHOLARSHIP COORDINATOR</h3>
            </div>

            <div class="letter-body">
                <p style="text-align: right; margin-bottom: 2rem;">
                    <strong>Date:</strong> <?php echo date("F d, Y"); ?>
                </p>

                <p style="margin-bottom: 2rem;">
                    <strong>MR./MS. <?php echo strtoupper(htmlspecialchars($letter_data['student_name'])); ?></strong><br>
                    Student ID: <?php echo htmlspecialchars($letter_data['school_id_number']); ?><br>
                    Davao Vision College
                </p>

                <h4 style="text-align: center; font-weight: bold; margin-bottom: 2rem;">NOTICE OF SCHOLARSHIP AWARD</h4>

                <p>Dear Mr./Ms. <?php echo htmlspecialchars(explode(' ', trim($letter_data['student_name']))[0]); ?>,</p>

                <p>We are pleased to inform you that your application for the <strong><?php echo htmlspecialchars($letter_data['scholarship_name']); ?></strong> has been <strong>APPROVED</strong> for the current academic term.</p>

                <p>This scholarship grants you the following benefits/amount: <strong>₱<?php echo number_format($letter_data['amount'], 2); ?></strong>.</p>

                <p>As a scholar of Davao Vision College, you are expected to maintain good academic standing and adhere to the policies set forth by the institution. Failure to comply with the scholarship retention requirements may result in the forfeiture of this grant.</p>

                <p>Please sign the conformity section below to officially accept this scholarship award.</p>

                <p>Congratulations on your achievement!</p>
            </div>

            <div class="letter-footer">
                <div class="row">
                    <div class="col-6">
                        <p>Very truly yours,</p>
                        <br><br>
                        <div class="signature-line">
                            <strong>[NAME OF COORDINATOR]</strong><br>
                            Scholarship Coordinator
                        </div>
                    </div>
                    <div class="col-6 text-end">
                        <p>Noted by:</p>
                        <br><br>
                        <div class="signature-line ms-auto">
                            <strong>[NAME OF DEAN/HEAD]</strong><br>
                            Dean of Student Affairs
                        </div>
                    </div>
                </div>

                <div style="margin-top: 4rem; border-top: 1px dashed #999; padding-top: 2rem;">
                    <p style="font-weight: bold; text-decoration: underline;">CONFORME:</p>
                    <p>I, <strong><?php echo htmlspecialchars($letter_data['student_name']); ?></strong>, hereby accept this scholarship and agree to abide by its terms and conditions.</p>
                    
                    <div style="margin-top: 3rem;">
                        <div style="border-top: 1px solid #000; width: 250px; display: inline-block;">
                            Signature over Printed Name
                        </div>
                        <div style="display: inline-block; margin-left: 2rem;">
                            Date: __________________
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

<?php include 'footer.php'; ?>
