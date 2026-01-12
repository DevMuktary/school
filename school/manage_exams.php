<?php
// PART 1: LOGIC
require_once 'auth_check.php';

if (!$is_admin && !$is_instructor) { header('Location: index.php'); exit(); }
$message = ''; $error = '';

// Handle POST request to CREATE a new exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_exam'])) {
    $course_id = intval($_POST['course_id']);
    $exam_title = trim($_POST['exam_title']);

    if (empty($exam_title)) {
        $error = "Exam title cannot be empty.";
    } else {
        try {
            // Security: Check if user has access to this course
            verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);
            
            // Insert the new exam
            $stmt = $conn->prepare("INSERT INTO exams (school_id, course_id, title, created_by_user_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iisi", $school_id, $course_id, $exam_title, $user_id);
            
            if ($stmt->execute()) {
                $new_exam_id = $conn->insert_id;
                // Success! Redirect to the gradebook for this new exam
                header('Location: gradebook.php?exam_id=' . $new_exam_id);
                exit();
            } else {
                $error = "Failed to create exam. It might already exist with this name.";
            }
            $stmt->close();

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}


// PART 2: LOAD DATA FOR DISPLAY
require_once 'layout_header.php'; 

// 1. Fetch courses for the dropdowns
$courses = [];
if ($is_admin) {
    $stmt = $conn->prepare("SELECT id, title FROM courses WHERE school_id = ? ORDER BY title ASC");
    $stmt->bind_param("i", $school_id);
} else { // Instructor
    $stmt = $conn->prepare("SELECT c.id, c.title FROM courses c JOIN course_assignments ca ON c.id = ca.course_id WHERE ca.instructor_id = ? AND c.school_id = ? ORDER BY c.title ASC");
    $stmt->bind_param("ii", $user_id, $school_id);
}
$stmt->execute();
$course_result = $stmt->get_result();
$course_ids = [];
if ($course_result) { 
    while($row = $course_result->fetch_assoc()) { 
        $courses[] = $row;
        $course_ids[] = $row['id']; // Collect course IDs for next query
    } 
}
$stmt->close();

// 2. Fetch all existing exams for these courses
$exams_by_course = [];
if (!empty($course_ids)) {
    // Creates a string of placeholders: ?,?,?
    $placeholders = implode(',', array_fill(0, count($course_ids), '?'));
    // Creates a string of types: "iii"
    $types = str_repeat('i', count($course_ids));
    
    $stmt_exams = $conn->prepare("SELECT id, course_id, title, created_at FROM exams WHERE course_id IN ($placeholders) ORDER BY created_at DESC");
    $stmt_exams->bind_param($types, ...$course_ids);
    $stmt_exams->execute();
    $exams_result = $stmt_exams->get_result();
    
    // Organize exams into an array keyed by course_id
    while ($exam = $exams_result->fetch_assoc()) {
        $exams_by_course[$exam['course_id']][] = $exam;
    }
    $stmt_exams->close();
}

$conn->close();

if (empty($courses) && empty($error)) {
    $error = $is_admin ? "No courses found. Please create a course first." : "You have not been assigned to any courses.";
}
?>

<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; margin-bottom: 25px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    
    /* --- ANTI-ZOOM FIX --- */
    /* This ensures 16px font size to stop mobile zoom */
    .form-group input[type="text"] {
        width: 100%;
        padding: 12px;
        box-sizing: border-box;
        border-radius: 5px;
        border: 1px solid var(--border-color);
        background-color: var(--bg-color);
        color: var(--text-color);
        font-size: 16px !important; /* NO ZOOM */
    }
    /* --- END ANTI-ZOOM FIX --- */

    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 600; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block; }
    .btn-sm { padding: 8px 12px; font-size: 14px; }
    .btn-secondary { background-color: #6c757d; }

    .message, .error { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .error { color: #721c24; background-color: #f8d7da; } .message { color: #155724; background-color: #d4edda; }

    .exam-list { list-style: none; padding: 0; margin: 0; }
    .exam-list li { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color); }
    .exam-list li:last-child { border-bottom: none; }
    .exam-list .exam-title { font-weight: 600; font-size: 18px; }
    .exam-list .exam-date { font-size: 14px; color: var(--text-muted); }
    
    .create-exam-form {
        background-color: var(--bg-color);
        padding: 20px;
        border-radius: 8px;
        margin-top: 20px;
        border: 1px solid var(--border-color);
    }
    .create-exam-form h3 { margin-top: 0; }
</style>

<div class="page-header">
    <h1>Manage Exams (Gradebook)</h1>
</div>

<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<?php if (empty($courses)): ?>
    <?php else: ?>
    <?php foreach ($courses as $course): 
        $course_id = $course['id'];
        $course_title = htmlspecialchars($course['title']);
        $existing_exams = $exams_by_course[$course_id] ?? [];
    ?>
    
    <div class="card" id="course-<?php echo $course_id; ?>">
        <h2><?php echo $course_title; ?></h2>
        
        <div class="exam-list-container">
            <?php if (empty($existing_exams)): ?>
                <p>No exams have been created for this course yet.</p>
            <?php else: ?>
                <ul class="exam-list">
                    <?php foreach ($existing_exams as $exam): ?>
                        <li>
                            <div>
                                <span class="exam-title"><?php echo htmlspecialchars($exam['title']); ?></span>
                                <span class="exam-date">Created: <?php echo date('d M, Y', strtotime($exam['created_at'])); ?></span>
                            </div>
                            <a href="gradebook.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-sm">
                                Open Gradebook
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <hr style="border:0; border-top: 1px solid var(--border-color); margin: 30px 0;">

        <div class="create-exam-form">
            <h3>Create New Exam</h3>
            <form action="manage_exams.php" method="POST">
                <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                <div class="form-group">
                    <label for="exam_title_<?php echo $course_id; ?>">New Exam Title</label>
                    <input type="text" name="exam_title" id="exam_title_<?php echo $course_id; ?>" placeholder="e.g., First Term 2025" required>
                </div>
                <button type="submit" name="create_exam" class="btn">Create & Open Gradebook</button>
            </form>
        </div>

    </div> <?php endforeach; ?>
<?php endif; ?>

<?php require_once 'layout_footer.php'; ?>
