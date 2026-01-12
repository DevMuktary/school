<?php
// This script is designed to be run automatically by a cron job once per day.
// Set a long timeout in case there are many emails to send
set_time_limit(300);

// Use absolute path for reliability in cron jobs
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

echo "Cron script started at " . date('Y-m-d H:i:s') . "\n";

// 1. Find all exams happening tomorrow, joining to get the school's name
$tomorrow_sql = "SELECT ce.id, ce.title, ce.start_date, ce.course_id, s.name as school_name
                 FROM calendar_events ce
                 JOIN courses c ON ce.course_id = c.id
                 JOIN schools s ON c.school_id = s.id
                 WHERE ce.event_type = 'Exam' AND DATE(ce.start_date) = CURDATE() + INTERVAL 1 DAY";
$exams = $conn->query($tomorrow_sql);

if (!$exams || $exams->num_rows == 0) {
    echo "No exams scheduled for tomorrow. Script finished.\n";
    $conn->close();
    exit;
}

while ($exam = $exams->fetch_assoc()) {
    $exam_title = $exam['title'];
    $exam_time = date("g:i A", strtotime($exam['start_date']));
    $course_id = $exam['course_id'];
    $school_name = $exam['school_name'];
    echo "Found exam: '{$exam_title}' for '{$school_name}'.\n";

    // 2. Find all students enrolled in that course using the correct 'users' table
    $students_sql = "SELECT u.full_name_eng, u.email 
                     FROM users u 
                     JOIN enrollments e ON u.id = e.student_id 
                     WHERE e.course_id = ? AND u.role = 'student'";
    $stmt = $conn->prepare($students_sql);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $students = $stmt->get_result();
    
    if ($students->num_rows > 0) {
        echo "  - Found " . $students->num_rows . " students to notify.\n";
        
        // 3. Loop through students and send a branded email
        while ($student = $students->fetch_assoc()) {
            $student_name = $student['full_name_eng'];
            $student_email = $student['email'];

            $mail = new PHPMailer(true);
            try {
                // Your SMTP settings
                $mail->isSMTP();
                $mail->Host       = 'mail.universityofmutoon.com.ng';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'no-reply@universityofmutoon.com.ng';
                $mail->Password   = 'Olalekan@100';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = 465;

                // Set the "From" name to the specific school's name
                $mail->setFrom('no-reply@universityofmutoon.com.ng', $school_name);
                $mail->addAddress($student_email, $student_name);
                $mail->isHTML(true);
                $mail->Subject = "Reminder: Exam Tomorrow - " . $exam_title;
                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; color: #333;'>
                        <h3>Assalamu 'alaykum " . htmlspecialchars($student_name) . ",</h3>
                        <p>This is a friendly reminder that your exam, <strong>'" . htmlspecialchars($exam_title) . "'</strong>, is scheduled for tomorrow at <strong>" . $exam_time . "</strong>.</p>
                        <p>Please be prepared and log in to your student portal on time. We wish you the best of luck!</p>
                        <br>
                        <p>Best regards,</p>
                        <p><strong>The " . htmlspecialchars($school_name) . " Team</strong></p>
                    </div>";
                
                $mail->send();
                echo "    - Reminder sent to {$student_email}\n";

            } catch (Exception $e) {
                echo "    - FAILED to send to {$student_email}. Mailer Error: {$mail->ErrorInfo}\n";
            }
            // Add a small delay to avoid overwhelming the mail server
            sleep(1);
        }
    } else {
        echo "  - No students found for this exam.\n";
    }
    $stmt->close();
}

echo "Cron script finished at " . date('Y-m-d H:i:s') . "\n";
$conn->close();
?>
