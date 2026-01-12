<?php
require_once 'db_connect.php'; // This also starts the session

// 1. Check if the user is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: index.php');
    exit();
}

// 2. Check if a course has been selected from the dashboard
if (!isset($_SESSION['current_course_id'])) {
    // If no course is selected, send them back to the dashboard to choose one.
    header('Location: dashboard.php');
    exit();
}

// 3. Use these variables on the rest of the page
$student_id = $_SESSION['student_id'];
$current_course_id = $_SESSION['current_course_id'];
$message = ''; 
$error = '';

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
$current_school_id = $details_data['school_id']; // Get the school_id for the current course
$school = [ 'name' => $details_data['school_name'], 'logo_path' => $details_data['logo_path'], 'brand_color' => $details_data['brand_color'] ];
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#001232';
$details_stmt->close();

// 5. Handle creating a new topic FOR THE CURRENT COURSE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_topic'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    if (empty($title) || empty($content)) {
        $error = "Both a title and content are required to create a topic.";
    } else {
        $stmt = $conn->prepare("INSERT INTO forum_topics (school_id, course_id, user_id, title, content) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiss", $current_school_id, $current_course_id, $student_id, $title, $content);
        if ($stmt->execute()) {
            header("Location: forum.php"); // Refresh to see the new topic
            exit();
        } else {
            $error = "Failed to create topic. Please try again.";
        }
        $stmt->close();
    }
}

// 6. Fetch all topics for the CURRENT student's course
$topics = [];
$sql = "SELECT t.*, u.full_name_eng as author_name,
               (SELECT COUNT(id) FROM forum_replies WHERE topic_id = t.id) as reply_count
        FROM forum_topics t
        JOIN users u ON t.user_id = u.id
        WHERE t.course_id = ?
        ORDER BY t.last_reply_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_course_id); // Use the session variable
$stmt->execute();
$result = $stmt->get_result();
if ($result) { while($row = $result->fetch_assoc()) { $topics[] = $row; } }
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discussion Forum - <?php echo htmlspecialchars($school['name']); ?></title>
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
        .main-container { max-width: 1000px; margin: 0 auto; padding: 30px 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; }
        .btn { padding: 10px 20px; border-radius: 5px; text-decoration: none; border: none; font-weight: 500; cursor: pointer; background-color: var(--brand-primary); color: white; }
        
        .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 25px; margin-bottom: 25px; }
        #create-topic-card { display: none; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 500; margin-bottom: 5px; }
        .form-group input, .form-group textarea { width: 100%; box-sizing: border-box; padding: 12px; font-size: 16px; border: 1px solid var(--border-color); border-radius: 5px; }
        
        .topic-list a { text-decoration: none; color: var(--text-color); }
        .topic-item { display: flex; align-items: center; gap: 15px; background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; margin-bottom: 15px; transition: all 0.2s ease; }
        .topic-item:hover { border-color: var(--brand-primary); transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .topic-details { flex-grow: 1; }
        .topic-title { font-size: 18px; font-weight: 600; margin: 0 0 5px; }
        .topic-meta { font-size: 13px; color: var(--text-muted); }
        .topic-stats { text-align: center; flex-shrink: 0; }
        .stat-number { font-size: 20px; font-weight: 600; }
    </style>
</head>
<body class="">
    <header class="header">
        <a href="dashboard.php" style="color:var(--text-color);">Dashboard</a>
    </header>

    <div class="main-container">
        <div class="page-header">
            <h1>Discussion Forum</h1>
            <button id="new-topic-btn" class="btn">＋ Create New Topic</button>
        </div>

        <div id="create-topic-card" class="card">
            <h2 style="margin-top:0;">Start a New Discussion</h2>
            <form action="forum.php" method="POST">
                <div class="form-group"><label for="title">Topic Title</label><input type="text" name="title" id="title" required></div>
                <div class="form-group"><label for="content">Your Question / Post</label><textarea name="content" id="content" rows="5" required></textarea></div>
                <button type="submit" name="create_topic" class="btn">Post Topic</button>
            </form>
        </div>

        <div class="topic-list">
            <?php if (empty($topics)): ?>
                <div class="card" style="text-align: center;">No topics have been created yet. Be the first!</div>
            <?php else: foreach ($topics as $topic): ?>
                <a href="view_topic.php?id=<?php echo $topic['id']; ?>" class="topic-item">
                    <div class="topic-details">
                        <div class="topic-title"><?php echo htmlspecialchars($topic['title']); ?></div>
                        <div class="topic-meta">Started by <?php echo htmlspecialchars($topic['author_name']); ?> on <?php echo date("M j, Y", strtotime($topic['created_at'])); ?></div>
                    </div>
                    <div class="topic-stats">
                        <div class="stat-number"><?php echo $topic['reply_count']; ?></div>
                        <div class="topic-meta">Replies</div>
                    </div>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
    
    <script>
        document.getElementById('new-topic-btn').addEventListener('click', function() {
            var card = document.getElementById('create-topic-card');
            if (card.style.display === 'block') {
                card.style.display = 'none';
            } else {
                card.style.display = 'block';
            }
        });
    </script>
</body>
</html>
