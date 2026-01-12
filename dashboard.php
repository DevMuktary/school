<?php
require_once 'db_connect.php';

// --- MODIFIED ---
// This now redirects to login.php, which will auto-redirect to your school's slug.
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}
// --- END MODIFICATION ---

$student_id = $_SESSION['student_id'];

// --- FETCH ALL ENROLLED COURSES FOR THE SWITCHER ---
$all_enrollments_stmt = $conn->prepare("
    SELECT c.id as course_id, c.title as course_name
    FROM enrollments e
    JOIN courses c ON e.course_id = c.id
    WHERE e.student_id = ?
    ORDER BY e.id DESC
");
$all_enrollments_stmt->bind_param("i", $student_id);
$all_enrollments_stmt->execute();
$all_courses_result = $all_enrollments_stmt->get_result();
$enrolled_courses = [];
while ($row = $all_courses_result->fetch_assoc()) {
    $enrolled_courses[] = $row;
}
$all_enrollments_stmt->close();

if (empty($enrolled_courses)) {
    die("Error: You are not enrolled in any course. Please contact your administrator.");
}

// --- LOGIC TO DETERMINE THE CURRENTLY VIEWED COURSE ---
$current_course_id = null;
if (isset($_GET['view_course']) && is_numeric($_GET['view_course'])) {
    $course_id_from_url = intval($_GET['view_course']);
    foreach ($enrolled_courses as $enrolled_course) {
        if ($enrolled_course['course_id'] == $course_id_from_url) {
            $current_course_id = $course_id_from_url;
            $_SESSION['current_course_id'] = $current_course_id;
            break;
        }
    }
}
elseif (isset($_SESSION['current_course_id'])) {
    $current_course_id = $_SESSION['current_course_id'];
}
else {
    $current_course_id = $enrolled_courses[0]['course_id'];
    $_SESSION['current_course_id'] = $current_course_id;
}


// --- Get Student's School & CURRENT Course Info ---
$enrollment_stmt = $conn->prepare("
    SELECT c.title as course_name, s.id as school_id, s.name as school_name, s.logo_path, s.brand_color
    FROM courses c
    JOIN schools s ON c.school_id = s.id
    WHERE c.id = ? LIMIT 1
");
$enrollment_stmt->bind_param("i", $current_course_id);
$enrollment_stmt->execute();
$enrollment_data = $enrollment_stmt->get_result()->fetch_assoc();

$course_name = $enrollment_data['course_name'];
$school_id = $enrollment_data['school_id'];
$school = ['name' => $enrollment_data['school_name'], 'logo_path' => $enrollment_data['logo_path'], 'brand_color' => $enrollment_data['brand_color']];
$enrollment_stmt->close();
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#001232';


// --- Fetch all other data for the dashboard widgets ---
$student_stmt = $conn->prepare("SELECT full_name_eng, level FROM users WHERE id = ?");
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student = $student_stmt->get_result()->fetch_assoc();
$student_stmt->close();

$unread_messages_count = 0;
$unread_stmt = $conn->prepare("SELECT COUNT(m.id) as count FROM messages m JOIN conversations c ON m.conversation_id = c.id WHERE c.student_id = ? AND m.sender_role != 'student' AND m.is_read = 0");
$unread_stmt->bind_param("i", $student_id);
$unread_stmt->execute();
$unread_messages_count = $unread_stmt->get_result()->fetch_assoc()['count'];
$unread_stmt->close();

$quizzes_due_count = 0;
$quiz_sql = "SELECT COUNT(q.id) as count FROM quizzes q WHERE q.status = 'published' AND q.course_id = ? AND q.id NOT IN (SELECT qs.quiz_id FROM quiz_submissions qs WHERE qs.student_id = ?) AND (q.available_from IS NULL OR NOW() >= q.available_from) AND (q.available_to IS NULL OR NOW() <= q.available_to)";
$quiz_stmt = $conn->prepare($quiz_sql);
$quiz_stmt->bind_param("ii", $current_course_id, $student_id);
$quiz_stmt->execute();
$quizzes_due_count = $quiz_stmt->get_result()->fetch_assoc()['count'];
$quiz_stmt->close();

$total_assessments_stmt = $conn->prepare("SELECT COUNT(id) as total FROM quizzes WHERE course_id = ? AND status = 'published'");
$total_assessments_stmt->bind_param("i", $current_course_id);
$total_assessments_stmt->execute();
$total_assessments = $total_assessments_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$total_assessments_stmt->close();

$completed_sql = "SELECT COUNT(qs.id) as total FROM quiz_submissions qs JOIN quizzes q ON qs.quiz_id = q.id WHERE qs.student_id = ? AND q.course_id = ?";
$completed_assessments_stmt = $conn->prepare($completed_sql);
$completed_assessments_stmt->bind_param("ii", $student_id, $current_course_id);
$completed_assessments_stmt->execute();
$completed_assessments = $completed_assessments_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$completed_assessments_stmt->close();
$progress_percentage = ($total_assessments > 0) ? round(($completed_assessments / $total_assessments) * 100) : 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($school['name']); ?></title>
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
            --text-muted: #a0a0e0; --border-color: #333;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; }
        
        /* --- HEADER STYLES --- */
        .header { 
            background-color: var(--card-bg-color); 
            border-bottom: 1px solid var(--border-color); 
            padding: 15px 20px;
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            flex-wrap: wrap;
            gap: 15px;
            position: sticky; 
            top: 0; 
            z-index: 1000;
        }
        .header .logo { flex-shrink: 0; display: flex; align-items: center; gap: 10px; }
        .header .logo img { max-height: 35px; }
        .header .logo span { font-size: 20px; font-weight: 700; color: var(--brand-primary); }

        .header-controls { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .header-btn { 
            padding: 6px 14px; border-radius: 50px; font-weight: 500; 
            text-decoration: none; border: 1px solid var(--border-color); 
            color: var(--text-color); background-color: transparent; cursor: pointer;
            font-size: 13px; white-space: nowrap;
        }
        .header-btn.primary { background-color: var(--brand-primary); color: white; border-color: var(--brand-primary); }

        .course-switcher select {
            font-family: 'Poppins', sans-serif; padding: 6px 12px; border-radius: 50px;
            border: 1px solid var(--border-color); background-color: var(--card-bg-color);
            color: var(--text-color); font-size: 13px; font-weight: 500;
            max-width: 220px;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column; 
                align-items: center;
            }
            .header-controls {
                justify-content: center;
                width: 100%;
            }
            .course-switcher {
                width: 100%;
                order: -1;
                margin-bottom: 10px;
            }
            .course-switcher select {
                width: 100%;
                max-width: none;
            }
        }
        
        .main-container { max-width: 1100px; margin: 0 auto; padding: 30px 20px; }
        .welcome-header { margin-bottom: 30px; text-align: center; }
        .welcome-header h1 { margin: 0; font-size: 32px; font-weight: 700; }
        .welcome-header p { color: var(--text-muted); font-size: 18px; }
        
        .dashboard-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
            gap: 25px; 
            margin-bottom: 40px;
        }
        .card { background-color: var(--card-bg-color); border-radius: 8px; padding: 25px; border: 1px solid var(--border-color); text-align: center; }
        .card h3 { margin-top: 0; font-size: 16px; font-weight: 500; color: var(--text-muted); }
        .card .stat-number { font-size: 36px; font-weight: 700; color: var(--brand-primary); line-height: 1.2; }
        
        .section-title { font-size: 24px; font-weight: 600; margin-bottom: 20px; text-align: center; }
        .quick-access-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; }
        .access-card { background-color: var(--card-bg-color); border-radius: 8px; padding: 20px; border: 1px solid var(--border-color); text-align: center; text-decoration: none; color: var(--text-color); transition: all 0.3s ease; }
        .access-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); border-color: var(--brand-primary); }
        .access-card .icon { background-color: var(--brand-primary); color: white; height: 50px; width: 50px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 15px; }
        .access-card .icon svg { width: 24px; height: 24px; }
        .access-card span { font-weight: 600; font-size: 15px; }
        
        .floating-chat-btn { position: fixed; bottom: 25px; right: 25px; width: 60px; height: 60px; background-color: var(--brand-primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; text-decoration: none; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); z-index: 1000; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background-color: #E74C3C; color: white; width: 24px; height: 24px; border-radius: 50%; font-size: 14px; font-weight: bold; display: flex; align-items: center; justify-content: center; border: 2px solid white; }
        
        .floating-whatsapp-btn {
            position: fixed;
            bottom: 25px;
            left: 25px;
            width: 60px;
            height: 60px;
            background-color: #25D366;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            z-index: 1000;
        }
        .floating-whatsapp-btn svg {
            width: 32px;
            height: 32px;
        }

    </style>
</head>
<body>
    <header class="header">
        <div class="logo">
            <?php if (!empty($school['logo_path'])): ?>
                <img src="uploads/logos/<?php echo htmlspecialchars($school['logo_path']); ?>" alt="<?php echo htmlspecialchars($school['name']); ?> Logo">
            <?php else: ?>
                <span><?php echo htmlspecialchars($school['name']); ?></span>
            <?php endif; ?>
        </div>
        <div class="header-controls">
            <?php if (count($enrolled_courses) > 1): ?>
            <div class="course-switcher">
                <form action="dashboard.php" method="GET" id="courseSwitchForm">
                    <select name="view_course" onchange="document.getElementById('courseSwitchForm').submit()">
                        <?php foreach ($enrolled_courses as $course): ?>
                            <option value="<?php echo $course['course_id']; ?>" <?php if ($course['course_id'] == $current_course_id) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($course['course_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php endif; ?>
            <button id="theme-toggle" class="header-btn">🌙</button>
            <a href="profile.php" class="header-btn">My Profile</a>
            <a href="logout.php" class="header-btn primary">Logout</a>
        </div>
    </header>

    <div class="main-container">
        <div class="welcome-header">
            <h1>Welcome, <?php echo htmlspecialchars(explode(' ', $student['full_name_eng'])[0]); ?>!</h1>
            <p>Here is your summary for <strong><?php echo htmlspecialchars($course_name); ?></strong></p>
        </div>

        <div class="dashboard-grid">
            <div class="card">
                <h3>Course Progress</h3>
                <div class="stat-number"><?php echo $progress_percentage; ?>%</div>
            </div>
            <div class="card">
                <h3>Assessments Due</h3>
                <div class="stat-number"><?php echo $quizzes_due_count; ?></div>
            </div>
            <div class="card">
                <h3>Unread Messages</h3>
                <div class="stat-number"><?php echo $unread_messages_count; ?></div>
            </div>
        </div>

        <h2 class="section-title">Quick Access</h2>
        <div class="quick-access-grid">
            <a href="resources.php" class="access-card">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg></div>
                <span>Resources</span>
            </a>
            <a href="assessments.php" class="access-card">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg></div>
                <span>Assessments</span>
            </a>
            <a href="results.php" class="access-card">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline><path d="M12 15l-1.5-1.5M12 15l1.5-1.5M12 15l-1.5 1.5M12 15l1.5 1.5"></path></svg></div>
                <span>My Results</span>
            </a>
            <a href="assignments.php" class="access-card">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg></div>
                <span>Assignments</span>
            </a>
            <a href="pay_fee.php" class="access-card">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg></div>
                <span>Pay School Fees</span>
            </a>
            <a href="messages.php" class="access-card">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg></div>
                <span>Messages</span>
            </a>
            <a href="forum.php" class="access-card">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg></div>
                <span>Forum</span>
            </a>
            <a href="calendar.php" class="access-card">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
                <span>Calendar</span>
            </a>
        </div>
    </div>
    
    <a href="messages.php" class="floating-chat-btn">
        💬
        <?php if ($unread_messages_count > 0): ?>
            <span class="notification-badge"><?php echo $unread_messages_count; ?></span>
        <?php endif; ?>
    </a>

    <a href="https://wa.me/2347016370067" class="floating-whatsapp-btn" target="_blank" title="Chat with Admin on WhatsApp">
        <svg fill="currentColor" viewBox="0 0 16 16"><path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.898 7.898 0 0 0 13.6 2.326zM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592zm3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.729.729 0 0 0-.529.247c-.182.198-.691.677-.691 1.654 0 .977.71 1.916.81 2.049.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232z"/></svg>
    </a>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('theme-toggle');
            if(themeToggle) {
                const body = document.body;
                const setTheme = (theme) => { 
                    if (theme === 'dark') { 
                        body.classList.add('dark-mode'); 
                        themeToggle.textContent = '☀️'; 
                    } else { 
                        body.classList.remove('dark-mode'); 
                        themeToggle.textContent = '🌙'; 
                    } 
                };
                const savedTheme = localStorage.getItem('theme');
                if (savedTheme) { 
                    setTheme(savedTheme); 
                }
                themeToggle.addEventListener('click', () => { 
                    const newTheme = body.classList.contains('dark-mode') ? 'light' : 'dark'; 
                    setTheme(newTheme); 
                    localStorage.setItem('theme', newTheme); 
                });
            }
        });
    </script>
</body>
</html>
