<?php
require_once 'db_connect.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
require_once 'phpmailer/src/Exception.php';
require_once 'phpmailer/src/PHPMailer.php';
require_once 'phpmailer/src/SMTP.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['email'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request.']);
    exit();
}

$email = trim($_POST['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address.']);
    exit();
}

$stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt_check->bind_param("s", $email);
$stmt_check->execute();
if ($stmt_check->get_result()->num_rows > 0) {
    echo json_encode(['status' => 'error', 'message' => 'This email address is already in use.']);
    exit();
}
$stmt_check->close();

$otp = rand(100000, 999999);
$_SESSION['otp'] = $otp;
$_SESSION['otp_email'] = $email;
$_SESSION['otp_expiry'] = time() + 300;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'mail.instituteofmutoon.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'no-reply@instituteofmutoon.com';
    $mail->Password   = 'Olalekan@100';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;

    $mail->setFrom('no-reply@universityofmutoon.com.ng', 'INTRA-EDU Platform');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = "Your INTRA-EDU Verification Code";
    
    $mail_body = file_get_contents('otp_email_template.html');
    $mail_body = str_replace('{{otp_code}}', $otp, $mail_body);
    $mail_body = str_replace('{{current_year}}', date('Y'), $mail_body); // <-- This is the new line
    $mail->Body = $mail_body;
    
    $mail->send();
    
    echo json_encode(['status' => 'success', 'message' => 'Verification code sent to your email.']);
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Could not send verification email. Please try again.']);
}
$conn->close();
?>
