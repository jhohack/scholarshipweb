<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
// Start the session if it's not already started. This is crucial for a standalone page.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php'; // Contains isLoggedIn()

checkSessionTimeout();

// Check if the user is logged in as a student
if (!isStudent()) {
    header("Location: ../public/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'User';
$rejected_applications_count = 0;
$recent_applications = [];
$active_scholarship = null;
$drop_request_status = null;
$pending_exam_application = null;
$student_id = null;

try {
    // First, get the student_id from the user_id
    $student_stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $student_stmt->execute([$user_id]);
    $student = $student_stmt->fetch();

    if ($student) {
        $student_id = $student['id'];

        // --- New Core Logic: Get the single most recent application status to ensure accuracy ---
        $latest_app_stmt = $pdo->prepare("
            SELECT 
                a.id as application_id, a.status, a.updated_at, a.submitted_at, a.remarks, 
                s.id as scholarship_id, s.name, s.end_of_term, s.accepting_renewal_applicants
            FROM applications a 
            JOIN scholarships s ON a.scholarship_id = s.id 
            WHERE a.student_id = ? 
            ORDER BY 
                CASE 
                    WHEN a.status IN ('Active', 'Approved', 'For Renewal', 'Renewal Request', 'Drop Requested', 'Pending', 'Under Review', 'Pending Exam') THEN 1 
                    ELSE 2 
                END ASC,
                COALESCE(a.submitted_at, a.updated_at) DESC 
            LIMIT 1
        ");
        $latest_app_stmt->execute([$student_id]);
        $latest_application = $latest_app_stmt->fetch(PDO::FETCH_ASSOC);

        $active_scholarship = null;
        $last_dropped_scholarship = null;

        if ($latest_application) {
            // Check if this latest application is an "active-like" one
            // Added 'Pending', 'Approved', 'Pending Exam' to capture New Applicants and other states
            if (in_array($latest_application['status'], ['Active', 'Approved', 'For Renewal', 'Renewal Request', 'Drop Requested', 'Pending Exam'])) {
                $active_scholarship = $latest_application;
            } 
            // Check if this latest application is a "dropped" one
            elseif ($latest_application['status'] === 'Dropped') {
                $last_dropped_scholarship = $latest_application;
            }
        }

        // --- New Logic: Check for Pending Exam ---
        if (!$active_scholarship) { // Only check for pending exam if no scholarship is active/under review
            $exam_stmt = $pdo->prepare("SELECT a.id as application_id, s.id as scholarship_id, s.name as scholarship_name FROM applications a JOIN scholarships s ON a.scholarship_id = s.id WHERE a.student_id = ? AND a.status = 'Pending Exam' LIMIT 1");
            $exam_stmt->execute([$student_id]);
            $pending_exam_application = $exam_stmt->fetch(PDO::FETCH_ASSOC);
        }


        // Count active applications
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE student_id = ? AND status IN ('Pending', 'Under Review', 'Pending Exam', 'Renewal Request')"); // Corrected to use student_id
        $stmt->execute([$student_id]);
        $active_applications_count = $stmt->fetchColumn();

        // Count approved applications
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE student_id = ? AND status IN ('Approved', 'Active', 'For Renewal', 'Drop Requested')");
        $stmt->execute([$student_id]);
        $approved_applications_count = $stmt->fetchColumn();

        // Count rejected applications
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE student_id = ? AND status = 'Rejected'");
        $stmt->execute([$student_id]);
        $rejected_applications_count = $stmt->fetchColumn();

        // Fetch 5 most recent applications
        $app_stmt = $pdo->prepare("
            SELECT s.name AS scholarship_name, a.status, a.submitted_at, a.updated_at, a.remarks 
            FROM applications a 
            JOIN scholarships s ON a.scholarship_id = s.id 
            WHERE a.student_id = ? 
            ORDER BY COALESCE(a.submitted_at, a.updated_at) DESC 
            LIMIT 5
        ");
        $app_stmt->execute([$student_id]);
        $recent_applications = $app_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // In a real app, you would log this error and potentially show a user-friendly error message.
    error_log("Dashboard Error: " . $e->getMessage());
}

$page_title = 'Dashboard';
include 'header.php';
displayFlashMessages();
?>

<div class="welcome-banner" data-aos="fade-down">
    <div class="banner-content text-center text-md-start">
        <h2 class="mb-1">Welcome Back, <?php echo htmlspecialchars($user_name); ?>!</h2>
        <p class="text-muted mb-0">Here's a summary of your scholarship activities.</p>
    </div>
</div>

<!-- Recent Applications -->
<div class="row">
    <div class="col-lg-8">
        <div class="content-block h-100" data-aos="fade-up" data-aos-delay="200">
            <h3>Recent Application Status</h3>
            <?php if (!$student_id || empty($recent_applications)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-folder2-open fs-1 text-muted"></i>
                    <h5 class="mt-3">No Applications Yet</h5>
                    <p class="text-muted">Your journey starts here. Find and apply for scholarships to see them appear on your dashboard.</p>
                    <a href="../public/scholarships.php" class="btn btn-primary mt-2">Explore Scholarships</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Scholarship Name</th>
                                <th>Date Submitted</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                    <?php foreach ($recent_applications as $app): ?>
                        <?php
                        // Standardize the status display for the user using the global function
                        $display_status = formatApplicationStatus($app['status']);

                        // Map status to a specific Bootstrap background color for highlighting
                        $badge_bg = match($display_status) {
                            'Approved' => 'bg-success',
                            'Rejected' => 'bg-danger',
                            'Pending' => 'bg-secondary',
                            'Under Review' => 'bg-info',
                            'Renewal Request' => 'bg-primary', // Blue highlight
                            'Drop Requested' => 'bg-warning text-dark', // Yellow highlight
                            'Pending Exam' => 'bg-dark',
                            'Passed' => 'bg-success',
                            'Failed' => 'bg-danger',
                            default => 'bg-light text-dark',
                        };
                        ?>
                        <tr class="clickable-row" data-href="applications.php">
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($app['scholarship_name']); ?></div>
                                <?php if ($app['status'] === 'Dropped'): ?>
                                    <div class="small text-danger mt-1">
                                        <i class="bi bi-x-circle me-1"></i> Dropped on <?php echo date("M d, Y", strtotime($app['updated_at'])); ?>
                                    </div>
                                <?php elseif ($app['status'] === 'For Renewal'): ?>
                                    <div class="small text-warning mt-1">
                                        <i class="bi bi-exclamation-circle me-1"></i> Term ended on <?php echo date("M d, Y", strtotime($app['updated_at'])); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?php echo htmlspecialchars(date("M d, Y", strtotime($app['submitted_at']))); ?></td>
                            <td class="text-center">
                                <span class="badge <?php echo $badge_bg; ?>"><?php echo htmlspecialchars($display_status); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="mt-3 text-end">
                        <a href="applications.php" class="btn btn-sm btn-outline-primary">View All Applications</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="content-block h-100" data-aos="fade-up" data-aos-delay="300">
            <h3>Quick Actions</h3>
            <div class="mb-3">
                <h6 class="text-muted mb-2"><i class="bi bi-play-btn-fill me-2"></i>System Tutorial</h6>
                <div class="ratio ratio-16x9 rounded overflow-hidden shadow-sm bg-dark">
                    <!-- IMPORTANT: Use the full video URL (e.g., facebook.com/page/videos/id), NOT a share link. -->
                    <!-- Replace the encoded URL in 'href' below with your actual public video URL. -->
                    <iframe src="https://www.facebook.com/plugins/video.php?height=314&href=https%3A%2F%2Fweb.facebook.com%2Freel%2F1957171125209519%2F&show_text=false&width=560&t=0" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allowfullscreen="true" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" allowFullScreen="true"></iframe>
                </div>
            </div>
            <div class="d-grid mb-3">
                <a href="../public/messages.php" class="btn btn-outline-primary shadow-sm fw-bold">
                    <i class="bi bi-chat-dots-fill me-2"></i> Chat with Admin
                </a>
            </div>
            <?php if ($active_scholarship): ?>
                <div class="p-3 bg-light border rounded">
                    <h5 class="fw-bold text-primary mb-2"><?php echo htmlspecialchars($active_scholarship['name']); ?></h5>
                    
                    <div class="mb-3">
                        <span class="badge <?php 
                            echo match($active_scholarship['status']) {
                                'Active', 'Approved', 'Passed' => 'bg-success',
                                'For Renewal' => 'bg-warning text-dark',
                                'Pending', 'Under Review', 'Renewal Request' => 'bg-info',
                                'Pending Exam' => 'bg-dark',
                                'Drop Requested' => 'bg-danger',
                                default => 'bg-secondary'
                            }; 
                        ?> mb-2">
                            <?php echo htmlspecialchars(formatApplicationStatus($active_scholarship['status'])); ?>
                        </span>
                    </div>

                    <p class="text-muted small mb-3">
                        <i class="bi bi-calendar-check me-1"></i> Applied: <?php echo date("M d, Y", strtotime($active_scholarship['submitted_at'])); ?>
                        <?php if (!empty($active_scholarship['end_of_term'])): ?>
                            <br><i class="bi bi-calendar-x me-1"></i> Term ends: <?php echo date("M d, Y", strtotime($active_scholarship['end_of_term'])); ?>
                        <?php endif; ?>
                    </p>

                    <?php if ($active_scholarship['status'] === 'Pending Exam'): ?>
                        <div class="alert alert-warning small border-warning">
                            <i class="bi bi-exclamation-circle-fill me-1"></i> <strong>Action Required:</strong> Please complete the entrance exam to proceed.
                        </div>
                        <div class="d-grid mb-2">
                            <a href="take_exam.php?id=<?php echo $active_scholarship['scholarship_id']; ?>" class="btn btn-warning fw-bold"><i class="bi bi-pencil-square me-2"></i>Take Exam Now</a>
                        </div>
                    <?php elseif ($active_scholarship['status'] === 'For Renewal'): ?>
                        <div class="alert alert-info small border-info">
                            <i class="bi bi-arrow-repeat me-1"></i> <strong>Renewal Open:</strong> Your term has ended. Please renew your scholarship.
                        </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <!-- Renew Button: Available for Active or For Renewal status -->
                        <?php if (in_array($active_scholarship['status'], ['Active', 'Approved', 'For Renewal'])): ?>
                            <button type="button" class="btn btn-success renewal-btn" data-url="../public/apply.php?id=<?php echo $active_scholarship['scholarship_id']; ?>">
                                <i class="bi bi-arrow-repeat me-2"></i>Renew Scholarship
                            </button>
                        <?php endif; ?>

                        <!-- Drop Button: Available for most statuses except if already dropped/rejected -->
                        <?php if ($active_scholarship['status'] !== 'Drop Requested'): ?>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#dropScholarshipModal">
                                <i class="bi bi-box-arrow-left me-2"></i>Request to Drop
                            </button>
                        <?php else: ?>
                            <button class="btn btn-secondary" disabled><i class="bi bi-hourglass-split me-2"></i>Drop Pending</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <a href="../public/scholarships.php" class="btn btn-primary btn-lg text-start shadow-sm">
                        <i class="bi bi-search me-2"></i> Find New Scholarship
                    </a>
                </div>
                <?php if ($last_dropped_scholarship): ?>
                    <div class="alert alert-warning mt-4 border-warning">
                        <h5 class="alert-heading"><i class="bi bi-exclamation-circle-fill"></i> Scholarship Dropped</h5>
                        <p class="mb-2">Your scholarship "<strong><?php echo htmlspecialchars($last_dropped_scholarship['name']); ?></strong>" was dropped on <strong><?php echo date("M d, Y", strtotime($last_dropped_scholarship['updated_at'])); ?></strong>.</p>
                        <div class="small text-muted mb-2">
                            <i class="bi bi-calendar-event me-1"></i> <strong>Applied:</strong> <?php echo date("M d, Y", strtotime($last_dropped_scholarship['submitted_at'])); ?><br>
                            <i class="bi bi-calendar-x me-1"></i> <strong>Dropped:</strong> <?php echo date("M d, Y", strtotime($last_dropped_scholarship['updated_at'])); ?>
                        </div>
                        <?php if (!empty($last_dropped_scholarship['remarks'])): ?>
                            <div class="p-2 bg-white rounded border border-warning-subtle mb-2"><strong class="text-dark">Reason:</strong> <span class="text-muted"><?php echo htmlspecialchars($last_dropped_scholarship['remarks']); ?></span></div>
                        <?php endif; ?>
                        <hr>
                        <p class="mb-0">You are now eligible to apply for a new scholarship.</p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mt-4">
                        <h5 class="alert-heading"><i class="bi bi-info-circle-fill"></i> No Active Scholarship</h5>
                        <p class="mb-0">You are eligible to apply for any available scholarship program.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Renewal Confirmation Modal -->
<div class="modal fade" id="renewalConfirmationModal" tabindex="-1" aria-labelledby="renewalConfirmationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold" id="renewalConfirmationModalLabel">Ready to Renew?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="text-center mb-3">
            <i class="bi bi-file-earmark-pdf-fill display-4 text-primary"></i>
        </div>
        <p class="text-center text-muted">Before you proceed, please make sure you have a scanned PDF copy of your most recent <strong>GWA (Grade Weighted Average)</strong> or <strong>Report Card</strong> ready for upload.</p>
      </div>
      <div class="modal-footer border-0 justify-content-center">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <a href="#" id="proceedRenewalLink" class="btn btn-primary">Proceed to Renewal</a>
      </div>
    </div>
  </div>
</div>

<!-- Drop Scholarship Modal -->
<?php if ($active_scholarship): ?>
<div class="modal fade" id="dropScholarshipModal" tabindex="-1" aria-labelledby="dropScholarshipModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold text-danger" id="dropScholarshipModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Request to Drop Scholarship</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="drop.php?id=<?php echo $active_scholarship['application_id']; ?>" method="post">
          <div class="modal-body">
            <div class="alert alert-warning small">
                <strong>Warning:</strong> Dropping your scholarship is a significant decision. Once approved, you will lose all benefits. This action requires admin approval.
            </div>
            <div class="mb-3">
                <label for="drop_reason" class="form-label fw-bold">Reason for Dropping <span class="text-danger">*</span></label>
                <textarea class="form-control" id="drop_reason" name="reason" rows="4" placeholder="Please explain why you are dropping this scholarship (min. 20 characters)..." required minlength="20"></textarea>
                <div class="form-text">Please provide a clear reason (e.g., shifting courses, financial changes).</div>
            </div>
          </div>
          <div class="modal-footer border-0 bg-light">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger">Submit Drop Request</button>
          </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('.clickable-row');
    rows.forEach(row => {
        row.addEventListener('click', function() {
            window.location.href = this.dataset.href;
        });
    });

    // --- New Code for Renewal Modal ---
    const renewalModalEl = document.getElementById('renewalConfirmationModal');
    if (renewalModalEl) {
        const renewalModal = new bootstrap.Modal(renewalModalEl);
        const proceedLink = document.getElementById('proceedRenewalLink');

        document.querySelectorAll('.renewal-btn').forEach(button => {
            button.addEventListener('click', function() {
                const renewalUrl = this.dataset.url;
                if (proceedLink) {
                    proceedLink.href = renewalUrl;
                }
                renewalModal.show();
            });
        });
    }
});
</script>

<?php
// The footer includes all necessary closing tags and scripts
include 'footer.php'; 
?>