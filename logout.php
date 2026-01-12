<?php
require_once 'db_connect.php'; // Needed to connect to the database and start the session

$redirect_slug = null;

// Check if a student is logged in before destroying the session
if (isset($_SESSION['student_id'])) {
    $student_id = $_SESSION['student_id'];
    
    // Find the student's school slug from their enrollment
    $stmt = $conn->prepare("
        SELECT s.slug 
        FROM schools s 
        JOIN enrollments e ON s.id = e.school_id 
        WHERE e.student_id = ?
    ");
    
    if ($stmt) {
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $school = $result->fetch_assoc();
            $redirect_slug = $school['slug'];
        }
        $stmt->close();
    }
}

$conn->close();

// Now, destroy the session completely
session_unset();
session_destroy();

// Redirect to the correct school's login page
if ($redirect_slug) {
    header('Location: login.php?school=' . $redirect_slug);
} else {
    // Fallback redirect to the main homepage if no school is found
    header('Location: index.php');
}
exit();
?>
