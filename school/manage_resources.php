<?php 
require_once 'layout_header.php';

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
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

$upload_dir = '../uploads/';
$message = ''; $error = '';

if (isset($_GET['delete'])) {
    $id_to_delete = intval($_GET['delete']);
    $stmt_file = $conn->prepare("SELECT file_path FROM class_materials cm JOIN courses c ON cm.course_id = c.id WHERE cm.id = ? AND c.school_id = ?");
    $stmt_file->bind_param("ii", $id_to_delete, $school_id);
    $stmt_file->execute();
    $result_file = $stmt_file->get_result();
    if ($result_file->num_rows > 0) {
        $row = $result_file->fetch_assoc();
        if (!empty($row['file_path']) && file_exists($upload_dir . $row['file_path'])) {
            unlink($upload_dir . $row['file_path']);
        }
    }
    $stmt_file->close();
    $stmt_del = $conn->prepare("DELETE FROM class_materials WHERE id = ? AND course_id = ?");
    $stmt_del->bind_param("ii", $id_to_delete, $course_id);
    if ($stmt_del->execute() && $stmt_del->affected_rows > 0) { $message = "Resource deleted successfully!"; } 
    else { $error = "Failed to delete resource."; }
    $stmt_del->close();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_resource'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $live_class_link = trim($_POST['live_class_link']);
    $recording_link = trim($_POST['recording_link']);
    $file_path = null;
    if (empty($title)) { $error = "Title is a required field."; } 
    else {
        if (isset($_FILES['resource_file']) && $_FILES['resource_file']['error'] == 0) {
            $file_name = time() . '_' . basename($_FILES['resource_file']['name']);
            $target_file = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['resource_file']['tmp_name'], $target_file)) {
                $file_path = $file_name;
            } else { $error = "File upload failed. Please check server permissions on the 'uploads' folder."; }
        }
        if (empty($error)) {
            $stmt = $conn->prepare("INSERT INTO class_materials (school_id, course_id, title, description, file_path, live_class_link, recording_link) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisssss", $school_id, $course_id, $title, $description, $file_path, $live_class_link, $recording_link);
            if ($stmt->execute()) { $message = "Resource added successfully!"; } 
            else { $error = "Failed to add resource to the database."; }
            $stmt->close();
        }
    }
}

$resources = [];
$stmt_res = $conn->prepare("SELECT * FROM class_materials WHERE course_id = ? ORDER BY upload_date DESC");
$stmt_res->bind_param("i", $course_id);
$stmt_res->execute();
$result_res = $stmt_res->get_result();
if ($result_res) { while ($row = $result_res->fetch_assoc()) { $resources[] = $row; } }
$stmt_res->close();
$course_title_stmt = $conn->prepare("SELECT title FROM courses WHERE id = ?"); $course_title_stmt->bind_param("i", $course_id); $course_title_stmt->execute();
$course_title = $course_title_stmt->get_result()->fetch_assoc()['title']; $course_title_stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .page-subtitle { font-size: 16px; color: var(--text-secondary-color); margin-top: -5px; margin-bottom: 30px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; margin-bottom: 30px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px;}
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .full-width { grid-column: 1 / -1; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    .form-group input, .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 5px; box-sizing: border-box; background-color: var(--bg-color); color: var(--text-color); font-family: 'Poppins', sans-serif; font-size: 16px; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; cursor: pointer; }
    .resource-card { border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; margin-bottom: 15px; }
    .resource-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;}
    .resource-header h3 { margin: 0 0 5px 0; }
    .resource-header a { color:#dc3545; text-decoration:none; font-weight:500; }
    .resource-meta { font-size: 12px; color: var(--text-secondary-color); margin: 0; }
    .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
    .message { color: #155724; background-color: #d4edda; } .error { color: #721c24; background-color: #f8d7da; }
    @media (max-width: 768px) { .page-header h1 { font-size: 24px; } .form-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-header"><h1>Manage Resources</h1></div>
<p class="page-subtitle">For course: <strong><?php echo htmlspecialchars($course_title); ?></strong></p>
<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<div class="card">
    <h2>Add New Resource / Lesson</h2>
    <form action="manage_resources.php?course_id=<?php echo $course_id; ?>" method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group full-width"><label for="title">Title</label><input type="text" id="title" name="title" required></div>
            <div class="form-group full-width"><label for="description">Description</label><textarea id="description" name="description" rows="3"></textarea></div>
            <div class="form-group"><label>Live Class Link</label><input type="url" name="live_class_link" placeholder="https://..."></div>
            <div class="form-group"><label>Recording Link</label><input type="url" name="recording_link" placeholder="https://..."></div>
            <div class="form-group full-width"><label>Supporting File</label><input type="file" name="resource_file"></div>
        </div><br>
        <button type="submit" name="add_resource" class="btn">Add Resource</button>
    </form>
</div>
<div class="card">
    <h2>Uploaded Resources</h2>
    <?php if(empty($resources)): ?>
        <p>No resources have been uploaded for this course yet.</p>
    <?php else: foreach($resources as $res): ?>
        <div class="resource-card">
            <div class="resource-header">
                <div>
                    <h3><?php echo htmlspecialchars($res['title']); ?></h3>
                    <p class="resource-meta">Uploaded on: <?php echo date("D, j M Y", strtotime($res['upload_date'])); ?></p>
                </div>
                <a href="manage_resources.php?course_id=<?php echo $course_id; ?>&delete=<?php echo $res['id']; ?>" onclick="return confirm('Are you sure?');">Delete</a>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
