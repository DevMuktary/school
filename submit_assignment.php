<?php
require_once 'db_connect.php';
if (!isset($_SESSION['student_id'])) { die("You must be logged in to submit an assignment."); }

$student_id = $_SESSION['student_id'];
$upload_dir = 'uploads/submissions/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assignment_id'])) {
    $assignment_id = intval($_POST['assignment_id']);
    $submission_text = trim($_POST['submission_text']);
    $file_path = '';

    // --- NEW: Security Check ---
    // First, verify the student is actually enrolled in the course this assignment belongs to.
    $security_check_sql = "
        SELECT a.id FROM assignments a
        JOIN enrollments e ON a.course_id = e.course_id
        WHERE a.id = ? AND e.student_id = ?
    ";
    $sec_stmt = $conn->prepare($security_check_sql);
    $sec_stmt->bind_param("ii", $assignment_id, $student_id);
    $sec_stmt->execute();
    if ($sec_stmt->get_result()->num_rows === 0) {
        // SECURITY FAILURE: Student is not enrolled in the course for this assignment.
        header('Location: assignments.php?error=not_enrolled');
        exit();
    }
    $sec_stmt->close();
    // --- End of Security Check ---


    if (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] != 0) {
        header('Location: assignments.php?error=nofile');
        exit();
    }

    $file = $_FILES['submission_file'];
    if ($file['size'] > 10000000) { // Max 10MB
        header('Location: assignments.php?error=toolarge');
        exit();
    }

    $file_name = time() . '_' . $student_id . '_' . basename($file['name']);
    if (move_uploaded_file($file['tmp_name'], $upload_dir . $file_name)) {
        $file_path = $file_name;
    } else {
        header('Location: assignments.php?error=uploadfail');
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO assignment_submissions (assignment_id, student_id, file_path, submission_text) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $assignment_id, $student_id, $file_path, $submission_text);
    
    if ($stmt->execute()) {
        header('Location: assignments.php?status=success');
    } else {
        header('Location: assignments.php?error=dberror');
    }
    $stmt->close();
    $conn->close();
    exit();
}
?>
