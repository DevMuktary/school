<?php
require_once '../db_connect.php';
if (!isset($_SESSION['instructor_id']) || !isset($_SESSION['school_id'])) {
    header('Location: index.php');
    exit();
}
$school_id = $_SESSION['school_id'];
$instructor_id = $_SESSION['instructor_id'];
$instructor_name = $_SESSION['school_user_name'];

// Fetch the school's branding info
$school_stmt = $conn->prepare("SELECT name, logo_path, brand_color FROM schools WHERE id = ?");
$school_stmt->bind_param("i", $school_id);
$school_stmt->execute();
$school = $school_stmt->get_result()->fetch_assoc();
$school_stmt->close();
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#E74C3C';

// Fetch ONLY the courses assigned to this instructor
$assigned_courses = [];
$sql = "SELECT c.id, c.title, c.description 
        FROM courses c
        JOIN course_assignments ca ON c.id = ca.course_id
        WHERE ca.instructor_id = ? AND c.school_id = ?
        ORDER BY c.title ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $instructor_id, $school_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) { while ($row = $result->fetch_assoc()) { $assigned_courses[] = $row; } }
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Dashboard - <?php echo htmlspecialchars($school['name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { 
            --brand-primary: <?php echo $school_brand_color; ?>;
            --brand-yellow: #FFB902;
            --bg-color: #f7f9fc; --card-bg-color: #FFFFFF; --text-color: #2c3e50;
            --text-muted: #6c757d; --border-color: #e9ecef;
        }
        body.dark-mode { 
            --bg-color: #121212; --card-bg-color: #1e1e1e; --text-color: #e0e0e0;
            --text-muted: #a0a0a0; --border-color: #333;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; }
        .header { background-color: var(--brand-primary); color: #FFFFFF; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .header .logo img { max-height: 40px; } .header .logo span { font-size: 22px; font-weight: 700; }
        .header-controls { display: flex; align-items: center; gap: 15px; }
        .header-btn { padding: 8px 15px; border-radius: 50px; font-weight: 500; text-decoration: none; border: 1px solid var(--border-color); color: var(--text-color); background-color: transparent; cursor: pointer; }
        .header-btn.icon-btn { padding: 6px 9px; }

        .main-container { max-width: 1200px; margin: 0 auto; padding: 25px 15px; }
        .welcome-header h1 { margin-top: 0; font-size: 28px; }
        .welcome-header p { margin-top: -15px; font-size: 16px; color: var(--text-muted); }
        .courses-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
        .course-card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; display: flex; flex-direction: column; }
        .course-card-body { padding: 25px; flex-grow: 1; }
        .course-card h3 { margin-top: 0; font-size: 20px; color: var(--brand-primary); }
        .course-card p { font-size: 14px; color: var(--text-muted); }
        
        .management-links {
            padding: 0 25px 25px 25px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); /* More flexible grid */
            gap: 10px;
        }
        .management-links a {
            background-color: var(--bg-color);
            border: 1px solid var(--border-color);
            padding: 10px;
            text-align: center;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            color: var(--text-color);
            font-size: 13px; /* Slightly smaller for better fit */
            transition: all 0.2s;
        }
        .management-links a:hover {
            border-color: var(--brand-primary);
            color: var(--brand-primary);
        }
    </style>
</head>
<body class="">
    <header class="header">
        <div class="logo">
             <?php if (!empty($school['logo_path'])): ?>
                <img src="../uploads/logos/<?php echo htmlspecialchars($school['logo_path']); ?>" alt="<?php echo htmlspecialchars($school['name']); ?> Logo">
            <?php else: ?>
                <span style="color:white;"><?php echo htmlspecialchars($school['name']); ?></span>
            <?php endif; ?>
        </div>
        <div class="header-controls">
            <button id="theme-toggle" class="header-btn icon-btn" style="color:white; border-color: rgba(255,255,255,0.5);">🌙</button>
            <a href="logout.php" style="background-color:white; color:var(--brand-primary); border:none;" class="header-btn">Logout</a>
        </div>
    </header>
    <div class="main-container">
        <div class="welcome-header">
            <h1>Instructor Dashboard</h1>
            <p>Welcome back, <?php echo htmlspecialchars($instructor_name); ?>. Here are your assigned courses.</p>
        </div>
        <div class="courses-grid">
            <?php if (empty($assigned_courses)): ?>
                <p>You have not been assigned to any courses yet. Please contact your school administrator.</p>
            <?php else: foreach($assigned_courses as $course): ?>
                <div class="course-card">
                    <div class="course-card-body">
                        <h3><?php echo htmlspecialchars($course['title']); ?></h3>
                        <p><?php echo !empty($course['description']) ? htmlspecialchars($course['description']) : 'No description provided.'; ?></p>
                    </div>
                    <div class="management-links">
                        <a href="manage_resources.php?course_id=<?php echo $course['id']; ?>">Resources</a>
                        <a href="manage_calendar.php?course_id=<?php echo $course['id']; ?>">Calendar</a>
                        <a href="manage_quizzes.php?course_id=<?php echo $course['id']; ?>">Assessments</a>
                        <a href="all_submissions.php?course_id=<?php echo $course['id']; ?>">Submissions</a>
                        <a href="manage_homework.php?course_id=<?php echo $course['id']; ?>">Assignments</a>
<a href="forum.php?course_id=<?php echo $course['id']; ?>">Forum</a>
                        <a href="manage_attendance.php?course_id=<?php echo $course['id']; ?>">Attendance</a>
                        <a href="manage_results.php?course_id=<?php echo $course['id']; ?>">Manage Results</a>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <script>
        const themeToggle = document.getElementById('theme-toggle');
        const body = document.body;
        const setTheme = (theme) => { 
            if (theme === 'dark') { 
                body.classList.add('dark-mode'); 
                themeToggle.textContent = '☀️'; 
                themeToggle.style.borderColor = '#555';
            } else { 
                body.classList.remove('dark-mode'); 
                themeToggle.textContent = '🌙'; 
                themeToggle.style.borderColor = 'rgba(255,255,255,0.5)';
            } 
        };
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme) { 
            setTheme(savedTheme); 
        } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            setTheme('dark');
        }
        themeToggle.addEventListener('click', () => { 
            const newTheme = body.classList.contains('dark-mode') ? 'light' : 'dark'; 
            setTheme(newTheme); 
            localStorage.setItem('theme', newTheme); 
        });
    </script>
</body>
</html>