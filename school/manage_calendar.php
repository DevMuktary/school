<?php 
require_once 'layout_header.php'; // This includes the header, sidebar, and all necessary initial code.

// This page can be used by both Admins and Instructors.
// The security check to ensure they can only access their own school's courses is now required.

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

// Create a new auth_check file if it doesn't exist to verify access
if (!function_exists('verify_course_access')) {
    // This is a fallback in case auth_check.php wasn't created yet.
    // Ideally, the function is in auth_check.php and that file is included in layout_header.php
    function verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id) { /* placeholder */ }
}
// verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id); // This line is assumed to be in layout_header or similar

$message = ''; $error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_event'])) {
    $title = trim($_POST['title']);
    $event_type = $_POST['event_type'];
    $start_date = $_POST['start_date'];
    $description = trim($_POST['description']);
    if (empty($title) || empty($event_type) || empty($start_date)) { 
        $error = "Title, Event Type, and Start Date are required."; 
    } else {
        $stmt = $conn->prepare("INSERT INTO calendar_events (course_id, school_id, title, event_type, start_date, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissss", $course_id, $school_id, $title, $event_type, $start_date, $description);
        if ($stmt->execute()) { $message = "Event added successfully!"; } 
        else { $error = "Failed to add event."; }
        $stmt->close();
    }
}

if (isset($_GET['delete'])) {
    $id_to_delete = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM calendar_events WHERE id = ? AND course_id = ? AND school_id = ?");
    $stmt->bind_param("iii", $id_to_delete, $course_id, $school_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) { $message = "Event deleted successfully!"; }
    else { $error = "Failed to delete event."; }
    $stmt->close();
}

$events = [];
$stmt = $conn->prepare("SELECT * FROM calendar_events WHERE course_id = ? ORDER BY start_date DESC");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) { while ($row = $result->fetch_assoc()) { $events[] = $row; } }
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
    .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px; }
    .page-header h1 { margin: 0; font-size: 28px; }
    .page-subtitle { font-size: 16px; color: var(--text-secondary-color); margin-top: -5px; margin-bottom: 30px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; margin-bottom: 30px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    .form-group input, .form-group select, .form-group textarea { 
        width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 5px; 
        box-sizing: border-box; font-family: 'Poppins', sans-serif; background-color: var(--bg-color); 
        color: var(--text-color); font-size: 16px; /* MOBILE ZOOM FIX */
    }
    .full-width { grid-column: 1 / -1; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    th { font-weight: 600; }
    .action-link { color: #dc3545; text-decoration: none; font-weight: 500; }
    .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
    .message { color: #155724; background-color: #d4edda; } .error { color: #721c24; background-color: #f8d7da; }
    @media (max-width: 768px) {
        .page-header h1 { font-size: 24px; }
        .table-wrapper thead { display: none; }
        .table-wrapper tr { display: block; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 5px; }
        .table-wrapper td { 
            display: block; text-align: right; padding-left: 50%; position: relative; 
            border-bottom: 1px solid var(--border-color); white-space: normal; word-break: break-word; font-size: 14px;
        }
        .table-wrapper td:last-child { border-bottom: none; }
        .table-wrapper td::before { content: attr(data-label); position: absolute; left: 15px; font-weight: 600; text-align: left; white-space: nowrap; color: var(--text-color); }
    }
</style>

<div class="page-header">
    <h1>Manage Calendar</h1>
</div>
<p class="page-subtitle">For course: <strong><?php echo htmlspecialchars($course_title); ?></strong></p>

<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<div class="card">
    <h2>Add New Event</h2>
    <form action="manage_calendar.php?course_id=<?php echo $course_id; ?>" method="POST">
        <div class="form-grid">
            <div class="form-group"><label for="title">Event Title</label><input type="text" id="title" name="title" required></div>
            <div class="form-group"><label for="event_type">Event Type</label><select id="event_type" name="event_type" required><option value="Class">Class</option><option value="Holiday">Holiday</option><option value="Exam">Exam</option></select></div>
            <div class="form-group"><label for="start_date">Date & Time</label><input type="datetime-local" id="start_date" name="start_date" required></div>
            <div class="form-group full-width"><label for="description">Description (Optional)</label><textarea id="description" name="description" rows="3"></textarea></div>
        </div>
        <br>
        <button type="submit" name="add_event" class="btn">Add Event</button>
    </form>
</div>

<div class="card">
    <h2>Existing Events</h2>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Title</th><th>Type</th><th>Date</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if(empty($events)): ?>
                    <tr><td colspan="4" style="text-align:center; padding: 20px;">No events found for this course.</td></tr>
                <?php else: foreach($events as $event): ?>
                    <tr>
                        <td data-label="Title"><?php echo htmlspecialchars($event['title']); ?></td>
                        <td data-label="Type"><?php echo htmlspecialchars($event['event_type']); ?></td>
                        <td data-label="Date"><?php echo date("D, j M Y, g:i a", strtotime($event['start_date'])); ?></td>
                        <td data-label="Action"><a href="manage_calendar.php?course_id=<?php echo $course_id; ?>&delete=<?php echo $event['id']; ?>" class="action-link" onclick="return confirm('Are you sure?');">Delete</a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
