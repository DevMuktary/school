<?php
// Set content type to JSON
header('Content-Type: application/json');

// PART 1: AUTH & SETUP
require_once 'auth_check.php';

// This API only accepts POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Get the JSON data sent from the JavaScript
$data = json_decode(file_get_contents('php://input'));

if ($data === null) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data.']);
    exit();
}

// Check authorization. Use the user ID from the SESSION, not from the request.
if (!$is_admin && !$is_instructor) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'You are not authorized.']);
    exit();
}

// --- NEW PERCENTAGE-BASED GRADING FUNCTION ---
/**
 * Calculates grade and remark based on score and max_score.
 * This is based on the percentages from your original 20-point scale.
 */
function calculate_grade_details($score, $max_score) {
    if ($max_score <= 0) {
        return ['grade' => 'N/A', 'remark' => 'Invalid Max Score'];
    }
    
    $perc = ($score / $max_score) * 100;

    if ($perc >= 95) return ['grade' => 'A1', 'remark' => 'Excellent']; // 19/20
    if ($perc >= 85) return ['grade' => 'B2', 'remark' => 'Very Good']; // 17/20
    if ($perc >= 80) return ['grade' => 'B3', 'remark' => 'Good'];      // 16/20
    if ($perc >= 70) return ['grade' => 'C4', 'remark' => 'Credit'];    // 14/20
    if ($perc >= 65) return ['grade' => 'C5', 'remark' => 'Credit'];    // 13/20
    if ($perc >= 55) return ['grade' => 'C6', 'remark' => 'Credit'];    // 11/20
    if ($perc >= 50) return ['grade' => 'D7', 'remark' => 'Pass'];      // 10/20
    if ($perc >= 45) return ['grade' => 'E8', 'remark' => 'Pass'];      // 9/20
    return ['grade' => 'F9', 'remark' => 'Fail'];
}
// --- END OF NEW FUNCTION ---


// --- PART 2: DATA VALIDATION & SECURITY ---
$exam_id = $data->exam_id ?? 0;
$student_id = $data->student_id ?? 0;
$exam_subject_id = $data->exam_subject_id ?? 0;
$score = $data->score ?? 0; // JS sends 0 for empty string

// Use the session user_id for security
$session_user_id = $user_id;

if (empty($exam_id) || empty($student_id) || empty($exam_subject_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required IDs.']);
    exit();
}

// Start transaction for safety
$conn->begin_transaction();
try {
    // 1. CRITICAL SECURITY CHECK
    // Get the course_id and max_score in one query.
    // This verifies the user has access to the exam's course AND
    // validates the score against the subject's max_score.
    
    $stmt_check = $conn->prepare("SELECT e.course_id, exs.max_score
                                 FROM exams e
                                 JOIN exam_subjects exs ON e.id = exs.exam_id
                                 WHERE e.id = ? AND exs.id = ? AND e.school_id = ?");
    $stmt_check->bind_param("iii", $exam_id, $exam_subject_id, $school_id);
    $stmt_check->execute();
    $check_result = $stmt_check->get_result();
    
    if ($check_result->num_rows === 0) {
        throw new Exception("Exam or Subject not found, or mismatch.", 404);
    }
    
    $check_data = $check_result->fetch_assoc();
    $course_id = $check_data['course_id'];
    $max_score = $check_data['max_score'];
    $stmt_check->close();

    // 2. VERIFY USER ACCESS
    // This re-uses your function from the original code.
    verify_course_access($conn, $course_id, $is_instructor, $session_user_id, $school_id);
    
    // 3. VALIDATE SCORE
    if ($score < 0 || $score > $max_score) {
        throw new Exception("Score $score is out of range (0-$max_score).", 400);
    }

    // 4. CALCULATE GRADE
    $grade_details = calculate_grade_details($score, $max_score);

    // 5. PERFORM "UPSERT" (Insert or Update)
    // This is the core of the collaborative system.
    // It will UPDATE the row if (exam_subject_id, student_id) exists,
    // or INSERT a new one if it doesn't.
    // It will NEVER delete other subjects.
    
    $stmt_upsert = $conn->prepare("
        INSERT INTO exam_scores 
            (exam_subject_id, student_id, score, grade, remark, last_updated_by_user_id)
        VALUES 
            (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            score = VALUES(score),
            grade = VALUES(grade),
            remark = VALUES(remark),
            last_updated_by_user_id = VALUES(last_updated_by_user_id),
            updated_at = NOW()
    ");
    
    $stmt_upsert->bind_param("iiissi",
        $exam_subject_id,
        $student_id,
        $score,
        $grade_details['grade'],
        $grade_details['remark'],
        $session_user_id  // Use the session user ID
    );
    
    $stmt_upsert->execute();
    $stmt_upsert->close();

    // 6. COMMIT
    $conn->commit();
    
    // 7. SEND SUCCESS RESPONSE
    // Send the calculated grade back to the UI
    echo json_encode([
        'success' => true,
        'message' => 'Score saved!',
        'grade' => $grade_details['grade'],
        'remark' => $grade_details['remark']
    ]);

} catch (Exception $e) {
    // Something went wrong, roll back
    $conn->rollback();
    
    // Set appropriate error code
    $code = $e->getCode();
    if ($code < 400 || $code >= 600) {
        $code = 500; // Internal Server Error
    }
    http_response_code($code);

    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
