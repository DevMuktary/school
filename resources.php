<?php
require_once 'db_connect.php'; // This also starts the session

// 1. Check if the user is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: index.php');
    exit();
}

// 2. Check if a course has been selected from the dashboard
if (!isset($_SESSION['current_course_id'])) {
    header('Location: dashboard.php');
    exit();
}

// 3. Use these variables on the rest of the page
$student_id = $_SESSION['student_id'];
$current_course_id = $_SESSION['current_course_id'];

// 4. Fetch School and Current Course details for branding
$details_stmt = $conn->prepare("
    SELECT s.name as school_name, s.logo_path, s.brand_color
    FROM courses c
    JOIN schools s ON c.school_id = s.id
    WHERE c.id = ? LIMIT 1
");
$details_stmt->bind_param("i", $current_course_id);
$details_stmt->execute();
$details_data = $details_stmt->get_result()->fetch_assoc();
$school = [ 'name' => $details_data['school_name'], 'logo_path' => $details_data['logo_path'], 'brand_color' => $details_data['brand_color'] ];
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#001232';
$details_stmt->close();

// Check for Unread Messages
$unread_messages_count = 0;
$unread_stmt = $conn->prepare("SELECT COUNT(m.id) as count FROM messages m JOIN conversations c ON m.conversation_id = c.id WHERE c.student_id = ? AND m.sender_role != 'student' AND m.is_read = 0");
$unread_stmt->bind_param("i", $student_id);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
if ($unread_result) { $unread_messages_count = $unread_result->fetch_assoc()['count']; }
$unread_stmt->close();

// Fetch ALL resources for the CURRENT course using a secure prepared statement
$resources = [];
$stmt = $conn->prepare("SELECT * FROM class_materials WHERE course_id = ? ORDER BY upload_date DESC");
$stmt->bind_param("i", $current_course_id);
$stmt->execute();
$resource_result = $stmt->get_result();
if ($resource_result) { while ($row = $resource_result->fetch_assoc()) { $resources[] = $row; } }
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Resources - <?php echo htmlspecialchars($school['name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root {
            --brand-primary: <?php echo $school_brand_color; ?>;
            --brand-secondary: #FFB902;
            --bg-color: #f7f9fc; --card-bg-color: #FFFFFF; --text-color: #2c3e50;
            --text-muted: #6c757d; --border-color: #e9ecef;
        }
        body.dark-mode {
            --bg-color: #121212; --card-bg-color: #1e1e1e; --text-color: #e0e0e0;
            --text-muted: #a0a0a0; --border-color: #333;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; }
        
        .header { background-color: var(--card-bg-color); border-bottom: 1px solid var(--border-color); padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1000; }
        .header .logo img { max-height: 35px; }
        .header .logo span { font-size: 20px; font-weight: 700; color: var(--brand-primary); }
        .header-controls { display: flex; align-items: center; gap: 10px; }
        .header-btn { padding: 6px 14px; border-radius: 50px; font-weight: 500; text-decoration: none; border: 1px solid var(--border-color); color: var(--text-color); background-color: transparent; cursor: pointer; font-size: 13px; white-space: nowrap; }
        .header-btn.primary { background-color: var(--brand-primary); color: white; border-color: var(--brand-primary); }
        @media (max-width: 480px) {
            .header { padding: 10px 15px; }
            .header .logo span { font-size: 18px; }
            .header-controls { gap: 8px; }
            .header-btn { padding: 5px 12px; font-size: 12px; }
        }

        .main-container { max-width: 900px; margin: 0 auto; padding: 30px 20px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 600; }
        
        .resource-list { display: flex; flex-direction: column; gap: 20px; }
        .resource-card { background-color: var(--card-bg-color); border-radius: 8px; padding: 20px; border: 1px solid var(--border-color); }
        .resource-card .title { font-size: 18px; font-weight: 600; margin-bottom: 5px; }
        .resource-card .meta { font-size: 13px; color: var(--text-muted); margin-bottom: 15px; }
        .resource-card p { font-size: 15px; line-height: 1.6; margin-top: 0; }
        .resource-links { margin-top: 15px; display: flex; flex-wrap: wrap; gap: 10px; }
        .resource-links a { display: inline-block; padding: 8px 15px; border-radius: 5px; font-weight: 500; font-size: 14px; text-decoration: none; background-color: var(--bg-color); color: var(--text-color); border: 1px solid var(--border-color); }
        audio { width: 100%; margin-top: 15px; height: 45px; }

        .floating-chat-btn { position: fixed; bottom: 25px; right: 25px; width: 60px; height: 60px; background-color: var(--brand-primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; text-decoration: none; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); z-index: 1000; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background-color: #E74C3C; color: white; width: 24px; height: 24px; border-radius: 50%; font-size: 14px; font-weight: bold; display: flex; align-items: center; justify-content: center; border: 2px solid white; }
    </style>
</head>
<body class="">
    <header class="header">
        <div class="logo">
            <?php if (!empty($school['logo_path'])): ?>
                <img src="uploads/logos/<?php echo htmlspecialchars($school['logo_path']); ?>" alt="<?php echo htmlspecialchars($school['name']); ?> Logo">
            <?php else: ?>
                <span><?php echo htmlspecialchars($school['name']); ?></span>
            <?php endif; ?>
        </div>
        <div class="header-controls">
            <button id="theme-toggle" class="header-btn">🌙</button>
            <a href="dashboard.php" class="header-btn">Dashboard</a>
            <a href="logout.php" class="header-btn primary">Logout</a>
        </div>
    </header>

    <div class="main-container">
        <div class="page-header">
            <h1>Course Resources</h1>
        </div>
        <div class="resource-list">
            <?php if (empty($resources)): ?>
                <div class="resource-card" style="text-align: center; color: var(--text-muted);">
                    No resources have been uploaded for this course yet.
                </div>
            <?php else: foreach ($resources as $res): ?>
                <div class="resource-card">
                    <div class="title"><?php echo htmlspecialchars($res['title']); ?></div>
                    <div class="meta">Uploaded on <?php echo date("F j, Y", strtotime($res['upload_date'])); ?></div>
                    <?php if(!empty($res['description'])): ?>
                        <p><?php echo nl2br(htmlspecialchars($res['description'])); ?></p>
                    <?php endif; ?>
                    
                    <div class="resource-links">
                        <?php if(!empty($res['recording_link'])): ?>
                            <a href="<?php echo htmlspecialchars($res['recording_link']); ?>" target="_blank">📹 Watch Recording</a>
                        <?php endif; ?>
                         <?php if(!empty($res['live_class_link'])): ?>
                            <a href="<?php echo htmlspecialchars($res['live_class_link']); ?>" target="_blank">💻 Join Live Class</a>
                        <?php endif; ?>
                    </div>

                    <?php
                    if (!empty($res['file_path'])) {
                        $file_ext = strtolower(pathinfo($res['file_path'], PATHINFO_EXTENSION));
                        $audio_types = ['mp3', 'wav', 'ogg', 'm4a'];
                        if (in_array($file_ext, $audio_types)) {
                            echo '<audio controls><source src="uploads/' . htmlspecialchars($res['file_path']) . '">Your browser does not support the audio element.</audio>';
                        } else {
                            echo '<div class="resource-links"><a href="uploads/' . htmlspecialchars($res['file_path']) . '" download>📄 Download File</a></div>';
                        }
                    }
                    ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    
    <a href="messages.php" class="floating-chat-btn">
        💬
        <?php if ($unread_messages_count > 0): ?>
            <span class="notification-badge"><?php echo $unread_messages_count; ?></span>
        <?php endif; ?>
    </a>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('theme-toggle');
            const body = document.body;
            const setTheme = (theme) => { if (theme === 'dark') { body.classList.add('dark-mode'); themeToggle.textContent = '☀️'; } else { body.classList.remove('dark-mode'); themeToggle.textContent = '🌙'; } };
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) { setTheme(savedTheme); }
            themeToggle.addEventListener('click', () => { const newTheme = body.classList.contains('dark-mode') ? 'light' : 'dark'; setTheme(newTheme); localStorage.setItem('theme', newTheme); });
        });
    </script>
</body>
</html>
