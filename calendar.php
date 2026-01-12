<?php
require_once 'db_connect.php'; // This also starts the session

// --- MODIFIED: Session Check ---
// 1. Check if the user is logged in
if (!isset($_SESSION['student_id'])) {
    if (isset($_COOKIE['last_school_id'])) {
        $last_school_id = $_COOKIE['last_school_id'];
        // Redirect to the login page, passing the school ID
        header("Location: login.php?school_id=" . urlencode($last_school_id));
        exit();
    } else {
        // No session AND no cookie, fallback to main index.
        header('Location: index.php');
        exit();
    }
}
// --- END MODIFICATION ---

// 2. Check if a course has been selected from the dashboard
if (!isset($_SESSION['current_course_id'])) {
    // If no course is selected, send them back to the dashboard to choose one.
    header('Location: dashboard.php');
    exit();
}

// 3. Use these variables on the rest of the page
$student_id = $_SESSION['student_id'];
$current_course_id = $_SESSION['current_course_id'];

// --- MODIFIED: Added s.id as school_id ---
// 4. Fetch School and Current Course details for the header and page titles
$details_stmt = $conn->prepare("
    SELECT s.id as school_id, s.name as school_name, s.logo_path, s.brand_color
    FROM courses c
    JOIN schools s ON c.school_id = s.id
    WHERE c.id = ? LIMIT 1
");
$details_stmt->bind_param("i", $current_course_id);
$details_stmt->execute();
$details_data = $details_stmt->get_result()->fetch_assoc();
$school_id = $details_data['school_id']; // <-- Get the school_id
$school = [ 'name' => $details_data['school_name'], 'logo_path' => $details_data['logo_path'], 'brand_color' => $details_data['brand_color'] ];
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#001232';
$details_stmt->close();

// --- ADDED: Set/Refresh cookie to remember the school ---
setcookie('last_school_id', $school_id, time() + (86400 * 30), "/");
// --- END ADDITION ---

// 5. Fetch other page-specific data
// Check for Unread Messages (This is student-specific, not course-specific)
$unread_messages_count = 0;
$unread_stmt = $conn->prepare("SELECT COUNT(m.id) as count FROM messages m JOIN conversations c ON m.conversation_id = c.id WHERE c.student_id = ? AND m.sender_role != 'student' AND m.is_read = 0");
$unread_stmt->bind_param("i", $student_id);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
if ($unread_result) { $unread_messages_count = $unread_result->fetch_assoc()['count']; }
$unread_stmt->close();

// Fetch ALL calendar events for the CURRENT student's course
$events = [];
$stmt = $conn->prepare("SELECT * FROM calendar_events WHERE course_id = ? ORDER BY start_date ASC");
$stmt->bind_param("i", $current_course_id); // Use the session variable
$stmt->execute();
$event_result = $stmt->get_result();
if ($event_result) { while ($row = $event_result->fetch_assoc()) { $events[] = $row; } }
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Calendar - <?php echo htmlspecialchars($school['name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root {
            --brand-primary: <?php echo $school_brand_color; ?>;
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
        
        .main-container { max-width: 900px; margin: 0 auto; padding: 30px 20px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 600; }
        
        .event-list { display: flex; flex-direction: column; gap: 15px; }
        .event-card {
            display: flex;
            align-items: center;
            background-color: var(--card-bg-color);
            border-radius: 8px;
            padding: 20px;
            border: 1px solid var(--border-color);
            gap: 20px;
        }
        .event-date {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: var(--bg-color);
            border-radius: 8px;
            width: 70px;
            height: 70px;
            flex-shrink: 0;
            border: 1px solid var(--border-color);
        }
        .event-date .month { font-size: 12px; text-transform: uppercase; font-weight: 600; color: var(--brand-primary); }
        .event-date .day { font-size: 28px; font-weight: 700; color: var(--text-color); line-height: 1.2; }
        .event-details { flex-grow: 1; }
        .event-details .title { font-size: 18px; font-weight: 600; margin: 0 0 5px; }
        .event-details .meta { font-size: 14px; color: var(--text-muted); }
        
        .floating-chat-btn { position: fixed; bottom: 25px; right: 25px; width: 60px; height: 60px; background-color: var(--brand-primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; text-decoration: none; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); z-index: 1000; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background-color: #E74C3C; color: white; width: 24px; height: 24px; border-radius: 50%; font-size: 14px; font-weight: bold; display: flex; align-items: center; justify-content: center; border: 2px solid white; }

        @media (max-width: 480px) {
            .header { padding: 10px 15px; }
            .header .logo span { font-size: 18px; }
            .header-controls { gap: 8px; }
            .header-btn { padding: 5px 12px; font-size: 12px; }
        }
    </style>
</head>
<body class="">
    <header class="header">
        <div class="logo">
            <a href="dashboard.php" style="display: flex; align-items: center; text-decoration: none;">
                <?php if (!empty($school['logo_path'])): ?>
                    <img src="uploads/logos/<?php echo htmlspecialchars($school['logo_path']); ?>" alt="<?php echo htmlspecialchars($school['name']); ?> Logo">
                <?php else: ?>
                    <span><?php echo htmlspecialchars($school['name']); ?></span>
                <?php endif; ?>
            </a>
            </div>
        <div class="header-controls">
            <button id="theme-toggle" class="header-btn">🌙</button>
            <a href="dashboard.php" class="header-btn">Dashboard</a>
            <a href="logout.php" class="header-btn primary">Logout</a>
        </div>
    </header>

    <div class="main-container">
        <div class="page-header">
            <h1>Academic Calendar</h1>
        </div>
        <div class="event-list">
            <?php if (empty($events)): ?>
                <div class="event-card" style="justify-content: center; color: var(--text-muted);">
                    No events have been scheduled for this course yet.
                </div>
            <?php else: foreach ($events as $event): ?>
                <div class="event-card">
                    <div class="event-date">
                        <span class="month"><?php echo date("M", strtotime($event['start_date'])); ?></span>
                        <span class="day"><?php echo date("d", strtotime($event['start_date'])); ?></span>
                    </div>
                    <div class="event-details">
                        <div class="title"><?php echo htmlspecialchars($event['title']); ?></div>
                        <div class="meta">
                            <span><?php echo date("l, F j, Y", strtotime($event['start_date'])); ?> at <?php echo date("g:i A", strtotime($event['start_date'])); ?></span>
                            &bull;
                            <span>Type: <strong><?php echo htmlspecialchars($event['event_type']); ?></strong></span>
                        </div>
                    </div>
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
