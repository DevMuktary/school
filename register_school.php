<?php
require_once 'db_connect.php';

$error = '';
$message = '';
$form_submitted_successfully = false;

function createSlug($string) {
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9-\s]/', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);
    return $string;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $school_name = trim($_POST['school_name']);
    $owner_name = trim($_POST['owner_name']);
    $owner_email = trim($_POST['owner_email']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];
    $submitted_otp = trim($_POST['otp']);

    if (empty($school_name) || empty($owner_name) || empty($owner_email) || empty($password) || empty($submitted_otp)) {
        $error = "All fields are required.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($password !== $password_confirm) {
        $error = "Passwords do not match.";
    } 
    elseif (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email']) || $_SESSION['otp_email'] !== $owner_email) {
        $error = "Email verification session is invalid. Please start over by re-verifying your email.";
    } elseif (time() > $_SESSION['otp_expiry']) {
        $error = "Your verification code has expired. Please re-verify your email.";
    } elseif ($submitted_otp != $_SESSION['otp']) {
        $error = "The verification code is incorrect.";
    } else {
        $school_slug = createSlug($school_name);
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $conn->begin_transaction();
        try {
            $stmt_school = $conn->prepare("INSERT INTO schools (name, owner_email, slug, subscription_status) VALUES (?, ?, ?, 'awaiting_payment')");
            $stmt_school->bind_param("sss", $school_name, $owner_email, $school_slug);
            $stmt_school->execute();
            $new_school_id = $conn->insert_id;
            $stmt_school->close();

            $stmt_user = $conn->prepare("INSERT INTO users (school_id, role, full_name_eng, email, password, username) VALUES (?, 'school_admin', ?, ?, ?, ?)");
            $stmt_user->bind_param("issss", $new_school_id, $owner_name, $owner_email, $hashed_password, $owner_email);
            $stmt_user->execute();
            $new_admin_id = $conn->insert_id;
            $stmt_user->close();

            $conn->commit();
            
            // Clear the OTP from session after successful use
            unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_expiry']);
            
            // --- CORRECTED: Auto-login and redirect logic ---
            $_SESSION['school_admin_id'] = $new_admin_id;
            $_SESSION['school_id'] = $new_school_id;
            $_SESSION['school_user_name'] = $owner_name;
            
            header("Location: school/dashboard.php");
            exit();

        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            $error = "Registration failed. The school name or email may already be taken.";
        }
    }
}
$conn->close();

define('PLATFORM_NAME', 'INTRA-EDU');
define('BRAND_RED', '#E74C3C');
define('TEXT_DARK', '#2c3e50');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Your School - <?php echo PLATFORM_NAME; ?> Platform</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { 
            --brand-primary: <?php echo BRAND_RED; ?>; 
            --text-dark: <?php echo TEXT_DARK; ?>;
            --bg-light: #f7f9fc;
            --border-color: #dee2e6;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-light); margin: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 40px 15px; box-sizing: border-box; }
        .auth-wrapper { display: flex; width: 100%; max-width: 900px; margin: 20px; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        .auth-brand { flex-basis: 40%; background-color: var(--text-dark); color: white; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 40px; box-sizing: border-box; }
        .auth-brand .logo { font-size: 36px; font-weight: 700; margin-bottom: 10px; color: var(--brand-primary); }
        .auth-brand p { font-size: 16px; opacity: 0.8; }
        .auth-form { flex-basis: 60%; padding: 50px; box-sizing: border-box; }
        .auth-form h2 { margin-top: 0; margin-bottom: 30px; font-size: 24px; color: var(--text-dark); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px; }
        .form-group input { width: 100%; padding: 12px; box-sizing: border-box; border: 1px solid var(--border-color); border-radius: 5px; font-family: 'Poppins', sans-serif; font-size: 16px; }
        .email-group { display: flex; gap: 10px; align-items: flex-end; }
        .email-group .form-group { flex-grow: 1; margin-bottom: 0; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; transition: background-color 0.3s; }
        .btn:disabled { background-color: #ccc; cursor: not-allowed; }
        .btn-outline { background: none; border: 2px solid var(--brand-primary); color: var(--brand-primary); }
        .btn-verify { padding: 12px; height: 49px; white-space: nowrap; width: auto; flex-shrink: 0;}
        #otp-group { display: none; }
        .links { margin-top: 20px; font-size: 14px; text-align: center; }
        .links a { color: var(--brand-primary); text-decoration: none; font-weight: 500; }
        .message, .error { padding: 12px; border-radius: 5px; margin-bottom: 20px; text-align: center; font-size: 14px; border: 1px solid transparent; }
        .error { color: #D8000C; background-color: #FFD2D2; border-color: #ffc2c2;}
        .message { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
        @media(max-width: 768px) { .auth-brand { display: none; } .auth-form { flex-basis: 100%; } }
    </style>
</head>
<body>
    <div class="auth-wrapper">
         <div class="auth-brand">
            <div class="logo">INTRA-EDU</div>
            <p>The Future of Learning Management</p>
        </div>
        <div class="auth-form">
            <h2>Register Your School</h2>
            <div id="form-message">
                <?php if (!empty($error)): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>
                <?php if (!empty($message)): ?><p class="message"><?php echo $message; ?></p><?php endif; ?>
            </div>
            
            <?php if (!$form_submitted_successfully): ?>
            <form id="register-form" action="register_school.php" method="POST">
                <div class="form-group">
                    <label for="owner_email">Your Email Address</label>
                    <div class="email-group">
                        <div class="form-group"><input type="email" id="owner_email" name="owner_email" required></div>
                        <button type="button" id="verify-email-btn" class="btn btn-outline btn-verify">Verify</button>
                    </div>
                </div>
                
                <div id="otp-group" class="form-group">
                    <label for="otp">Verification Code</label>
                    <input type="text" id="otp" name="otp" required placeholder="Check your email for the code">
                </div>

                <div class="form-group"><label for="school_name">School Name</label><input type="text" id="school_name" name="school_name" required></div>
                <div class="form-group"><label for="owner_name">Your Full Name (Admin)</label><input type="text" id="owner_name" name="owner_name" required></div>
                <div class="form-group"><label for="password">Password</label><input type="password" id="password" name="password" required></div>
                <div class="form-group"><label for="password_confirm">Confirm Password</label><input type="password" id="password_confirm" name="password_confirm" required></div>

                <button type="submit" name="register_school" id="register-btn" class="btn" disabled>Register School</button>
            </form>
            <?php endif; ?>
             <div class="links">
                Already have an account? <a href="school/">School Admin Login</a>
            </div>
        </div>
    </div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const verifyBtn = document.getElementById('verify-email-btn');
    const registerBtn = document.getElementById('register-btn');
    const emailInput = document.getElementById('owner_email');
    const otpGroup = document.getElementById('otp-group');
    const formMessage = document.getElementById('form-message');

    // Restore state if there was a server-side error
    <?php if ($error && isset($_POST['owner_email'])): ?>
        emailInput.value = '<?php echo htmlspecialchars($_POST['owner_email']); ?>';
        emailInput.readOnly = true;
        otpGroup.style.display = 'block';
        registerBtn.disabled = false;
        verifyBtn.textContent = 'Sent!';
        verifyBtn.disabled = true;
    <?php endif; ?>

    verifyBtn.addEventListener('click', function() {
        const email = emailInput.value;
        if (!email) {
            formMessage.innerHTML = `<p class="error">Please enter an email address first.</p>`;
            return;
        }

        verifyBtn.textContent = 'Sending...';
        verifyBtn.disabled = true;

        fetch('send_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'email=' + encodeURIComponent(email)
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                formMessage.innerHTML = `<p class="message">${data.message}</p>`;
                otpGroup.style.display = 'block';
                emailInput.readOnly = true;
                registerBtn.disabled = false;
                verifyBtn.textContent = 'Sent!';
            } else {
                formMessage.innerHTML = `<p class="error">${data.message}</p>`;
                verifyBtn.textContent = 'Verify';
                verifyBtn.disabled = false;
            }
        })
        .catch(error => {
            formMessage.innerHTML = `<p class="error">A network error occurred. Please try again.</p>`;
            verifyBtn.textContent = 'Verify';
            verifyBtn.disabled = false;
        });
    });
});
</script>
</body>
</html>
