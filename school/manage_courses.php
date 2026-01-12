<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

// This is an admin-only page.
if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

// --- CSRF TOKEN GENERATION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$message = ''; $error = '';

// Handle creating a new course for this school
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_course'])) {
    // --- CSRF TOKEN VALIDATION ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again from the original page.";
    } else {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $enrollment_key = trim($_POST['enrollment_key']);
        
        if (!empty($title) && !empty($enrollment_key)) {
            $stmt = $conn->prepare("INSERT INTO courses (school_id, title, description, enrollment_key) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $school_id, $title, $description, $enrollment_key);
            if ($stmt->execute()) { $message = "Course created successfully!"; } 
            else { $error = "Failed to create course. The enrollment key might already be in use."; }
            $stmt->close();
        } else {
            $error = "Course title and enrollment key are required.";
        }
    }
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

// Fetch all courses for THIS SCHOOL ONLY
$courses = [];
$stmt = $conn->prepare("SELECT c.*, (SELECT COUNT(id) FROM enrollments WHERE course_id = c.id) as student_count FROM courses c WHERE c.school_id = ? ORDER BY c.created_at DESC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) { while ($row = $result->fetch_assoc()) { $courses[] = $row; } }
$stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border-radius: 8px; padding: 25px; margin-bottom: 25px; border: 1px solid var(--border-color); }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
    .form-group input, .form-group textarea { width: 100%; padding: 12px; box-sizing: border-box; border-radius: 5px; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); font-family: 'Poppins', sans-serif; font-size: 16px; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; cursor: pointer; font-size: 16px; font-weight: 600; }
    .courses-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
    .course-card { background-color: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; display: flex; flex-direction: column; }
    .course-card h3 { margin-top: 0; }
    .course-card p { color: var(--text-muted); font-size: 14px; flex-grow: 1; margin-bottom: 20px; }
    .course-stats { display: flex; justify-content: space-between; padding-top: 15px; border-top: 1px solid var(--border-color); font-size: 14px; }
    .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
</style>

<div class="page-header">
    <h1>Manage Courses</h1>
</div>

<?php if($message): ?><div class="message" style="color: #155724; background-color: #d4edda;"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error" style="color: #721c24; background-color: #f8d7da;"><?php echo $error; ?></div><?php endif; ?>

<div class="card">
    <h2>Create New Course</h2>
    <form action="manage_courses.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        
        <div class="form-group">
            <label for="title">Course Title</label>
            <input type="text" name="title" id="title" required>
        </div>
        <div class="form-group">
            <label for="enrollment_key">Enrollment Key (e.g., ARABIC102)</label>
            <input type="text" name="enrollment_key" id="enrollment_key" required>
        </div>
        <div class="form-group">
            <label for="description">Description (Optional)</label>
            <textarea name="description" id="description" rows="3"></textarea>
        </div>
        <button type="submit" name="add_course" class="btn">Add Course</button>
    </form>
</div>

<div class="card">
    <h2>Your Courses</h2>
    <div class="courses-grid">
        <?php if(empty($courses)): ?>
            <p>You have not created any courses yet. Use the form above to add one.</p>
        <?php else: foreach($courses as $course): ?>
            <div class="course-card">
                <h3><?php echo htmlspecialchars($course['title']); ?></h3>
                <p><?php echo !empty($course['description']) ? htmlspecialchars($course['description']) : 'No description provided.'; ?></p>
                <div class="course-stats">
                    <span><strong>Enrollment Key:</strong> <?php echo htmlspecialchars($course['enrollment_key']); ?></span>
                    <span><strong>Students:</strong> <?php echo $course['student_count']; ?></span>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
