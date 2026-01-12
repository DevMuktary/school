<?php 
require_once 'layout_header.php';

$course_id = 0;
if ($is_admin) { $course_id = $_SESSION['selected_course_id'] ?? 0; } 
// For instructors, we will show submissions from ALL their assigned courses
// So we don't need a specific course_id from the URL for them

if ($course_id == 0 && $is_admin) {
    header("Location: dashboard.php?error=no_course_selected"); 
    exit();
}

// Fetch submissions based on role
$submissions = [];
$sql = "SELECT s.id, s.score, s.end_time, u.full_name_eng, q.title as quiz_title, q.id as quiz_id, q.course_id
        FROM quiz_submissions s 
        JOIN users u ON s.student_id = u.id 
        JOIN quizzes q ON s.quiz_id = q.id";

if ($is_instructor) {
    // Instructor sees submissions from all courses they are assigned to
    $sql .= " JOIN course_assignments ca ON q.course_id = ca.course_id WHERE ca.instructor_id = ? AND s.school_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $school_id);
} else { // Admin sees submissions for the selected course
    $sql .= " WHERE q.course_id = ? AND s.school_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $course_id, $school_id);
}

$stmt->execute();
$result = $stmt->get_result();
if($result) { while ($row = $result->fetch_assoc()) { $submissions[] = $row; } }
$stmt->close();

$page_subtitle = '';
if ($is_admin && $course_id > 0) {
    $course_title_stmt = $conn->prepare("SELECT title FROM courses WHERE id = ?"); 
    $course_title_stmt->bind_param("i", $course_id); 
    $course_title_stmt->execute();
    $course_title = $course_title_stmt->get_result()->fetch_assoc()['title']; 
    $course_title_stmt->close();
    $page_subtitle = 'Showing all submissions for course: <strong>' . htmlspecialchars($course_title) . '</strong>';
} else {
    $page_subtitle = 'Showing all submissions from your assigned courses.';
}
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .page-subtitle { font-size: 16px; color: var(--text-muted); margin-top: -5px; margin-bottom: 30px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 5px; border-radius: 8px; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    .btn { padding: 8px 15px; border-radius: 5px; color: white; text-decoration: none; font-weight: 500; background-color: var(--brand-primary); }
    @media (max-width: 992px) {
        .table-wrapper thead { display: none; }
        .table-wrapper tr { display: block; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 5px; }
        .table-wrapper td { display: block; text-align: right; padding-left: 50%; position: relative; border-bottom: 1px solid var(--border-color); white-space: normal; word-break: break-word; }
        .table-wrapper td:last-child { border-bottom: none; }
        .table-wrapper td::before { content: attr(data-label); position: absolute; left: 15px; font-weight: 600; text-align: left; }
    }
</style>

<div class="page-header">
    <h1>All Submissions</h1>
</div>
<p class="page-subtitle"><?php echo $page_subtitle; ?></p>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Assessment</th>
                    <th>Submitted On</th>
                    <th>Score</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($submissions)): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 40px;">No submissions found.</td></tr>
                <?php else: foreach($submissions as $sub): ?>
                    <tr>
                        <td data-label="Student"><?php echo htmlspecialchars($sub['full_name_eng']); ?></td>
                        <td data-label="Assessment"><?php echo htmlspecialchars($sub['quiz_title']); ?></td>
                        <td data-label="Submitted On"><?php echo date("j M Y, g:i a", strtotime($sub['end_time'])); ?></td>
                        <td data-label="Score">
                            <?php if(is_null($sub['score'])): ?>
                                <span style="color:#ffc107; font-weight:bold;">Pending Grade</span>
                            <?php else: ?>
                                <span style="font-weight:bold;"><?php echo $sub['score']; ?>%</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Action">
                            <a href="grade_submission.php?id=<?php echo $sub['id']; ?>&course_id=<?php echo $sub['course_id']; ?>" class="btn">
                                <?php echo (is_null($sub['score'])) ? 'Grade' : 'View'; ?>
                            </a>
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
