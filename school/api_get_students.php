<?php
// 1. Use the SAME auth file as your other pages
require_once 'auth_check.php'; 

// auth_check.php already:
// - starts the session
// - checks if user is $is_admin or $is_instructor
// - provides $conn, $school_id, $user_id

// 2. Get and validate the course_id from the URL
$course_id = $_GET['course_id'] ?? 0;
if (empty($course_id)) {
    http_response_code(400); // Bad Request
    echo json_encode([]); // Send empty list
    exit();
}

$students = [];

// 3. Use your table name 'enrollments'
$sql = "SELECT u.id, u.full_name_eng 
        FROM users u 
        JOIN enrollments e ON u.id = e.student_id 
        WHERE u.school_id = ? AND e.course_id = ? AND u.role = 'student'";
$params = [$school_id, $course_id];
$types = "ii";

// 4. (Security) If user is an instructor, double-check they are assigned to this course
if ($is_instructor) {
    // This assumes you have a 'course_assignments' table as used in your other files
    $sql .= " AND e.course_id IN (SELECT course_id FROM course_assignments WHERE instructor_id = ?)";
    $params[] = $user_id;
    $types .= "i";
}

$sql .= " ORDER BY u.full_name_eng ASC";

// 5. Execute query
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
}
$conn->close();

// 6. Return the list of students as JSON data
header('Content-Type: application/json');
echo json_encode($students);
?>
