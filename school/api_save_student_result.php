<?php
require_once 'auth_check.php';

// This API only accepts POST requests with JSON data
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Get the JSON data sent from the JavaScript
$data = json_decode(file_get_contents('php://input'));

if (!$is_admin && !$is_instructor) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'You are not authorized.']);
    exit();
}

// --- THIS IS THE NEW 20-POINT GRADING FUNCTION ---
function calculate_grade_details($score) {
    $score = intval($score);
    if ($score >= 19) { return ['grade' => 'A1', 'remark' => 'Excellent']; }
    if ($score >= 17) { return ['grade' => 'B2', 'remark' => 'Very Good']; }
    if ($score >= 16) { return ['grade' => 'B3', 'remark' => 'Good']; }
    if ($score >= 14) { return ['grade' => 'C4', 'remark' => 'Credit']; }
    if ($score >= 13) { return ['grade' => 'C5', 'remark' => 'Credit']; }
    if ($score >= 11) { return ['grade' => 'C6', 'remark' => 'Credit']; }
    if ($score >= 10) { return ['grade' => 'D7', 'remark' => 'Pass']; }
    if ($score >= 9) { return ['grade' => 'E8', 'remark' => 'Pass']; }
    return ['grade' => 'F9', 'remark' => 'Fail'];
}
// --- END OF NEW FUNCTION ---

// --- Extract and Validate Data ---
$course_id = $data->course_id ?? 0;
$student_id = $data->student_id ?? 0;
$result_title = $data->result_title ?? '';
$scores = $data->scores ?? [];

if (empty($course_id) || empty($student_id) || empty($result_title) || empty($scores)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Missing required data.']);
    exit();
}

// --- Security Check: Verify user has access to this course ---
try {
    verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
}

// --- Database Transaction ---
// This logic will find an existing result sheet and UPDATE it,
// or create a new one if it doesn't exist. This prevents duplicates.

$conn->begin_transaction();
try {
    // 1. Check for an existing result_set
    $stmt_find = $conn->prepare("SELECT id FROM result_sets WHERE school_id = ? AND course_id = ? AND student_id = ? AND result_title = ?");
    $stmt_find->bind_param("iiis", $school_id, $course_id, $student_id, $result_title);
    $stmt_find->execute();
    $result = $stmt_find->get_result();
    $existing_set = $result->fetch_assoc();
    $stmt_find->close();

    $result_set_id = null;

    if ($existing_set) {
        // --- UPDATE ---
        $result_set_id = $existing_set['id'];
        
        // A. Delete old line items
        $stmt_del_items = $conn->prepare("DELETE FROM result_line_items WHERE result_set_id = ?");
        $stmt_del_items->bind_param("i", $result_set_id);
        $stmt_del_items->execute();
        $stmt_del_items->close();
        
        // B. Update the parent set's 'updated_at' timestamp and reset status
        $stmt_touch = $conn->prepare("UPDATE result_sets SET status = 'draft', created_at = NOW() WHERE id = ?"); // Resets status to draft
        $stmt_touch->bind_param("i", $result_set_id);
        $stmt_touch->execute();
        $stmt_touch->close();
        
    } else {
        // --- CREATE ---
        $stmt_set = $conn->prepare("INSERT INTO result_sets (school_id, course_id, student_id, result_title) VALUES (?, ?, ?, ?)");
        $stmt_set->bind_param("iiis", $school_id, $course_id, $student_id, $result_title);
        $stmt_set->execute();
        $result_set_id = $conn->insert_id;
        $stmt_set->close();
    }

    // 2. Insert all new result_line_items
    $stmt_item = $conn->prepare("INSERT INTO result_line_items (result_set_id, subject_name, score, grade, remarks) VALUES (?, ?, ?, ?, ?)");
    foreach ($scores as $score_entry) {
        $grade_details = calculate_grade_details($score_entry->score);
        $stmt_item->bind_param("isiss",
            $result_set_id,
            $score_entry->subject,
            $score_entry->score,
            $grade_details['grade'],
            $grade_details['remark']
        );
        $stmt_item->execute();
    }
    $stmt_item->close();

    // 3. Commit
    $conn->commit();
    
    // 4. Send success response
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Result saved successfully.']);

} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>
