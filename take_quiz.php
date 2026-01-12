<?php
require_once 'db_connect.php';
if (!isset($_SESSION['student_id'])) { header('Location: index.php'); exit(); }
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: dashboard.php'); exit(); }
$quiz_id = intval($_GET['id']);
$student_id = $_SESSION['student_id'];

// Get School Info for branding
$school_stmt = $conn->prepare("SELECT s.name as school_name, s.logo_path, s.brand_color FROM schools s JOIN users u ON s.id = u.school_id WHERE u.id = ?");
$school_stmt->bind_param("i", $student_id);
$school_stmt->execute();
$school = $school_stmt->get_result()->fetch_assoc();
$school_stmt->close();
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#001232';

// --- Security Check 1: Is the quiz valid and available? ---
$quiz_check_stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ? AND status = 'published' AND (available_from IS NULL OR NOW() >= available_from) AND (available_to IS NULL OR NOW() <= available_to)");
$quiz_check_stmt->bind_param("i", $quiz_id);
$quiz_check_stmt->execute();
$quiz_result = $quiz_check_stmt->get_result();
if ($quiz_result->num_rows === 0) {
    header('Location: dashboard.php?error=assessment_unavailable');
    exit();
}
$quiz = $quiz_result->fetch_assoc();
$quiz_check_stmt->close();

// --- Security Check 2: Has the student already taken this quiz? ---
$sub_check_stmt = $conn->prepare("SELECT id FROM quiz_submissions WHERE quiz_id = ? AND student_id = ?");
$sub_check_stmt->bind_param("ii", $quiz_id, $student_id);
$sub_check_stmt->execute();
if ($sub_check_stmt->get_result()->num_rows > 0) {
    header('Location: dashboard.php?error=quiz_taken');
    exit();
}
$sub_check_stmt->close();

// --- State Management ---
$quiz_started = ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['start_quiz']) || isset($_POST['submit_quiz'])));

// --- Handle final quiz submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    $quiz_type = $_POST['quiz_type'];
    $mcq_answers = $_POST['questions'] ?? [];
    $essay_answers = $_POST['essays'] ?? [];
    $score = null;
    $course_id = $quiz['course_id'];
    $school_id = $quiz['school_id'];
    
    if ($quiz_type === 'Test') {
        $total_questions = 0; $correct_answers = 0;
        if (!empty($mcq_answers)) {
            $correct_options_sql = "SELECT qo.question_id, qo.id as correct_option_id FROM question_options qo JOIN quiz_questions qq ON qo.question_id = qq.id WHERE qq.quiz_id = ? AND qo.is_correct = 1";
            $stmt_correct = $conn->prepare($correct_options_sql);
            $stmt_correct->bind_param("i", $quiz_id);
            $stmt_correct->execute();
            $correct_options_result = $stmt_correct->get_result();
            $correct_map = [];
            while($row = $correct_options_result->fetch_assoc()) { $correct_map[$row['question_id']] = $row['correct_option_id']; }
            $stmt_correct->close();
            $total_questions = count($correct_map);
            foreach ($mcq_answers as $question_id => $selected_option_id) {
                if (isset($correct_map[$question_id]) && $correct_map[$question_id] == $selected_option_id) { $correct_answers++; }
            }
        }
        $score = ($total_questions > 0) ? round(($correct_answers / $total_questions) * 100) : 0;
    }

    $stmt1 = $conn->prepare("INSERT INTO quiz_submissions (quiz_id, student_id, school_id, course_id, end_time, score) VALUES (?, ?, ?, ?, NOW(), ?)");
    $stmt1->bind_param("iiiid", $quiz_id, $student_id, $school_id, $course_id, $score);
    $stmt1->execute();
    $submission_id = $conn->insert_id;
    $stmt1->close();

    if (!empty($mcq_answers)) {
        $stmt2 = $conn->prepare("INSERT INTO student_answers (submission_id, question_id, selected_option_id) VALUES (?, ?, ?)");
        foreach ($mcq_answers as $question_id => $option_id) { $stmt2->bind_param("iii", $submission_id, $question_id, $option_id); $stmt2->execute(); }
        $stmt2->close();
    }
    if (!empty($essay_answers)) {
        $stmt3 = $conn->prepare("INSERT INTO essay_answers (submission_id, question_id, answer_text) VALUES (?, ?, ?)");
        foreach ($essay_answers as $question_id => $answer_text) { if(!empty(trim($answer_text))){ $stmt3->bind_param("iis", $submission_id, $question_id, $answer_text); $stmt3->execute(); } }
        $stmt3->close();
    }
    
    header('Location: assessments.php?status=submitted');
    exit();
}

// --- Fetch Questions ONLY if quiz has started ---
$questions = [];
if ($quiz_started) {
    $sql = "SELECT q.*, GROUP_CONCAT(opt.id, '|||', opt.option_text SEPARATOR ';;;') as options FROM quiz_questions q LEFT JOIN question_options opt ON q.id = opt.question_id WHERE q.quiz_id = ? GROUP BY q.id ORDER BY RAND()";
    $q_stmt = $conn->prepare($sql);
    $q_stmt->bind_param("i", $quiz_id);
    $q_stmt->execute();
    $result = $q_stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $options_array = [];
        if($row['options']){
            foreach(explode(';;;', $row['options']) as $option_str){
                list($opt_id, $opt_text) = explode('|||', $option_str, 2);
                $options_array[] = ['id' => $opt_id, 'text' => $opt_text];
            }
            shuffle($options_array);
        }
        $row['options'] = $options_array;
        $questions[] = $row;
    }
    $q_stmt->close();
} else {
    $q_count_stmt = $conn->prepare("SELECT COUNT(id) as total FROM quiz_questions WHERE quiz_id = ?");
    $q_count_stmt->bind_param("i", $quiz_id);
    $q_count_stmt->execute();
    $question_count = $q_count_stmt->get_result()->fetch_assoc()['total'];
    $q_count_stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment: <?php echo htmlspecialchars($quiz['title']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { --brand-primary: <?php echo $school_brand_color; ?>; }
        body { font-family: 'Poppins', sans-serif; background-color: #f0f2f5; margin: 0; color: #333; }
        .container { max-width: 900px; margin: 20px auto; padding: 0 15px; }
        .card { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); text-align: center; }
        .card h1 { font-size: 32px; margin-top: 0; color: var(--brand-primary); }
        .card .instructions { font-size: 16px; color: #555; margin: 20px auto; max-width: 600px; }
        .details-grid { display: flex; justify-content: center; gap: 40px; margin: 30px 0; font-size: 16px; }
        .detail-item .label { color: #888; font-size: 14px; }
        .detail-item .value { font-weight: 600; font-size: 20px; }
        .btn-start { background-color: var(--brand-primary); color: white; padding: 15px 40px; border: none; border-radius: 50px; font-size: 18px; font-weight: 600; cursor: pointer; }
        .quiz-header { background-color: #fff; padding: 15px 25px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #eee; position: sticky; top: 15px; z-index: 100; }
        .quiz-header h2 { margin: 0; font-size: 20px; }
        #quiz-timer { background-color: var(--brand-primary); color: white; padding: 8px 15px; border-radius: 5px; font-size: 18px; font-weight: 600; }
        .quiz-form { background: #fff; padding: 30px; border-radius: 8px; border: 1px solid #eee; }
        .question-block { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        .options-list { list-style: none; padding: 0; }
        .options-list label { display: block; padding: 15px; border: 1px solid #ccc; border-radius: 5px; cursor: pointer; }
        .options-list input[type="radio"]:checked + label { background-color: #ffe8e6; border-color: var(--brand-primary); }
        textarea.essay-answer { width: 100%; box-sizing: border-box; padding: 15px; border: 1px solid #ccc; border-radius: 5px; font-family: 'Poppins'; font-size: 16px; min-height: 150px; }
        .btn-submit { display: block; width: 100%; padding: 15px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 18px; font-weight: 600; cursor: pointer; }
        [dir="auto"] { text-align: start; }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$quiz_started): ?>
            <div class="card">
                <h1 dir="auto"><?php echo htmlspecialchars($quiz['title']); ?></h1>
                <p class="instructions" dir="auto"><?php echo !empty($quiz['description']) ? nl2br(htmlspecialchars($quiz['description'])) : 'Please read the details below before you begin.'; ?></p>
                <div class="details-grid">
                    <div class="detail-item"><span class="label">Questions</span><div class="value"><?php echo $question_count; ?></div></div>
                    <div class="detail-item"><span class="label">Time Limit</span><div class="value"><?php echo htmlspecialchars($quiz['duration_minutes']); ?> Minutes</div></div>
                </div>
                <form action="take_quiz.php?id=<?php echo $quiz_id; ?>" method="POST">
                    <button type="submit" name="start_quiz" class="btn-start">Start Assessment</button>
                </form>
            </div>
        <?php else: ?>
            <div class="quiz-header">
                <h2 dir="auto"><?php echo htmlspecialchars($quiz['title']); ?></h2>
                <div id="quiz-timer">--:--</div>
            </div>
            <form id="quizForm" class="quiz-form" action="take_quiz.php?id=<?php echo $quiz_id; ?>" method="POST">
                <input type="hidden" name="quiz_type" value="<?php echo $quiz['type']; ?>">
                <?php foreach ($questions as $index => $q_data): ?>
                <div class="question-block">
                    <p dir="auto"><strong>Question <?php echo $index + 1; ?>:</strong> <?php echo htmlspecialchars($q_data['question_text']); ?></p>
                    <?php if ($q_data['question_type'] === 'mcq'): ?>
                        <ul class="options-list">
                            <?php foreach ($q_data['options'] as $option): ?>
                            <li>
                                <input type="radio" style="display:none;" id="option_<?php echo $option['id']; ?>" name="questions[<?php echo $q_data['id']; ?>]" value="<?php echo $option['id']; ?>" required>
                                <label for="option_<?php echo $option['id']; ?>" dir="auto"><?php echo htmlspecialchars($option['text']); ?></label>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php elseif ($q_data['question_type'] === 'essay'): ?>
                        <textarea class="essay-answer" name="essays[<?php echo $q_data['id']; ?>]" placeholder="Type your answer here..." required dir="auto"></textarea>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <button type="submit" name="submit_quiz" class="btn-submit">Submit My Answers</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($quiz_started): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const quizDuration = <?php echo $quiz['duration_minutes'] * 60; ?>;
            const timerDisplay = document.getElementById('quiz-timer');
            const quizForm = document.getElementById('quizForm');
            
            // --- 1. PROGRESS SAVING LOGIC (Using localStorage) ---
            const storageKey = `quizProgress_<?php echo $student_id; ?>_<?php echo $quiz_id; ?>`;

            const saveProgress = () => {
                const formData = new FormData(quizForm);
                const data = {};
                for (let [key, value] of formData.entries()) {
                    data[key] = value;
                }
                localStorage.setItem(storageKey, JSON.stringify(data));
            };

            const loadProgress = () => {
                const savedData = localStorage.getItem(storageKey);
                if (savedData) {
                    const data = JSON.parse(savedData);
                    for (const key in data) {
                        const element = quizForm.elements[key];
                        if (element) {
                            if (element.type === 'radio') {
                                const selector = `input[name="${key}"][value="${data[key]}"]`;
                                const radioToSelect = document.querySelector(selector);
                                if (radioToSelect) {
                                    radioToSelect.checked = true;
                                }
                            } else {
                                element.value = data[key];
                            }
                        }
                    }
                }
            };

            quizForm.addEventListener('change', saveProgress);
            quizForm.addEventListener('input', saveProgress);
            loadProgress();

            // --- 2. TIMER LOGIC ---
            let timeLeft = quizDuration;
            const timerInterval = setInterval(() => {
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    alert('Time is up! Your answers will be submitted automatically.');
                    localStorage.removeItem(storageKey);
                    quizForm.submit();
                    return;
                }
                timeLeft--;
                const minutes = Math.floor(timeLeft / 60);
                let seconds = timeLeft % 60;
                seconds = seconds < 10 ? '0' + seconds : seconds;
                timerDisplay.textContent = `${minutes}:${seconds}`;
            }, 1000);

            // --- 3. SUBMIT LOGIC ---
            quizForm.addEventListener('submit', function(e) {
                if (!this.dataset.confirmed) {
                    if (confirm('Are you sure you want to submit your answers?')) {
                        localStorage.removeItem(storageKey);
                        this.dataset.confirmed = true;
                    } else {
                        e.preventDefault();
                    }
                }
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>
