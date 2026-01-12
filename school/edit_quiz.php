<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: dashboard.php'); exit(); }
$quiz_id = intval($_GET['id']);
$message = ''; $error = '';

$course_id = 0;
if ($is_admin) { $course_id = $_SESSION['selected_course_id'] ?? 0; }
elseif ($is_instructor) { $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0; }
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

// Handle Publish/Unpublish/Delete Actions
if (isset($_GET['action'])) {
    $redirect_url = "edit_quiz.php?id=$quiz_id" . ($is_instructor ? "&course_id=$course_id" : "");
    if ($_GET['action'] == 'publish') {
        $stmt = $conn->prepare("UPDATE quizzes SET status = 'published' WHERE id = ? AND school_id = ?");
        $stmt->bind_param("ii", $quiz_id, $school_id); $stmt->execute(); $stmt->close();
        header("Location: " . $redirect_url . "&status=published"); exit();
    }
    if ($_GET['action'] == 'unpublish') {
        $stmt = $conn->prepare("UPDATE quizzes SET status = 'draft' WHERE id = ? AND school_id = ?");
        $stmt->bind_param("ii", $quiz_id, $school_id); $stmt->execute(); $stmt->close();
        header("Location: " . $redirect_url . "&status=drafted"); exit();
    }
    if ($_GET['action'] == 'delete_question' && isset($_GET['qid'])) {
        $question_id_to_delete = intval($_GET['qid']);
        $stmt = $conn->prepare("DELETE q FROM quiz_questions q JOIN quizzes z ON q.quiz_id = z.id WHERE q.id = ? AND z.id = ? AND z.school_id = ?");
        $stmt->bind_param("iii", $question_id_to_delete, $quiz_id, $school_id);
        if($stmt->execute() && $stmt->affected_rows > 0){
             header("Location: " . $redirect_url . "&status=q_deleted"); exit();
        } else { $error = "Failed to delete question."; }
        $stmt->close();
    }
}

// Handle POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        if (isset($_POST['update_settings'])) {
            $title = trim($_POST['title']); $description = trim($_POST['description']); $duration = intval($_POST['duration_minutes']);
            $available_from = !empty($_POST['available_from']) ? date("Y-m-d H:i:s", strtotime($_POST['available_from'])) : null;
            $available_to = !empty($_POST['available_to']) ? date("Y-m-d H:i:s", strtotime($_POST['available_to'])) : null;
            $stmt = $conn->prepare("UPDATE quizzes SET title = ?, description = ?, duration_minutes = ?, available_from = ?, available_to = ? WHERE id = ? AND school_id = ?");
            $stmt->bind_param("ssissii", $title, $description, $duration, $available_from, $available_to, $quiz_id, $school_id);
            if ($stmt->execute()) { $message = "Settings updated successfully!"; } $stmt->close();
        }
        elseif(isset($_POST['add_mcq_question'])) {
            $question_text = trim($_POST['question_text']); $options = $_POST['options']; $correct_option = $_POST['is_correct'];
            if(empty($question_text) || count($options) != 4 || !isset($correct_option)) { $error = "Please fill in the question, all four options, and select a correct answer."; }
            else {
                $conn->begin_transaction();
                try {
                    $stmt1 = $conn->prepare("INSERT INTO quiz_questions (quiz_id, question_text, question_type) VALUES (?, ?, 'mcq')");
                    $stmt1->bind_param("is", $quiz_id, $question_text); $stmt1->execute();
                    $question_id = $conn->insert_id; $stmt1->close();
                    $stmt2 = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                    foreach($options as $index => $option_text) { $is_correct = ($index == $correct_option) ? 1 : 0; $stmt2->bind_param("isi", $question_id, $option_text, $is_correct); $stmt2->execute(); }
                    $stmt2->close(); $conn->commit(); $message = "Multiple-choice question added successfully!";
                } catch (Exception $e) { $conn->rollback(); $error = "Failed to add question."; }
            }
        }
        elseif(isset($_POST['add_essay_question'])) {
            $question_text = trim($_POST['essay_question_text']);
            if(empty($question_text)) { $error = "Essay question cannot be empty."; }
            else {
                $stmt = $conn->prepare("INSERT INTO quiz_questions (quiz_id, question_text, question_type) VALUES (?, ?, 'essay')");
                $stmt->bind_param("is", $quiz_id, $question_text);
                if ($stmt->execute()) { $message = "Essay question added successfully!"; } else { $error = "Failed to add essay question."; }
                $stmt->close();
            }
        }
    }
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php';

if (isset($_GET['status'])) {
    if($_GET['status'] == 'created') $message = 'Assessment created! You can now add questions.';
    if($_GET['status'] == 'published') $message = 'Assessment has been published and is now visible to students.';
    if($_GET['status'] == 'drafted') $message = 'Assessment has been reverted to a draft and is hidden from students.';
    if($_GET['status'] == 'q_deleted') $message = 'Question deleted successfully.';
    if($_GET['status'] == 'q_updated') $message = 'Question updated successfully.';
}

$quiz_stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ? AND school_id = ? AND course_id = ?");
$quiz_stmt->bind_param("iii", $quiz_id, $school_id, $course_id);
$quiz_stmt->execute();
$quiz = $quiz_stmt->get_result()->fetch_assoc();
$quiz_stmt->close();
if (!$quiz) {
    $redirect_url = $is_admin ? 'dashboard.php' : 'instructor_dashboard.php';
    header("Location: $redirect_url?error=not_found");
    exit();
}
$questions = [];
$q_stmt = $conn->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY id ASC");
$q_stmt->bind_param("i", $quiz_id);
$q_stmt->execute();
$q_result = $q_stmt->get_result();
while ($row = $q_result->fetch_assoc()) { $questions[] = $row; }
$q_stmt->close();
$course_title_stmt = $conn->prepare("SELECT title FROM courses WHERE id = ?"); $course_title_stmt->bind_param("i", $course_id); $course_title_stmt->execute();
$course_title = $course_title_stmt->get_result()->fetch_assoc()['title']; $course_title_stmt->close();
$conn->close();
?>
<style>
    html, body { width: 100%; margin: 0; padding: 0; overflow-x: hidden; }
    *, *:before, *:after { box-sizing: border-box; }
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
    .page-header h1 { margin: 0; font-size: 28px; word-break: break-word; }
    .page-subtitle { font-size: 16px; color: var(--text-muted); margin-top: -5px; margin-bottom: 30px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 20px; border-radius: 8px; margin-bottom: 30px; }
    .card h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid var(--brand-primary); font-size: 20px; }
    .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; overflow-wrap: break-word; }
    .error { background-color: #f8d7da; color: #721c24; } /* Added basic error style */
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
    .form-group input, .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 5px; background-color: var(--bg-color); color: var(--text-color); font-family: 'Poppins', sans-serif; font-size: 16px; }
    .form-grid-2, .options-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .option-group { display: flex; align-items: center; gap: 10px; }
    .option-group input[type="text"] { flex-grow: 1; min-width: 0; }
    .option-group input[type="radio"] { width: 1.2em; height: 1.2em; flex-shrink: 0; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; width: 100%; text-align: center; }
    .btn-secondary { background: none; border: 1px solid var(--border-color); color: var(--text-color); }
    .question-list-item { border-bottom: 1px solid var(--border-color); padding: 15px 0; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; }
    .question-list-item div:first-child { flex-grow: 1; overflow-wrap: break-word; min-width: 100px; }
    .question-type-badge { font-size: 12px; font-weight: 500; padding: 4px 10px; border-radius: 12px; background-color: var(--bg-color); flex-shrink: 0; }
    .delete-link { color: #dc3545; text-decoration: none; font-weight: 500; font-size: 14px; flex-shrink: 0; }
    .edit-link { color: var(--brand-primary); text-decoration: none; font-weight: 500; font-size: 14px; flex-shrink: 0; }
    .edit-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; }
    .status-indicator { padding: 20px; border-radius: 8px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
    .status-indicator.draft { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
    .status-indicator.published { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    [dir="auto"] { text-align: start; }
    @media (max-width: 900px) { .edit-layout { grid-template-columns: 1fr; } }
    @media (max-width: 600px) {
        .form-grid-2, .options-grid { grid-template-columns: 1fr; }
        .page-header, .status-indicator { flex-direction: column; align-items: stretch; }
        .page-header h1, .status-indicator span { text-align: center; }
        .card { padding: 15px; }
    }
</style>

<div class="page-header">
    <h1 dir="auto"><?php echo htmlspecialchars($quiz['title']); ?></h1>
    <a href="manage_quizzes.php?course_id=<?php echo $course_id; ?>" class="btn btn-secondary">Finish & Return</a>
</div>
<p class="page-subtitle" dir="auto">Editing assessment for course: <strong><?php echo htmlspecialchars($course_title); ?></strong></p>

<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<div class="status-indicator <?php echo $quiz['status']; ?>">
    <?php if ($quiz['status'] == 'draft'): ?>
        <span><strong>This assessment is a DRAFT.</strong> It is hidden from students.</span>
        <a href="?id=<?php echo $quiz_id; ?>&course_id=<?php echo $course_id; ?>&action=publish" class="btn">Publish to Students</a>
    <?php else: ?>
        <span><strong>✓ PUBLISHED.</strong> Visible to students based on availability dates.</span>
        <a href="?id=<?php echo $quiz_id; ?>&course_id=<?php echo $course_id; ?>&action=unpublish" style="color:var(--text-muted); text-decoration: underline;">Revert to Draft</a>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Assessment Settings</h2>
    <form action="edit_quiz.php?id=<?php echo $quiz_id; ?>&course_id=<?php echo $course_id; ?>" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <div class="form-group"><label>Title</label><input type="text" name="title" value="<?php echo htmlspecialchars($quiz['title']); ?>" required dir="auto"></div>
        <div class="form-grid-2">
            <div class="form-group"><label>Available From</label><input type="datetime-local" name="available_from" value="<?php echo !empty($quiz['available_from']) ? date('Y-m-d\TH:i', strtotime($quiz['available_from'])) : ''; ?>"></div>
            <div class="form-group"><label>Available To</label><input type="datetime-local" name="available_to" value="<?php echo !empty($quiz['available_to']) ? date('Y-m-d\TH:i', strtotime($quiz['available_to'])) : ''; ?>"></div>
        </div>
        <div class="form-group"><label>Duration (minutes)</label><input type="number" name="duration_minutes" value="<?php echo $quiz['duration_minutes']; ?>" required></div>
        <div class="form-group"><label>Instructions</label><textarea name="description" rows="3" dir="auto"><?php echo htmlspecialchars($quiz['description']); ?></textarea></div>
        <button type="submit" name="update_settings" class="btn">Update Settings</button>
    </form>
</div>

<div class="edit-layout">
    <div class="questions-column">
        <div class="card">
            <h2>Added Questions (<?php echo count($questions); ?>)</h2>
            <div>
                <?php if(empty($questions)): ?><p style="text-align:center; color:var(--text-muted)">No questions added yet.</p>
                <?php else: foreach($questions as $index => $q): ?>
                    <div class="question-list-item">
                        <div dir="auto"><strong>Q<?php echo $index + 1; ?>:</strong> <?php echo htmlspecialchars($q['question_text']); ?></div>
                        <div style="display:flex; align-items:center; gap: 15px;">
                            <span class="question-type-badge"><?php echo strtoupper($q['question_type']); ?></span>
                            <a href="edit_question.php?id=<?php echo $q['id']; ?>&quiz_id=<?php echo $quiz_id; ?>&course_id=<?php echo $course_id; ?>" class="edit-link">Edit</a>
                            <a href="edit_quiz.php?id=<?php echo $quiz_id; ?>&course_id=<?php echo $course_id; ?>&action=delete_question&qid=<?php echo $q['id']; ?>" class="delete-link" onclick="return confirm('Are you sure?');">Delete</a>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <div class="add-questions-column">
        <div class="card">
            <h2>Add New Multiple-Choice Question</h2>
            <form action="edit_quiz.php?id=<?php echo $quiz_id; ?>&course_id=<?php echo $course_id; ?>" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group"><label>Question Text</label><textarea name="question_text" rows="3" required dir="auto"></textarea></div>
                <div class="options-grid">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="form-group"><label>Option <?php echo chr(65 + $i); ?></label><div class="option-group"><input type="radio" name="is_correct" value="<?php echo $i; ?>" required title="Select correct answer"><input type="text" name="options[]" required dir="auto"></div></div>
                    <?php endfor; ?>
                </div>
                <button type="submit" name="add_mcq_question" class="btn">Add MCQ Question</button>
            </form>
        </div>
        <?php if ($quiz['type'] === 'Exam'): ?>
        <div class="card">
            <h2>Add New Essay Question</h2>
            <form action="edit_quiz.php?id=<?php echo $quiz_id; ?>&course_id=<?php echo $course_id; ?>" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group"><label>Essay Question Text</label><textarea name="essay_question_text" rows="4" required dir="auto"></textarea></div>
                <button type="submit" name="add_essay_question" class="btn">Add Essay Question</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'layout_footer.php'; ?>
