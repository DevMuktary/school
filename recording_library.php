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

// 5. Fetch all recordings for the CURRENT course
$recordings = [];
$rec_stmt = $conn->prepare("SELECT title, description, recording_link, upload_date FROM class_materials WHERE course_id = ? AND recording_link IS NOT NULL AND recording_link != '' ORDER BY upload_date DESC");
$rec_stmt->bind_param("i", $current_course_id);
$rec_stmt->execute();
$rec_result = $rec_stmt->get_result();
while ($row = $rec_result->fetch_assoc()) {
    $recordings[] = $row;
}
$rec_stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recording Library - <?php echo htmlspecialchars($school['name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root {
            --brand-primary: <?php echo $school_brand_color; ?>;
            --brand-secondary: #FFB902;
        }
        body { font-family: 'Poppins', sans-serif; background-color: #f0f2f5; margin: 0; color: #001232; }
        .header { background-color: var(--brand-primary); color: #FFFFFF; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .header .logo { font-size: 22px; font-weight: 700; }
        .header .logo span { color: var(--brand-secondary); }
        .main-container { max-width: 1200px; margin: 0 auto; padding: 25px 15px; }
        .page-title { font-size: 28px; font-weight: 600; margin-bottom: 25px; }
        .recording-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
        .rec-card { background-color: #FFFFFF; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-radius: 8px; padding: 20px; display: flex; flex-direction: column; }
        .rec-card h3 { margin-top: 0; }
        .rec-card p { font-size: 14px; color: #555; flex-grow: 1; }
        .rec-card .date { font-size: 13px; color: #777; margin: -10px 0 15px 0; }
        .rec-card a { display: block; text-align: center; background-color: var(--brand-primary); color: white; padding: 10px; border-radius: 5px; text-decoration: none; font-weight: 500; margin-top: 10px; }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo"><?php echo htmlspecialchars($school['name']); ?><span>.</span></div>
        <a href="dashboard.php" style="color:white; text-decoration:none;">← Back to Dashboard</a>
    </header>
    <div class="main-container">
        <h1 class="page-title">Class Recording Library</h1>
        <div class="recording-grid">
            <?php if (empty($recordings)): ?>
                <p>No recordings are available in the library for this course yet.</p>
            <?php else: foreach ($recordings as $rec): ?>
                <div class="rec-card">
                    <h3><?php echo htmlspecialchars($rec['title']); ?></h3>
                    <p class="date">Recorded on: <?php echo date("F j, Y", strtotime($rec['upload_date'])); ?></p>
                    <p><?php echo htmlspecialchars($rec['description']); ?></p>
                    <a href="<?php echo htmlspecialchars($rec['recording_link']); ?>" target="_blank">Watch Recording</a>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</body>
</html>
