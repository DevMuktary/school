<?php
require_once 'db_connect.php';

// --- ADDED: Auto-redirect for single-school setup ---
// If this page is loaded without a ?school=... slug,
// we automatically redirect to the default school's login page.
if (!isset($_GET['school']) || empty(trim($_GET['school']))) {
    // This is the hard-coded URL you specified
    header('Location: https://arabic.instituteofmutoon.com/login.php?school=institute-of-mutoon');
    exit();
}
// --- END ADDITION ---


// --- SECURITY SETTINGS ---
define('MAX_LOGIN_ATTEMPTS', 5); // Max failed attempts allowed
define('LOCKOUT_PERIOD', 900); // Lockout time in seconds (900s = 15 minutes)

$error_message = '';
$school = null;
$school_slug = '';

if (isset($_GET['school'])) {
    $school_slug = trim($_GET['school']);
    $stmt = $conn->prepare("SELECT id, name, logo_path, brand_color FROM schools WHERE slug = ? AND status = 'active'");
    $stmt->bind_param("s", $school_slug);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) { $school = $result->fetch_assoc(); }
    $stmt->close();
}

if (!$school) {
    die("School not found or is inactive. Please use the login link provided by your school.");
}
$school_id = $school['id'];
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#E74C3C';

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
            $stmt = $conn->prepare("SELECT id, password, account_status FROM users WHERE username = ? AND school_id = ? AND role = 'student'");
            $stmt->bind_param("si", $username, $school_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $login_success = false;

            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                if ($user['account_status'] === 'suspended') {
                    $error_message = "Your account has been suspended due to an overdue payment. Please contact your school administrator.";
                } elseif (password_verify($password, $user['password'])) {
                    $login_success = true;
                    
                    $clear_stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                    $clear_stmt->bind_param("s", $ip_address);
                    $clear_stmt->execute();
                    $clear_stmt->close();
                    
                    // --- 2c. SESSION HARDENING ---
                    session_regenerate_id(true); 
                    $_SESSION['student_id'] = $user['id'];
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
                    
                    header("Location: dashboard.php");
                    exit();
                }
            }

            if (!$login_success) {
                $error_message = 'Invalid username or password.';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login - <?php echo htmlspecialchars($school['name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { 
            --brand-primary: <?php echo $school_brand_color; ?>; 
            --bg-light: #f7f9fc;
            --text-dark: #2c3e50;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-light); margin: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .auth-wrapper { display: flex; width: 100%; max-width: 900px; margin: 20px; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        .auth-brand { flex-basis: 40%; background: var(--brand-primary); color: white; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 40px; box-sizing: border-box; }
        .auth-brand .logo img { max-height: 50px; margin-bottom: 15px; }
        .auth-brand .logo span { font-size: 32px; font-weight: 700; }
        .auth-brand p { font-size: 16px; opacity: 0.8; }
        .auth-form { flex-basis: 60%; padding: 50px; box-sizing: border-box; }
        .auth-form h2 { margin-top: 0; margin-bottom: 10px; font-size: 24px; color: var(--text-dark); }
        .auth-form .school-name { font-weight: 600; color: #555; margin-top: 0; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px; }
        .form-group input { width: 100%; padding: 12px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 5px; font-family: 'Poppins', sans-serif; font-size: 16px; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
        .btn:disabled { background-color: #ccc; cursor: not-allowed; }
        .links { margin-top: 20px; font-size: 14px; text-align: center; line-height: 1.8; }
        .links a { color: var(--brand-primary); text-decoration: none; font-weight: 500; }
        .error, .success { padding: 12px; border-radius: 5px; margin-bottom: 20px; border: 1px solid; font-size: 14px; }
        .error { color: #D8000C; background-color: #FFD2D2; border-color: #FFCFCF; }
        .success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
        @media(max-width: 768px) { .auth-brand { display: none; } .auth-form { flex-basis: 100%; } .auth-wrapper { margin: 0; border-radius: 0; box-shadow: none; } }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-brand">
            <div class="logo">
                <?php if (!empty($school['logo_path'])): ?>
                    <img src="uploads/logos/<?php echo htmlspecialchars($school['logo_path']); ?>" alt="<?php echo htmlspecialchars($school['name']); ?> Logo">
                <?php else: ?>
                    <span style="color:white;"><?php echo htmlspecialchars($school['name']); ?></span>
                <?php endif; ?>
            </div>
            <p>Welcome to the Portal</p>
        </div>
        <div class="auth-form">
            <h2>Student Login</h2>
            <p class="school-name"><?php echo htmlspecialchars($school['name']); ?></p>
            
            <?php if (!empty($error_message)): ?><div class="error"><?php echo $error_message; ?></div><?php endif; ?>
            <?php if (isset($_GET['reg'])): ?>
                <div class="success">
                    <?php 
                        if ($_GET['reg'] == 'success_email') { echo 'Account created! Login details have been sent to your email.'; }
                        elseif ($_GET['reg'] == 'success_no_email') { echo 'Account created! Please contact your admin for your password.'; }
                        else { echo 'Account created successfully! Please log in.'; }
                    ?>
                </div>
            <?php endif; ?>

            <form action="login.php?school=<?php echo htmlspecialchars($school_slug); ?>" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required <?php if($is_locked_out) echo 'disabled'; ?>>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required <?php if($is_locked_out) echo 'disabled'; ?>>
                </div>
                <button type="submit" class="btn" <?php if($is_locked_out) echo 'disabled'; ?>>Login</button>
            </form>
            <div class="links">
                No account? <a href="register.php?school=<?php echo htmlspecialchars($school_slug); ?>">Create one</a>
                <br>
                <a href="forgot_password.php?school=<?php echo htmlspecialchars($school_slug); ?>">Forgot Password?</a>
            </div>
        </div>
    </div>
</body>
</html>
