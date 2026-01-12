<?php
require_once 'auth_check.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: manage_quizzes.php'); exit(); }
$submission_id = intval($_GET['id']);
$course_id = 0;
if ($is_admin) { $course_id = $_SESSION['selected_course_id'] ?? 0; } 
elseif ($is_instructor) { $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0; }
if ($course_id == 0) { header('Location: dashboard.php?error=no_course'); exit(); }
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grade_and_feedback'])) {
    $conn->begin_transaction();
    try {
        // 1. Save Final Score
        $final_score = floatval($_POST['final_score']);
        $stmt_score = $conn->prepare("UPDATE quiz_submissions SET score = ? WHERE id = ? AND school_id = ?");
        $stmt_score->bind_param("dii", $final_score, $submission_id, $school_id);
        $stmt_score->execute();
        $stmt_score->close();

        // 2. Save Essay Feedback
        if (isset($_POST['feedback']) && is_array($_POST['feedback'])) {
            $stmt_feedback = $conn->prepare("UPDATE essay_answers SET feedback = ? WHERE id = ? AND submission_id = ?");
            foreach ($_POST['feedback'] as $essay_answer_id => $feedback_text) {
                $e_id = intval($essay_answer_id);
                $stmt_feedback->bind_param("sii", $feedback_text, $e_id, $submission_id);
                $stmt_feedback->execute();
            }
            $stmt_feedback->close();
        }
        
        $conn->commit();
        $message = "Grades and feedback saved successfully!";
    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        $message = "Error: Could not save data.";
    }
}

// ===== CHANGED: Explicitly selected q.id as quiz_id for the back button link =====
$sql = "SELECT s.*, u.full_name_eng, q.id as quiz_id, q.title as quiz_title, q.type as quiz_type FROM quiz_submissions s JOIN users u ON s.student_id = u.id JOIN quizzes q ON s.quiz_id = q.id WHERE s.id = ? AND s.school_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $submission_id, $school_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$submission || $submission['course_id'] != $course_id) { 
    $redirect_url = $is_admin ? 'dashboard.php' : 'instructor_dashboard.php';
    header("Location: $redirect_url?error=access_denied");
    exit();
}

require_once 'layout_header.php'; 

$mcq_review = [];
$sql_mcq = "SELECT qq.question_text, qo.option_text as selected_option, qo.is_correct FROM student_answers sa JOIN quiz_questions qq ON sa.question_id = qq.id JOIN question_options qo ON sa.selected_option_id = qo.id WHERE sa.submission_id = ? AND qq.question_type = 'mcq'";
$stmt_mcq = $conn->prepare($sql_mcq);
$stmt_mcq->bind_param("i", $submission_id);
$stmt_mcq->execute();
$result_mcq = $stmt_mcq->get_result();
while ($row = $result_mcq->fetch_assoc()) { $mcq_review[] = $row; }
$stmt_mcq->close();

$essay_review = [];
if ($submission['quiz_type'] == 'Exam') {
    $sql_essay = "SELECT ea.id as essay_answer_id, qq.question_text, ea.answer_text, ea.feedback FROM essay_answers ea JOIN quiz_questions qq ON ea.question_id = qq.id WHERE ea.submission_id = ?";
    $stmt_essay = $conn->prepare($sql_essay);
    $stmt_essay->bind_param("i", $submission_id);
    $stmt_essay->execute();
    $result_essay = $stmt_essay->get_result();
    while ($row = $result_essay->fetch_assoc()) { $essay_review[] = $row; }
    $stmt_essay->close();
}
$conn->close();
?>
<style>
    /* ===== CHANGED: Made page-header a flex container for the new button ===== */
    .page-header { 
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 15px;
    }
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 30px; border-radius: 8px; margin-bottom: 30px; }
    .card h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid var(--brand-primary); }
    .message { padding: 15px; margin-bottom: 20px; border-radius: 5px; color: #155724; background-color: #d4edda; }
    .review-item { border-bottom: 1px solid var(--border-color); padding: 25px 0; }
    .review-item:first-child { padding-top: 10px; }
    .review-item:last-child { border-bottom: none; }
    .review-item .question { font-weight: 600; }
    .review-item .answer { margin-top: 10px; padding-left: 20px; font-style: italic; }
    .essay-answer-box { margin-top: 15px; }
    .essay-answer-box label { font-weight: 600; font-size: 14px; color: var(--text-muted); }
    .essay-answer { width: 100%; box-sizing: border-box; background-color: var(--bg-color); border: 1px solid var(--border-color); color: var(--text-color); padding: 10px; min-height: 120px; font-family:'Poppins'; border-radius: 5px; margin-top: 5px; font-size: 16px; }
    .final-grade-form { margin-top: 20px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;}
    .final-grade-form input { font-weight: bold; width: 100px; padding: 10px; text-align: center; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); border-radius: 5px; font-size: 16px; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
    
    /* ===== ADDED: Secondary button style for the back button ===== */
    .btn.btn-secondary {
        background: none;
        border: 1px solid var(--border-color);
        color: var(--text-color);
        font-size: 14px;
        padding: 8px 20px;
    }

    @media (max-width: 768px) {
        .card { padding: 15px; }
        .page-header h1 { font-size: 24px; }
        .card h2 { font-size: 20px; }
        .review-item .answer { padding-left: 0; }
        .final-grade-form { align-items: flex-start; flex-direction: column; gap: 10px; }
        .final-grade-form div { display: flex; align-items: center; gap: 10px; }
        .btn { width: 100%; text-align: center; }
        .page-header .btn { width: auto; } /* Keep header buttons from going full width */
    }
</style>

<div class="page-header">
    <h1>Grade Submission</h1>
    <a href="view_submissions.php?id=<?php echo $submission['quiz_id']; ?>&course_id=<?php echo $course_id; ?>" class="btn btn-secondary">‹ Back to Submissions</a>
</div>
<p><strong>Student:</strong> <?php echo htmlspecialchars($submission['full_name_eng']); ?> | <strong>Assessment:</strong> <?php echo htmlspecialchars($submission['quiz_title']); ?></p>

<?php if($message): ?><p class="message"><?php echo $message; ?></p><?php endif; ?>

<form action="grade_submission.php?id=<?php echo $submission_id; ?>&course_id=<?php echo $course_id; ?>" method="POST">
    <div class="card">
        <h2>Objective Questions Review</h2>
        <?php if(empty($mcq_review)): ?> <p>No objective questions were submitted.</p> <?php endif; ?>
        <?php foreach($mcq_review as $item): ?>
            <div class="review-item">
                <p class="question" dir="auto"><?php echo htmlspecialchars($item['question_text']); ?></p>
                <p class="answer" dir="auto" style="color: <?php echo $item['is_correct'] ? '#28a745' : '#dc3545'; ?>;">
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
                <p class="question" dir="auto"><?php echo htmlspecialchars($item['question_text']); ?></p>
                
                <div class="essay-answer-box">
                    <label>Student's Answer:</label>
                    <textarea class="essay-answer" readonly dir="auto"><?php echo htmlspecialchars($item['answer_text']); ?></textarea>
                </div>
                
                <div class="essay-answer-box">
                    <label for="feedback_<?php echo $item['essay_answer_id']; ?>">Feedback / Correct Answer:</label>
                    <textarea class="essay-answer" name="feedback[<?php echo $item['essay_answer_id']; ?>]" id="feedback_<?php echo $item['essay_answer_id']; ?>" placeholder="Provide feedback or the model answer here..." dir="auto"><?php echo htmlspecialchars($item['feedback'] ?? ''); ?></textarea>
                </div>

            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Final Score & Submission</h2>
        <div class="final-grade-form">
            <label for="final_score" style="font-weight:bold; font-size: 20px;">Final Score:</label>
            <div>
                <input type="number" step="0.01" min="0" max="100" name="final_score" id="final_score" value="<?php echo $submission['score']; ?>" required>
                <span>%</span>
            </div>
            <button type="submit" name="save_grade_and_feedback" class="btn">Save Grades & Feedback</button>
        </div>
    </div>
</form>

<?php require_once 'layout_footer.php'; ?>
