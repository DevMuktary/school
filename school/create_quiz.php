<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

// --- CSRF TOKEN GENERATION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$course_id = 0;
if ($is_admin) { $course_id = $_SESSION['selected_course_id'] ?? 0; } 
elseif ($is_instructor) { $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0; }
if ($course_id == 0) {
    $redirect_url = $is_admin ? 'dashboard.php' : 'instructor_dashboard.php';
    header("Location: $redirect_url?error=no_course"); 
    exit();
}
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- CSRF TOKEN VALIDATION ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $duration = intval($_POST['duration_minutes']);
        $type = $_POST['type'];
        $available_from = !empty($_POST['available_from']) ? date("Y-m-d H:i:s", strtotime($_POST['available_from'])) : null;
        $available_to = !empty($_POST['available_to']) ? date("Y-m-d H:i:s", strtotime($_POST['available_to'])) : null;

        if(empty($title) || $duration <= 0 || empty($type)) {
            $error = "Title, Type, and a valid duration are required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO quizzes (school_id, course_id, title, description, duration_minutes, available_from, available_to, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisissis", $school_id, $course_id, $title, $description, $duration, $available_from, $available_to, $type);
            if ($stmt->execute()) {
                $new_quiz_id = $conn->insert_id;
                $redirect_url = "edit_quiz.php?id=$new_quiz_id" . ($is_instructor ? "&course_id=$course_id" : "") . "&status=created";
                header("Location: " . $redirect_url);
                exit();
            } else { $error = "Failed to create assessment."; }
            $stmt->close();
        }
    }
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

$course_title_stmt = $conn->prepare("SELECT title FROM courses WHERE id = ?");
$course_title_stmt->bind_param("i", $course_id);
$course_title_stmt->execute();
$course_title = $course_title_stmt->get_result()->fetch_assoc()['title'];
$course_title_stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .page-subtitle { font-size: 16px; color: var(--text-muted); margin-top: -5px; margin-bottom: 30px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 30px; border-radius: 8px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
    .form-group input, .form-group textarea, .form-group select { 
        width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 5px; 
        box-sizing: border-box; font-family: 'Poppins', sans-serif; background-color: var(--bg-color); 
        color: var(--text-color); font-size: 16px;
    }
    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 768px) { .form-grid-2 { grid-template-columns: 1fr; } }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
    .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; color: #721c24; background-color: #f8d7da; }
</style>

<div class="page-header"><h1>Create New Assessment</h1></div>
<p class="page-subtitle">For course: <strong><?php echo htmlspecialchars($course_title); ?></strong></p>

<div class="card">
    <?php if ($error): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>
    <form action="create_quiz.php?course_id=<?php echo $course_id; ?>" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <div class="form-group"><label for="title">Title (e.g., "Week 1 Test")</label><input type="text" name="title" id="title" required></div>
        <div class="form-group"><label for="type">Type</label><select name="type" id="type" required><option value="Test">Test (Objective only)</option><option value="Exam">Exam (Objective + Essay)</option></select></div>
        <div class="form-grid-2">
            <div class="form-group"><label for="available_from">Available From (Optional)</label><input type="datetime-local" name="available_from" id="available_from"></div>
            <div class="form-group"><label for="available_to">Available To (Optional)</label><input type="datetime-local" name="available_to" id="available_to"></div>
        </div>
        <div class="form-group"><label for="duration_minutes">Duration (in minutes)</label><input type="number" name="duration_minutes" id="duration_minutes" required min="1" value="30"></div>
        <div class="form-group"><label for="description">Instructions (Optional)</label><textarea name="description" id="description" rows="4"></textarea></div>
        <button type="submit" class="btn">Save & Add Questions</button>
    </form>
</div>

<?php require_once 'layout_footer.php'; ?>
