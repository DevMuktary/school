<?php
// Set the default timezone to your local time
date_default_timezone_set('Africa/Lagos');

// Start session on all pages
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- DATABASE CONNECTION (Railway & Localhost Compatible) ---
// Railway provides these variables automatically.
// If they are missing (like on your laptop), it falls back to 'localhost'.

$db_host = getenv('MYSQLHOST') ?: 'localhost';
$db_user = getenv('MYSQLUSER') ?: 'universi_arabic';
$db_pass = getenv('MYSQLPASSWORD') ?: 'universi_arabic';
$db_name = getenv('MYSQLDATABASE') ?: 'universi_arabic';
$db_port = getenv('MYSQLPORT') ?: 3306;

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    // On production, don't show the detailed error to users, but log it.
    error_log("Connection failed: " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}

// --- BRANDING & CONFIG ---
// We use getenv() so you can change the URL on Railway without editing code
define('SCHOOL_NAME', 'INTRA-EDU');
define('PORTAL_URL', getenv('PORTAL_URL') ?: 'https://arabic.instituteofmutoon.com');

// --- PAYSTACK API KEYS (SECURED) ---
// These are now loaded from the server environment. 
// NEVER hardcode keys starting with "sk_live_" or "pk_live_" again.
define('PAYSTACK_SECRET_KEY', getenv('PAYSTACK_SECRET_KEY'));
define('PAYSTACK_PUBLIC_KEY', getenv('PAYSTACK_PUBLIC_KEY'));
?>
