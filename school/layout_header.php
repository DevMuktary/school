<?php
require_once '../db_connect.php';

// --- CENTRAL SECURITY CHECKS ---
if (!isset($_SESSION['school_admin_id']) && !isset($_SESSION['instructor_id'])) {
    header('Location: index.php');
    exit();
}

$is_admin = isset($_SESSION['school_admin_id']);
$is_instructor = isset($_SESSION['instructor_id']);
$user_id = $is_admin ? $_SESSION['school_admin_id'] : $_SESSION['instructor_id'];
$school_id = $_SESSION['school_id'];

if (!function_exists('verify_course_access')) {
    function verify_course_access($conn, $course_id_to_check, $is_instructor, $instructor_id, $school_id) {
        if ($is_instructor) {
            $stmt = $conn->prepare("SELECT id FROM course_assignments WHERE instructor_id = ? AND course_id = ? AND school_id = ?");
            $stmt->bind_param("iii", $instructor_id, $course_id_to_check, $school_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                header('Location: instructor_dashboard.php?error=access_denied');
                exit();
            }
            $stmt->close();
        } else { // For School Admins
            $stmt = $conn->prepare("SELECT id FROM courses WHERE id = ? AND school_id = ?");
            $stmt->bind_param("ii", $course_id_to_check, $school_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                header('Location: dashboard.php?error=access_denied');
                exit();
            }
            $stmt->close();
        }
    }
}

// Fetch school branding info
$school_stmt = $conn->prepare("SELECT name, logo_path, brand_color FROM schools WHERE id = ?");
$school_stmt->bind_param("i", $school_id);
$school_stmt->execute();
$school = $school_stmt->get_result()->fetch_assoc();
$school_stmt->close();

define('PLATFORM_NAME', 'INTRA-EDU');
define('BRAND_RED', '#E74C3C');
define('BRAND_YELLOW', '#FFB902');
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : BRAND_RED;
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Admin Panel</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { 
            --brand-primary: <?php echo $school_brand_color; ?>;
            --brand-yellow: <?php echo BRAND_YELLOW; ?>;
            --bg-color: #f7f9fc; 
            --card-bg-color: #FFFFFF; 
            --text-color: #2c3e50;
            --text-muted: #6c757d; 
            --border-color: #e9ecef;
            /* UPDATED: Sidebar uses the main light theme colors */
            --sidebar-bg: #FFFFFF;
            --sidebar-text: #495057;
            --sidebar-text-hover: var(--brand-primary);
            --sidebar-active-bg: var(--brand-primary);
        }
        body.dark-mode { 
            --bg-color: #121212; 
            --card-bg-color: #1e1e1e; 
            --text-color: #e0e0e0;
            --text-muted: #a0a0a0; 
            --border-color: #333;
            /* UPDATED: Dark mode sidebar colors */
            --sidebar-bg: #1e1e1e;
            --sidebar-text: #a0a0a0;
            --sidebar-text-hover: #ffffff;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; }
        .page-wrapper { display: flex; min-height: 100vh; }
        
        .sidebar {
            width: 260px; 
            background-color: var(--sidebar-bg); 
            border-right: 1px solid var(--border-color);
            position: fixed; top: 0; left: 0; height: 100%;
            display: flex; flex-direction: column; 
            transition: transform 0.3s ease-in-out; 
            z-index: 1100;
        }
        .sidebar-header { 
            padding: 20px; display: flex; align-items: center; justify-content: center; 
            border-bottom: 1px solid var(--border-color); 
            height: 69px; box-sizing: border-box; 
        }
        .sidebar-header img { max-height: 40px; }
        .sidebar-header span { font-size: 24px; font-weight: 700; color: var(--brand-primary); }
        .sidebar-nav { flex-grow: 1; overflow-y: auto; padding: 15px; }
        .nav-section-title { font-size: 11px; text-transform: uppercase; color: var(--text-muted); padding: 15px 10px 5px; font-weight: 600; }
        .sidebar-nav a { display: flex; align-items: center; gap: 15px; padding: 12px; text-decoration: none; color: var(--sidebar-text); border-radius: 5px; font-weight: 500; margin-bottom: 5px; font-size: 14px; }
        .sidebar-nav a:hover { background-color: var(--bg-color); color: var(--sidebar-text-hover); }
        .sidebar-nav a.active { background-color: var(--sidebar-active-bg); color: white; }
        .sidebar-nav a svg { width: 22px; height: 22px; flex-shrink: 0; }
        
        .main-content { flex-grow: 1; display: flex; flex-direction: column; transition: margin-left 0.3s ease-in-out; }
        .top-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 30px; background-color: var(--card-bg-color); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 1000; min-height: 45px; }
        .menu-toggle { display: none; background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-color); }
        .header-controls { display: flex; align-items: center; gap: 15px; }
        .header-btn { padding: 8px 15px; border-radius: 50px; font-weight: 500; text-decoration: none; border: 1px solid var(--border-color); color: var(--text-color); background-color: transparent; cursor: pointer; }
        .header-btn.icon-btn { padding: 6px 9px; }

        @media (min-width: 993px) { .main-content { margin-left: 260px; } }
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 1000; opacity: 0; visibility: hidden; transition: opacity 0.3s ease; }
            .menu-toggle { display: block; }
            .top-header { justify-content: space-between; }
            body.sidebar-open .sidebar { transform: translateX(0); box-shadow: 5px 0 25px rgba(0,0,0,0.1); }
            body.sidebar-open .sidebar-overlay { opacity: 1; visibility: visible; }
        }
    </style>
</head>
<body class="">
<div class="page-wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <?php if (!empty($school['logo_path'])): ?>
                <img src="../uploads/logos/<?php echo htmlspecialchars($school['logo_path']); ?>" alt="<?php echo htmlspecialchars($school['name']); ?> Logo">
            <?php else: ?>
                <span class="custom-color"><?php echo htmlspecialchars($school['name']); ?></span>
            <?php endif; ?>
        </div>
        <nav class="sidebar-nav">
            <?php if ($is_admin): ?>
            <span class="nav-section-title">Main Menu</span>
            <a href="dashboard.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M10,20V14H14V20H19V12H22L12,3L2,12H5V20H10Z" /></svg> <span>Dashboard</span></a>
            <a href="manage_courses.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M12,3L1,9L12,15L23,9L12,3M5,13.18V17.18L12,21L19,17.18V13.18L12,17L5,13.18Z" /></svg> <span>Courses</span></a>
            <a href="manage_instructors.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M12,3A3,3 0 0,0 9,6A3,3 0 0,0 12,9A3,3 0 0,0 15,6A3,3 0 0,0 12,3M16,10H15.61C14.5,10.64 13.31,11 12,11C10.69,11 9.5,10.64 8.39,10H8C5.79,10 4,11.79 4,14V20H20V14C20,11.79 18.21,10 16,10Z" /></svg> <span>Instructors</span></a>
            <a href="manage_students.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M12,19.2C9.5,19.2 7.29,17.92 6,16C6.03,14 10,12.9 12,12.9C14,12.9 17.97,14 18,16C16.71,17.92 14.5,19.2 12,19.2M12,5A3,3 0 0,1 15,8A3,3 0 0,1 12,11A3,3 0 0,1 9,8A3,3 0 0,1 12,5M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12C22,6.47 17.5,2 12,2Z" /></svg> <span>Students</span></a>
            <a href="manage_assignments.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M21,10.12H18V12.12H21V10.12M21,6.12H18V8.12H21V6.12M21,2.12H18V4.12H21V2.12M16,12.12H3V10.12H16V12.12M16,8.12H3V6.12H16V8.12M16,4.12H3V2.12H16V4.12Z" /></svg> <span>Assign Instructors</span></a>
            <?php endif; ?>
            <span class="nav-section-title">Course Tools</span>
            <a href="manage_calendar.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z" /></svg> <span>Calendar</span></a>
            <a href="manage_resources.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M14,12H10A2,2 0 0,0 8,14V20H16V14A2,2 0 0,0 14,12M14,14V15H10V14H14M18,2H14L12,4H7A2,2 0 0,0 5,6V20A2,2 0 0,0 7,22H17A2,2 0 0,0 19,20V6A2,2 0 0,0 17,4H12L14,2H18Z" /></svg> <span>Resources</span></a>
            <a href="manage_results.php"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M13.5,16V19H10.5V16H13.5M13,9V3.5L18.5,9H13Z" /></svg> <span>Manage Results</span></a>
            <a href="manage_quizzes.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M12,18L10,16H14L12,18M12,10A2,2 0 0,1 14,12A2,2 0 0,1 12,14A2,2 0 0,1 10,12A2,2 0 0,1 12,10M13,9V3.5L18.5,9H13Z" /></svg> <span>Assessments</span></a>
            <a href="all_submissions.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M4,12L8.5,16.5L19.5,5.5L18.08,4.08L8.5,13.67L5.41,10.59L4,12M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z" /></svg> <span>Submissions</span></a>
            <a href="manage_homework.php"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M12,19L8,15H11V12H13V15H16L12,19M13,9V3.5L18.5,9H13Z" /></svg> <span>Assignments</span></a>
            <a href="manage_attendance.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M16,17V19H2V17H16M11.5,12.5A3.5,3.5 0 0,1 8,9A3.5,3.5 0 0,1 11.5,5.5A3.5,3.5 0 0,1 15,9A3.5,3.5 0 0,1 11.5,12.5M16.5,14.5L15.4,13.35C16.1,12.38 16.5,11.22 16.5,10H18.5V12H22V10H20.5C20.5,11.73 19.53,13.28 18.05,14.07L17,15.12V22H19V15.5L16.5,14.5Z" /></svg> <span>Attendance</span></a>
            <?php if ($is_admin): ?>
            <span class="nav-section-title">Communication</span>
            <a href="manage_announcements.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M19,13H17V11H19M19,17H17V15H19M21,3V21H15V19H13V17H11V15H9V13H7V11H5V9H3V3H21Z" /></svg> <span>Announcements</span></a>
            <a href="messages.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M22 6C22 4.9 21.1 4 20 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6M20 6L12 11L4 6H20M20 18H4V8L12 13L20 8V18Z" /></svg> <span>Messages</span></a>
            <a href="forum.php"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12,3C17.5,3 22,6.58 22,11C22,15.42 17.5,19 12,19C11.1,19 10.23,18.9 9.4,18.73L4,21L5.27,16.6C3.9,15.21 3,13.2 3,11C3,6.58 7.5,3 12,3M12,5C8.62,5 6,7.24 6,10C6,11.79 7.05,13.37 8.5,14.23L8.27,15.11L7.1,18.9L10.3,17.17C10.83,17.3 11.4,17.39 12,17.39C15.38,17.39 18,15.15 18,12.39C18,9.63 15.38,7.39 12,7.39Z" /></svg> <span>Forum</span></a>
            <span class="nav-section-title">Finance & Records</span>
            <a href="billing.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M18,17H6V15H18M18,13H6V11H18M18,9H6V7H18M20,4H4A2,2 0 0,0 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V6A2,2 0 0,0 20,4Z" /></svg> <span>Billing</span></a>
            <a href="manage_fees.php"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M9,14H15V16H9V14M9,10H15V12H9V10M9,6H15V8H9V6M17,2H7A2,2 0 0,0 5,4V20A2,2 0 0,0 7,22H17A2,2 0 0,0 19,20V4A2,2 0 0,0 17,2Z" /></svg> <span>Manage Fees</span></a>
            <a href="payouts.php"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.83,9L15,9V7H11.83C11.42,6.17 11.17,5.27 11.07,4.3L15,4.3V2.3H11.07C11,1.34 10.5,0.42 9.67,0L9,0.67C9.94,1.25 10.34,2.25 10.34,3.3C10.34,3.63 10.29,3.96 10.22,4.28L3,4.28V2.28H1V11L3,11V9H5.08L7.6,14.22L8.38,13.6L6.3,9H9V11H13V13H9.66C9.66,14.1 9.22,15.14 8.28,15.73L9,16.4C9.8,15.93 10.27,15.04 10.33,14H15V12H12.17C12.58,11.17 12.83,10.27 12.93,9.3H15V11.3H17V9H18.92L16.4,3.78L15.62,4.4L17.7,9H15M3,6.28V7L1,7V6.28H3M23,12L19,16V13H1V11H19V8L23,12Z" /></svg> <span>Payouts</span></a>
            <a href="manage_certificates.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M11,14L8,12.5L11,11L12.5,8L14,11L17,12.5L14,14L12.5,17L11,14Z" /></svg> <span>Certificates</span></a>
            <span class="nav-section-title">Account</span>
            <a href="settings.php"><svg fill="currentColor" viewBox="0 0 24 24"><path d="M12,8A4,4 0 0,1 16,12A4,4 0 0,1 12,16A4,4 0 0,1 8,12A4,4 0 0,1 12,8M12,10A2,2 0 0,0 10,12A2,2 0 0,0 12,14A2,2 0 0,0 14,12A2,2 0 0,0 12,10M10.25,5.46L11.04,7.45C11.3,7.56 11.56,7.69 11.81,7.85L13.75,7.25L15.5,4.5L13.74,5.26C13.91,5.5 14.05,5.76 14.16,6.03L16.12,5.28L17.22,7.13L15.54,8.19C15.65,8.47 15.73,8.76 15.8,9.05L17.94,8.96L18.5,10.8L16.7,11.5C16.64,11.66 16.56,11.83 16.46,12L18.5,13.28L17.94,15.12L15.8,14.94C15.73,15.23 15.65,15.53 15.54,15.81L17.22,16.87L16.12,18.72L14.16,17.97C14.05,18.24 13.91,18.5 13.74,18.74L15.5,19.5L13.75,22.25L11.81,21.15C11.56,21.31 11.3,21.44 11.04,21.55L10.25,23.54H7.75L6.96,21.55C6.7,21.44 6.44,21.31 6.19,21.15L4.25,21.75L2.5,19.5L4.26,18.74C4.09,18.5 3.95,18.24 3.84,17.97L1.88,18.72L0.78,16.87L2.46,15.81C2.35,15.53 2.27,15.23 2.2,14.95L0.06,15.04L-0.5,13.2L1.3,12.5C1.36,12.34 1.44,12.17 1.54,12L-0.5,10.72L0.06,8.88L2.2,9.06C2.27,8.77 2.35,8.47 2.46,8.19L0.78,7.13L1.88,5.28L3.84,6.03C3.95,5.76 4.09,5.5 4.26,5.26L2.5,4.5L4.25,1.75L6.19,2.85C6.44,2.69 6.7,2.56 6.96,2.45L7.75,0.46H10.25Z" /></svg> <span>Settings</span></a>
            <?php endif; ?>
        </nav>
    </aside>
    <div class="sidebar-overlay" id="sidebar-overlay"></div>
    <div class="main-content">
        <header class="top-header">
            <button class="menu-toggle" id="menu-toggle">☰</button>
            <div class="header-controls">
                <button id="theme-toggle" class="header-btn icon-btn">🌙</button>
                <a href="logout.php" class="header-btn">Logout</a>
            </div>
        </header>
        <main class="page-container">
