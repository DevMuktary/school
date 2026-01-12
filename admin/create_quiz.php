<?php
require_once '../db_connect.php';

if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit(); }
if (!isset($_SESSION['selected_course_id']) || $_SESSION['selected_course_id'] == 0) {
    header('Location: dashboard.php?error=no_course_selected');
    exit();
}
$course_id = $_SESSION['selected_course_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $duration = intval($_POST['duration_minutes']);
    $type = $_POST['type'];

    if(empty($title) || $duration <= 0 || empty($type)) {
        $error = "Title, Type, and a valid duration are required.";
    } else {
        $stmt = $conn->prepare("INSERT INTO quizzes (course_id, title, description, duration_minutes, type) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issis", $course_id, $title, $description, $duration, $type);
        if ($stmt->execute()) {
            $new_quiz_id = $conn->insert_id;
            header("Location: edit_quiz.php?id=$new_quiz_id&status=created");
            exit();
        } else {
            $error = "Failed to create quiz.";
        }
    }
}

$course_title_stmt = $conn->prepare("SELECT title FROM courses WHERE id = ?");
$course_title_stmt->bind_param("i", $course_id);
$course_title_stmt->execute();
$course_title = $course_title_stmt->get_result()->fetch_assoc()['title'];
$course_title_stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Assessment - Admin</title>
    <style>
        /* Re-using styles */
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        body { font-family: 'Poppins', sans-serif; background-color: #f0f2f5; margin: 0; }
        .header { background-color: <?php echo BRAND_COLOR_BLUE; ?>; color: #FFFFFF; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .container { padding: 30px; max-width: 800px; margin: auto; }
        .form-card { background-color: #FFFFFF; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .form-card h1 { margin: 0 0 10px 0; }
        .page-subtitle { font-size: 16px; color: #555; margin-top: -5px; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: <?php echo BRAND_COLOR_BLUE; ?>; color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
        .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; color: #721c24; background-color: #f8d7da; }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo"><?php echo SCHOOL_NAME; ?><span>.</span></div>
        <a href="manage_quizzes.php" style="color:white; text-decoration:none;">← Back to Assessments</a>
    </header>
    <div class="container">
        <div class="form-card">
            <h1>Create New Assessment</h1>
            <p class="page-subtitle">For course: <strong><?php echo htmlspecialchars($course_title); ?></strong></p>
            <?php if ($error): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>
            <form method="POST">
                <div class="form-group"><label for="title">Title</label><input type="text" name="title" id="title" required></div>
                <div class="form-group"><label for="type">Assessment Type</label><select name="type" id="type" required><option value="Test">Test (Objective only)</option><option value="Exam">Exam (Objective + Essay)</option></select></div>
                <div class="form-group"><label for="description">Instructions</label><textarea name="description" id="description" rows="4"></textarea></div>
                <div class="form-group"><label for="duration_minutes">Duration (minutes)</label><input type="number" name="duration_minutes" id="duration_minutes" required min="1" value="30"></div>
                <button type="submit" class="btn">Save and Add Questions</button>
            </form>
        </div>
    </div>
</body>
</html>
