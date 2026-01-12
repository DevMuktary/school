<?php
// Set the default timezone
date_default_timezone_set('Africa/Lagos');

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- DATABASE CONNECTION ---
// We use getenv() to pull strictly from Railway.
// If these are missing, the app will (and should) fail, alerting us to fix the config.
$db_host = getenv('MYSQLHOST');
$db_user = getenv('MYSQLUSER');
$db_pass = getenv('MYSQLPASSWORD');
$db_name = getenv('MYSQLDATABASE');
$db_port = getenv('MYSQLPORT');

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    // Log the error for the developer but show a clean message to the user
    error_log("DB Connection Error: " . $conn->connect_error);
    die("System maintenance. Please try again later.");
}

// --- BRANDING & CONFIG ---
define('SCHOOL_NAME', 'INTRA-EDU');

// This will now ONLY use the URL you set in Railway variables.
define('PORTAL_URL', getenv('PORTAL_URL'));

// --- PAYSTACK API KEYS ---
define('PAYSTACK_SECRET_KEY', getenv('PAYSTACK_SECRET_KEY'));
define('PAYSTACK_PUBLIC_KEY', getenv('PAYSTACK_PUBLIC_KEY'));
?>
