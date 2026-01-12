<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

if (!$is_admin && !$is_instructor) { header('Location: index.php'); exit(); }

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
    header("Location: $redirect_url?error=no_course_selected"); 
    exit();
}
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

$upload_dir = '../uploads/assignments/';
$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assignment'])) {
    // --- CSRF TOKEN VALIDATION ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        $title = trim($_POST['title']);
        $instructions = trim($_POST['instructions']);
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $attachment_path = null;

        if (empty($title)) {
            $error = "Assignment title is required.";
        } else {
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
                $file_name = time() . '_' . basename($_FILES['attachment']['name']);
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $file_name)) {
                    $attachment_path = $file_name;
                } else { $error = "Could not upload attachment."; }
            }

            if (empty($error)) {
                $stmt = $conn->prepare("INSERT INTO assignments (school_id, course_id, title, instructions, attachment_path, due_date) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iissss", $school_id, $course_id, $title, $instructions, $attachment_path, $due_date);
                if ($stmt->execute()) { $message = "Assignment created successfully!"; }
                else { $error = "Failed to create assignment."; }
                $stmt->close();
            }
        }
    }
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

// Fetch all assignments for this course, WITH SUBMISSION COUNT
$assignments = [];
$stmt = $conn->prepare("
    SELECT a.*, (SELECT COUNT(id) FROM assignment_submissions WHERE assignment_id = a.id) as submission_count 
    FROM assignments a 
    WHERE a.course_id = ? ORDER BY a.created_at DESC");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();
if($result) { while($row = $result->fetch_assoc()) { $assignments[] = $row; } }
$stmt->close();

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
    .grid-layout { display: grid; grid-template-columns: 350px 1fr; gap: 25px; align-items: start; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    .form-group input, .form-group textarea { width: 100%; padding: 12px; box-sizing: border-box; border-radius: 5px; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); font-size: 16px; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 600; cursor: pointer; width: 100%; font-size: 16px; }
    .assignment-list .assignment-item { background-color: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; margin-bottom: 15px; }
    .assignment-item h3 { margin: 0 0 10px; }
    .assignment-meta { font-size: 13px; color: var(--text-muted); }
    .assignment-footer { margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-color); }
    .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
    @media (max-width: 992px) { .grid-layout { grid-template-columns: 1fr; } }
</style>

<div class="page-header"><h1>Manage Assignments</h1></div>
<p class="page-subtitle">For course: <strong><?php echo htmlspecialchars($course_title); ?></strong></p>

<?php if($message): ?><div class="message" style="color: #155724; background-color: #d4edda;"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error" style="color: #721c24; background-color: #f8d7da;"><?php echo $error; ?></div><?php endif; ?>

<div class="grid-layout">
    <div class="card">
        <h2>Create New Assignment</h2>
        <form action="manage_homework.php?course_id=<?php echo $course_id; ?>" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group"><label for="title">Assignment Title</label><input type="text" name="title" id="title" required></div>
            <div class="form-group"><label for="instructions">Instructions</label><textarea name="instructions" id="instructions" rows="5"></textarea></div>
            <div class="form-group"><label for="due_date">Due Date (Optional)</label><input type="datetime-local" name="due_date" id="due_date"></div>
            <div class="form-group"><label for="attachment">Attach File (Optional)</label><input type="file" name="attachment" id="attachment"></div>
            <button type="submit" name="save_assignment" class="btn">Create Assignment</button>
        </form>
    </div>
    <div class="card">
        <h2>Created Assignments</h2>
        <div class="assignment-list">
            <?php if(empty($assignments)): ?>
                <p>No assignments created for this course yet.</p>
            <?php else: foreach($assignments as $assignment): ?>
                <div class="assignment-item">
                    <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                    <div class="assignment-meta">
                        <span><strong>Due:</strong> <?php echo !empty($assignment['due_date']) ? date("M j, Y, g:i a", strtotime($assignment['due_date'])) : 'No due date'; ?></span>
                    </div>
                    <div class="assignment-footer">
                        <a href="grade_homework.php?id=<?php echo $assignment['id']; ?>" style="font-weight:600; text-decoration:none; color:var(--brand-primary);">
                            Submissions: <?php echo $assignment['submission_count']; ?>
                        </a>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
