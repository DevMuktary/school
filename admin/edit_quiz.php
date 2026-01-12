<?php
require_once '../db_connect.php';

if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit(); }
if (!isset($_SESSION['selected_course_id']) || $_SESSION['selected_course_id'] == 0) { header('Location: dashboard.php'); exit(); }
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: manage_quizzes.php'); exit(); }

$quiz_id = intval($_GET['id']);
$course_id = $_SESSION['selected_course_id'];
$message = '';
$error = '';

if (isset($_GET['status']) && $_GET['status'] == 'created') { $message = 'Assessment created successfully! Now add questions below.'; }

// --- Handle Deleting a question ---
if (isset($_GET['delete_question'])) {
    $question_id_to_delete = intval($_GET['delete_question']);
    // The database is set to cascade deletes, so deleting a question will also delete its options.
    $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE id = ? AND quiz_id = ?");
    $stmt->bind_param("ii", $question_id_to_delete, $quiz_id);
    if($stmt->execute()){
        $message = "Question deleted successfully.";
    } else {
        $error = "Failed to delete question.";
    }
    $stmt->close();
}

// --- Handle adding a new question (both MCQ and Essay) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(isset($_POST['add_mcq_question'])) {
        $question_text = trim($_POST['question_text']);
        $options = $_POST['options'];
        $correct_option = $_POST['is_correct'];

        if(empty($question_text) || count($options) != 4 || !isset($correct_option)) {
            $error = "Please fill in the question, all four options, and select a correct answer.";
        } else {
            $conn->begin_transaction();
            try {
                $stmt1 = $conn->prepare("INSERT INTO quiz_questions (quiz_id, question_text, question_type) VALUES (?, ?, 'mcq')");
                $stmt1->bind_param("is", $quiz_id, $question_text);
                $stmt1->execute();
                $question_id = $conn->insert_id;
                $stmt1->close();

                $stmt2 = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                foreach($options as $index => $option_text) {
                    $is_correct = ($index == $correct_option) ? 1 : 0;
                    $stmt2->bind_param("isi", $question_id, $option_text, $is_correct);
                    $stmt2->execute();
                }
                $stmt2->close();
                $conn->commit();
                $message = "Multiple-choice question added successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to add question. Error: " . $e->getMessage();
            }
        }
    }
    elseif(isset($_POST['add_essay_question'])) {
        $question_text = trim($_POST['essay_question_text']);
        if(empty($question_text)) {
            $error = "Essay question cannot be empty.";
        } else {
            $stmt = $conn->prepare("INSERT INTO quiz_questions (quiz_id, question_text, question_type) VALUES (?, ?, 'essay')");
            $stmt->bind_param("is", $quiz_id, $question_text);
            if ($stmt->execute()) {
                $message = "Essay question added successfully!";
            } else {
                $error = "Failed to add essay question.";
            }
            $stmt->close();
        }
    }
}

// Fetch quiz details, ensuring it belongs to the selected course
$quiz_stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ? AND course_id = ?");
$quiz_stmt->bind_param("ii", $quiz_id, $course_id);
$quiz_stmt->execute();
$quiz = $quiz_stmt->get_result()->fetch_assoc();
$quiz_stmt->close();
if (!$quiz) { header('Location: manage_quizzes.php?error=not_found'); exit(); }

// Fetch existing questions for this quiz
$questions = [];
$q_stmt = $conn->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY id ASC");
$q_stmt->bind_param("i", $quiz_id);
$q_stmt->execute();
$q_result = $q_stmt->get_result();
while ($row = $q_result->fetch_assoc()) { $questions[] = $row; }
$q_stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit <?php echo htmlspecialchars($quiz['type']); ?> - Admin</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { --bg-color: #f7f9fc; --card-bg-color: #FFFFFF; --text-color: #001232; --text-secondary-color: #555; --border-color: #e9ecef; --brand-blue: <?php echo BRAND_COLOR_BLUE; ?>; --brand-yellow: <?php echo BRAND_COLOR_YELLOW; ?>; }
        body.dark-mode { --bg-color: #121212; --card-bg-color: #1e1e1e; --text-color: #e0e0e0; --text-secondary-color: #a0a0a0; --border-color: #333; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; }
        .header { background-color: var(--brand-blue); color: #FFFFFF; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .container { padding: 30px; max-width: 900px; margin: auto; }
        .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 30px; border-radius: 8px; margin-bottom: 30px; }
        .card h1, .card h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid var(--brand-yellow); }
        .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .message { color: #155724; background-color: #d4edda; } .error { color: #721c24; background-color: #f8d7da; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input[type="text"], .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 5px; box-sizing: border-box; font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); }
        .options-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .option-group { display: flex; align-items: center; gap: 10px; }
        .option-group input[type="radio"] { transform: scale(1.3); }
        .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-blue); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
        .question-list-item { border-bottom: 1px solid var(--border-color); padding: 15px 0; display:flex; justify-content: space-between; align-items: center; gap: 15px; }
        .question-list-item:last-child { border-bottom: none; }
        .question-type-badge { font-size: 12px; font-weight: 500; padding: 4px 10px; border-radius: 12px; background-color: var(--bg-color); flex-shrink: 0;}
        .delete-link { color: #dc3545; text-decoration: none; font-weight: 500; font-size: 14px; flex-shrink: 0; }
        @media (max-width: 600px) { .options-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo"><?php echo SCHOOL_NAME; ?><span>.</span></div>
        <a href="manage_quizzes.php" style="color:white; text-decoration:none;">← Back to Assessments</a>
    </header>

    <div class="container">
        <?php if($message): ?><p class="message"><?php echo $message; ?></p><?php endif; ?>
        <?php if($error): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>

        <div class="card">
            <h1><?php echo htmlspecialchars($quiz['title']); ?> <span class="question-type-badge" style="font-size: 16px;"><?php echo htmlspecialchars($quiz['type']); ?></span></h1>
            <p style="color:var(--text-secondary-color); margin-bottom: 0;"><?php echo nl2br(htmlspecialchars($quiz['description'])); ?></p>
        </div>
        
        <div class="card">
            <h2>Added Questions (<?php echo count($questions); ?>)</h2>
            <div class="question-list">
                <?php if(empty($questions)): ?>
                    <p style="text-align:center; color: var(--text-secondary-color);">No questions have been added yet.</p>
                <?php else: foreach($questions as $index => $q): ?>
                    <div class="question-list-item">
                        <div><strong>Q<?php echo $index + 1; ?>:</strong> <?php echo htmlspecialchars($q['question_text']); ?></div>
                        <div style="display:flex; align-items:center; gap: 15px;">
                            <span class="question-type-badge"><?php echo strtoupper($q['question_type']); ?></span>
                            <a href="edit_quiz.php?id=<?php echo $quiz_id; ?>&delete_question=<?php echo $q['id']; ?>" class="delete-link" onclick="return confirm('Are you sure you want to delete this question?');">Delete</a>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="card">
            <h2>Add New Multiple-Choice Question</h2>
            <form method="POST">
                <div class="form-group"><label for="question_text">Question Text</label><textarea name="question_text" id="question_text" rows="3" required></textarea></div>
                <div class="options-grid">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="form-group">
                        <label>Option <?php echo chr(65 + $i); ?></label>
                        <div class="option-group">
                            <input type="radio" name="is_correct" value="<?php echo $i; ?>" required title="Select this as the correct answer">
                            <input type="text" name="options[]" required>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                <button type="submit" name="add_mcq_question" class="btn">Add MCQ Question</button>
            </form>
        </div>

        <?php if ($quiz['type'] === 'Exam'): ?>
        <div class="card">
            <h2>Add New Essay Question</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="essay_question_text">Essay Question Text</label>
                    <textarea name="essay_question_text" id="essay_question_text" rows="4" required></textarea>
                </div>
                <button type="submit" name="add_essay_question" class="btn">Add Essay Question</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
