<?php
require_once 'db_connect.php'; // This also starts the session

// ... (initial PHP code for session checks, fetching school details, etc. remains the same) ...
if (!isset($_SESSION['student_id'])) { header('Location: index.php'); exit(); }
if (!isset($_SESSION['current_course_id'])) { header('Location: dashboard.php'); exit(); }
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: dashboard.php'); exit(); }
$student_id = $_SESSION['student_id'];
$current_course_id = $_SESSION['current_course_id'];
$submission_id = intval($_GET['id']);
$details_stmt = $conn->prepare("SELECT s.name as school_name, s.logo_path, s.brand_color FROM courses c JOIN schools s ON c.school_id = s.id WHERE c.id = ? LIMIT 1");
$details_stmt->bind_param("i", $current_course_id);
$details_stmt->execute();
$details_data = $details_stmt->get_result()->fetch_assoc();
$school = [ 'name' => $details_data['school_name'], 'logo_path' => $details_data['logo_path'], 'brand_color' => $details_data['brand_color'] ];
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#001232';
$details_stmt->close();
$stmt = $conn->prepare("SELECT s.score, s.end_time, q.id as quiz_id, q.title as quiz_title, q.result_status FROM quiz_submissions s JOIN quizzes q ON s.quiz_id = q.id WHERE s.id = ? AND s.student_id = ?");
$stmt->bind_param("ii", $submission_id, $student_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$submission) { header('Location: dashboard.php?error=not_found'); exit(); }
if ($submission['result_status'] == 'Pending') { header('Location: assessments.php?status=result_pending'); exit(); }

$quiz_id = $submission['quiz_id'];
$answers_review = [];
$all_questions = [];
$questions_stmt = $conn->prepare("SELECT id, question_text, question_type FROM quiz_questions WHERE quiz_id = ? ORDER BY id");
$questions_stmt->bind_param("i", $quiz_id);
$questions_stmt->execute();
$questions_result = $questions_stmt->get_result();
while ($row = $questions_result->fetch_assoc()) { $all_questions[$row['id']] = $row; }
$questions_stmt->close();
$mcq_answers = [];
$mcq_sql = "SELECT sa.question_id, qo.option_text as selected_option_text, qo.is_correct as was_student_correct, (SELECT opt.option_text FROM question_options opt WHERE opt.question_id = sa.question_id AND opt.is_correct = 1 LIMIT 1) as correct_option_text FROM student_answers sa JOIN question_options qo ON sa.selected_option_id = qo.id WHERE sa.submission_id = ?";
$mcq_stmt = $conn->prepare($mcq_sql);
$mcq_stmt->bind_param("i", $submission_id);
$mcq_stmt->execute();
$mcq_result = $mcq_stmt->get_result();
while($row = $mcq_result->fetch_assoc()) { $mcq_answers[$row['question_id']] = $row; }
$mcq_stmt->close();

// ===== CHANGED: Fetch feedback along with essay answer =====
$essay_answers = [];
$essay_stmt = $conn->prepare("SELECT question_id, answer_text, feedback FROM essay_answers WHERE submission_id = ?");
$essay_stmt->bind_param("i", $submission_id);
$essay_stmt->execute();
$essay_result = $essay_stmt->get_result();
while($row = $essay_result->fetch_assoc()) {
    $essay_answers[$row['question_id']] = $row; // Store the whole row
}
$essay_stmt->close();

foreach($all_questions as $qid => $question) {
    $review_item = $question;
    if ($question['question_type'] === 'mcq') {
        if (isset($mcq_answers[$qid])) {
            $review_item['selected_option_text'] = $mcq_answers[$qid]['selected_option_text'];
            $review_item['was_student_correct'] = $mcq_answers[$qid]['was_student_correct'];
            $review_item['correct_option_text'] = $mcq_answers[$qid]['correct_option_text'];
        } else {
            $review_item['selected_option_text'] = 'Not Answered';
            $review_item['was_student_correct'] = 0;
            $review_item['correct_option_text'] = $mcq_answers[$qid]['correct_option_text'] ?? 'N/A';
        }
    } elseif ($question['question_type'] === 'essay') {
        $review_item['student_essay_answer'] = $essay_answers[$qid]['answer_text'] ?? 'Not Answered';
        $review_item['feedback'] = $essay_answers[$qid]['feedback'] ?? null; // Pass feedback to review item
    }
    $answers_review[] = $review_item;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Result - <?php echo htmlspecialchars($school['name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root {
            --brand-primary: <?php echo $school_brand_color; ?>;
            --bg-color: #f7f9fc; --card-bg-color: #FFFFFF; --text-color: #2c3e50;
            --text-muted: #6c757d; --border-color: #e9ecef;
            --correct-bg: #d4edda; --correct-text: #155724;
            --incorrect-bg: #f8d7da; --incorrect-text: #721c24;
            --essay-bg: #eef1f5;
            --feedback-bg: #fffbe6; /* A light yellow for feedback */
            --feedback-border: #ffe58f;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; line-height: 1.6; }
        .header { background-color: var(--card-bg-color); border-bottom: 1px solid var(--border-color); padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; }
        .header .logo img { max-height: 35px; }
        .header .logo span { font-size: 20px; font-weight: 700; color: var(--brand-primary); }
        .header-controls a { padding: 6px 14px; border-radius: 50px; font-weight: 500; text-decoration: none; border: 1px solid var(--border-color); color: var(--text-color); }
        
        .main-container { max-width: 900px; margin: 0 auto; padding: 30px 20px; }
        .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 25px; margin-bottom: 25px; }
        .result-header { text-align: center; }
        .result-header h1 { font-size: 24px; margin: 0; }
        .result-header .score { font-size: 48px; font-weight: 700; color: var(--brand-primary); margin: 10px 0; }
        
        .review-section h2 { font-size: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 20px; }
        .question-review { margin-bottom: 25px; padding-bottom: 25px; border-bottom: 1px dashed var(--border-color); }
        .question-review:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .question-text { font-weight: 600; font-size: 16px; margin-bottom: 15px; }
        .answer-row { display: flex; align-items: baseline; gap: 10px; font-size: 14px; padding: 8px 12px; border-radius: 5px; }
        .answer-row.correct { background-color: var(--correct-bg); color: var(--correct-text); }
        .answer-row.incorrect { background-color: var(--incorrect-bg); color: var(--incorrect-text); }
        .answer-label { font-weight: 500; flex-shrink: 0; }
        .essay-answer-box { background-color: var(--essay-bg); border-left: 3px solid var(--brand-primary); padding: 15px; border-radius: 5px; margin-top: 10px; }
        .essay-answer-box .answer-label { font-weight: 600; margin-bottom: 8px; display: block; }
        .essay-answer-box p { margin: 0; white-space: pre-wrap; }
        
        /* ===== ADDED: Styling for the feedback box ===== */
        .feedback-box { background-color: var(--feedback-bg); border: 1px solid var(--feedback-border); border-left: 3px solid #ffc107; padding: 15px; border-radius: 5px; margin-top: 15px; }
        .feedback-box .answer-label { font-weight: 600; margin-bottom: 8px; display: block; color: #856404; }
        .feedback-box p { margin: 0; white-space: pre-wrap; }
    </style>
</head>
<body>
    <header class="header"></header>
    <div class="main-container">
        <div class="card result-header">
            <h1><?php echo htmlspecialchars($submission['quiz_title']); ?></h1>
            <p style="color: var(--text-muted);">Completed on <?php echo date("F j, Y, g:i a", strtotime($submission['end_time'])); ?></p>
            <?php if($submission['score'] !== null): ?><div class="score"><?php echo round($submission['score']); ?>%</div><?php endif; ?>
        </div>
        <div class="card review-section">
            <h2>Answer Review</h2>
            <?php foreach ($answers_review as $index => $review): ?>
                <div class="question-review">
                    <div class="question-text"><?php echo ($index + 1); ?>. <?php echo htmlspecialchars($review['question_text']); ?></div>
                    
                    <?php if ($review['question_type'] === 'mcq'): ?>
                        <div class="answer-row <?php echo ($review['was_student_correct'] ? 'correct' : 'incorrect'); ?>">
                            <span class="answer-label">Your Answer:</span>
                            <span><?php echo htmlspecialchars($review['selected_option_text']); ?></span>
                        </div>
                        <?php if (!$review['was_student_correct']): ?>
                            <div class="answer-row correct" style="margin-top: 10px;">
                                <span class="answer-label">Correct Answer:</span>
                                <span><?php echo htmlspecialchars($review['correct_option_text']); ?></span>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($review['question_type'] === 'essay'): ?>
                        <div class="essay-answer-box">
                            <span class="answer-label">Your Answer:</span>
                            <p><?php echo nl2br(htmlspecialchars($review['student_essay_answer'])); ?></p>
                        </div>
                        
                        <?php if (!empty($review['feedback'])): ?>
                            <div class="feedback-box">
                                <span class="answer-label">Instructor Feedback:</span>
                                <p><?php echo nl2br(htmlspecialchars($review['feedback'])); ?></p>
                            </div>
                        <?php endif; ?>
                        
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
