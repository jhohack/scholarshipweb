<?php
// Database configuration - Environment detection
if ($_SERVER['HTTP_HOST'] === 'dvc.infinityfree.me') {
    // Production: InfinityFree
    define('DB_HOST', 'sql312.infinityfree.com');
    define('DB_NAME', 'if0_39584519_scholarship_db');
    define('DB_USER', 'if0_39584519');
    define('DB_PASS', 'firmeza04');
    define('BASE_URL', 'https://dvc.infinityfree.me/websitescholarship/scholarship-portal');
} else {
    // Local development
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'scholarship_db');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('BASE_URL', 'http://localhost/websitescholarship/scholarship-portal');
}

// Google Client ID
define('GOOGLE_CLIENT_ID', '127649949023-se8oo6060ho0amkk852h2lk0atms23vj.apps.googleusercontent.com');

// --- SMTP Settings for PHPMailer ---
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'jhorosef@gmail.com'); // Your Gmail address
define('SMTP_PASS', 'wxphswocdbjqsppf'); // The App Password you generated
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls'); // Or 'ssl' for port 465

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>