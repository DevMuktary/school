<?php
// Set the default timezone
date_default_timezone_set('Africa/Lagos');

// Start session on all pages if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- DATABASE CONNECTION (Secure & Railway Ready) ---
// Railway automatically provides these "MYSQL..." variables.
// If they are missing (like on your laptop), it falls back to 'localhost'.
$db_host = getenv('MYSQLHOST') ?: 'localhost';
$db_user = getenv('MYSQLUSER') ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: '';
$db_name = getenv('MYSQLDATABASE') ?: 'school_db';
$db_port = getenv('MYSQLPORT') ?: 3306;

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    // In production, we don't want to show technical errors to users
    error_log("Connection failed: " . $conn->connect_error);
    die("System is currently undergoing maintenance. Please try again later.");
}

// --- BRANDING & CONFIG ---
define('SCHOOL_NAME', 'INTRA-EDU');

// Use an environment variable for the URL, fallback to your live site
define('PORTAL_URL', getenv('PORTAL_URL') ?: 'https://arabic.instituteofmutoon.com');

// --- PAYSTACK API KEYS (Loaded from Environment) ---
// We use getenv() so the keys are never written in the code
define('PAYSTACK_SECRET_KEY', getenv('PAYSTACK_SECRET_KEY'));
define('PAYSTACK_PUBLIC_KEY', getenv('PAYSTACK_PUBLIC_KEY'));
?>
