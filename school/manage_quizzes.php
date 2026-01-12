<?php 
require_once 'layout_header.php'; // Includes the new header, sidebar, and all CSS

// Determine the course ID based on the user's role
$course_id = 0;
if ($is_admin) {
    $course_id = $_SESSION['selected_course_id'] ?? 0;
} elseif ($is_instructor) {
    $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
}
if ($course_id == 0) {
    $redirect_url = $is_admin ? 'dashboard.php' : 'instructor_dashboard.php';
    header("Location: $redirect_url?error=no_course"); 
    exit();
}
// Verify the user has permission to access this course
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

$quizzes = [];
$sql = "SELECT q.*, 
            (SELECT COUNT(id) FROM quiz_questions WHERE quiz_id = q.id) as question_count,
            (SELECT COUNT(id) FROM quiz_submissions WHERE quiz_id = q.id) as submission_count
        FROM quizzes q 
        WHERE q.course_id = ? AND q.school_id = ?
        ORDER BY q.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $course_id, $school_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) { while ($row = $result->fetch_assoc()) { $quizzes[] = $row; } }
$stmt->close();

$course_title_stmt = $conn->prepare("SELECT title FROM courses WHERE id = ?");
$course_title_stmt->bind_param("i", $course_id);
$course_title_stmt->execute();
$course_title = $course_title_stmt->get_result()->fetch_assoc()['title'];
$course_title_stmt->close();
$conn->close();
?>
<style>
    /* Page-specific styles */
    .page-header { display:flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;}
    .page-header h1 { margin: 0; font-size: 28px; }
    .page-subtitle { font-size: 16px; color: var(--text-muted); margin-top: 5px; }
    .btn { padding: 10px 20px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 500; cursor: pointer; text-decoration: none; }
    
    .assessments-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
    .assessment-card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; display: flex; flex-direction: column; overflow: hidden; }
    .assessment-card-body { padding: 25px; flex-grow: 1; }
    .assessment-card h3 { margin-top: 0; margin-bottom: 15px; font-size: 18px; word-break: break-word; }
    .assessment-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); }
    .stat-item .label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; }
    .stat-item .value { font-size: 16px; font-weight: 600; }
    .status-badge { font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 12px; display: inline-block;}
    
    /* ===== CHANGED/ADDED: CSS for all status types ===== */
    .status-badge.pending { background-color: #fff3cd; color: #856404; } /* Lowercase for consistency */
    .status-badge.released { background-color: #d4edda; color: #155724; }
    .status-badge.live { background-color: #d4edda; color: #155724; border: 1px solid #155724; }
    .status-badge.scheduled { background-color: #cce5ff; color: #004085; }
    .status-badge.finished { background-color: #f8d7da; color: #721c24; }
    .status-badge.open { background-color: #e2e3e5; color: #383d41; }
    
    .assessment-card-footer { background-color: var(--bg-color); padding: 15px 25px; display: flex; gap: 10px; border-top: 1px solid var(--border-color); }
    .btn-sm { padding: 8px 15px; font-size: 14px; font-weight: 500; border-radius: 5px; text-decoration: none; text-align: center; border: 1px solid transparent; }
    .btn-edit { background-color: var(--brand-primary); color: white; }
    .btn-view { color: var(--text-color); background-color: var(--card-bg-color); border-color: var(--border-color); }
</style>

<div class="page-header">
    <div>
        <h1 dir="auto">Manage Assessments</h1>
        <p class="page-subtitle" dir="auto">For course: <strong><?php echo htmlspecialchars($course_title); ?></strong></p>
    </div>
    <a href="create_quiz.php?course_id=<?php echo $course_id; ?>" class="btn">＋ Create New</a>
</div>

<div class="assessments-grid">
    <?php if(empty($quizzes)): ?>
        <p>No assessments have been created for this course yet.</p>
    <?php else: foreach($quizzes as $quiz): ?>
        <?php
            // ===== ADDED: Dynamic availability status logic =====
            $availability_status = 'Open';
            $availability_class = 'open';
            $now = new DateTime();
            
            $from_time = $quiz['available_from'] ? new DateTime($quiz['available_from']) : null;
            $to_time = $quiz['available_to'] ? new DateTime($quiz['available_to']) : null;

            if ($from_time && $to_time) {
                if ($now < $from_time) {
                    $availability_status = 'Scheduled';
                    $availability_class = 'scheduled';
                } elseif ($now > $to_time) {
                    $availability_status = 'Finished';
                    $availability_class = 'finished';
                } else {
                    $availability_status = 'Live';
                    $availability_class = 'live';
                }
            } elseif ($from_time) {
                if ($now < $from_time) {
                    $availability_status = 'Scheduled';
                    $availability_class = 'scheduled';
                } else {
                    $availability_status = 'Live';
                    $availability_class = 'live';
                }
            } elseif ($to_time) {
                if ($now > $to_time) {
                    $availability_status = 'Finished';
                    $availability_class = 'finished';
                } else {
                    $availability_status = 'Live';
                    $availability_class = 'live';
                }
            }
        ?>
        <div class="assessment-card">
            <div class="assessment-card-body">
                <h3 dir="auto"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                <div class="assessment-stats">
                    <div class="stat-item"><span class="label">Type</span><span class="value"><?php echo htmlspecialchars($quiz['type']); ?></span></div>
                    <div class="stat-item"><span class="label">Questions</span><span class="value"><?php echo $quiz['question_count']; ?></span></div>
                    <div class="stat-item"><span class="label">Submissions</span><span class="value"><?php echo $quiz['submission_count']; ?></span></div>
                    
                    <div class="stat-item">
                        <span class="label">Availability</span>
                        <span class="value">
                            <span class="status-badge <?php echo $availability_class; ?>"><?php echo $availability_status; ?></span>
                        </span>
                    </div>

                    <div class="stat-item">
                        <span class="label">Result Status</span>
                        <span class="value">
                            <span class="status-badge <?php echo strtolower($quiz['result_status']); ?>">
                                <?php echo htmlspecialchars($quiz['result_status']); ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>
            <div class="assessment-card-footer">
                <a href="edit_quiz.php?id=<?php echo $quiz['id']; ?>&course_id=<?php echo $course_id; ?>" class="btn-sm btn-edit">Edit</a>
                <a href="view_submissions.php?id=<?php echo $quiz['id']; ?>&course_id=<?php echo $course_id; ?>" class="btn-sm btn-view">Submissions</a>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
