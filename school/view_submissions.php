<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

$course_id = 0;
if ($is_admin) { $course_id = $_SESSION['selected_course_id'] ?? 0; } 
elseif ($is_instructor) { $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0; }
if ($course_id == 0) {
    $redirect_url = $is_admin ? 'dashboard.php' : 'instructor_dashboard.php';
    header("Location: $redirect_url?error=no_course"); 
    exit();
}
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: manage_quizzes.php?course_id='.$course_id); exit(); }
$quiz_id = intval($_GET['id']);

$quiz_stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ? AND school_id = ? AND course_id = ?");
$quiz_stmt->bind_param("iii", $quiz_id, $school_id, $course_id);
$quiz_stmt->execute();
$quiz = $quiz_stmt->get_result()->fetch_assoc();
$quiz_stmt->close();
if (!$quiz) { header('Location: manage_quizzes.php?course_id='.$course_id.'&error=access_denied'); exit(); }

if (isset($_GET['action']) && $_GET['action'] == 'release') {
    $update_stmt = $conn->prepare("UPDATE quizzes SET result_status = 'Released' WHERE id = ? AND school_id = ?");
    $update_stmt->bind_param("ii", $quiz_id, $school_id);
    $update_stmt->execute();
    $update_stmt->close();
    header('Location: view_submissions.php?id=' . $quiz_id . '&course_id=' . $course_id . '&status=released');
    exit();
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

$submissions = [];
$sql = "SELECT s.id, s.score, s.end_time, u.full_name_eng FROM quiz_submissions s JOIN users u ON s.student_id = u.id WHERE s.quiz_id = ? AND s.school_id = ? ORDER BY s.end_time DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $quiz_id, $school_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $submissions[] = $row; }
$stmt->close();
$conn->close();
?>
<style>
    .page-header { display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;}
    .page-header h1 { margin: 0; font-size: 28px; }
    .btn { padding: 10px 20px; border-radius: 5px; color: white; text-decoration: none; font-weight: 500; border:none; font-family:'Poppins'; font-size: 14px; cursor: pointer;}
    .btn-green { background-color: #28a745; } .btn-blue { background-color: var(--brand-primary); }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 5px; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    .message { padding: 15px; margin-bottom: 20px; border-radius: 5px; color: #155724; background-color: #d4edda; }

    @media (max-width: 992px) {
        .table-wrapper thead { display: none; }
        .table-wrapper tr { display: block; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 5px; }
        .table-wrapper td { display: block; text-align: right; padding-left: 50%; position: relative; border-bottom: 1px solid var(--border-color); white-space: normal; word-break: break-word; }
        .table-wrapper td:last-child { border-bottom: none; }
        .table-wrapper td::before { content: attr(data-label); position: absolute; left: 15px; font-weight: 600; text-align: left; }
    }
</style>

<div class="page-header">
    <h1>Submissions for "<?php echo htmlspecialchars($quiz['title']); ?>"</h1>
    <?php if($quiz['result_status'] == 'Pending'): ?>
        <a href="view_submissions.php?id=<?php echo $quiz_id; ?>&course_id=<?php echo $course_id; ?>&action=release" class="btn btn-green" onclick="return confirm('Are you sure? This will make scores visible to all students who have submitted.');">Release Results</a>
    <?php else: ?>
        <span style="font-weight:bold; color:#28a745;">✓ Results Released</span>
    <?php endif; ?>
</div>
<?php if(isset($_GET['status']) && $_GET['status'] == 'released'): ?>
    <p class="message">Results have been released to all students.</p>
<?php endif; ?>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Student Name</th><th>Submitted On</th><th>Score</th><th>Action</th></tr></thead>
            <tbody>
                <?php if(empty($submissions)): ?>
                    <tr><td colspan="4" style="text-align:center; padding: 20px;">No submissions yet.</td></tr>
                <?php else: foreach($submissions as $sub): ?>
                    <tr>
                        <td data-label="Student"><?php echo htmlspecialchars($sub['full_name_eng']); ?></td>
                        <td data-label="Submitted On"><?php echo date("D, j M Y", strtotime($sub['end_time'])); ?></td>
                        <td data-label="Score">
                            <?php if(is_null($sub['score'])): ?>
                                <span style="color:#ffc107; font-weight:bold;">Pending Grade</span>
                            <?php else: ?>
                                <span style="font-weight:bold;"><?php echo $sub['score']; ?>%</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Action"><a href="grade_submission.php?id=<?php echo $sub['id']; ?>&course_id=<?php echo $course_id; ?>" class="btn btn-blue"><?php echo (is_null($sub['score']) && $quiz['type'] == 'Exam') ? 'Grade' : 'View'; ?></a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once 'layout_footer.php'; ?>
