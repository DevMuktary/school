<?php 
require_once 'layout_header.php';

if (!isset($_GET['id'])) { header('Location: dashboard.php'); exit(); }
$assignment_id = intval($_GET['id']);
$message = ''; $error = '';

// Fetch assignment details to get the course_id
$a_stmt = $conn->prepare("SELECT course_id, title FROM assignments WHERE id = ? AND school_id = ?");
$a_stmt->bind_param("ii", $assignment_id, $school_id);
$a_stmt->execute();
$assignment = $a_stmt->get_result()->fetch_assoc();
$a_stmt->close();
if(!$assignment) { die("Assignment not found."); }
$course_id = $assignment['course_id'];
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

// Handle POST request to save a grade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grade'])) {
    $submission_id = intval($_POST['submission_id']);
    $grade = trim($_POST['grade']);
    $feedback = trim($_POST['feedback']);
    $stmt = $conn->prepare("UPDATE assignment_submissions SET grade = ?, feedback = ?, graded_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $grade, $feedback, $submission_id);
    if ($stmt->execute()) { $message = "Grade saved successfully!"; }
    else { $error = "Failed to save grade."; }
    $stmt->close();
}

// Fetch all submissions for this assignment
$submissions = [];
$sql = "SELECT asub.*, u.full_name_eng FROM assignment_submissions asub JOIN users u ON asub.student_id = u.id WHERE asub.assignment_id = ? ORDER BY asub.submitted_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$result = $stmt->get_result();
if($result) { while($row = $result->fetch_assoc()) { $submissions[] = $row; } }
$stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .page-subtitle { font-size: 16px; color: var(--text-muted); margin-top: -5px; margin-bottom: 30px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 5px; border-radius: 8px; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    td input, td textarea { font-size: 14px; padding: 8px; border-radius: 5px; border: 1px solid var(--border-color); width: 100%; box-sizing: border-box; background-color: var(--bg-color); color: var(--text-color); font-size: 16px; }
    td textarea { min-height: 40px; }
    .btn-sm { padding: 8px 12px; font-size: 14px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; cursor: pointer; }
    .message { padding: 15px; margin-bottom: 20px; border-radius: 5px; color: #155724; background-color: #d4edda; }
    @media (max-width: 992px) {
        .table-wrapper thead { display: none; }
        .table-wrapper tr { display: block; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 8px; padding: 10px; }
        .table-wrapper td { display: flex; justify-content: space-between; align-items: center; text-align: right; padding: 10px 0; border-bottom: 1px solid var(--border-color); }
        .table-wrapper td:last-child { border-bottom: none; }
        .table-wrapper td::before { content: attr(data-label); font-weight: 600; text-align: left; margin-right: 15px; }
        td form { flex-direction: column; align-items: stretch; gap: 10px; width: 100%; }
    }
</style>

<div class="page-header">
    <h1>Grade Submissions</h1>
</div>
<p class="page-subtitle">For assignment: <strong><?php echo htmlspecialchars($assignment['title']); ?></strong></p>
<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Student</th><th>Submitted File</th><th>Grade</th><th>Feedback</th><th>Action</th></tr></thead>
            <tbody>
                <?php if(empty($submissions)): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 20px;">No submissions for this assignment yet.</td></tr>
                <?php else: foreach($submissions as $sub): ?>
                    <tr>
                        <td data-label="Student"><?php echo htmlspecialchars($sub['full_name_eng']); ?></td>
                        <td data-label="File"><a href="../uploads/submissions/<?php echo htmlspecialchars($sub['file_path']); ?>" download>Download</a></td>
                        <td colspan="3">
                            <form action="grade_homework.php?id=<?php echo $assignment_id; ?>" method="POST">
                                <input type="hidden" name="submission_id" value="<?php echo $sub['id']; ?>">
                                <div data-label="Grade" style="flex:1;"><input type="text" name="grade" value="<?php echo htmlspecialchars($sub['grade'] ?? ''); ?>" placeholder="e.g., 85/100"></div>
                                <div data-label="Feedback" style="flex:2;"><textarea name="feedback" rows="1"><?php echo htmlspecialchars($sub['feedback'] ?? ''); ?></textarea></div>
                                <div data-label="Action"><button type="submit" name="save_grade" class="btn-sm">Save</button></div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
