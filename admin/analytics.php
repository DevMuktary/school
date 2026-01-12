<?php
require_once '../db_connect.php';

if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit(); }
if (!isset($_SESSION['selected_course_id']) || $_SESSION['selected_course_id'] == 0) {
    header('Location: dashboard.php?error=no_course_selected');
    exit();
}
$course_id = $_SESSION['selected_course_id'];

// --- Fetch Course Title ---
$course_title_stmt = $conn->prepare("SELECT title FROM courses WHERE id = ?");
$course_title_stmt->bind_param("i", $course_id);
$course_title_stmt->execute();
$course_title = $course_title_stmt->get_result()->fetch_assoc()['title'];
$course_title_stmt->close();

// --- 1. Fetch "At-Risk" Students ---
// Definition: Students with an average score below 50% on released assessments.
$at_risk_students = [];
$at_risk_sql = "SELECT s.full_name_eng, AVG(qs.score) as average_score
                FROM quiz_submissions qs
                JOIN students s ON qs.student_id = s.id
                JOIN quizzes q ON qs.quiz_id = q.id
                WHERE q.course_id = ? AND q.result_status = 'Released'
                GROUP BY qs.student_id
                HAVING average_score < 50
                ORDER BY average_score ASC";
$at_risk_stmt = $conn->prepare($at_risk_sql);
$at_risk_stmt->bind_param("i", $course_id);
$at_risk_stmt->execute();
$at_risk_result = $at_risk_stmt->get_result();
if($at_risk_result) { while($row = $at_risk_result->fetch_assoc()) { $at_risk_students[] = $row; } }
$at_risk_stmt->close();


// --- 2. Fetch Assessments for Question Analysis Dropdown ---
$assessments = [];
$assessments_stmt = $conn->prepare("SELECT id, title FROM quizzes WHERE course_id = ? ORDER BY title ASC");
$assessments_stmt->bind_param("i", $course_id);
$assessments_stmt->execute();
$assessments_result = $assessments_stmt->get_result();
if($assessments_result) { while($row = $assessments_result->fetch_assoc()) { $assessments[] = $row; } }
$assessments_stmt->close();

// --- 3. Perform Question Analysis if an assessment is selected ---
$question_analysis = [];
$selected_quiz_title = '';
if(isset($_GET['analyze_quiz_id']) && is_numeric($_GET['analyze_quiz_id'])) {
    $analyze_quiz_id = intval($_GET['analyze_quiz_id']);
    
    // Get the title of the selected quiz
    foreach($assessments as $a) { if($a['id'] == $analyze_quiz_id) $selected_quiz_title = $a['title']; }
    
    $analysis_sql = "SELECT
                        qq.question_text,
                        COUNT(sa.id) AS total_answers,
                        SUM(qo.is_correct) AS correct_answers,
                        ROUND((SUM(qo.is_correct) / COUNT(sa.id)) * 100, 1) AS success_rate
                    FROM student_answers sa
                    JOIN question_options qo ON sa.selected_option_id = qo.id
                    JOIN quiz_questions qq ON sa.question_id = qq.id
                    WHERE qq.quiz_id = ? AND qq.question_type = 'mcq'
                    GROUP BY sa.question_id
                    ORDER BY success_rate ASC";
    $analysis_stmt = $conn->prepare($analysis_sql);
    $analysis_stmt->bind_param("i", $analyze_quiz_id);
    $analysis_stmt->execute();
    $analysis_result = $analysis_stmt->get_result();
    if($analysis_result) { while($row = $analysis_result->fetch_assoc()) { $question_analysis[] = $row; } }
    $analysis_stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics & Reports - Admin</title>
    <style>
        /* Re-using styles from other admin pages for consistency */
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { --bg-color: #f7f9fc; --card-bg-color: #FFFFFF; --text-color: #001232; --border-color: #e9ecef; --brand-blue: <?php echo BRAND_COLOR_BLUE; ?>; --brand-yellow: <?php echo BRAND_COLOR_YELLOW; ?>; }
        body.dark-mode { --bg-color: #121212; --card-bg-color: #1e1e1e; --text-color: #e0e0e0; --border-color: #333; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; }
        .header { background-color: var(--brand-blue); color: #FFFFFF; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .container { max-width: 1000px; margin: 0 auto; padding: 25px 15px; }
        .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; margin-bottom: 25px; }
        h1 { margin: 0 0 10px 0; font-size: 28px; }
        .page-subtitle { font-size: 16px; color: var(--text-secondary-color); margin-top: -5px; margin-bottom: 30px; }
        .card h3 { margin-top: 0; font-size: 18px; border-bottom: 2px solid var(--brand-yellow); padding-bottom: 10px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 5px; text-align: left; border-bottom: 1px solid var(--border-color); }
        .score-low { font-weight: bold; color: #dc3545; }
        .success-rate-bar { background-color: #e9ecef; border-radius: 5px; overflow: hidden; }
        .success-rate-fill { height: 20px; border-radius: 5px; background-color: #28a745; text-align: right; padding-right: 5px; color: white; font-size: 12px; line-height: 20px; }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo"><?php echo SCHOOL_NAME; ?><span>.</span></div>
        <a href="dashboard.php" style="color:white; text-decoration:none;">← Back to Dashboard</a>
    </header>
    <div class="container">
        <h1>Analytics & Reports</h1>
        <p class="page-subtitle">For course: <strong><?php echo htmlspecialchars($course_title); ?></strong></p>

        <div class="card">
            <h3>At-Risk Students</h3>
            <p style="font-size:14px; color: var(--text-secondary-color); margin-top:-15px;">Students with an average score below 50% on released assessments.</p>
            <table>
                <?php if(empty($at_risk_students)): ?>
                    <tr><td>Excellent! No students are currently at risk.</td></tr>
                <?php else: ?>
                    <thead><tr><th>Student Name</th><th style="text-align:right;">Average Score</th></tr></thead>
                    <tbody>
                    <?php foreach($at_risk_students as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['full_name_eng']); ?></td>
                            <td style="text-align:right;" class="score-low"><?php echo round($student['average_score'], 1); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>

        <div class="card">
            <h3>Assessment Analysis</h3>
            <p style="font-size:14px; color: var(--text-secondary-color); margin-top:-15px;">Analyze the performance of objective questions in an assessment.</p>
            <form method="GET">
                <select name="analyze_quiz_id" onchange="this.form.submit()">
                    <option value="">-- Select an Assessment to Analyze --</option>
                    <?php foreach($assessments as $a): ?>
                        <option value="<?php echo $a['id']; ?>" <?php if(isset($analyze_quiz_id) && $a['id'] == $analyze_quiz_id) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($a['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if(isset($analyze_quiz_id) && !empty($question_analysis)): ?>
            <h4 style="margin-top:30px;">Question Breakdown for "<?php echo htmlspecialchars($selected_quiz_title); ?>"</h4>
            <table>
                <thead><tr><th>Question</th><th style="width:200px;">Success Rate</th></tr></thead>
                <tbody>
                <?php foreach($question_analysis as $q): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($q['question_text']); ?></td>
                        <td>
                            <div class="success-rate-bar">
                                <div class="success-rate-fill" style="width:<?php echo $q['success_rate']; ?>%; background-color: <?php echo ($q['success_rate'] < 50) ? '#dc3545' : '#28a745'; ?>;">
                                    <?php echo $q['success_rate']; ?>%
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php elseif(isset($analyze_quiz_id)): ?>
                <p style="margin-top:20px;">No objective question data to analyze for this assessment.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
