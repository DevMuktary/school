<?php
require_once 'db_connect.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
require_once 'phpmailer/src/Exception.php';
require_once 'phpmailer/src/PHPMailer.php';
require_once 'phpmailer/src/SMTP.php';

$errors = [];
$school = null;
$school_slug = '';

// Step 1: Get the school from the URL
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
    die("This registration page is invalid. Please use the link provided by your school.");
}
$school_id = $school['id'];
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#E74C3C'; // Default to INTRA-EDU Red

// Step 2: Fetch courses for this specific school
$courses = [];
$course_stmt = $conn->prepare("SELECT id, title FROM courses WHERE school_id = ? ORDER BY title ASC");
$course_stmt->bind_param("i", $school_id);
$course_stmt->execute();
$course_result = $course_stmt->get_result();
if ($course_result) { while($row = $course_result->fetch_assoc()) { $courses[] = $row; } }
$course_stmt->close();

// Step 3: Handle the form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullNameEng = trim($_POST['full_name_eng']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone_number']);
    $username = trim($_POST['username']);
    $level = $_POST['level'];
    $course_id = intval($_POST['course_id']);
    $enrollment_key = trim($_POST['enrollment_key']);

    // --- Validation ---
    if (empty($fullNameEng) || empty($email) || empty($phone) || empty($username) || empty($level) || empty($course_id) || empty($enrollment_key)) {
        $errors[] = "All fields are required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Invalid email format."; }
    
    $course_title = "the selected course"; // Default title
    foreach($courses as $c) { if ($c['id'] == $course_id) { $course_title = $c['title']; break; } }

    $key_stmt = $conn->prepare("SELECT id FROM courses WHERE id = ? AND school_id = ? AND enrollment_key = ?");
    $key_stmt->bind_param("iis", $course_id, $school_id, $enrollment_key);
    $key_stmt->execute();
    if ($key_stmt->get_result()->num_rows == 0) { $errors[] = "The enrollment key for the selected course is incorrect."; }
    $key_stmt->close();

    if (empty($errors)) {
        $user_check_stmt = $conn->prepare("SELECT id, full_name_eng FROM users WHERE email = ?");
        $user_check_stmt->bind_param("s", $email);
        $user_check_stmt->execute();
        $user_result = $user_check_stmt->get_result();
        $existing_user = $user_result->fetch_assoc();
        $user_check_stmt->close();

        if ($existing_user) {
            $student_id = $existing_user['id'];
            $enrollment_check_stmt = $conn->prepare("SELECT id FROM enrollments WHERE student_id = ? AND course_id = ?");
            $enrollment_check_stmt->bind_param("ii", $student_id, $course_id);
            $enrollment_check_stmt->execute();
            if ($enrollment_check_stmt->get_result()->num_rows > 0) {
                $errors[] = "You are already enrolled in this course. Please login to access it.";
            } else {
                $stmt = $conn->prepare("INSERT INTO enrollments (student_id, course_id, school_id) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $student_id, $course_id, $school_id);
                $stmt->execute();
                $stmt->close();

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'mail.instituteofmutoon.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'no-reply@instituteofmutoon.com';
                    $mail->Password   = 'Olalekan@100';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port       = 465;

                    $mail->setFrom('no-reply@instituteofmutoon.com', $school['name']);
                    $mail->addAddress($email, $existing_user['full_name_eng']);
                    
                    $mail->isHTML(true);
                    $mail->Subject = 'New Course Enrollment Confirmation';
                    $mail_body = file_get_contents('enrollment_confirmation_template.html');
                    $mail_body = str_replace('{{student_name}}', $existing_user['full_name_eng'], $mail_body);
                    $mail_body = str_replace('{{course_name}}', $course_title, $mail_body);
                    $mail_body = str_replace('{{portal_url}}', "https://arabic.instituteofmutoon.com/login.php?school=" . $school_slug, $mail_body);
                    $mail_body = str_replace('{{school_name}}', $school['name'], $mail_body);
                    $mail_body = str_replace('{{brand_color}}', $school_brand_color, $mail_body);
                    $mail->Body = $mail_body;
                    
                    $mail->send();
                    
                    header("Location: login.php?school=" . $school_slug . "&reg=success_enroll");
                    exit();
                } catch (Exception $e) {
                    $errors[] = "Enrollment was successful, but the confirmation email could not be sent. Please login to access your new course. Mailer Error: {$mail->ErrorInfo}";
                }
            }
        } else {
            $username_check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $username_check_stmt->bind_param("s", $username);
            $username_check_stmt->execute();
            if ($username_check_stmt->get_result()->num_rows > 0) {
                $errors[] = "This username is already taken. Please choose another one.";
            }
            $username_check_stmt->close();

            if (empty($errors)) {
                $password = bin2hex(random_bytes(5));
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $conn->begin_transaction();
                try {
                    $stmt1 = $conn->prepare("INSERT INTO users (school_id, role, full_name_eng, email, phone_number, username, password, level, account_status) VALUES (?, 'student', ?, ?, ?, ?, ?, ?, 'active')");
                    $stmt1->bind_param("issssss", $school_id, $fullNameEng, $email, $phone, $username, $hashed_password, $level);
                    $stmt1->execute();
                    $new_student_id = $conn->insert_id;
                    $stmt1->close();
                    
                    $stmt2 = $conn->prepare("INSERT INTO enrollments (student_id, course_id, school_id) VALUES (?, ?, ?)");
                    $stmt2->bind_param("iii", $new_student_id, $course_id, $school_id);
                    $stmt2->execute();
                    $stmt2->close();

                    $conn->commit();

                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = 'mail.instituteofmutoon.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'no-reply@instituteofmutoon.com';
                        $mail->Password   = 'Olalekan@100';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                        $mail->Port       = 465;

                        $mail->setFrom('no-reply@instituteofmutoon.com', $school['name']);
                        $mail->addAddress($email, $fullNameEng);
                        
                        $mail->isHTML(true);
                        $mail->Subject = 'Welcome to ' . $school['name'];
                        $mail_body = file_get_contents('email_template.html');
                        $mail_body = str_replace('{{student_name}}', $fullNameEng, $mail_body);
                        $mail_body = str_replace('{{portal_url}}', "https://arabic.instituteofmutoon.com/login.php?school=" . $school_slug, $mail_body);
                        $mail_body = str_replace('{{username}}', $username, $mail_body);
                        $mail_body = str_replace('{{password}}', $password, $mail_body);
                        $mail_body = str_replace('{{school_name}}', $school['name'], $mail_body);
                        $mail_body = str_replace('{{brand_color}}', $school_brand_color, $mail_body);
                        $mail->Body = $mail_body;
                        
                        $mail->send();
                        
                        header("Location: login.php?school=" . $school_slug . "&reg=success_email");
                        exit();

                    } catch (Exception $e) {
                        $errors[] = "Registration was successful, but the welcome email could not be sent. Please contact the administrator. Mailer Error: {$mail->ErrorInfo}";
                    }
                } catch (mysqli_sql_exception $exception) {
                    $conn->rollback();
                    $errors[] = "A database error occurred during registration. Please try again.";
                }
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
    <title>Create Account - <?php echo htmlspecialchars($school['name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { 
            --brand-primary: <?php echo $school_brand_color; ?>; 
            --bg-light: #f7f9fc;
            --text-dark: #2c3e50;
            --border-color: #dee2e6;
        }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-light);
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 40px 15px;
            box-sizing: border-box;
        }
        .auth-wrapper {
            display: flex;
            width: 100%;
            max-width: 1000px;
            margin: 20px;
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .auth-brand {
            flex-basis: 40%;
            background: var(--brand-primary);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 40px;
            box-sizing: border-box;
        }
        .auth-brand .logo img { max-height: 50px; margin-bottom: 15px; }
        .auth-brand .logo span { font-size: 32px; font-weight: 700; }
        .auth-brand p { font-size: 16px; opacity: 0.8; }
        .auth-form {
            flex-basis: 60%;
            padding: 40px;
            box-sizing: border-box;
            overflow-y: auto;
        }
        .auth-form h2 {
            margin-top: 0;
            margin-bottom: 5px;
            font-size: 24px;
            color: var(--text-dark);
        }
        .auth-form .school-name {
            font-weight: 600;
            color: #555;
            margin-top: 0;
            margin-bottom: 25px;
            text-align: center;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .full-width { grid-column: 1 / -1; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px; }
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            box-sizing: border-box;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
        }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
        .links { margin-top: 20px; font-size: 14px; text-align: center; }
        .links a { color: var(--brand-primary); text-decoration: none; font-weight: 500; }
        .error-list { list-style-type: none; padding: 12px; margin: 0 0 20px 0; border-radius: 5px; color: #D8000C; background-color: #FFD2D2; font-size: 14px; }
        .error-list li { padding: 5px 0; border: none; }
        
        @media(max-width: 900px) {
            .auth-brand { display: none; }
            .auth-form { flex-basis: 100%; }
        }
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
        }
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
            <p>Student Registration</p>
        </div>
        <div class="auth-form">
            <h2>Create Your Student Account</h2>
            <p class="school-name"><?php echo htmlspecialchars($school['name']); ?></p>
            <?php if (!empty($errors)): ?>
                <ul class="error-list">
                    <?php foreach ($errors as $error_item): ?><li><?php echo $error_item; ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form action="register.php?school=<?php echo htmlspecialchars($school_slug); ?>" method="POST">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="course_id">Select a Course</label>
                        <select id="course_id" name="course_id" required>
                            <option value="" disabled selected>-- Choose your course --</option>
                            <?php foreach($courses as $course): ?>
                                <option value="<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label for="enrollment_key">Course Enrollment Key</label>
                        <input type="text" id="enrollment_key" name="enrollment_key" required>
                    </div>
                    <div class="form-group"><label for="full_name_eng">Full Name</label><input type="text" id="full_name_eng" name="full_name_eng" required></div>
                    <div class="form-group"><label for="email">Email</label><input type="email" id="email" name="email" required></div>
                    <div class="form-group"><label for="phone_number">Phone</label><input type="tel" id="phone_number" name="phone_number" required></div>
                    <div class="form-group"><label for="username">Username</label><input type="text" id="username" name="username" required></div>
                    <div class="form-group full-width"><label for="level">Your Level</label><select id="level" name="level" required><option value="" disabled selected>-- Choose --</option><option value="Beginner">Beginner</option><option value="Intermediate">Intermediate</option></select></div>
                    <div class="form-group full-width"><button type="submit" class="btn">Create Account</button></div>
                </div>
            </form>
            <div class="links">Already have an account? <a href="login.php?school=<?php echo htmlspecialchars($school_slug); ?>">Login</a></div>
        </div>
    </div>
</body>
</html>
