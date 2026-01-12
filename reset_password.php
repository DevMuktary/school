<?php
require_once 'db_connect.php';

$error = '';
$message = '';
$token_valid = false;
$token = $_GET['token'] ?? '';

if(!empty($token)) {
    // Check if token is valid and NOT expired
    $stmt_check = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt_check->bind_param("s", $token);
    $stmt_check->execute();
    $result = $stmt_check->get_result();

    if($result->num_rows > 0) {
        $token_valid = true;
        $row = $result->fetch_assoc();
        $email = $row['email'];
    } else {
        $error = "This password reset link is invalid or has expired.";
    }
    $stmt_check->close();
} else {
    $error = "No reset token provided. Please request a new link.";
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    if(strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif($password === $password_confirm) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Update the correct 'users' table
        $stmt_update = $conn->prepare("UPDATE users SET password = ? WHERE email = ? AND role = 'student'");
        $stmt_update->bind_param("ss", $hashed_password, $email);
        $stmt_update->execute();
        $stmt_update->close();
        
        // Delete the used token
        $stmt_delete = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt_delete->bind_param("s", $email);
        $stmt_delete->execute();
        $stmt_delete->close();

        $message = "Your password has been reset successfully!";
        $token_valid = false; // Hide form after successful reset
    } else {
        $error = "Passwords do not match.";
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
    <title>Reset Password - INTRA-EDU</title>
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
        .auth-form h2 { margin-top: 0; font-size: 24px; color: var(--text-dark); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px; }
        .form-group input { width: 100%; padding: 12px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 5px; font-family: 'Poppins', sans-serif; font-size: 16px; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
        .links { margin-top: 20px; font-size: 14px; text-align: center; }
        .links a { color: var(--brand-primary); text-decoration: none; font-weight: 500; }
        .message, .error { padding: 12px; border-radius: 5px; margin-bottom: 20px; border: 1px solid; font-size: 14px; }
        .message { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
        .error { color: #D8000C; background-color: #FFD2D2; border-color: #D8000C; }
        @media(max-width: 768px) { .auth-brand { display: none; } .auth-form { flex-basis: 100%; } }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-brand">
            <div class="logo">INTRA-EDU</div>
            <p>Set Your New Password</p>
        </div>
        <div class="auth-form">
            <h2>Set a New Password</h2>
            
            <?php if (!empty($error)): ?>
                <div class="error"><?php echo $error; ?></div>
                <div class="links"><a href="index.php">Return to Homepage</a></div>
            <?php elseif (!empty($message)): ?>
                <div class="message"><?php echo $message; ?></div>
                <div class="links"><a href="index.php">Proceed to Login</a></div>
            <?php endif; ?>

            <?php if ($token_valid): ?>
            <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="POST">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                 <div class="form-group">
                    <label for="password_confirm">Confirm New Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" required>
                </div>
                <button type="submit" class="btn">Reset Password</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
