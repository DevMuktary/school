<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

// This is a School Admin-only page.
if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

$message = ''; $error = '';

// Handle actions like delete or toggle status, which cause a redirect
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    if ($_GET['action'] == 'toggle') {
        $status = intval($_GET['status']);
        $stmt = $conn->prepare("UPDATE announcements SET is_active = ? WHERE id = ? AND school_id = ?");
        $stmt->bind_param("iii", $status, $id, $school_id);
        $stmt->execute();
        $stmt->close();
    } elseif ($_GET['action'] == 'delete') {
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ? AND school_id = ?");
        $stmt->bind_param("ii", $id, $school_id);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: manage_announcements.php');
    exit();
}

// Handle form submission to add a new announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_announcement'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    if (!empty($title) && !empty($content)) {
        $stmt = $conn->prepare("INSERT INTO announcements (school_id, title, content) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $school_id, $title, $content);
        if ($stmt->execute()) { $message = "Announcement posted successfully!"; }
        else { $error = "Failed to post announcement."; }
        $stmt->close();
    } else { $error = "Title and content are required."; }
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

// --- SECURITY FIX: Fetch all announcements for this school using a prepared statement ---
$announcements = [];
$stmt = $conn->prepare("SELECT * FROM announcements WHERE school_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) { while($row = $result->fetch_assoc()){ $announcements[] = $row; } }
$stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; margin-bottom: 25px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    .form-group input, .form-group textarea { 
        width: 100%; padding: 12px; box-sizing: border-box; border-radius: 5px; 
        border: 1px solid var(--border-color); background-color: var(--bg-color); 
        color: var(--text-color); font-size: 16px; /* MOBILE ZOOM FIX */
    }
    .btn { padding: 10px 20px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; cursor: pointer; font-size: 16px; font-weight: 600;}
    .message, .error { padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
    .error { color: #D8000C; background-color: #FFD2D2; } .message { color: #155724; background-color: #d4edda; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    th { font-weight: 600; }
    .status-active { color: #155724; font-weight: bold; }
    .status-inactive { color: #721c24; }
    .actions a { text-decoration: none; font-weight: 500; margin-right: 15px; }
    .actions a.delete { color: #dc3545; }

    @media (max-width: 768px) {
        .table-wrapper thead { display: none; }
        .table-wrapper tr { display: block; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 5px; }
        .table-wrapper td { display: block; text-align: right; padding-left: 50%; position: relative; border-bottom: 1px solid var(--border-color); white-space: normal; word-break: break-word; font-size: 14px; }
        .table-wrapper td:last-child { border-bottom: none; }
        .table-wrapper td::before { content: attr(data-label); position: absolute; left: 15px; font-weight: 600; text-align: left; }
    }
</style>

<div class="page-header">
    <h1>Manage Announcements</h1>
</div>

<?php if($message): ?><p class="message"><?php echo $message; ?></p><?php endif; ?>
<?php if($error): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>

<div class="card">
    <h2>Post a New Announcement</h2>
    <form action="manage_announcements.php" method="POST">
        <div class="form-group"><label for="title">Title</label><input type="text" name="title" id="title" required></div>
        <div class="form-group"><label for="content">Content</label><textarea name="content" id="content" rows="4" required></textarea></div>
        <button type="submit" name="add_announcement" class="btn">Post Announcement</button>
    </form>
</div>

<div class="card">
    <h2>Posted Announcements</h2>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Title</th><th>Content</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php if(empty($announcements)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 20px;">No announcements have been posted yet.</td></tr>
                <?php else: foreach($announcements as $item): ?>
                <tr>
                    <td data-label="Title"><?php echo htmlspecialchars($item['title']); ?></td>
                    <td data-label="Content" style="white-space: normal; min-width: 250px;"><?php echo htmlspecialchars(substr($item['content'], 0, 100)) . (strlen($item['content']) > 100 ? '...' : ''); ?></td>
                    <td data-label="Status">
                        <?php if($item['is_active']): ?>
                            <span class="status-active">Active</span>
                        <?php else: ?>
                            <span class="status-inactive">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions" class="actions">
                        <?php if ($item['is_active']): ?>
                            <a href="?action=toggle&id=<?php echo $item['id']; ?>&status=0">Deactivate</a>
                        <?php else: ?>
                            <a href="?action=toggle&id=<?php echo $item['id']; ?>&status=1">Activate</a>
                        <?php endif; ?>
                        <a href="?action=delete&id=<?php echo $item['id']; ?>" class="delete" onclick="return confirm('Are you sure?');">Delete</a>
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
