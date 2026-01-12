<?php
require_once '../db_connect.php'; 

// --- SECURITY SETTINGS ---
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_PERIOD', 900); // 15 minutes

$error_message = '';

if (isset($_SESSION['super_admin_id'])) {
    header("Location: dashboard.php");
    exit();
}

// --- CSRF TOKEN GENERATION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$ip_address = $_SERVER['REMOTE_ADDR'];
$is_locked_out = false;

// --- 1. BRUTE-FORCE: Check if IP is currently locked out ---
$lock_stmt = $conn->prepare("SELECT failed_attempts, last_attempt_at FROM login_attempts WHERE ip_address = ?");
$lock_stmt->bind_param("s", $ip_address);
$lock_stmt->execute();
$lock_result = $lock_stmt->get_result();
if ($lock_result->num_rows > 0) {
    $attempt_data = $lock_result->fetch_assoc();
    $time_since_last_attempt = time() - strtotime($attempt_data['last_attempt_at']);
    if ($attempt_data['failed_attempts'] >= MAX_LOGIN_ATTEMPTS && $time_since_last_attempt < LOCKOUT_PERIOD) {
        $is_locked_out = true;
        $wait_time = ceil((LOCKOUT_PERIOD - $time_since_last_attempt) / 60);
        $error_message = "Too many failed login attempts. Please try again in {$wait_time} minute(s).";
    }
}
$lock_stmt->close();

// --- 2. Process Login Attempt if not locked out ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && !$is_locked_out) {
    // --- 2a. CSRF TOKEN VALIDATION ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "Invalid security token. Please refresh and try again.";
    } else {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        
        if (empty($username) || empty($password)) {
            $error_message = 'Please enter both username and password.';
        } else {
            $stmt = $conn->prepare("SELECT id, username, password FROM super_admins WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $login_success = false;

            if ($result->num_rows == 1) {
                $admin = $result->fetch_assoc();
                if (password_verify($password, $admin['password'])) {
                    $login_success = true;

                    $clear_stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                    $clear_stmt->bind_param("s", $ip_address);
                    $clear_stmt->execute();
                    $clear_stmt->close();
                    
                    // --- 2c. SESSION HARDENING ---
                    session_regenerate_id(true);
                    $_SESSION['super_admin_id'] = $admin['id'];
                    $_SESSION['super_admin_username'] = $admin['username'];
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];

                    header("Location: dashboard.php");
                    exit();
                }
            }
            
            if (!$login_success) {
                $error_message = 'Invalid credentials.';
                $record_attempt_sql = "INSERT INTO login_attempts (ip_address, failed_attempts) VALUES (?, 1) ON DUPLICATE KEY UPDATE failed_attempts = failed_attempts + 1";
                $record_stmt = $conn->prepare($record_attempt_sql);
                $record_stmt->bind_param("s", $ip_address);
                $record_stmt->execute();
                $record_stmt->close();
            }
        }
    }
}
$conn->close();

define('BRAND_RED', '#E74C3C');
define('TEXT_DARK', '#2c3e50');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login - INTRA-EDU</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { 
            --brand-primary: <?php echo BRAND_RED; ?>; 
            --text-dark: <?php echo TEXT_DARK; ?>;
            --bg-light: #f7f9fc;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-light); margin: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .auth-wrapper { display: flex; width: 100%; max-width: 900px; margin: 20px; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        .auth-brand { flex-basis: 40%; background-color: var(--text-dark); color: white; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 40px; box-sizing: border-box; }
        .auth-brand .logo { font-size: 36px; font-weight: 700; margin-bottom: 10px; color: var(--brand-primary); }
        .auth-brand p { font-size: 16px; opacity: 0.8; }
        .auth-form { flex-basis: 60%; padding: 50px; box-sizing: border-box; }
        .auth-form h2 { margin-top: 0; margin-bottom: 30px; font-size: 24px; color: var(--text-dark); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px; }
        .form-group input { width: 100%; padding: 12px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 5px; font-family: 'Poppins', sans-serif; font-size: 16px; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
        .btn:disabled { background-color: #ccc; cursor: not-allowed; }
        .error { color: #D8000C; background-color: #FFD2D2; padding: 12px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; }
        @media(max-width: 768px) { .auth-brand { display: none; } .auth-form { flex-basis: 100%; } }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-brand">
            <div class="logo">INTRA-EDU</div>
            <p>Platform Administration</p>
        </div>
        <div class="auth-form">
            <h2>Super Admin Panel</h2>
            <?php if (!empty($error_message)): ?>
                <div class="error"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <form action="index.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="username">Super Admin Username</label>
                    <input type="text" id="username" name="username" required <?php if($is_locked_out) echo 'disabled'; ?>>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required <?php if($is_locked_out) echo 'disabled'; ?>>
                </div>
                <button type="submit" class="btn" <?php if($is_locked_out) echo 'disabled'; ?>>Login</button>
            </form>
        </div>
    </div>
</body>
</html>
