<?php
// PART 1: HANDLE ALL LOGIC & REDIRECTS FIRST
// This file must be included first to start the session and connect to DB.
// It should NOT output any HTML.
require_once '../db_connect.php'; 

// Perform auth checks from your auth_check.php or similar file
if (!isset($_SESSION['school_admin_id']) && !isset($_SESSION['instructor_id'])) {
    header('Location: index.php');
    exit();
}
$is_admin = isset($_SESSION['school_admin_id']);
$user_id = $_SESSION['school_admin_id'] ?? $_SESSION['instructor_id'];
$school_id = $_SESSION['school_id'];


// Handle the course selection from the form submission
if (isset($_POST['select_course'])) {
    $_SESSION['selected_course_id'] = intval($_POST['course_id']);
    header("Location: dashboard.php"); 
    exit(); // IMPORTANT: Stop the script here before any HTML is sent
}

// PART 2: NOW THAT REDIRECTS ARE DONE, LOAD THE VISUALS AND DATA
require_once 'layout_header.php'; // This can now safely output HTML

// Fetch all courses for this specific school to populate the dropdown
$courses = [];
$course_stmt = $conn->prepare("SELECT id, title FROM courses WHERE school_id = ? ORDER BY title ASC");
$course_stmt->bind_param("i", $school_id);
$course_stmt->execute();
$course_result = $course_stmt->get_result();
if ($course_result) { while($row = $course_result->fetch_assoc()) { $courses[] = $row; } }
$course_stmt->close();

// Determine the currently selected course ID
$selected_course_id = $_SESSION['selected_course_id'] ?? ($courses[0]['id'] ?? 0);
$_SESSION['selected_course_id'] = $selected_course_id; // Ensure it's always set in the session

// Initialize stats
$total_students = 0;
$pending_grades = 0;
$total_resources = 0;

// SECURELY fetch stats for the selected course
if ($selected_course_id > 0) {
    $stmt = $conn->prepare("SELECT COUNT(id) as count FROM enrollments WHERE course_id = ?");
    $stmt->bind_param("i", $selected_course_id);
    $stmt->execute();
    $total_students = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(qs.id) as count FROM quiz_submissions qs JOIN quizzes q ON qs.quiz_id = q.id WHERE q.course_id = ? AND qs.score IS NULL");
    $stmt->bind_param("i", $selected_course_id);
    $stmt->execute();
    $pending_grades = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(id) as count FROM class_materials WHERE course_id = ?");
    $stmt->bind_param("i", $selected_course_id);
    $stmt->execute();
    $total_resources = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}

$total_courses = count($courses);
$conn->close();
?>
<style>
    .page-header { 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        margin-bottom: 25px; 
    }
    .page-header h1 { 
        margin: 0; 
        font-size: 28px;
    }
    .course-selector { 
        background-color: var(--card-bg-color); 
        border-radius: 8px; 
        padding: 20px; 
        margin-bottom: 25px; 
        display: flex; 
        align-items: center; 
        gap: 15px; 
        border: 1px solid var(--border-color); 
        flex-wrap: wrap; /* Allows wrapping */
    }
    .course-selector label { 
        font-weight:600; 
        margin-right: 10px;
    }
    .course-selector select { 
        font-size: 16px; 
        padding: 8px 12px; 
        border-radius: 5px; 
        border: 1px solid var(--border-color); 
        background-color: var(--bg-color); 
        color: var(--text-color);
        max-width: 350px;
    }
    .stats-grid { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px; 
    }
    .stat-card { 
        background-color: var(--card-bg-color); 
        border-radius: 8px; 
        padding: 25px; 
        border: 1px solid var(--border-color); 
    }
    .stat-card .stat-title { 
        font-size: 15px; 
        color: var(--text-secondary-color); 
    }
    .stat-card .stat-number { 
        font-size: 36px; 
        font-weight:600; 
        color: var(--brand-primary); 
    }
    @media (max-width: 768px) {
        .page-header h1 { font-size: 24px; }
        .course-selector { flex-direction: column; align-items: flex-start; gap: 10px; }
        .course-selector form { width: 100%; }
        .course-selector select { width: 100%; max-width: 100%; }
    }
</style>

<div class="page-header">
    <h1>Dashboard</h1>
</div>

<div class="course-selector">
    <label for="course_id">Currently Managing Course:</label>
    <form method="POST" id="course_select_form" style="margin:0;">
        <select name="course_id" id="course_id" onchange="document.getElementById('course_select_form').submit();">
            <?php if(empty($courses)): ?>
                <option>Please create a course first</option>
            <?php else: foreach($courses as $course): ?>
                <option value="<?php echo $course['id']; ?>" <?php if($course['id'] == $selected_course_id) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($course['title']); ?>
                </option>
            <?php endforeach; endif; ?>
        </select>
        <input type="hidden" name="select_course">
    </form>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-title">Students in Course</div>
        <div class="stat-number"><?php echo $total_students; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Total Courses</div>
        <div class="stat-number"><?php echo $total_courses; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Submissions to Grade</div>
        <div class="stat-number"><?php echo $pending_grades; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Course Resources</div>
        <div class="stat-number"><?php echo $total_resources; ?></div>
    </div>
</div>

<?php 
require_once 'layout_footer.php'; // This closes the HTML tags and adds the JS
?>
