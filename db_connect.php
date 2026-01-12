<?php
date_default_timezone_set('Africa/Lagos');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- DATABASE CONNECTION ---
$db_host = getenv('MYSQLHOST');
$db_user = getenv('MYSQLUSER');
$db_pass = getenv('MYSQLPASSWORD');
$db_name = getenv('MYSQLDATABASE');
$db_port = getenv('MYSQLPORT');

// 1. Check if variables are missing
if (empty($db_name)) {
    die("<b>Error:</b> The environment variable 'MYSQLDATABASE' is not set or is empty. Please check your Railway Variables.");
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    die("System maintenance. Please try again later.");
}

// --- CONFIG ---
define('SCHOOL_NAME', 'INTRA-EDU');
define('PORTAL_URL', getenv('PORTAL_URL'));
define('PAYSTACK_SECRET_KEY', getenv('PAYSTACK_SECRET_KEY'));
define('PAYSTACK_PUBLIC_KEY', getenv('PAYSTACK_PUBLIC_KEY'));
?>
