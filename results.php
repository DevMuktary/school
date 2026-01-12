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
// ... (Your unread messages query can go here if needed)

// Fetch all RELEASED result sets for this student AND THE CURRENT COURSE
$result_sets = [];
$rs_sql = "SELECT rs.id, rs.result_title, rs.release_date, c.title as course_name 
           FROM result_sets rs 
           JOIN courses c ON rs.course_id = c.id
           WHERE rs.student_id = ? AND rs.status = 'released' AND rs.course_id = ?
           ORDER BY rs.release_date DESC";
$rs_stmt = $conn->prepare($rs_sql);
$rs_stmt->bind_param("ii", $student_id, $current_course_id); // Filter by student AND current course
$rs_stmt->execute();
$rs_result = $rs_stmt->get_result();
if($rs_result) { while($row = $rs_result->fetch_assoc()) { $result_sets[] = $row; } }
$rs_stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Academic Results - <?php echo htmlspecialchars($school['name']); ?></title>
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
        
        .result-list { display: flex; flex-direction: column; gap: 15px; }
        .result-card {
            display: flex;
            align-items: center;
            background-color: var(--card-bg-color);
            border-radius: 8px;
            padding: 20px;
            border: 1px solid var(--border-color);
            text-decoration: none;
            color: var(--text-color);
            transition: all 0.2s ease;
        }
        .result-card:hover { border-color: var(--brand-primary); transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .result-card .icon { font-size: 24px; margin-right: 20px; color: var(--brand-primary); }
        .result-card .details { flex-grow: 1; }
        .result-card .title { font-size: 18px; font-weight: 600; margin-bottom: 3px; }
        .result-card .meta { font-size: 14px; color: var(--text-muted); }
        .result-card .arrow { font-size: 24px; color: var(--text-muted); }

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
            <h1>My Academic Results</h1>
        </div>
        <div class="result-list">
            <?php if (empty($result_sets)): ?>
                <div class="result-card" style="justify-content: center; color: var(--text-muted);">
                    No results have been released for this course yet.
                </div>
            <?php else: foreach ($result_sets as $rs): ?>
                <a href="view_result.php?id=<?php echo $rs['id']; ?>" class="result-card">
                    <div class="icon">📄</div>
                    <div class="details">
                        <div class="title"><?php echo htmlspecialchars($rs['result_title']); ?></div>
                        <div class="meta">
                            <span>Course: <?php echo htmlspecialchars($rs['course_name']); ?></span> |
                            <span>Released: <?php echo date("F j, Y", strtotime($rs['release_date'])); ?></span>
                        </div>
                    </div>
                    <div class="arrow">&rarr;</div>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
    
    <a href="messages.php" class="floating-chat-btn">
        💬
        <?php if (isset($unread_messages_count) && $unread_messages_count > 0): ?>
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
