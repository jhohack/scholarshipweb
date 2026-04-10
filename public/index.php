<?php
require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/db.php'; // This file should create the $pdo object
require_once __DIR__ . '/../includes/functions.php';

checkSessionTimeout();

$current_page = basename($_SERVER['PHP_SELF']);

// Check if a user is logged in. If so, hide the CTA.
$is_user_logged_in = isset($_SESSION['user_id']);

// --- Migration: Add amount_type to scholarships table ---
try {
    $pdo->query("SELECT amount_type FROM scholarships LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE scholarships ADD COLUMN amount_type ENUM('Peso', 'Percentage', 'None') NOT NULL DEFAULT 'Peso'");
}

// Fetch featured scholarships from the same pool used in scholarships.php
try {
    $sql = "SELECT s.* FROM scholarships s";
    $whereClauses = [];
    $params = [];

    // Match scholarships page rules
    $whereClauses[] = "s.status = 'active'";
    $whereClauses[] = "(s.requires_exam = 0 OR (s.requires_exam = 1 AND EXISTS (SELECT 1 FROM exam_questions eq WHERE eq.scholarship_id = s.id)))";

    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(' AND ', $whereClauses);
    }

    $sql .= " ORDER BY s.deadline ASC LIMIT 6";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $scholarships = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $scholarships = [];
    // Log error in production
}

// Fetch the latest 3 active announcements
try {
    $announcements_stmt = $pdo->query("SELECT id, title, content, created_at FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 3");
    $announcements = $announcements_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch attachments for these announcements
    foreach ($announcements as &$ann) {
        $stmt_att = $pdo->prepare("SELECT file_path, file_name FROM announcement_attachments WHERE announcement_id = ?");
        $stmt_att->execute([$ann['id']]);
        $ann['attachments'] = $stmt_att->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($ann); // Break reference
} catch (PDOException $e) {
    $announcements = [];
    // Log error in production
}

// --- Fetch Trust Badge Stats ---
$trust_stats = [
    'scholarships' => 0,
    'students' => 0,
    'success_rate' => 0
];
try {
    $trust_stats['scholarships'] = $pdo->query("SELECT COUNT(*) FROM scholarships WHERE status = 'active'")->fetchColumn();
    $trust_stats['students'] = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    
    $total_apps = $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn();
    $approved_apps = $pdo->query("SELECT COUNT(*) FROM applications WHERE status IN ('Approved', 'Active')")->fetchColumn();
    
    if ($total_apps > 0) {
        $trust_stats['success_rate'] = round(($approved_apps / $total_apps) * 100);
    }
} catch (PDOException $e) {
    // Ignore errors, defaults will display 0
}

// HTML output
$page_title = 'DVC Scholarship Hub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
   
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- AOS (Animate on Scroll) CSS -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Swiper.js CSS -->
    <link rel="stylesheet" href="https://unpkg.com/swiper/swiper-bundle.min.css" />
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
    /* New Announcement Section Styles */
    .announcement-section-v2 {
        position: relative;
    }
    .announcement-titles-list .list-group-item {
        border-radius: 0.5rem;
        margin-bottom: 0.5rem;
        border: 1px solid #e9ecef;
        transition: all 0.3s ease;
        padding: 1rem 1.25rem;
    }
    .announcement-titles-list .list-group-item:hover {
        transform: translateX(5px);
        background-color: #f8f9fa;
    }
    .announcement-titles-list .list-group-item.active {
        background-color: var(--bs-primary);
        color: #fff;
        border-color: var(--bs-primary);
        box-shadow: 0 4px 15px rgba(13, 110, 253, 0.25);
        transform: translateX(10px);
    }
    .announcement-titles-list .list-group-item.active .text-muted {
        color: rgba(255, 255, 255, 0.75) !important;
    }
    .announcement-content-wrapper {
        position: relative;
        padding: 2rem;
        background-color: #fff;
        border-radius: 0.5rem;
        box-shadow: 0 4px 25px rgba(0,0,0,0.07);
        min-height: 300px;
        overflow: hidden;
    }
    .announcement-content-pane {
        display: none;
        opacity: 0;
        transition: opacity 0.4s ease-in-out;
    }
    .announcement-content-pane.active {
        display: block;
        opacity: 1;
    }
    .announcement-content-pane .content-body {
        line-height: 1.7;
    }

    /* Minimal Announcement Section Styles */
    .announcement-list-minimal .announcement-item-minimal {
        border-bottom: 1px solid #e9ecef;
    }
    .announcement-list-minimal .announcement-item-minimal:last-child {
        border-bottom: none;
    }
    .announcement-header-minimal {
        padding: 1.5rem 0.5rem;
        text-decoration: none;
        color: #212529;
        transition: background-color 0.2s ease-in-out;
        cursor: pointer;
    }
    .announcement-chevron {
        transition: transform 0.3s ease;
    }
    .announcement-header-minimal[aria-expanded="true"] .announcement-chevron {
        transform: rotate(180deg);
    }
    .announcement-body-minimal {
        padding: 0 0.5rem 1.5rem 0.5rem;
        color: #6c757d;
        line-height: 1.7;
    }
    
    /* Professional Hover Effects */
    .hover-lift {
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .hover-lift:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important;
    }
    
    /* Hero Enhancement */
    .hero-content {
        
        padding: 2rem;
        border-radius: 15px;
       
    }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>

    <main class="p-0">
        <!-- Hero Section -->
        <section class="hero d-flex align-items-center text-white position-relative">
            <div class="hero-grid-overlay"></div>
            <div class="container text-center position-relative">
                <div class="hero-content">
                    <h1 class="display-3 fw-bold hero-text mb-3" data-aos="zoom-in" style="letter-spacing: -1px;">Welcome to the DVC Scholarship Portal</h1>
                    <p class="lead col-lg-8 mx-auto my-4 hero-text" data-aos="zoom-in" data-aos-delay="200">Discover scholarships from Davao Vision College that match your ambitions and fund your educational journey.</p>
                    <a href="scholarships.php" class="btn btn-light btn-lg fw-bold mt-3 hero-button" data-aos="zoom-in" data-aos-delay="400">Explore Scholarships</a>
                </div>
            </div>
            <div class="section-divider section-divider-angle"></div>
        </section>

        <!-- Trust Badges Section -->
        <section class="trust-badges-section py-4">
            <div class="container">
                <div class="row text-center">
                    <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                        <div class="badge-item p-4 hover-lift rounded-3">
                            <i class="bi bi-patch-check-fill fs-2 text-primary"></i>
                            <h5 class="fw-bold mt-2 mb-1"><?php echo number_format($trust_stats['scholarships']); ?></h5>
                            <p class="text-muted small mb-0">Verified Scholarships</p>
                        </div>
                    </div>
                    <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                        <div class="badge-item p-4 hover-lift rounded-3">
                            <i class="bi bi-people-fill fs-2 text-primary"></i>
                            <h5 class="fw-bold mt-2 mb-1"><?php echo number_format($trust_stats['students']); ?></h5>
                            <p class="text-muted small mb-0">Active Students</p>
                        </div>
                    </div>
                    <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                        <div class="badge-item p-4 hover-lift rounded-3">
                            <i class="bi bi-trophy-fill fs-2 text-primary"></i>
                            <h5 class="fw-bold mt-2 mb-1"><?php echo $trust_stats['success_rate']; ?>% Success Rate</h5>
                            <p class="text-muted small mb-0">in Application Assistance</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Value Proposition Section -->
        <section class="value-prop-section py-5 bg-light position-relative">
            <div class="container">
                <div class="row align-items-center g-5">
                    <div class="col-lg-6" data-aos="fade-right">
                        <img src="https://images.unsplash.com/photo-1523240795612-9a054b0db644?q=80&w=2070&auto=format&fit=crop" class="img-fluid rounded shadow-lg" alt="Students studying together">
                    </div>
                    <div class="col-lg-6" data-aos="fade-left" data-aos-delay="100">
                        <h2 class="fw-bold mb-4">Your Direct Path to Funding</h2>
                        <p class="text-muted mb-4">We streamline the scholarship search, making it easier than ever to find and apply for opportunities that match your unique profile and goals.</p>
                        <div class="value-item d-flex align-items-start mb-4">
                            <div class="value-icon bg-primary text-white me-3">
                                <i class="bi bi-patch-check-fill"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold">Verified Listings</h5>
                                <p class="mb-0 text-muted">Scholarships are carefully listed based on approved requirements so students can apply with confidence.</p>
                            </div>
                        </div>
                        <div class="value-item d-flex align-items-start">
                            <div class="value-icon bg-primary text-white me-3">
                                <i class="bi bi-bullseye"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold">Simplified Process</h5>
                                <p class="mb-0 text-muted">A single platform to search, manage, and track all your applications.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="section-divider section-divider-angle-flip"></div>
        </section>

     

          <!-- How It Works Section -->
        <section id="how-it-works" class="py-5">
            <div class="container">
                <div class="text-center mb-5" data-aos="fade-up">
                    <h2 class="fw-bold">Your Journey in Three Simple Steps</h2>
                    <p class="text-muted">A clear and simple path from discovery to application.</p>
                </div>
                <div class="row g-4">
                    <div class="col-lg-4" data-aos="fade-up" data-aos-delay="100">
                        <div class="how-it-works-card hover-lift">
                            <div class="card-content">
                                <div class="card-icon"><i class="bi bi-search"></i></div>
                                <h4 class="fw-bold">01. Discover</h4>
                                <p class="text-muted">Browse our curated list of scholarships and use powerful filters to find your perfect match.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4" data-aos="fade-up" data-aos-delay="200">
                        <div class="how-it-works-card hover-lift">
                            <div class="card-content">
                                <div class="card-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
                                <h4 class="fw-bold">02. Prepare</h4>
                                <p class="text-muted">Gather your documents and use our resources to craft a standout application.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4" data-aos="fade-up" data-aos-delay="300">
                        <div class="how-it-works-card hover-lift">
                            <div class="card-content">
                                <div class="card-icon"><i class="bi bi-send-check-fill"></i></div>
                                <h4 class="fw-bold">03. Apply & Track</h4>
                                <p class="text-muted">Submit through our easy-to-use portal and monitor your application status.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

          <!-- Announcement Section -->
        <?php if (!empty($announcements)): ?>
            <section id="index-announcements" class="announcement-section py-5 bg-light">
                <div class="container">
                    <div class="text-center mb-5" data-aos="fade-up">
                        <h2 class="fw-bold">Latest Announcements</h2>
                        <p class="text-muted">Stay updated with the latest news and opportunities.</p>
                    </div>
                    <div class="row g-4 justify-content-center">
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="col-md-4 d-flex align-items-stretch" data-aos="fade-up">
                                <div class="card h-100 border-0 shadow-sm hover-lift w-100 overflow-hidden">
                                    <?php 
                                    $img_src = '';
                                    if (!empty($announcement['attachments'])) {
                                        foreach ($announcement['attachments'] as $att) {
                                            if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $att['file_path'])) {
                                                $img_src = $att['file_path'];
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <div class="ratio ratio-16x9 bg-light">
                                        <?php if($img_src): ?>
                                            <img src="<?php echo htmlspecialchars($img_src); ?>" class="object-fit-cover" alt="Announcement Image">
                                        <?php else: ?>
                                            <div class="d-flex align-items-center justify-content-center text-primary bg-primary bg-opacity-10">
                                                <i class="bi bi-megaphone-fill fs-1"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body p-4 d-flex flex-column">
                                        <div class="mb-2 text-muted small">
                                            <i class="bi bi-calendar-event me-1"></i> <?php echo date('M d, Y', strtotime($announcement['created_at'])); ?>
                                        </div>
                                        <h5 class="card-title fw-bold mb-3 text-truncate"><?php echo htmlspecialchars($announcement['title']); ?></h5>
                                        <p class="card-text text-muted small flex-grow-1" style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">
                                            <?php echo htmlspecialchars(strip_tags($announcement['content'])); ?>
                                        </p>
                                        <a href="announcements.php?id=<?php echo $announcement['id']; ?>" class="btn btn-outline-primary btn-sm stretched-link mt-3 rounded-pill px-4 align-self-start">Read More</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-center mt-5" data-aos="fade-up">
                        <a href="announcements.php" class="btn btn-primary btn-lg rounded-pill px-5 shadow-sm">View All Announcements</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- Featured Scholarships Section -->
        <section id="index-featured-scholarships" class="scholarship-listings py-5">
            <div class="container">
                <div class="text-center mb-5" data-aos="fade-up">
                    <h2 class="fw-bold">Featured Scholarships</h2>
                    <p class="text-muted">Get a head start with these popular scholarship opportunities.</p>
                </div>
                <!-- Scholarship Grid -->
                <div class="row g-4" data-aos="fade-up" data-aos-delay="100">
                    <?php if (empty($scholarships)): ?>
                        <div class="col-12">
                            <p class="text-center text-muted">No featured scholarships available at this time.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($scholarships as $scholarship): ?>
                            <div class="col-md-6 col-lg-4 d-flex align-items-stretch">
                                <div class="scholarship-card-v2 h-100 w-100 hover-lift">
                                    <div class="card-banner">
                                        <span class="badge category-badge"><?php echo htmlspecialchars(ucfirst($scholarship['category'])); ?></span>
                                    </div>
                                    <div class="card-body d-flex flex-column p-4 pt-3">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="scholarship-provider-logo me-3"><i class="bi bi-building"></i></div>
                                            <h5 class="card-title fw-bold mb-0 flex-grow-1"><?php echo htmlspecialchars($scholarship['name']); ?></h5>
                                        </div>
                                        <p class="card-text text-muted small flex-grow-1"><?php echo htmlspecialchars(substr($scholarship['description'], 0, 100)) . '...'; ?></p>
                                        <div class="row g-2 text-center small mb-3">
                                            <div class="col-4">
                                                <?php
                                                $amt_type = $scholarship['amount_type'] ?? 'Peso';
                                                $amt_val = $scholarship['amount'];
                                                if ($amt_type === 'Percentage') {
                                                    $amt_display = number_format($amt_val, 0) . '%';
                                                } elseif ($amt_type === 'None') {
                                                    $amt_display = 'None';
                                                } else {
                                                    $amt_display = '₱' . number_format($amt_val, 0);
                                                }
                                                ?>
                                                <div class="fw-bold fs-5 text-primary"><?php echo htmlspecialchars($amt_display); ?></div>
                                                <div class="text-muted">Value</div>
                                            </div>
                                            <div class="col-4">
                                                <div class="fw-bold fs-5 text-danger"><?php echo htmlspecialchars(date("d M", strtotime($scholarship['deadline']))); ?></div>
                                                <div class="text-muted">Deadline</div>
                                            </div>
                                            <div class="col-4">
                                                <div class="fw-bold fs-5"><?php echo htmlspecialchars($scholarship['available_slots']); ?></div>
                                                <div class="text-muted">Slots</div>
                                            </div>
                                        </div>
                                        <div class="mt-auto">
                                            <button type="button" class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#scholarshipModal-<?php echo $scholarship['id']; ?>">
                                                View Details
                                            </button>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <div class="text-center mt-5" data-aos="fade-up">
                    <a href="scholarships.php" class="btn btn-primary btn-lg rounded-pill px-5 shadow-sm">View All Scholarships</a>
                </div>
            </div>
        </section>

        <!-- Scholarship Modals -->
        <?php if (!empty($scholarships)): ?>
            <?php foreach ($scholarships as $scholarship): ?>
                <div class="modal fade" id="scholarshipModal-<?php echo $scholarship['id']; ?>" tabindex="-1" aria-labelledby="scholarshipModalLabel-<?php echo $scholarship['id']; ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title" id="scholarshipModalLabel-<?php echo $scholarship['id']; ?>"><?php echo htmlspecialchars($scholarship['name']); ?></h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <span class="badge category-badge mb-3"><?php echo htmlspecialchars(ucfirst($scholarship['category'])); ?></span>
                                <?php
                                $amt_type = $scholarship['amount_type'] ?? 'Peso';
                                $amt_val = $scholarship['amount'];
                                if ($amt_type === 'Percentage') {
                                    $amt_display = number_format($amt_val, 0) . '%';
                                } elseif ($amt_type === 'None') {
                                    $amt_display = 'None';
                                } else {
                                    $amt_display = '₱' . number_format($amt_val, 2);
                                }
                                ?>
                                <p><strong>Amount:</strong> <span class="text-primary fw-bold fs-5"><?php echo htmlspecialchars($amt_display); ?></span></p>
                                <p><strong>Deadline:</strong> <span class="text-danger fw-bold"><?php echo htmlspecialchars(date("F j, Y", strtotime($scholarship['deadline']))); ?></span></p>
                                <p><strong>Available Slots:</strong> <?php echo htmlspecialchars($scholarship['available_slots']); ?></p>
                                
                                <h6 class="fw-bold mt-4">Description</h6>
                                <p><?php echo nl2br(htmlspecialchars($scholarship['description'])); ?></p>
                                
                                <h6 class="fw-bold mt-4">Requirements</h6>
                                <p><?php echo nl2br(htmlspecialchars($scholarship['requirements'])); ?></p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <a href="apply.php?id=<?php echo $scholarship['id']; ?>" class="btn btn-primary"><i class="bi bi-send-check-fill me-2"></i>Apply Now</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
       


        <!-- Upcoming Deadlines Section -->
        <section class="deadlines-section py-5 bg-light position-relative">
            <div class="container">
                <div class="text-center mb-5" data-aos="fade-up">
                    <h2 class="fw-bold">Don't Miss Out!</h2>
                    <p class="text-muted">These scholarship application deadlines are just around the corner.</p>
                </div>
                <div class="row g-4 justify-content-center">
                    <?php if (empty($scholarships)): ?>
                        <div class="col"><p class="text-center">No upcoming deadlines to show.</p></div>
                    <?php else: ?>
                        <?php // Display up to 3 scholarships with the nearest deadlines ?>
                        <?php foreach (array_slice($scholarships, 0, 3) as $index => $scholarship): ?>
                            <div class="col-md-6 col-lg-4" data-aos="fade-up" data-aos-delay="<?php echo ($index + 1) * 100; ?>">
                                <div class="card h-100 text-center shadow-sm border-0">
                                    <div class="card-body d-flex flex-column">
                                        <h5 class="card-title fw-bold"><?php echo htmlspecialchars($scholarship['name']); ?></h5>
                                        <p class="card-text text-danger fw-bold">
                                            <i class="bi bi-clock"></i> Deadline: <?php echo htmlspecialchars(date("F j, Y", strtotime($scholarship['deadline']))); ?>
                                        </p>
                                        <p class="card-text text-muted flex-grow-1"><?php echo htmlspecialchars(substr($scholarship['description'], 0, 80)) . '...'; ?></p>
                                        <a href="apply.php?id=<?php echo $scholarship['id']; ?>" class="btn btn-primary mt-auto">Apply Now</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="section-divider section-divider-angle"></div>
        </section>

        <!-- FAQ Section -->
        <section id="index-faq" class="faq-section py-5 bg-light">
            <div class="container">
                <div class="text-center mb-5" data-aos="fade-up">
                    <h2 class="fw-bold">Frequently Asked Questions</h2>
                    <p class="text-muted">Have questions? We have answers. Here are some common queries from students.</p>
                </div>
                <div class="row justify-content-center">
                    <div class="col-lg-8" data-aos="fade-up" data-aos-delay="100">
                        <div class="accordion" id="faqAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingOne">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="false" aria-controls="collapseOne">
                                        How do I know if I'm eligible for a scholarship?
                                    </button>
                                </h2>
                                <div id="collapseOne" class="accordion-collapse collapse" aria-labelledby="headingOne" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">Eligibility criteria are listed on each scholarship's detail page under "Qualifications". The most important system rule is that students can only have <strong>one active scholarship at a time</strong>. If you are already an active scholar, you can only apply to renew your current scholarship, not apply for a new one.</div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingTwo">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                                        Can I apply for multiple scholarships?
                                    </button>
                                </h2>
                                <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">Yes, you can have multiple <strong>pending</strong> applications for different scholarships simultaneously. However, once an administrator approves one of your applications, the system will automatically reject your other pending applications. Our policy ensures a student can only benefit from one active scholarship at a time to provide fair opportunities for everyone.</div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingThree">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
                                        What documents are required for an application?
                                    </button>
                                </h2>
                                <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">For <strong>new applicants</strong>, the standard required documents are: Certificate of Registration, Report Card/Copy of Grades, Certificate of Good Moral Character, Parent's ITR or Certificate of Indigency, and a PSA Birth Certificate. For <strong>renewal applicants</strong>, the primary requirement is an updated scanned copy of your GWA. Always check the "Application Requirements" section on the scholarship details page for any additional documents.</div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingFour">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour" aria-expanded="false" aria-controls="collapseFour">
                                        Is this service free for students?
                                    </button>
                                </h2>
                                <div id="collapseFour" class="accordion-collapse collapse" aria-labelledby="headingFour" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">Yes, our platform is completely free for students. Our mission is to make education accessible, and we do not charge any fees for searching or applying for scholarships.</div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingFive">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFive" aria-expanded="false" aria-controls="collapseFive">
                                        How will I know if I've been awarded a scholarship?
                                    </button>
                                </h2>
                                <div id="collapseFive" class="accordion-collapse collapse" aria-labelledby="headingFive" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">You will be notified immediately via an automated email as soon as an administrator approves or rejects your application. You can also track the real-time status of all your submissions on your Student Dashboard under the "My Applications" section.</div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingSix">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSix" aria-expanded="false" aria-controls="collapseSix">
                                        What happens if I need to drop my scholarship?
                                    </button>
                                </h2>
                                <div id="collapseSix" class="accordion-collapse collapse" aria-labelledby="headingSix" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">If you have an active scholarship and need to drop it, you can submit a "Drop Request" from your Student Dashboard. You will be required to provide a reason for dropping. The request is then sent to an administrator for review. You will be notified via email once the request is either approved or rejected. If approved, you will be eligible to apply for other scholarships.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Call to Action Section -->
        <?php if (!$is_user_logged_in): ?>
            <section class="cta-section py-5">
                <div class="container">
                    <div class="cta-card bg-primary text-white p-5 rounded-3 shadow-lg" data-aos="fade-up">
                        <div class="row align-items-center">
                            <div class="col-lg-8 text-center text-lg-start">
                                <h2 class="display-5 fw-bold">Ready to Find Your Scholarship?</h2>
                                <p class="lead mb-4 mb-lg-0">Create an account today and start applying for opportunities that will shape your future.</p>
                            </div>
                            <div class="col-lg-4 text-center text-lg-end">
                                <a href="register.php" class="btn btn-light btn-lg fw-bold mt-3 mt-lg-0 cta-button">Get Started Now</a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

    </main>

<?php
include __DIR__ . '/../includes/footer.php';
?>
