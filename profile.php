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
$message = '';
$error = '';

// --- CSRF TOKEN GENERATION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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

// Check for Unread Messages (Student-specific, not course-specific)
$unread_messages_count = 0;
// ... (Your unread messages query can go here if needed)

// --- Handle Password Change (Student-specific, not course-specific) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please try again from the original page.";
    } else {
        $current_pass = $_POST['current_password'];
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result && password_verify($current_pass, $result['password'])) {
            if (strlen($new_pass) < 6) {
                $error = "New password must be at least 6 characters long.";
            } elseif ($new_pass === $confirm_pass) {
                $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update_stmt->bind_param("si", $hashed_password, $student_id);
                if ($update_stmt->execute()) { $message = "Password updated successfully!"; }
                $update_stmt->close();
            } else {
                $error = "New passwords do not match.";
            }
        } else {
            $error = "Incorrect current password.";
        }
        $stmt->close();
    }
}

// Fetch Student's Main Details (Student-specific)
$stmt = $conn->prepare("SELECT full_name_eng, email, level, reg_date FROM users WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch Assessment History FOR THE CURRENT COURSE
$submissions = [];
$comp_sql = "SELECT q.title, qs.id as submission_id, qs.score 
             FROM quiz_submissions qs 
             JOIN quizzes q ON qs.quiz_id = q.id 
             WHERE qs.student_id = ? AND q.course_id = ? AND q.result_status = 'Released' 
             ORDER BY qs.end_time DESC";
$comp_stmt = $conn->prepare($comp_sql);
$comp_stmt->bind_param("ii", $student_id, $current_course_id); // Using $current_course_id
$comp_stmt->execute();
$comp_result = $comp_stmt->get_result();
while ($row = $comp_result->fetch_assoc()) { $submissions[] = $row; }
$comp_stmt->close();

// Fetch Attendance History FOR THE CURRENT COURSE
$attendance_present = 0;
$total_classes = 0;
$total_classes_stmt = $conn->prepare("SELECT COUNT(id) as total FROM calendar_events WHERE course_id = ? AND event_type = 'Class'");
$total_classes_stmt->bind_param("i", $current_course_id); // Using $current_course_id
$total_classes_stmt->execute();
$total_classes_res = $total_classes_stmt->get_result();
if ($total_classes_res) { $total_classes = $total_classes_res->fetch_assoc()['total']; }
$total_classes_stmt->close();

$att_stmt = $conn->prepare("SELECT COUNT(id) as present_count FROM attendance WHERE student_id = ? AND course_id = ? AND status = 'Present'");
$att_stmt->bind_param("ii", $student_id, $current_course_id); // Using $current_course_id
$att_stmt->execute();
$att_res = $att_stmt->get_result();
if ($att_res) { $attendance_present = $att_res->fetch_assoc()['present_count']; }
$att_stmt->close();
$attendance_percentage = ($total_classes > 0) ? round(($attendance_present / $total_classes) * 100, 1) : 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo htmlspecialchars($school['name']); ?></title>
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
        
        .main-container { max-width: 1000px; margin: 0 auto; padding: 25px 15px; }
        .page-title { font-size: 28px; font-weight: 600; margin-bottom: 25px; }
        .profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; }
        .card h3, .card h4 { margin-top: 0; font-size: 18px; border-bottom: 2px solid var(--brand-secondary); padding-bottom: 10px; margin-bottom: 20px; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border-color); font-size: 15px; flex-wrap: wrap; }
        .detail-row span:first-child { font-weight: 500; color: var(--text-muted); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input { width: 100%; padding: 12px; box-sizing: border-box; border-radius: 5px; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); font-size: 16px; }
        .btn { padding: 12px 20px; width: 100%; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 500; cursor: pointer; font-size: 16px;}
        .message, .error { padding: 10px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; }
        .error { color: #D8000C; background-color: #FFD2D2; }
        .message { color: #155724; background-color: #d4edda; }
        table { width: 100%; border-collapse: collapse; font-size: 15px; }
        th, td { padding: 12px 0; text-align: left; border-bottom: 1px solid var(--border-color); }
        td a { color: var(--brand-primary); text-decoration: none; font-weight: 500; }
        
        .floating-chat-btn { position: fixed; bottom: 25px; right: 25px; width: 60px; height: 60px; background-color: var(--brand-primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; text-decoration: none; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); z-index: 1000; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background-color: #E74C3C; color: white; width: 24px; height: 24px; border-radius: 50%; font-size: 14px; font-weight: bold; display: flex; align-items: center; justify-content: center; border: 2px solid white; }
        
        @media (max-width: 768px) { .profile-grid { grid-template-columns: 1fr; } }
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
        <h1 class="page-title">My Profile & Progress</h1>
        <div class="profile-grid">
            <div class="card">
                <h3>Account Details</h3>
                <div class="detail-row"><span>Full Name</span> <span><?php echo htmlspecialchars($student['full_name_eng']); ?></span></div>
                <div class="detail-row"><span>Email</span> <span><?php echo htmlspecialchars($student['email']); ?></span></div>
                <div class="detail-row"><span>Course Level</span> <span><?php echo htmlspecialchars($student['level']); ?></span></div>
                <div class="detail-row"><span>Date Registered</span> <span><?php echo date("F j, Y", strtotime($student['reg_date'])); ?></span></div>
            </div>
            <div class="card">
                <h3>Change Password</h3>
                 <?php if($message): ?><p class="message"><?php echo $message; ?></p><?php endif; ?>
                 <?php if($error): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>
                <form action="profile.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="form-group"><label for="current_password">Current Password</label><input type="password" name="current_password" id="current_password" required></div>
                    <div class="form-group"><label for="new_password">New Password</label><input type="password" name="new_password" id="new_password" required></div>
                    <div class="form-group"><label for="confirm_password">Confirm New Password</label><input type="password" name="confirm_password" id="confirm_password" required></div>
                    <button type="submit" name="change_password" class="btn">Update Password</button>
                </form>
            </div>
        </div>
        <div style="margin-top: 25px;" class="card">
            <h3>Academic Record</h3>
            <div class="profile-grid">
                <div>
                    <h4>Assessment History</h4>
                    <table>
                        <thead><tr><th>Assessment</th><th style="text-align:right;">Score</th></tr></thead>
                        <tbody>
                            <?php if(empty($submissions)): ?>
                                <tr><td colspan="2" style="text-align:center; padding: 20px;">No results available for this course.</td></tr>
                            <?php else: foreach($submissions as $sub): ?>
                                <tr>
                                    <td><a href="quiz_result.php?id=<?php echo $sub['submission_id']; ?>"><?php echo htmlspecialchars($sub['title']); ?></a></td>
                                    <td style="text-align:right; font-weight:600;"><?php echo $sub['score']; ?>%</td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h4>Attendance Summary</h4>
                    <p style="font-size: 36px; font-weight: 600; text-align: center; color: var(--brand-primary); margin-bottom: 5px;"><?php echo $attendance_percentage; ?>%</p>
                    <p style="text-align: center; margin-top: 0; color: var(--text-muted);">Present for <?php echo $attendance_present; ?> of <?php echo $total_classes; ?> classes in this course.</p>
                </div>
            </div>
        </div>
    </div>

    <a href="messages.php" class="floating-chat-btn">
        💬
        <?php if ($unread_messages_count > 0): ?>
            <span class="notification-badge"><?php echo $unread_messages_count; ?></span>
        <?php endif; ?>
    </a>

    <script>
        const themeToggle = document.getElementById('theme-toggle');
        const body = document.body;
        const setTheme = (theme) => { if (theme === 'dark') { body.classList.add('dark-mode'); themeToggle.textContent = '☀️'; } else { body.classList.remove('dark-mode'); themeToggle.textContent = '🌙'; } };
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme) { setTheme(savedTheme); }
        themeToggle.addEventListener('click', () => { const newTheme = body.classList.contains('dark-mode') ? 'light' : 'dark'; setTheme(newTheme); localStorage.setItem('theme', newTheme); });
    </script>
</body>
</html>
