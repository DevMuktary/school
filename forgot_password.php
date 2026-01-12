<?php
require_once 'db_connect.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once 'phpmailer/src/Exception.php';
require_once 'phpmailer/src/PHPMailer.php';
require_once 'phpmailer/src/SMTP.php';

$message = ''; $error = '';
$school = null; $school_slug = '';

// Get the school context from the URL
if (isset($_GET['school'])) {
    $school_slug = trim($_GET['school']);
    $stmt = $conn->prepare("SELECT id, name, logo_path, brand_color FROM schools WHERE slug = ? AND status = 'active'");
    $stmt->bind_param("s", $school_slug);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) { $school = $result->fetch_assoc(); }
    $stmt->close();
}
if (!$school) { die("School not found or is inactive. Please use the link provided by your school."); }
$school_id = $school['id'];
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#E74C3C';

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    
    // Find the user in the correct school with the student role
    $stmt = $conn->prepare("SELECT id, full_name_eng FROM users WHERE email = ? AND school_id = ? AND role = 'student'");
    $stmt->bind_param("si", $email, $school_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $token = bin2hex(random_bytes(50));
        $expires_str = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $stmt_insert = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
        $stmt_insert->bind_param("sss", $email, $token, $expires_str);
        $stmt_insert->execute();
        $stmt_insert->close();

        // Send Email using the new template
        $reset_link = PORTAL_URL . '/reset_password.php?token=' . $token;
        $mail = new PHPMailer(true);
        try {
            // Your SMTP settings
            $mail->isSMTP();
            $mail->Host       = 'mail.instituteofmutoon.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'no-reply@instituteofmutoon.com';
            $mail->Password   = 'Olalekan@100';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;

            $mail->setFrom('no-reply@instituteofmutoon.com', $school['name']);
            $mail->addAddress($email, $user['full_name_eng']);
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request for ' . $school['name'];
            
            $mail_body = file_get_contents('reset_email_template.html');
            $mail_body = str_replace('{{school_name}}', $school['name'], $mail_body);
            $mail_body = str_replace('{{reset_link}}', $reset_link, $mail_body);
            $mail_body = str_replace('{{brand_primary}}', $school_brand_color, $mail_body);
            $mail_body = str_replace('{{current_year}}', date('Y'), $mail_body);
            $mail->Body = $mail_body;

            $mail->send();
            $message = "If an account with that email exists, a password reset link has been sent.";
        } catch (Exception $e) {
            $error = "Message could not be sent. Please try again later.";
        }
    } else {
        // Show a generic message to prevent users from confirming if an email exists in the system
        $message = "If an account with that email exists, a reset link has been sent.";
    }
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo htmlspecialchars($school['name']); ?></title>
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
             <div class="logo">
                <?php if (!empty($school['logo_path'])): ?>
                    <img src="uploads/logos/<?php echo htmlspecialchars($school['logo_path']); ?>" alt="<?php echo htmlspecialchars($school['name']); ?> Logo">
                <?php else: ?>
                    <span style="color:white;"><?php echo htmlspecialchars($school['name']); ?></span>
                <?php endif; ?>
            </div>
            <p>Password Reset</p>
        </div>
        <div class="auth-form">
            <h2>Reset Your Password</h2>
            <p style="color:#555; margin-top: -20px; margin-bottom: 30px;">Enter your email to receive a reset link.</p>
            
            <?php if (!empty($message)): ?>
                <div class="message"><?php echo $message; ?></div>
            <?php elseif (!empty($error)): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php else: ?>
                <form action="forgot_password.php?school=<?php echo htmlspecialchars($school_slug); ?>" method="POST">
                    <div class="form-group">
                        <label for="email">Your Email Address</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <button type="submit" class="btn">Send Reset Link</button>
                </form>
            <?php endif; ?>

            <div class="links">
                Remember your password? <a href="login.php?school=<?php echo htmlspecialchars($school_slug); ?>">Login</a>
            </div>
        </div>
    </div>
</body>
</html>
