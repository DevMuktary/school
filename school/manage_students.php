<?php 
require_once 'layout_header.php'; // Includes the header, sidebar, and session start

// This is a School Admin-only page.
if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

// Use the course ID from the session, redirect if none is selected
$course_id = $_SESSION['selected_course_id'] ?? 0;
if ($course_id == 0) {
    header("Location: dashboard.php?error=no_course_selected"); 
    exit();
}
// You could optionally add a verify_course_access call here if you have one

$message = ''; $error = '';

// Handle deleting a student
if (isset($_GET['delete'])) {
    $id_to_delete = intval($_GET['delete']);
    // Security check: Only delete students from the admin's school
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND school_id = ? AND role = 'student'");
    $stmt->bind_param("ii", $id_to_delete, $school_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) { $message = "Student record deleted successfully!"; }
    else { $error = "Failed to delete student record."; }
    $stmt->close();
}

// --- MODIFIED: Fetch students ENROLLED in the selected course ---
$students = [];
$stmt = $conn->prepare("
    SELECT u.id, u.full_name_eng, u.email, u.phone_number, u.level, u.reg_date 
    FROM users u
    JOIN enrollments e ON u.id = e.student_id
    WHERE u.school_id = ? AND e.course_id = ? AND u.role = 'student'
    ORDER BY u.full_name_eng ASC
");
$stmt->bind_param("ii", $school_id, $course_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) { while ($row = $result->fetch_assoc()) { $students[] = $row; } }
$stmt->close();

// Fetch course title for the subtitle
$course_title_stmt = $conn->prepare("SELECT title FROM courses WHERE id = ?"); 
$course_title_stmt->bind_param("i", $course_id); 
$course_title_stmt->execute();
$course_title = $course_title_stmt->get_result()->fetch_assoc()['title']; 
$course_title_stmt->close();

$conn->close();
?>
<style>
    /* Page-specific styles */
    .page-header h1 { margin: 0; font-size: 28px; }
    .page-subtitle { font-size: 16px; color: var(--text-secondary-color); margin-top: -5px; margin-bottom: 30px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 5px; border-radius: 8px; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    th { font-weight: 600; }
    .action-link { color: #dc3545; text-decoration: none; font-weight: 500; }
    .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
    .message { color: #155724; background-color: #d4edda; }
    .error { color: #721c24; background-color: #f8d7da; }

    /* --- MOBILE-FIT TABLE STYLES --- */
    @media (max-width: 992px) {
        .table-wrapper thead { display: none; }
        .table-wrapper tr { 
            display: block; 
            margin-bottom: 15px; 
            border: 1px solid var(--border-color); 
            border-radius: 5px; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); 
        }
        .table-wrapper td { 
            display: block; 
            text-align: right; 
            padding-left: 50%; 
            position: relative; 
            border-bottom: 1px solid var(--border-color);
            white-space: normal;
            word-break: break-word;
            font-size: 14px;
        }
        .table-wrapper td:last-child { border-bottom: none; }
        .table-wrapper td::before {
            content: attr(data-label);
            position: absolute;
            left: 15px;
            width: calc(50% - 30px);
            font-weight: 600;
            text-align: left;
            white-space: nowrap;
            color: var(--text-color);
        }
    }
</style>

<div class="page-header">
    <h1>Manage Students</h1>
</div>
<p class="page-subtitle">For course: <strong><?php echo htmlspecialchars($course_title); ?></strong></p>

<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Phone Number</th>
                    <th>Level</th>
                    <th>Registered</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($students)): ?>
                    <tr><td colspan="6" style="text-align:center; padding: 40px;">No students are enrolled in this course.</td></tr>
                <?php else: foreach($students as $student): ?>
                    <tr>
                        <td data-label="Name"><?php echo htmlspecialchars($student['full_name_eng']); ?></td>
                        <td data-label="Email"><?php echo htmlspecialchars($student['email']); ?></td>
                        <td data-label="Phone"><?php echo htmlspecialchars($student['phone_number']); ?></td>
                        <td data-label="Level"><?php echo htmlspecialchars($student['level']); ?></td>
                        <td data-label="Registered"><?php echo date("j M Y", strtotime($student['reg_date'])); ?></td>
                        <td data-label="Action"><a href="manage_students.php?delete=<?php echo $student['id']; ?>" class="action-link" onclick="return confirm('Are you sure? This will delete the student and all their data permanently.');">Delete</a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
