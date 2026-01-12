<?php
require_once 'db_connect.php';

// --- LOAD PHPMAILER ---
// We check if the files exist to prevent "File not found" crashes
if (file_exists('phpmailer/src/PHPMailer.php')) {
    require_once 'phpmailer/src/Exception.php';
    require_once 'phpmailer/src/PHPMailer.php';
    require_once 'phpmailer/src/SMTP.php';
} else {
    // Fallback if using Composer (since you added composer.json)
    if (file_exists('vendor/autoload.php')) {
        require_once 'vendor/autoload.php';
    } else {
         die("Error: PHPMailer files are missing. Please ensure the 'phpmailer' folder is uploaded.");
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$errors = [];
$success_msg = "";
$school = null;
$school_slug = '';

// --- 1. GET SCHOOL INFO ---
if (isset($_GET['school'])) {
    $school_slug = trim($_GET['school']);
    $stmt = $conn->prepare("SELECT id, name, logo_path, brand_color FROM schools WHERE slug = ? AND status = 'active'");
    $stmt->bind_param("s", $school_slug);
    $stmt->execute();
    $school = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$school) {
    die("Error: School not found. Please check your URL.");
}

$school_id = $school['id'];
$brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#000000';

// --- 2. FETCH CLASSES ---
$classes = [];
$class_stmt = $conn->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$class_stmt->bind_param("i", $school_id);
$class_stmt->execute();
$result = $class_stmt->get_result();
while($row = $result->fetch_assoc()) { 
    $classes[] = $row; 
}
$class_stmt->close();

// --- 3. PROCESS FORM SUBMISSION ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name  = trim($_POST['full_name']);
    $email      = trim($_POST['email']);
    $phone      = trim($_POST['phone']);
    $username   = trim($_POST['username']);
    $class_id   = intval($_POST['class_id']); 
    $access_code = trim($_POST['access_code']); 

    // A. Validation
    if (empty($full_name) || empty($email) || empty($username) || empty($class_id)) {
        $errors[] = "All fields marked with * are required.";
    }

    // B. Verify Class Access Code
    if (empty($errors)) {
        $code_stmt = $conn->prepare("SELECT id, class_name FROM classes WHERE id = ? AND school_id = ? AND enrollment_key = ?");
        $code_stmt->bind_param("iis", $class_id, $school_id, $access_code);
        $code_stmt->execute();
        $class_info = $code_stmt->get_result()->fetch_assoc();
        $code_stmt->close();

        if (!$class_info) {
            $errors[] = "The Access Code is incorrect for this class.";
        }
    }

    // C. Check Duplicate User
    if (empty($errors)) {
        $check = $conn->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND school_id = ?");
        $check->bind_param("ssi", $email, $username, $school_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errors[] = "This Email or Username is already registered.";
        }
        $check->close();
    }

    // D. Create Account & Enroll
    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $raw_password = bin2hex(random_bytes(4)); // Random password
            $hashed_password = password_hash($raw_password, PASSWORD_DEFAULT);

            // 1. Insert User
            $stmt = $conn->prepare("INSERT INTO users (school_id, role, full_name_eng, email, phone_number, username, password, account_status) VALUES (?, 'student', ?, ?, ?, ?, ?, 'active')");
            
            // FIX IS HERE: "isssss" (6 letters) for 6 variables
            $stmt->bind_param("isssss", $school_id, $full_name, $email, $phone, $username, $hashed_password);
            
            $stmt->execute();
            $student_id = $conn->insert_id;
            $stmt->close();

            // 2. Insert Enrollment
            $stmt = $conn->prepare("INSERT INTO enrollments (student_id, class_id, school_id) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $student_id, $class_id, $school_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            // E. Send Email
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'mail.instituteofmutoon.com'; 
                $mail->SMTPAuth   = true;
                $mail->Username   = 'no-reply@instituteofmutoon.com';
                $mail->Password   = getenv('MAIL_PASSWORD'); 
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = 465;

                $mail->setFrom('no-reply@instituteofmutoon.com', $school['name']);
                $mail->addAddress($email, $full_name);
                $mail->isHTML(true);
                $mail->Subject = 'Admission Successful - ' . $school['name'];
                $mail->Body    = "
                    <h2>Welcome to {$school['name']}</h2>
                    <p>You have been admitted to <strong>{$class_info['class_name']}</strong>.</p>
                    <p><strong>Username:</strong> {$username}</p>
                    <p><strong>Password:</strong> {$raw_password}</p>
                    <p><a href='" . PORTAL_URL . "/login.php?school={$school_slug}'>Login here</a></p>
                ";
                $mail->send();
            } catch (Exception $e) {
                error_log("Mail Error: " . $mail->ErrorInfo);
            }

            header("Location: login.php?school=" . $school_slug . "&reg=success");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "System Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo htmlspecialchars($school['name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
        body { font-family: 'Poppins', sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .register-card { background: white; width: 100%; max-width: 500px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); overflow: hidden; }
        .card-header { background: <?php echo $brand_color; ?>; padding: 30px; text-align: center; color: white; }
        .card-header h2 { margin: 0; font-size: 24px; font-weight: 600; }
        .card-body { padding: 40px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: #555; margin-bottom: 8px; }
        .form-group input, .form-group select { width: 100%; padding: 12px 15px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; }
        .btn-submit { width: 100%; background: <?php echo $brand_color; ?>; color: white; border: none; padding: 14px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .error-msg { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="register-card">
    <div class="card-header">
        <h2><?php echo htmlspecialchars($school['name']); ?></h2>
        <p>Student Admission</p>
    </div>
    <div class="card-body">
        <?php if (!empty($errors)): ?>
            <div class="error-msg"><?php foreach ($errors as $err) echo "• $err<br>"; ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
            <div class="form-group"><label>Phone</label><input type="tel" name="phone"></div>
            <div class="form-group"><label>Class</label>
                <select name="class_id" required>
                    <option value="">-- Select Class --</option>
                    <?php foreach($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Access Code</label><input type="text" name="access_code" required></div>
            <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
            <button type="submit" class="btn-submit">Complete Admission</button>
        </form>
    </div>
</div>
</body>
</html>
