<?php
require_once '../db_connect.php';

// 1. Check if a School Admin or an Instructor is logged in
if (!isset($_SESSION['school_admin_id']) && !isset($_SESSION['instructor_id'])) {
    header('Location: index.php'); // If not, send them to the login page
    exit();
}

// 2. Set easy-to-use variables for the user's role, ID, and school ID
$is_admin = isset($_SESSION['school_admin_id']);
$is_instructor = isset($_SESSION['instructor_id']);
$user_id = $is_admin ? $_SESSION['school_admin_id'] : $_SESSION['instructor_id'];
$school_id = $_SESSION['school_id'];

/**
 * 3. A reusable function to verify that the logged-in user has permission to access a specific course.
 * This is the core of the security for instructors.
 */
function verify_course_access($conn, $course_id_to_check, $is_instructor, $instructor_id, $school_id) {
    if ($is_instructor) {
        // For instructors, check if they are explicitly assigned to this course
        $stmt = $conn->prepare("SELECT id FROM course_assignments WHERE instructor_id = ? AND course_id = ? AND school_id = ?");
        $stmt->bind_param("iii", $instructor_id, $course_id_to_check, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            // Access Denied! This instructor is not assigned to this course.
            header('Location: instructor_dashboard.php?error=access_denied');
            exit();
        }
        $stmt->close();
    } else { // For School Admins
        // For admins, just verify the course belongs to their school
        $stmt = $conn->prepare("SELECT id FROM courses WHERE id = ? AND school_id = ?");
        $stmt->bind_param("ii", $course_id_to_check, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            // This course doesn't belong to the admin's school.
            header('Location: dashboard.php?error=access_denied');
            exit();
        }
        $stmt->close();
    }
}
?>
