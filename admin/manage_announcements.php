<?php
require_once '../db_connect.php';
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit(); }
$message = ''; $error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_announcement'])) {
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        if (!empty($title) && !empty($content)) {
            $stmt = $conn->prepare("INSERT INTO announcements (title, content) VALUES (?, ?)");
            $stmt->bind_param("ss", $title, $content);
            if ($stmt->execute()) { $message = "Announcement posted successfully!"; }
            $stmt->close();
        } else { $error = "Title and content are required."; }
    }
}

// Handle actions like delete or toggle status
if (isset($_GET['action'])) {
    $id = intval($_GET['id']);
    if ($_GET['action'] == 'toggle') {
        $status = intval($_GET['status']);
        $stmt = $conn->prepare("UPDATE announcements SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $status, $id);
        $stmt->execute();
    } elseif ($_GET['action'] == 'delete') {
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    header('Location: manage_announcements.php');
    exit();
}

$announcements = [];
$result = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC");
if ($result) { while($row = $result->fetch_assoc()){ $announcements[] = $row; } }
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Announcements - Admin</title>
    <style>
        /* Re-using styles from other admin pages */
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        body { font-family: 'Poppins', sans-serif; background-color: #f7f9fc; margin: 0; }
        .header { background-color: <?php echo BRAND_COLOR_BLUE; ?>; color: #FFFFFF; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .logo { font-size: 24px; font-weight: 700; color: #FFFFFF; }
        .logo span { color: #feca57; }
        .container { max-width: 1000px; margin: 0 auto; padding: 25px 15px; }
        .card { background-color: #FFFFFF; border-radius: 8px; padding: 25px; margin-bottom: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .card h2 { margin-top: 0; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input, .form-group textarea { width: 100%; padding: 10px; box-sizing: border-box; border-radius: 5px; border: 1px solid #ccc; font-family: 'Poppins', sans-serif; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; background-color: <?php echo BRAND_COLOR_BLUE; ?>; color: white; cursor: pointer; transition: background-color 0.3s ease; }
        .btn:hover { background-color: #0d47a1; }
        
        /* Table styles for desktop */
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #f1f3f5; font-weight: 600; }
        
        /* Mobile-specific styles */
        @media (max-width: 768px) {
            .header { padding: 15px; }
            .header a { margin-top: 10px; }
            .container { padding: 15px; }
            .card { padding: 15px; }
            
            /* Responsive table behavior */
            table, thead, tbody, th, td, tr { display: block; }
            thead tr { display: none; } /* Hide the table header on small screens */
            tr { margin-bottom: 15px; border: 1px solid #ddd; border-radius: 8px; }
            td { text-align: right; border-bottom: 1px solid #eee; position: relative; padding-left: 50%; }
            td:last-child { border-bottom: none; }
            
            td::before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                width: calc(50% - 30px);
                text-align: left;
                font-weight: 600;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo"><?php echo SCHOOL_NAME; ?><span>.</span></div>
        <a href="dashboard.php" style="color:white; text-decoration:none;">← Back to Dashboard</a>
    </header>
    <div class="container">
        <h1>Manage Announcements</h1>
        <div class="card">
            <h2>Post a New Announcement</h2>
            <form method="POST">
                <div class="form-group"><label for="title">Title</label><input type="text" name="title" required></div>
                <div class="form-group"><label for="content">Content</label><textarea name="content" rows="4" required></textarea></div>
                <button type="submit" name="add_announcement" class="btn">Post Announcement</button>
            </form>
        </div>
        <div class="card">
            <h2>Posted Announcements</h2>
            <table>
                <thead><tr><th>Title</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach($announcements as $item): ?>
                    <tr>
                        <td data-label="Title"><?php echo htmlspecialchars($item['title']); ?></td>
                        <td data-label="Status"><?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?></td>
                        <td data-label="Actions" style="text-align: left;">
                            <?php if ($item['is_active']): ?>
                                <a href="?action=toggle&id=<?php echo $item['id']; ?>&status=0">Deactivate</a>
                            <?php else: ?>
                                <a href="?action=toggle&id=<?php echo $item['id']; ?>&status=1">Activate</a>
                            <?php endif; ?>
                            | <a href="?action=delete&id=<?php echo $item['id']; ?>" onclick="return confirm('Are you sure?');" style="color:red;">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
