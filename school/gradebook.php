<?php
// PART 1: LOGIC & DATA LOADING
require_once 'auth_check.php';

if (!$is_admin && !$is_instructor) { header('Location: index.php'); exit(); }
$message = ''; $error = '';

// Get the Exam ID from the URL. This is essential.
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
if ($exam_id === 0) {
    header('Location: manage_results.php'); // Go to new dashboard
    exit();
}

// --- HANDLE POST REQUEST (Add New Subject) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    $subject_name = trim($_POST['subject_name']);
    $max_score = intval($_POST['max_score']);
    $post_exam_id = intval($_POST['exam_id']); 

    if (empty($subject_name) || $max_score <= 0 || $post_exam_id !== $exam_id) {
        $error = "Invalid data. Could not add subject.";
    } else {
        try {
            // Security: Get course_id for this exam to verify access
            $stmt_cid = $conn->prepare("SELECT course_id FROM exams WHERE id = ? AND school_id = ?");
            $stmt_cid->bind_param("ii", $exam_id, $school_id);
            $stmt_cid->execute();
            $course_res = $stmt_cid->get_result();
            if ($course_res->num_rows === 0) { throw new Exception("Exam not found."); }
            $course_id = $course_res->fetch_assoc()['course_id'];
            $stmt_cid->close();
            
            // Verify user has access to this course
            verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

            // Now, insert the new subject
            $stmt_add = $conn->prepare("INSERT INTO exam_subjects (exam_id, subject_name, max_score, added_by_user_id) VALUES (?, ?, ?, ?)");
            $stmt_add->bind_param("isii", $exam_id, $subject_name, $max_score, $user_id);
            
            if ($stmt_add->execute()) {
                $message = "Subject '$subject_name' added successfully!";
                header('Location: gradebook.php?exam_id=' . $exam_id);
                exit();
            } else {
                $error = "Failed to add subject. It might already exist for this exam.";
            }
            $stmt_add->close();

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}


// --- PART 2: LOAD ALL DATA FOR THE GRADEBOOK ---
require_once 'layout_header.php'; 

// 1. Get Exam & Course Details
$stmt_exam = $conn->prepare("SELECT e.title AS exam_title, e.course_id, c.title AS course_title 
                            FROM exams e
                            JOIN courses c ON e.course_id = c.id
                            WHERE e.id = ? AND e.school_id = ?");
$stmt_exam->bind_param("ii", $exam_id, $school_id);
$stmt_exam->execute();
$exam_result = $stmt_exam->get_result();
$exam = $exam_result->fetch_assoc();
$stmt_exam->close();

if (!$exam) {
    echo "<div class='page-header'><h1>Error</h1></div><div class='error'>Exam not found. <a href='manage_results.php'>Go back</a></div>";
    require_once 'layout_footer.php';
    exit();
}

$course_id = $exam['course_id'];
$exam_title = $exam['exam_title'];
$course_title = $exam['course_title'];

// 2. Security Check (critical)
try {
    verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);
} catch (Exception $e) {
    echo "<div class='page-header'><h1>Access Denied</h1></div><div class='error'>".$e->getMessage()." <a href='manage_results.php'>Go back</a></div>";
    require_once 'layout_footer.php';
    exit();
}

// -----------------------------------------------------------------
// --- 3. Get Students in this Course (FIXED QUERY) ---
// -----------------------------------------------------------------
$students = [];
$sql = "SELECT u.id, u.full_name_eng 
        FROM users u 
        JOIN enrollments e ON u.id = e.student_id 
        WHERE u.school_id = ? AND e.course_id = ? AND u.role = 'student'";
$params = [$school_id, $course_id];
$types = "ii";

if ($is_instructor) {
    $sql .= " AND e.course_id IN (SELECT course_id FROM course_assignments WHERE instructor_id = ?)";
    $params[] = $user_id;
    $types .= "i";
}
$sql .= " ORDER BY u.full_name_eng ASC";

$stmt_students = $conn->prepare($sql);
$stmt_students->bind_param($types, ...$params);
$stmt_students->execute();
$students_result = $stmt_students->get_result();
while ($row = $students_result->fetch_assoc()) { $students[] = $row; }
$stmt_students->close();
// --- END OF FIX ---

// 4. Get Subjects for this Exam
$subjects = [];
$stmt_subjects = $conn->prepare("SELECT id, subject_name, max_score FROM exam_subjects WHERE exam_id = ? ORDER BY subject_name ASC");
$stmt_subjects->bind_param("i", $exam_id);
$stmt_subjects->execute();
$subjects_result = $stmt_subjects->get_result();
while ($row = $subjects_result->fetch_assoc()) { $subjects[] = $row; }
$stmt_subjects->close();

// 5. Get ALL existing scores and put them in a fast-lookup map
$scores_map = [];
$stmt_scores = $conn->prepare("SELECT es.student_id, es.exam_subject_id, es.score, es.grade, es.remark 
                               FROM exam_scores es
                               JOIN exam_subjects exs ON es.exam_subject_id = exs.id
                               WHERE exs.exam_id = ?");
$stmt_scores->bind_param("i", $exam_id);
$stmt_scores->execute();
$scores_result = $stmt_scores->get_result();
while ($score = $scores_result->fetch_assoc()) {
    $scores_map[$score['student_id']][$score['exam_subject_id']] = $score;
}
$stmt_scores->close();
$conn->close();
?>

<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .page-header h2 { margin: 0; font-size: 18px; color: var(--text-muted); font-weight: 500; }
    
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; margin-bottom: 25px; }
    .card h3 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }

    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 600; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block; }
    .btn-secondary { background-color: #6c757d; }
    
    .message, .error { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .error { color: #721c24; background-color: #f8d7da; } .message { color: #155724; background-color: #d4edda; }

    /* --- ANTI-ZOOM FIX (16px+) --- */
    .form-group input[type="text"],
    .form-group input[type="number"] {
        width: 100%;
        padding: 12px;
        box-sizing: border-box;
        border-radius: 5px;
        border: 1px solid var(--border-color);
        background-color: var(--bg-color);
        color: var(--text-color);
        font-size: 16px !important; /* NO ZOOM */
    }
    
    /* --- Gradebook Table --- */
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
    th { background-color: var(--bg-color); position: sticky; top: 0; }
    td:first-child, th:first-child { 
        position: sticky; 
        left: 0; 
        background-color: var(--card-bg-color);
        border-right: 1px solid var(--border-color);
        font-weight: 600;
        z-index: 1;
    }
    
    /* --- Score Input & Status --- */
    td .score-input-wrapper { display: flex; align-items: center; gap: 8px; }
    
    /* NO-ZOOM FIX */
    .score-input {
        width: 80px;
        padding: 8px;
        text-align: center;
        font-size: 16px !important; /* NO ZOOM */
        box-sizing: border-box;
        border: 1px solid var(--border-color);
        background-color: var(--bg-color);
        color: var(--text-color);
        border-radius: 5px;
    }
    
    .status-indicator {
        font-size: 12px;
        display: inline-block;
        width: 60px; /* Give it space */
    }
    .status-saving { color: #007bff; }
    .status-saved { color: #28a745; font-weight: bold; }
    .status-error { color: #dc3545; font-weight: bold; }
</style>

<div class="page-header">
    <h1><?php echo htmlspecialchars($exam_title); ?></h1>
    <h2><?php echo htmlspecialchars($course_title); ?></h2>
</div>

<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<div class="card">
    <h3>Add New Subject to this Exam</h3>
    <form action="gradebook.php?exam_id=<?php echo $exam_id; ?>" method="POST" style="display: flex; gap: 15px; align-items: flex-end;">
        <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
        
        <div class="form-group" style="flex-grow: 1; margin-bottom: 0;">
            <label>Subject Name</label>
            <input type="text" name="subject_name" placeholder="e.g., English Language" required>
        </div>
        <div class="form-group" style="width: 120px; margin-bottom: 0;">
            <label>Max Score</label>
            <input type="number" name="max_score" value="20" min="1" required>
        </div>
        <button type="submit" name="add_subject" class="btn" style="height: 47px;">+ Add Subject</button>
    </form>
</div>

<div class="card">
    <h3>Gradebook</h3>
    
    <?php if (empty($students)): ?>
        <p>No students are enrolled in this course. You may need to <a href="manage_enrollments.php">enroll students</a> first.</p>
    <?php elseif (empty($subjects)): ?>
        <p>No subjects have been added to this exam yet. Use the form above to add one.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table id="gradebook-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <?php foreach ($subjects as $subject): ?>
                            <th><?php echo htmlspecialchars($subject['subject_name']); ?> (<?php echo $subject['max_score']; ?>)</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                    <tr data-student-id="<?php echo $student['id']; ?>">
                        <td><?php echo htmlspecialchars($student['full_name_eng']); ?></td>
                        
                        <?php foreach ($subjects as $subject): ?>
                            <?php
                                // Get the score from our pre-loaded map
                                $score_data = $scores_map[$student['id']][$subject['id']] ?? null;
                                $score_value = $score_data ? $score_data['score'] : '';
                                $grade_value = $score_data ? $score_data['grade'] : '';
                            ?>
                            <td>
                                <div class="score-input-wrapper">
                                    <input 
                                        type="number" 
                                        class="score-input" 
                                        min="0"
                                        max="<?php echo $subject['max_score']; ?>"
                                        placeholder="0"
                                        value="<?php echo $score_value; ?>"
                                        data-student-id="<?php echo $student['id']; ?>"
                                        data-subject-id="<?php echo $subject['id']; ?>"
                                        data-max-score="<?php echo $subject['max_score']; ?>"
                                        data-original-value="<?php echo $score_value; ?>"
                                    >
                                    <span class="status-indicator" data-grade="<?php echo $grade_value; ?>">
                                        <?php echo $grade_value; ?>
                                    </span>
                                </div>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <a href="manage_results.php" class="btn" style="background-color: #28a745; margin-top: 30px;">
        Done? Go to Compile & Release Results
    </a>
    <a href="manage_results.php" class="btn btn-secondary" style="margin-top: 30px; margin-left: 10px;">Back to Results Dashboard</a>
</div>

<?php require_once 'layout_footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const gradebookTable = document.getElementById('gradebook-table');

    if (gradebookTable) {
        // Use event delegation on the table
        gradebookTable.addEventListener('change', function(e) {
            // Only trigger if the user changed a .score-input
            if (!e.target.classList.contains('score-input')) {
                return;
            }
            
            const input = e.target;
            
            // Don't save if the value didn't actually change
            if (input.value === input.dataset.originalValue) {
                return;
            }

            saveScore(input);
        });
    }

    // --- The new AJAX save function ---
    async function saveScore(inputElement) {
        const studentId = inputElement.dataset.studentId;
        const subjectId = inputElement.dataset.subjectId;
        const maxScore = parseInt(inputElement.dataset.maxScore, 10);
        const score = inputElement.value; // Send as string, let backend validate
        
        const statusIndicator = inputElement.nextElementSibling;
        
        // 1. Validation
        const scoreNum = parseInt(score, 10);
        if (score !== "" && (isNaN(scoreNum) || scoreNum < 0 || scoreNum > maxScore)) {
            statusIndicator.textContent = 'Invalid';
            statusIndicator.className = 'status-indicator status-error';
            return; // Don't save invalid data
        }

        // 2. Prepare data for API
        const data = {
            exam_id: <?php echo $exam_id; ?>,
            student_id: studentId,
            exam_subject_id: subjectId,
            score: score === "" ? 0 : scoreNum, // Send 0 if empty
            user_id: <?php echo $user_id; ?>
        };

        // 3. Send AJAX request
        try {
            statusIndicator.textContent = 'Saving...';
            statusIndicator.className = 'status-indicator status-saving';

            const response = await fetch('api_save_subject_score.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (response.ok && result.success) {
                statusIndicator.textContent = `Saved! (${result.grade})`;
                statusIndicator.className = 'status-indicator status-saved';
                // Update the "original" value to prevent re-saving
                inputElement.dataset.originalValue = score; 
            } else {
                throw new Error(result.message || 'Server error');
            }

        } catch (error) {
            statusIndicator.textContent = `Error!`;
            statusIndicator.className = 'status-indicator status-error';
            console.error('Save error:', error.message);
        }
    }
});
</script>
