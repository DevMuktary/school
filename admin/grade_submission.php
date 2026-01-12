<?php
require_once '../db_connect.php';

if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit(); }
if (!isset($_SESSION['selected_course_id']) || $_SESSION['selected_course_id'] == 0) { header('Location: dashboard.php'); exit(); }
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: manage_quizzes.php'); exit(); }

$submission_id = intval($_GET['id']);
$course_id = $_SESSION['selected_course_id'];
$message = '';

// Handle updating the score
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grade'])) {
    $final_score = floatval($_POST['final_score']);
    $stmt = $conn->prepare("UPDATE quiz_submissions SET score = ? WHERE id = ?");
    $stmt->bind_param("di", $final_score, $submission_id);
    if($stmt->execute()){
        $message = "Final score saved successfully!";
    }
    $stmt->close();
}

// Fetch submission details, ensuring it belongs to a quiz in the selected course
$sql = "SELECT s.*, st.full_name_eng, q.title as quiz_title, q.type as quiz_type 
        FROM quiz_submissions s 
        JOIN students st ON s.student_id = st.id
        JOIN quizzes q ON s.quiz_id = q.id
        WHERE s.id = ? AND q.course_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $submission_id, $course_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$submission) { header('Location: manage_quizzes.php?error=access_denied'); exit(); }

// Fetch MCQ Answers for Review
$mcq_review = [];
$sql_mcq = "
    SELECT qq.question_text, qo.option_text as selected_option, qo.is_correct
    FROM student_answers sa
    JOIN quiz_questions qq ON sa.question_id = qq.id
    JOIN question_options qo ON sa.selected_option_id = qo.id
    WHERE sa.submission_id = ? AND qq.question_type = 'mcq'";
$stmt_mcq = $conn->prepare($sql_mcq);
$stmt_mcq->bind_param("i", $submission_id);
$stmt_mcq->execute();
$result_mcq = $stmt_mcq->get_result();
while ($row = $result_mcq->fetch_assoc()) { $mcq_review[] = $row; }
$stmt_mcq->close();

// Fetch Essay Answers for Grading
$essay_review = [];
if ($submission['quiz_type'] == 'Exam') {
    $sql_essay = "SELECT qq.question_text, ea.answer_text FROM essay_answers ea JOIN quiz_questions qq ON ea.question_id = qq.id WHERE ea.submission_id = ?";
    $stmt_essay = $conn->prepare($sql_essay);
    $stmt_essay->bind_param("i", $submission_id);
    $stmt_essay->execute();
    $result_essay = $stmt_essay->get_result();
    while ($row = $result_essay->fetch_assoc()) { $essay_review[] = $row; }
    $stmt_essay->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Submission</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { --bg-color: #f7f9fc; --card-bg-color: #FFFFFF; --text-color: #001232; --border-color: #e9ecef; --brand-blue: <?php echo BRAND_COLOR_BLUE; ?>; --brand-yellow: <?php echo BRAND_COLOR_YELLOW; ?>; }
        body.dark-mode { --bg-color: #121212; --card-bg-color: #1e1e1e; --text-color: #e0e0e0; --border-color: #333; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; }
        .header { background-color: var(--brand-blue); color: #FFFFFF; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .container { padding: 30px; max-width: 900px; margin: auto; }
        .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 30px; border-radius: 8px; margin-bottom: 30px; }
        .card h1, .card h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid var(--brand-yellow); }
        .message { padding: 15px; margin-bottom: 20px; border-radius: 5px; color: #155724; background-color: #d4edda; }
        .review-item { border-bottom: 1px solid var(--border-color); padding: 15px 0; }
        .review-item:last-child { border-bottom: none; }
        .review-item .question { font-weight: 600; }
        .review-item .answer { margin-top: 10px; padding-left: 20px; font-style: italic; }
        .essay-answer { width: 100%; box-sizing: border-box; background-color: var(--bg-color); border: 1px solid var(--border-color); color: var(--text-color); padding: 10px; min-height: 120px; font-family:'Poppins'; border-radius: 5px;}
        .final-grade-form { margin-top: 20px; display: flex; align-items: center; gap: 15px; }
        .final-grade-form input { font-size: 20px; font-weight: bold; width: 100px; padding: 10px; text-align: center; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); border-radius: 5px; }
        .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-blue); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo"><?php echo SCHOOL_NAME; ?><span>.</span></div>
        <a href="view_submissions.php?id=<?php echo $submission['quiz_id']; ?>" style="color:white; text-decoration:none;">← Back to Submissions</a>
    </header>
    <div class="container">
        <h1>Grading: <?php echo htmlspecialchars($submission['quiz_title']); ?></h1>
        <p><strong>Student:</strong> <?php echo htmlspecialchars($submission['full_name_eng']); ?></p>
        
        <?php if($message): ?><p class="message"><?php echo $message; ?></p><?php endif; ?>

        <div class="card">
            <h2>Objective Questions Review</h2>
            <?php if(empty($mcq_review)): ?> <p>No objective questions were submitted.</p> <?php endif; ?>
            <?php foreach($mcq_review as $item): ?>
                <div class="review-item">
                    <p class="question"><?php echo htmlspecialchars($item['question_text']); ?></p>
                    <p class="answer" style="color: <?php echo $item['is_correct'] ? '#28a745' : '#dc3545'; ?>;">
                        Student answered: <?php echo htmlspecialchars($item['selected_option']); ?> 
                        (<?php echo $item['is_correct'] ? 'Correct' : 'Incorrect'; ?>)
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if($submission['quiz_type'] == 'Exam'): ?>
        <div class="card">
            <h2>Essay Questions for Grading</h2>
            <?php if(empty($essay_review)): ?> <p>No essay questions were submitted.</p> <?php endif; ?>
            <?php foreach($essay_review as $item): ?>
                <div class="review-item">
                    <p class="question"><?php echo htmlspecialchars($item['question_text']); ?></p>
                    <textarea class="essay-answer" readonly><?php echo htmlspecialchars($item['answer_text']); ?></textarea>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Final Score</h2>
            <form method="POST" class="final-grade-form">
                <label for="final_score" style="font-weight:bold; font-size: 20px;">Final Score:</label>
                <input type="number" step="0.01" min="0" max="100" name="final_score" id="final_score" value="<?php echo $submission['score']; ?>" required>
                <span>%</span>
                <button type="submit" name="save_grade" class="btn">Save Final Score</button>
            </form>
        </div>
    </div>
</body>
</html>
