<?php
require_once 'auth_check.php';

// --- VALIDATE INPUTS ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: dashboard.php'); exit(); }
$question_id = intval($_GET['id']);
$quiz_id = isset($_GET['quiz_id']) ? intval($_GET['quiz_id']) : 0;
$course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
if ($quiz_id == 0 || $course_id == 0) { header('Location: dashboard.php'); exit(); }
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

// --- FETCH QUESTION DETAILS ---
$question_stmt = $conn->prepare("SELECT q.* FROM quiz_questions q JOIN quizzes z ON q.quiz_id = z.id WHERE q.id = ? AND z.id = ? AND z.school_id = ?");
$question_stmt->bind_param("iii", $question_id, $quiz_id, $school_id);
$question_stmt->execute();
$question = $question_stmt->get_result()->fetch_assoc();
$question_stmt->close();
if (!$question) { header("Location: edit_quiz.php?id=$quiz_id&course_id=$course_id&error=q_not_found"); exit(); }

$options = [];
if ($question['question_type'] == 'mcq') {
    $options_stmt = $conn->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY id ASC");
    $options_stmt->bind_param("i", $question_id);
    $options_stmt->execute();
    $options_result = $options_stmt->get_result();
    while ($row = $options_result->fetch_assoc()) { $options[] = $row; }
    $options_stmt->close();
}

// --- HANDLE FORM SUBMISSION ---
$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_question'])) {
    $question_text = trim($_POST['question_text']);
    
    $conn->begin_transaction();
    try {
        // Update the main question text
        $update_q_stmt = $conn->prepare("UPDATE quiz_questions SET question_text = ? WHERE id = ?");
        $update_q_stmt->bind_param("si", $question_text, $question_id);
        $update_q_stmt->execute();
        $update_q_stmt->close();

        // If it's an MCQ, update the options as well
        if ($question['question_type'] == 'mcq') {
            $correct_option_id = intval($_POST['is_correct']);
            $update_opt_stmt = $conn->prepare("UPDATE question_options SET option_text = ?, is_correct = ? WHERE id = ?");
            foreach ($_POST['options'] as $option_id => $option_text) {
                $is_correct = ($option_id == $correct_option_id) ? 1 : 0;
                $opt_id = intval($option_id);
                $update_opt_stmt->bind_param("sii", $option_text, $is_correct, $opt_id);
                $update_opt_stmt->execute();
            }
            $update_opt_stmt->close();
        }
        
        $conn->commit();
        // Redirect back with a success message
        header("Location: edit_quiz.php?id=$quiz_id&course_id=$course_id&status=q_updated");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $error = "Failed to update the question. Please try again.";
    }
}

require_once 'layout_header.php';
?>
<style>
    /* Styles are similar to edit_quiz.php for consistency */
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 30px; border-radius: 8px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
    .form-group input, .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 5px; background-color: var(--bg-color); color: var(--text-color); font-family: 'Poppins', sans-serif; font-size: 16px; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; text-decoration: none; }
    .btn-secondary { background: none; border: 1px solid var(--border-color); color: var(--text-color); }
    .options-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .option-group { display: flex; align-items: center; gap: 10px; }
    [dir="auto"] { text-align: start; }
    @media (max-width: 600px) { .options-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-header">
    <h1 dir="auto">Edit Question</h1>
    <a href="edit_quiz.php?id=<?php echo $quiz_id; ?>&course_id=<?php echo $course_id; ?>" class="btn btn-secondary">‹ Back to Assessment</a>
</div>

<div class="card">
    <form method="POST" action="edit_question.php?id=<?php echo $question_id; ?>&quiz_id=<?php echo $quiz_id; ?>&course_id=<?php echo $course_id; ?>">
        <div class="form-group">
            <label for="question_text">Question Text</label>
            <textarea name="question_text" id="question_text" rows="4" required dir="auto"><?php echo htmlspecialchars($question['question_text']); ?></textarea>
        </div>

        <?php if ($question['question_type'] == 'mcq'): ?>
            <div class="options-grid">
                <?php foreach ($options as $index => $option): ?>
                    <div class="form-group">
                        <label>Option <?php echo chr(65 + $index); ?></label>
                        <div class="option-group">
                            <input type="radio" name="is_correct" value="<?php echo $option['id']; ?>" <?php if($option['is_correct']) echo 'checked'; ?> required title="Select correct answer">
                            <input type="text" name="options[<?php echo $option['id']; ?>]" value="<?php echo htmlspecialchars($option['option_text']); ?>" required dir="auto">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <button type="submit" name="update_question" class="btn">Save Changes</button>
    </form>
</div>

<?php require_once 'layout_footer.php'; ?>
