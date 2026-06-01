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
        if (!in_array('exports.php', $perms, true)) {
            header("Location: dashboard.php");
            exit();
        }
    } else {
        header("Location: login.php");
        exit();
    }
}

try {
    dbEnsureUserStudentSyncSchema($pdo);
    dbEnsureStudentAcademicSchema($pdo);
    dbEnsureApplicationsSchema($pdo);
} catch (PDOException $e) {
    $errors[] = 'Database error while preparing academic report fields.';
    error_log($e->getMessage());
}

function reportH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function selectedOption($current, $expected): string
{
    return (string) $current === (string) $expected ? 'selected' : '';
}

function formatReportDate($value, bool $includeTime = false): string
{
    $time = strtotime((string) $value);
    if (!$time) {
        return '';
    }

    return date($includeTime ? 'M d, Y h:i A' : 'M d, Y', $time);
}

function formatReportMoney($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return 'PHP ' . number_format((float) $value, 2);
}

function formatReportAcademic(array $row, string $field): string
{
    return resolveStudentAcademicField($row, $field);
}

function formatReportCoverage(array $row): string
{
    if (($row['scholarship_percentage'] ?? '') !== '' && $row['scholarship_percentage'] !== null) {
        return number_format((float) $row['scholarship_percentage'], 0) . '%';
    }

    if (($row['scholarship_amount'] ?? '') !== '' && $row['scholarship_amount'] !== null) {
        return formatReportMoney($row['scholarship_amount']);
    }

    if (($row['scholarship_base_amount'] ?? '') !== '' && $row['scholarship_base_amount'] !== null) {
        $amountType = trim((string) ($row['amount_type'] ?? ''));
        $amount = (float) $row['scholarship_base_amount'];
        return strcasecmp($amountType, 'Percentage') === 0 ? number_format($amount, 0) . '%' : formatReportMoney($amount);
    }

    return '';
}

function statusBadgeClass(string $status): string
{
    return match ($status) {
        'Approved', 'Active', 'Accepted' => 'bg-success',
        'Rejected', 'Dropped' => 'bg-danger',
        'Pending' => 'bg-warning text-dark',
        'Under Review' => 'bg-info text-dark',
        'For Renewal', 'Renewal Request' => 'bg-primary',
        default => 'bg-secondary',
    };
}

function buildReportWhere(array $filters, array &$params): string
{
    $where = [];

    if ($filters['scholarship_id'] !== '') {
        $where[] = 'a.scholarship_id = :scholarship_id';
        $params['scholarship_id'] = (int) $filters['scholarship_id'];
    }

    if ($filters['status'] !== '') {
        $where[] = 'a.status = :status';
        $params['status'] = $filters['status'];
    }

    if ($filters['applicant_type'] !== '') {
        $where[] = 'a.applicant_type = :applicant_type';
        $params['applicant_type'] = $filters['applicant_type'];
    }

    if ($filters['search'] !== '') {
        $where[] = "(
            LOWER(COALESCE(st.student_name, '')) LIKE :search
            OR LOWER(COALESCE(st.school_id_number, '')) LIKE :search
            OR LOWER(COALESCE(st.email, '')) LIKE :search
            OR LOWER(COALESCE(sch.name, '')) LIKE :search
        )";
        $params['search'] = '%' . strtolower(preg_replace('/\s+/', ' ', $filters['search'])) . '%';
    }

    return $where ? ' WHERE ' . implode(' AND ', $where) : '';
}

function fetchReportRows(PDO $pdo, array $filters): array
{
    $params = [];
    $whereSql = buildReportWhere($filters, $params);
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.student_id,
            a.scholarship_id,
            a.status,
            a.applicant_type,
            a.student_status,
            a.program,
            a.year_level,
            a.year_program,
            a.scholarship_percentage,
            a.scholarship_amount,
            a.submitted_at,
            a.updated_at,
            a.remarks,
            st.student_name,
            st.school_id_number,
            st.email,
            st.phone,
            st.date_of_birth,
            st.program AS student_program,
            st.year_level AS student_year_level,
            sch.name AS scholarship_name,
            sch.category AS scholarship_category,
            sch.amount AS scholarship_base_amount,
            sch.amount_type
        FROM applications a
        JOIN students st ON a.student_id = st.id
        JOIN scholarships sch ON a.scholarship_id = sch.id
        $whereSql
        ORDER BY sch.name ASC, st.student_name ASC, a.submitted_at DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchOfficialScholarRows(PDO $pdo, ?int $scholarshipId = null): array
{
    $params = [];
    $whereSql = " WHERE a.status IN ('Approved', 'Active', 'Accepted')";

    if ($scholarshipId !== null && $scholarshipId > 0) {
        $whereSql .= ' AND a.scholarship_id = :scholarship_id';
        $params['scholarship_id'] = $scholarshipId;
    }

    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.student_id,
            a.scholarship_id,
            a.status,
            a.applicant_type,
            a.student_status,
            a.program,
            a.year_level,
            a.year_program,
            a.scholarship_percentage,
            a.scholarship_amount,
            a.submitted_at,
            a.updated_at,
            a.remarks,
            st.student_name,
            st.school_id_number,
            st.email,
            st.phone,
            st.date_of_birth,
            st.program AS student_program,
            st.year_level AS student_year_level,
            sch.name AS scholarship_name,
            sch.category AS scholarship_category,
            sch.amount AS scholarship_base_amount,
            sch.amount_type
        FROM applications a
        JOIN students st ON a.student_id = st.id
        JOIN scholarships sch ON a.scholarship_id = sch.id
        $whereSql
        ORDER BY sch.name ASC, st.student_name ASC, COALESCE(a.updated_at, a.submitted_at) DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchReportRowByApplicationId(PDO $pdo, int $applicationId): ?array
{
    if ($applicationId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.student_id,
            a.scholarship_id,
            a.status,
            a.applicant_type,
            a.student_status,
            a.program,
            a.year_level,
            a.year_program,
            a.scholarship_percentage,
            a.scholarship_amount,
            a.submitted_at,
            a.updated_at,
            a.remarks,
            st.student_name,
            st.school_id_number,
            st.email,
            st.phone,
            st.date_of_birth,
            st.program AS student_program,
            st.year_level AS student_year_level,
            sch.name AS scholarship_name,
            sch.category AS scholarship_category,
            sch.amount AS scholarship_base_amount,
            sch.amount_type
        FROM applications a
        JOIN students st ON a.student_id = st.id
        JOIN scholarships sch ON a.scholarship_id = sch.id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$applicationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fetchScholarshipSummary(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            sch.id,
            sch.name,
            sch.category,
            sch.available_slots,
            COUNT(a.id) AS total_applications,
            SUM(CASE WHEN a.status IN ('Approved', 'Active', 'Accepted') THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN a.status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN a.status = 'Under Review' THEN 1 ELSE 0 END) AS review_count,
            SUM(CASE WHEN a.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN a.status IN ('For Renewal', 'Renewal Request') THEN 1 ELSE 0 END) AS renewal_count,
            SUM(COALESCE(a.scholarship_amount, 0)) AS awarded_amount
        FROM scholarships sch
        LEFT JOIN applications a ON a.scholarship_id = sch.id
        GROUP BY sch.id, sch.name, sch.category, sch.available_slots
        ORDER BY sch.name ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function outputReportCsv(array $rows): void
{
    $filename = 'scholarship_student_report_' . date('Y-m-d_H-i') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Scholarship',
        'Category',
        'Student Name',
        'School ID',
        'Email',
        'Phone',
        'Applicant Type',
        'Student Status',
        'Program',
        'Year Level',
        'Application Status',
        'Coverage',
        'Awarded Amount',
        'Date Applied',
        'Last Updated',
        'Remarks',
    ]);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['scholarship_name'],
            $row['scholarship_category'],
            $row['student_name'],
            $row['school_id_number'],
            $row['email'],
            $row['phone'],
            $row['applicant_type'],
            $row['student_status'],
            formatReportAcademic($row, 'program'),
            formatReportAcademic($row, 'year_level'),
            $row['status'],
            formatReportCoverage($row),
            $row['scholarship_amount'],
            formatReportDate($row['submitted_at'], true),
            formatReportDate($row['updated_at'], true),
            $row['remarks'],
        ]);
    }

    fclose($output);
}

function outputOfficialScholarsCsv(array $rows, string $scholarshipName = ''): void
{
    $safeName = $scholarshipName !== '' ? preg_replace('/[^A-Za-z0-9_-]+/', '_', strtolower($scholarshipName)) : 'all_scholarships';
    $filename = 'official_scholars_' . trim($safeName, '_') . '_' . date('Y-m-d_H-i') . '.csv';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Scholarship',
        'Student Name',
        'School ID',
        'Email',
        'Phone',
        'Program',
        'Year Level',
        'Applicant Type',
        'Status',
        'Coverage',
        'Awarded Amount',
        'Date Approved/Updated',
    ]);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['scholarship_name'],
            $row['student_name'],
            $row['school_id_number'],
            $row['email'],
            $row['phone'],
            formatReportAcademic($row, 'program'),
            formatReportAcademic($row, 'year_level'),
            $row['applicant_type'],
            $row['status'],
            formatReportCoverage($row),
            $row['scholarship_amount'],
            formatReportDate($row['updated_at'] ?: $row['submitted_at'], true),
        ]);
    }

    fclose($output);
}

function buildLetterBody(array $row, string $letterType): array
{
    $studentName = $row['student_name'] ?? 'Student';
    $scholarshipName = $row['scholarship_name'] ?? 'the scholarship program';
    $coverage = formatReportCoverage($row);
    $coverageText = $coverage !== '' ? " The recorded scholarship benefit is {$coverage}." : '';
    $remarks = trim((string) ($row['remarks'] ?? ''));

    if ($letterType === 'rejection') {
        return [
            'title' => 'Scholarship Application Result',
            'subject' => 'Notice of Scholarship Application Result',
            'paragraphs' => [
                "Thank you for applying for {$scholarshipName}. After review, we regret to inform you that your application was not approved for the current evaluation period.",
                $remarks !== '' ? "Remarks: {$remarks}" : 'You may contact the Scholarship Office if you need clarification about the result or future application opportunities.',
                'We appreciate your interest and encourage you to continue monitoring available scholarship programs.',
            ],
        ];
    }

    if ($letterType === 'renewal') {
        return [
            'title' => 'Scholarship Renewal Notice',
            'subject' => 'Notice of Scholarship Renewal',
            'paragraphs' => [
                "Our records show that your scholarship under {$scholarshipName} is marked for renewal processing.",
                'Please coordinate with the Scholarship Office and submit any required renewal documents within the prescribed period.',
                $remarks !== '' ? "Remarks: {$remarks}" : 'Failure to complete renewal requirements may affect continued scholarship eligibility.',
            ],
        ];
    }

    if ($letterType === 'requirements') {
        return [
            'title' => 'Scholarship Requirement Notice',
            'subject' => 'Notice of Required Scholarship Documents',
            'paragraphs' => [
                "Your application for {$scholarshipName} requires additional review or document completion.",
                $remarks !== '' ? "Required action or remarks: {$remarks}" : 'Please check your application record and submit the missing or updated requirements requested by the Scholarship Office.',
                'Kindly complete the requirements as soon as possible so your application can proceed.',
            ],
        ];
    }

    return [
        'title' => 'Notice of Scholarship Award',
        'subject' => 'Notice of Scholarship Award',
        'paragraphs' => [
            "We are pleased to inform you that your application for {$scholarshipName} has been approved for the current academic term.{$coverageText}",
            'As a scholar, you are expected to maintain good academic standing and comply with the retention requirements and policies of the institution.',
            'Please sign the conformity section below to officially acknowledge this scholarship notice.',
        ],
    ];
}

$filters = [
    'scholarship_id' => trim((string) ($_GET['scholarship_id'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'applicant_type' => trim((string) ($_GET['applicant_type'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
$view = $_GET['view'] ?? 'report';
$tab = $_GET['tab'] ?? 'scholarships';
if (!in_array($tab, ['scholarships', 'letters', 'reports'], true)) {
    $tab = 'scholarships';
}
$letterType = $_GET['letter_type'] ?? 'approval';
$applicationId = isset($_GET['application_id']) ? (int) $_GET['application_id'] : 0;
$selectedScholarshipId = $filters['scholarship_id'] !== '' ? (int) $filters['scholarship_id'] : 0;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['export_action'] ?? '') === 'official_scholars') {
        $scholarshipId = isset($_POST['scholarship_id']) ? (int) $_POST['scholarship_id'] : 0;
        $rows = fetchOfficialScholarRows($pdo, $scholarshipId > 0 ? $scholarshipId : null);
        $scholarshipName = '';
        if ($scholarshipId > 0 && !empty($rows)) {
            $scholarshipName = (string) ($rows[0]['scholarship_name'] ?? '');
        }
        outputOfficialScholarsCsv($rows, $scholarshipName);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['export_action'] ?? '') === 'scholarship_report') {
        $exportFilters = [
            'scholarship_id' => trim((string) ($_POST['scholarship_id'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? '')),
            'applicant_type' => trim((string) ($_POST['applicant_type'] ?? '')),
            'search' => trim((string) ($_POST['search'] ?? '')),
        ];
        outputReportCsv(fetchReportRows($pdo, $exportFilters));
        exit();
    }

    $scholarships = $pdo->query("SELECT id, name FROM scholarships ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statuses = $pdo->query("SELECT DISTINCT status FROM applications WHERE status IS NOT NULL AND status <> '' ORDER BY status ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $summaryRows = fetchScholarshipSummary($pdo);
    $reportRows = fetchReportRows($pdo, $filters);
    $officialScholarRows = $selectedScholarshipId > 0 ? fetchOfficialScholarRows($pdo, $selectedScholarshipId) : [];
    $letterRows = fetchOfficialScholarRows($pdo, null);
    $selectedScholarship = null;
    foreach ($summaryRows as $summaryRow) {
        if ((int) $summaryRow['id'] === $selectedScholarshipId) {
            $selectedScholarship = $summaryRow;
            break;
        }
    }

    $letterData = null;
    if ($view === 'letter' && $applicationId > 0) {
        $letterData = fetchReportRowByApplicationId($pdo, $applicationId);
    }
} catch (PDOException $e) {
    $scholarships = [];
    $statuses = [];
    $summaryRows = [];
    $reportRows = [];
    $officialScholarRows = [];
    $letterRows = [];
    $selectedScholarship = null;
    $letterData = null;
    $errors[] = 'Database error while loading reports. Please try again later.';
    error_log($e->getMessage());
}

$page_title = 'Reports & Exports';
include 'header.php';
?>

<style>
    .report-stat {
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 8px;
        padding: 1rem;
        background: #fff;
        min-height: 100%;
    }
    .report-stat .value {
        font-size: 1.75rem;
        font-weight: 800;
        color: #0f172a;
    }
    .report-table th {
        white-space: nowrap;
    }
    .report-tabs {
        border-bottom: 1px solid rgba(15, 23, 42, 0.12);
        gap: 0.5rem;
    }
    .report-tabs .nav-link {
        border: 0;
        border-bottom: 3px solid transparent;
        color: #64748b;
        font-weight: 700;
        padding: 0.8rem 1rem;
    }
    .report-tabs .nav-link.active {
        background: transparent;
        border-bottom-color: #0d6efd;
        color: #0d6efd;
    }
    .scholarship-row-link {
        color: inherit;
        text-decoration: none;
    }
    .scholarship-row-link:hover {
        color: #0d6efd;
    }
    .letter-container {
        background: #fff;
        max-width: 8.5in;
        min-height: 11in;
        margin: 0 auto;
        padding: 1.2in;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
        font-family: "Times New Roman", Times, serif;
        color: #111827;
        line-height: 1.6;
    }
    .letter-header {
        text-align: center;
        border-bottom: 2px solid #111827;
        padding-bottom: 1rem;
        margin-bottom: 2rem;
    }
    .letter-body {
        font-size: 12pt;
        text-align: justify;
    }
    .signature-line {
        border-top: 1px solid #111827;
        width: 260px;
        padding-top: 0.5rem;
        margin-top: 3rem;
    }
    @media print {
        .sidebar, .main-header, .no-print {
            display: none !important;
        }
        .main-content, .content-wrapper {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }
        body {
            background: #fff !important;
        }
        .letter-container {
            box-shadow: none !important;
            margin: 0 !important;
            padding: 0.6in !important;
            max-width: none !important;
        }
    }
</style>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <p class="mb-0"><?php echo reportH($error); ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($view === 'letter' && $letterData): ?>
    <?php $letter = buildLetterBody($letterData, $letterType); ?>
    <div class="no-print d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="exports.php?tab=letters" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Letters
            </a>
            <h4 class="d-inline-block ms-3 mb-0"><?php echo reportH($letter['title']); ?></h4>
        </div>
        <button type="button" onclick="window.print()" class="btn btn-primary">
            <i class="bi bi-printer-fill me-2"></i>Print / Save PDF
        </button>
    </div>

    <div class="letter-container">
        <div class="letter-header">
            <img src="../images/brand-mark.svg" alt="DVC" width="72" height="72" class="mb-2">
            <h2 class="fw-bold text-uppercase mb-0">Davao Vision College</h2>
            <p class="mb-1">Davao City, Philippines</p>
            <h5 class="fw-bold text-uppercase mb-0">Office of the Scholarship Coordinator</h5>
        </div>

        <div class="letter-body">
            <p class="text-end"><strong>Date:</strong> <?php echo date('F d, Y'); ?></p>

            <p>
                <strong><?php echo strtoupper(reportH($letterData['student_name'])); ?></strong><br>
                School ID: <?php echo reportH($letterData['school_id_number']); ?><br>
                Program: <?php echo reportH(formatReportAcademic($letterData, 'program') ?: 'N/A'); ?><br>
                Year Level: <?php echo reportH(formatReportAcademic($letterData, 'year_level') ?: 'N/A'); ?>
            </p>

            <h4 class="text-center fw-bold text-uppercase my-4"><?php echo reportH($letter['subject']); ?></h4>

            <p>Dear <?php echo reportH($letterData['student_name']); ?>,</p>

            <?php foreach ($letter['paragraphs'] as $paragraph): ?>
                <p><?php echo reportH($paragraph); ?></p>
            <?php endforeach; ?>

            <p>Thank you.</p>
        </div>

        <div class="row mt-5">
            <div class="col-6">
                <p>Very truly yours,</p>
                <div class="signature-line">
                    <strong>Scholarship Coordinator</strong><br>
                    Office of Student Affairs
                </div>
            </div>
            <div class="col-6 text-end">
                <p>Noted by:</p>
                <div class="signature-line ms-auto">
                    <strong>Dean / Authorized Signatory</strong><br>
                    Davao Vision College
                </div>
            </div>
        </div>

        <div class="mt-5 pt-4 border-top">
            <p class="fw-bold text-decoration-underline">CONFORME:</p>
            <p>I, <?php echo reportH($letterData['student_name']); ?>, acknowledge receipt of this scholarship notice.</p>
            <div class="d-flex gap-5 mt-5">
                <div style="border-top: 1px solid #111827; width: 260px;">Signature over Printed Name</div>
                <div>Date: __________________</div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="page-header mb-4">
        <h1 class="fw-bold">Reports & Exports</h1>
        <p class="text-muted">Review official scholars, generate scholarship letters, and export scholarship summaries.</p>
    </div>

    <ul class="nav nav-tabs report-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'scholarships' ? 'active' : ''; ?>" href="exports.php?tab=scholarships">
                <i class="bi bi-mortarboard-fill me-2"></i>Scholarships
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'letters' ? 'active' : ''; ?>" href="exports.php?tab=letters">
                <i class="bi bi-file-earmark-richtext-fill me-2"></i>Scholarship Letters
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'reports' ? 'active' : ''; ?>" href="exports.php?tab=reports">
                <i class="bi bi-bar-chart-fill me-2"></i>Scholarship Reports
            </a>
        </li>
    </ul>

    <?php if ($tab === 'scholarships'): ?>
        <?php if (!$selectedScholarship): ?>
            <div class="content-block">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h3 class="mb-0">Scholarships</h3>
                        <p class="text-muted mb-0">Select a scholarship to view its official approved scholars.</p>
                    </div>
                    <span class="badge bg-primary-soft text-primary fs-6 rounded-pill"><?php echo count($summaryRows); ?> Scholarships</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle report-table">
                        <thead class="table-light">
                            <tr>
                                <th>Scholarship</th>
                                <th>Category</th>
                                <th>Slots</th>
                                <th>Official Scholars</th>
                                <th>Total Applicants</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($summaryRows)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No scholarships found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($summaryRows as $summary): ?>
                                    <tr>
                                        <td>
                                            <a class="fw-bold scholarship-row-link" href="exports.php?tab=scholarships&scholarship_id=<?php echo (int) $summary['id']; ?>">
                                                <?php echo reportH($summary['name']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo reportH($summary['category']); ?></td>
                                        <td><?php echo reportH($summary['available_slots'] ?? 0); ?></td>
                                        <td><span class="badge bg-success"><?php echo (int) $summary['approved_count']; ?></span></td>
                                        <td><?php echo (int) $summary['total_applications']; ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-primary" href="exports.php?tab=scholarships&scholarship_id=<?php echo (int) $summary['id']; ?>">
                                                View Students
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="content-block">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <a href="exports.php?tab=scholarships" class="btn btn-outline-secondary btn-sm mb-2">
                            <i class="bi bi-arrow-left"></i> Back to Scholarships
                        </a>
                        <h3 class="mb-0"><?php echo reportH($selectedScholarship['name']); ?></h3>
                        <p class="text-muted mb-0">Official approved scholars under this scholarship only.</p>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="export_action" value="official_scholars">
                        <input type="hidden" name="scholarship_id" value="<?php echo (int) $selectedScholarshipId; ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-file-earmark-spreadsheet-fill me-2"></i>Export Scholars CSV
                        </button>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle report-table">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Program / Year</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Coverage</th>
                                <th>Approved / Updated</th>
                                <th class="text-end">Letter</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($officialScholarRows)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No official scholars found for this scholarship.</td></tr>
                            <?php else: ?>
                                <?php foreach ($officialScholarRows as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo reportH($row['student_name']); ?></div>
                                            <small class="text-muted"><?php echo reportH($row['school_id_number']); ?> &middot; <?php echo reportH($row['email']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo reportH(formatReportAcademic($row, 'program') ?: 'N/A'); ?>
                                            <small class="text-muted d-block"><?php echo reportH(formatReportAcademic($row, 'year_level') ?: 'N/A'); ?></small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?php echo reportH($row['applicant_type'] ?: 'N/A'); ?></span></td>
                                        <td><span class="badge <?php echo statusBadgeClass((string) $row['status']); ?>"><?php echo reportH($row['status']); ?></span></td>
                                        <td><?php echo reportH(formatReportCoverage($row) ?: 'N/A'); ?></td>
                                        <td><?php echo reportH(formatReportDate($row['updated_at'] ?: $row['submitted_at'])); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="exports.php?view=letter&application_id=<?php echo (int) $row['id']; ?>&letter_type=approval">
                                                <i class="bi bi-file-text me-1"></i>Award
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
<?php elseif ($tab === 'letters'): ?>
        <div class="content-block">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h3 class="mb-0">Scholarship Letters</h3>
                    <p class="text-muted mb-0">Generate printable letters for official scholars.</p>
                </div>
                <span class="badge bg-primary-soft text-primary fs-6 rounded-pill"><?php echo count($letterRows); ?> Official Scholars</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle report-table">
                    <thead class="table-light">
                        <tr>
                            <th>Scholarship</th>
                            <th>Student</th>
                            <th>Status</th>
                            <th>Coverage</th>
                            <th class="text-end">Generate Letter</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($letterRows)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No official scholars available for letters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($letterRows as $row): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo reportH($row['scholarship_name']); ?></div>
                                        <small class="text-muted"><?php echo reportH($row['scholarship_category']); ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo reportH($row['student_name']); ?></div>
                                        <small class="text-muted"><?php echo reportH($row['school_id_number']); ?> &middot; <?php echo reportH($row['email']); ?></small>
                                    </td>
                                    <td><span class="badge <?php echo statusBadgeClass((string) $row['status']); ?>"><?php echo reportH($row['status']); ?></span></td>
                                    <td><?php echo reportH(formatReportCoverage($row) ?: 'N/A'); ?></td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                Generate
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><a class="dropdown-item" href="exports.php?view=letter&application_id=<?php echo (int) $row['id']; ?>&letter_type=approval"><i class="bi bi-award me-2"></i>Award Letter</a></li>
                                                <li><a class="dropdown-item" href="exports.php?view=letter&application_id=<?php echo (int) $row['id']; ?>&letter_type=renewal"><i class="bi bi-arrow-repeat me-2"></i>Renewal Notice</a></li>
                                                <li><a class="dropdown-item" href="exports.php?view=letter&application_id=<?php echo (int) $row['id']; ?>&letter_type=requirements"><i class="bi bi-file-earmark-check me-2"></i>Requirement Notice</a></li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>

    <div class="content-block mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="reports">
            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="scholarship_id">Scholarship</label>
                <select class="form-select" id="scholarship_id" name="scholarship_id">
                    <option value="">All Scholarships</option>
                    <?php foreach ($scholarships as $scholarship): ?>
                        <option value="<?php echo (int) $scholarship['id']; ?>" <?php echo selectedOption($filters['scholarship_id'], $scholarship['id']); ?>>
                            <?php echo reportH($scholarship['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo reportH($status); ?>" <?php echo selectedOption($filters['status'], $status); ?>>
                            <?php echo reportH($status); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="applicant_type">Applicant Type</label>
                <select class="form-select" id="applicant_type" name="applicant_type">
                    <option value="">All Types</option>
                    <option value="New" <?php echo selectedOption($filters['applicant_type'], 'New'); ?>>New</option>
                    <option value="Renewal" <?php echo selectedOption($filters['applicant_type'], 'Renewal'); ?>>Renewal</option>
                </select>
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="search">Search</label>
                <input type="text" class="form-control" id="search" name="search" placeholder="Name, School ID, email" value="<?php echo reportH($filters['search']); ?>">
            </div>
            <div class="col-lg-2 col-md-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel-fill me-1"></i>Filter</button>
                <a href="exports.php?tab=reports" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="report-stat">
                <div class="text-muted small">Filtered Records</div>
                <div class="value"><?php echo count($reportRows); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-stat">
                <div class="text-muted small">Approved / Active</div>
                <div class="value"><?php echo count(array_filter($reportRows, fn($r) => in_array($r['status'], ['Approved', 'Active', 'Accepted'], true))); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-stat">
                <div class="text-muted small">Pending / Review</div>
                <div class="value"><?php echo count(array_filter($reportRows, fn($r) => in_array($r['status'], ['Pending', 'Under Review'], true))); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-stat">
                <div class="text-muted small">Renewal Records</div>
                <div class="value"><?php echo count(array_filter($reportRows, fn($r) => in_array($r['status'], ['For Renewal', 'Renewal Request'], true) || ($r['applicant_type'] ?? '') === 'Renewal')); ?></div>
            </div>
        </div>
    </div>

    <div class="content-block mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-0">Scholarship Summary</h3>
                <p class="text-muted mb-0">Students and application status counts grouped by scholarship.</p>
            </div>
            <form method="POST">
                <input type="hidden" name="export_action" value="scholarship_report">
                <input type="hidden" name="scholarship_id" value="<?php echo reportH($filters['scholarship_id']); ?>">
                <input type="hidden" name="status" value="<?php echo reportH($filters['status']); ?>">
                <input type="hidden" name="applicant_type" value="<?php echo reportH($filters['applicant_type']); ?>">
                <input type="hidden" name="search" value="<?php echo reportH($filters['search']); ?>">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet-fill me-2"></i>Export Filtered CSV
                </button>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle report-table">
                <thead class="table-light">
                    <tr>
                        <th>Scholarship</th>
                        <th>Slots</th>
                        <th>Total</th>
                        <th>Approved</th>
                        <th>Pending</th>
                        <th>Review</th>
                        <th>Rejected</th>
                        <th>Renewal</th>
                        <th>Awarded Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summaryRows as $summary): ?>
                        <tr>
                            <td>
                                <a class="fw-bold text-decoration-none" href="exports.php?scholarship_id=<?php echo (int) $summary['id']; ?>">
                                    <?php echo reportH($summary['name']); ?>
                                </a>
                                <small class="text-muted d-block"><?php echo reportH($summary['category']); ?></small>
                            </td>
                            <td><?php echo reportH($summary['available_slots'] ?? 0); ?></td>
                            <td><?php echo (int) $summary['total_applications']; ?></td>
                            <td><?php echo (int) $summary['approved_count']; ?></td>
                            <td><?php echo (int) $summary['pending_count']; ?></td>
                            <td><?php echo (int) $summary['review_count']; ?></td>
                            <td><?php echo (int) $summary['rejected_count']; ?></td>
                            <td><?php echo (int) $summary['renewal_count']; ?></td>
                            <td><?php echo reportH(formatReportMoney($summary['awarded_amount'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="content-block">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-0">Student Scholarship Records</h3>
                <p class="text-muted mb-0">Filtered list of students segregated by scholarship.</p>
            </div>
            <span class="badge bg-primary-soft text-primary fs-6 rounded-pill"><?php echo count($reportRows); ?> Records</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle report-table">
                <thead class="table-light">
                    <tr>
                        <th>Scholarship</th>
                        <th>Student</th>
                        <th>Program / Year</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Coverage</th>
                        <th>Date Applied</th>
                        <th class="text-end">Letters</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No student scholarship records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?php echo reportH($row['scholarship_name']); ?></div>
                                    <small class="text-muted"><?php echo reportH($row['scholarship_category']); ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo reportH($row['student_name']); ?></div>
                                    <small class="text-muted">
                                        <?php echo reportH($row['school_id_number']); ?> &middot; <?php echo reportH($row['email']); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php echo reportH(formatReportAcademic($row, 'program') ?: 'N/A'); ?>
                                    <small class="text-muted d-block"><?php echo reportH(formatReportAcademic($row, 'year_level') ?: 'N/A'); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?php echo reportH($row['applicant_type'] ?: 'N/A'); ?></span>
                                    <?php if (!empty($row['student_status'])): ?>
                                        <small class="text-muted d-block"><?php echo reportH($row['student_status']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?php echo statusBadgeClass((string) $row['status']); ?>"><?php echo reportH($row['status']); ?></span></td>
                                <td><?php echo reportH(formatReportCoverage($row) ?: 'N/A'); ?></td>
                                <td><?php echo reportH(formatReportDate($row['submitted_at'])); ?></td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                            Generate
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="exports.php?view=letter&application_id=<?php echo (int) $row['id']; ?>&letter_type=approval"><i class="bi bi-award me-2"></i>Award Letter</a></li>
                                            <li><a class="dropdown-item" href="exports.php?view=letter&application_id=<?php echo (int) $row['id']; ?>&letter_type=renewal"><i class="bi bi-arrow-repeat me-2"></i>Renewal Notice</a></li>
                                            <li><a class="dropdown-item" href="exports.php?view=letter&application_id=<?php echo (int) $row['id']; ?>&letter_type=requirements"><i class="bi bi-file-earmark-check me-2"></i>Requirement Notice</a></li>
                                            <li><a class="dropdown-item" href="exports.php?view=letter&application_id=<?php echo (int) $row['id']; ?>&letter_type=rejection"><i class="bi bi-file-earmark-x me-2"></i>Result Letter</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php include 'footer.php'; ?>
