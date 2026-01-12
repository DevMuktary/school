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

$message = ''; $error = '';
$class_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_attendance'])) {
    $statuses = $_POST['status'] ?? [];
    $date_to_save = $_POST['class_date'];

    if(!empty($statuses)) {
        $stmt = $conn->prepare("INSERT INTO attendance (student_id, school_id, course_id, class_date, status) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)");
        $success_count = 0;
        foreach ($statuses as $student_id => $status) {
            $sid = intval($student_id);
            $stmt->bind_param("iiiss", $sid, $school_id, $course_id, $date_to_save, $status);
            if ($stmt->execute()) { $success_count++; }
        }
        $stmt->close();
        if ($success_count > 0) {
            $redirect_url = "manage_attendance.php?date=$date_to_save&status=saved" . ($is_instructor ? "&course_id=$course_id" : "");
            header("Location: " . $redirect_url);
            exit();
        } else {
            $error = "Could not save attendance records.";
        }
    } else {
        $error = "No student attendance data was submitted.";
    }
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

if (isset($_GET['status']) && $_GET['status'] == 'saved') {
     $message = "Attendance for " . date("F j, Y", strtotime($class_date)) . " saved successfully!";
}

// Fetch students ENROLLED in the selected course
$students = [];
$student_sql = "SELECT u.id, u.full_name_eng FROM users u JOIN enrollments e ON u.id = e.student_id WHERE u.school_id = ? AND e.course_id = ? AND u.role = 'student' ORDER BY u.full_name_eng ASC";
$student_stmt = $conn->prepare($student_sql);
$student_stmt->bind_param("ii", $school_id, $course_id);
$student_stmt->execute();
$student_result = $student_stmt->get_result();
if ($student_result) { while ($row = $student_result->fetch_assoc()) { $students[] = $row; } }
$student_stmt->close();

// Fetch existing attendance for the selected date AND course
$attendance_records = [];
$att_stmt = $conn->prepare("SELECT student_id, status FROM attendance WHERE class_date = ? AND course_id = ? AND school_id = ?");
$att_stmt->bind_param("sii", $class_date, $course_id, $school_id);
$att_stmt->execute();
$attendance_result = $att_stmt->get_result();
if ($attendance_result) { while ($row = $attendance_result->fetch_assoc()) { $attendance_records[$row['student_id']] = $row['status']; } }
$att_stmt->close();

$course_title_stmt = $conn->prepare("SELECT title FROM courses WHERE id = ?"); $course_title_stmt->bind_param("i", $course_id); $course_title_stmt->execute();
$course_title = $course_title_stmt->get_result()->fetch_assoc()['title']; $course_title_stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .page-subtitle { font-size: 16px; color: var(--text-muted); margin-top: -5px; margin-bottom: 30px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; }
    .date-selector { display: flex; align-items: center; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; }
    .date-selector label { font-weight: 600; }
    .date-selector input[type="date"] { padding: 10px; border: 1px solid var(--border-color); border-radius: 5px; background-color: var(--bg-color); color: var(--text-color); font-family: 'Poppins'; font-size: 16px; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    .student-name { font-weight: 500; }
    .status-radios label { margin-right: 20px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;}
    .status-radios input { cursor: pointer; width: 18px; height: 18px; }
    .btn { display: block; width: 100%; text-align: center; margin-top: 20px; box-sizing: border-box; padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; cursor: pointer; font-weight: 600; }
    .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
    .message { color: #155724; background-color: #d4edda; } .error { color: #721c24; background-color: #f8d7da; }
</style>

<div class="page-header">
    <h1>Mark Attendance</h1>
</div>
<p class="page-subtitle">For course: <strong><?php echo htmlspecialchars($course_title); ?></strong></p>

<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<div class="card">
    <form action="manage_attendance.php" method="GET" class="date-selector">
        <?php if($is_instructor): ?><input type="hidden" name="course_id" value="<?php echo $course_id; ?>"><?php endif; ?>
        <label for="date">Select Class Date:</label>
        <input type="date" name="date" value="<?php echo $class_date; ?>" onchange="this.form.submit()">
    </form>
    <div class="table-wrapper">
        <form action="manage_attendance.php?course_id=<?php echo $course_id; ?>&date=<?php echo $class_date; ?>" method="POST">
            <input type="hidden" name="class_date" value="<?php echo $class_date; ?>">
            <table>
                <thead><tr><th>Student Name</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if(empty($students)): ?>
                        <tr><td colspan="2" style="text-align:center; padding: 20px;">No students are enrolled in this course.</td></tr>
                    <?php else: foreach($students as $student): 
                            $current_status = $attendance_records[$student['id']] ?? '';
                        ?>
                            <tr>
                                <td class="student-name"><?php echo htmlspecialchars($student['full_name_eng']); ?></td>
                                <td class="status-radios">
                                    <label><input type="radio" name="status[<?php echo $student['id']; ?>]" value="Present" <?php if($current_status == 'Present') echo 'checked'; ?> required> Present</label>
                                    <label><input type="radio" name="status[<?php echo $student['id']; ?>]" value="Absent" <?php if($current_status == 'Absent') echo 'checked'; ?> required> Absent</label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if(!empty($students)): ?>
                <button type="submit" name="save_attendance" class="btn">Save Attendance</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
