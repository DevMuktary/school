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

// 3. Validate the topic ID from the URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: forum.php');
    exit();
}

// 4. Use these variables on the rest of the page
$student_id = $_SESSION['student_id'];
$current_course_id = $_SESSION['current_course_id'];
$topic_id = intval($_GET['id']);
$message = ''; 
$error = '';

// 5. Fetch School details for branding
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

// 6. Security Check: Ensure this topic belongs to the student's CURRENTLY VIEWED course
$topic_check = $conn->prepare("SELECT id FROM forum_topics WHERE id = ? AND course_id = ?");
$topic_check->bind_param("ii", $topic_id, $current_course_id);
$topic_check->execute();
if($topic_check->get_result()->num_rows === 0) {
    die("Access Denied: This topic does not belong to the course you are currently viewing.");
}
$topic_check->close();

// 7. Handle adding a new reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_reply'])) {
    $content = trim($_POST['content']);
    if (empty($content)) {
        $error = "Reply cannot be empty.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO forum_replies (topic_id, user_id, content) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $topic_id, $student_id, $content);
            $stmt->execute();
            $stmt->close();
            
            $update_stmt = $conn->prepare("UPDATE forum_topics SET last_reply_at = NOW() WHERE id = ?");
            $update_stmt->bind_param("i", $topic_id);
            $update_stmt->execute();
            $update_stmt->close();

            $conn->commit();
            header("Location: view_topic.php?id=" . $topic_id);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to post reply. Please try again.";
        }
    }
}

// Fetch main topic details
$topic_sql = "SELECT t.*, u.full_name_eng as author_name FROM forum_topics t JOIN users u ON t.user_id = u.id WHERE t.id = ?";
$topic_stmt = $conn->prepare($topic_sql);
$topic_stmt->bind_param("i", $topic_id);
$topic_stmt->execute();
$topic = $topic_stmt->get_result()->fetch_assoc();
$topic_stmt->close();

// Fetch replies for this topic
$replies = [];
$replies_sql = "SELECT r.*, u.full_name_eng as author_name, u.role as author_role FROM forum_replies r JOIN users u ON r.user_id = u.id WHERE r.topic_id = ? ORDER BY r.created_at ASC";
$replies_stmt = $conn->prepare($replies_sql);
$replies_stmt->bind_param("i", $topic_id);
$replies_stmt->execute();
$result = $replies_stmt->get_result();
if ($result) { while($row = $result->fetch_assoc()) { $replies[] = $row; } }
$replies_stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($topic['title']); ?></title>
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
        .header { background-color: var(--card-bg-color); border-bottom: 1px solid var(--border-color); padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; }
        
        .main-container { max-width: 900px; margin: 0 auto; padding: 30px 20px; }
        .page-header { margin-bottom: 20px; }
        .page-header a { text-decoration: none; color: var(--brand-primary); font-weight: 500; }
        .original-post { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 25px; margin-bottom: 30px; }
        .original-post h1 { margin: 0 0 10px; font-size: 28px; }
        .post-meta { font-size: 14px; color: var(--text-muted); margin-bottom: 20px; }
        .post-content { line-height: 1.7; }
        
        .replies-section h2 { font-size: 22px; margin-bottom: 20px; }
        .reply-card { display: flex; gap: 15px; margin-bottom: 20px; }
        .reply-author { flex-shrink: 0; }
        .author-avatar { width: 40px; height: 40px; border-radius: 50%; background-color: var(--brand-primary); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; }
        .reply-body { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 15px; width: 100%; }
        .reply-header { display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: var(--text-muted); margin-bottom: 10px; }
        .author-name { font-weight: 600; color: var(--text-color); }
        .author-role { background-color: var(--bg-color); padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; }
        .reply-content { line-height: 1.6; }
        
        .reply-form-card { background-color: var(--card-bg-color); border-radius: 8px; padding: 25px; border-top: 3px solid var(--brand-primary); }
        .form-group textarea { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid var(--border-color); border-radius: 5px; font-size: 16px; min-height: 100px; }
        .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
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
        <div><a href="dashboard.php" style="color:var(--text-color); text-decoration:none;">Dashboard</a></div>
    </header>

    <div class="main-container">
        <div class="page-header"><a href="forum.php">&larr; Back to All Topics</a></div>

        <div class="original-post">
            <h1><?php echo htmlspecialchars($topic['title']); ?></h1>
            <div class="post-meta">
                Posted by <strong><?php echo htmlspecialchars($topic['author_name']); ?></strong> on <?php echo date("F j, Y", strtotime($topic['created_at'])); ?>
            </div>
            <div class="post-content">
                <?php echo nl2br(htmlspecialchars($topic['content'])); ?>
            </div>
        </div>

        <div class="replies-section">
            <h2>Replies (<?php echo count($replies); ?>)</h2>
            <?php if(empty($replies)): ?>
                <p>No replies yet. Be the first to comment!</p>
            <?php else: foreach($replies as $reply): ?>
                <div class="reply-card">
                    <div class="reply-author">
                        <div class="author-avatar"><?php echo strtoupper(substr($reply['author_name'], 0, 1)); ?></div>
                    </div>
                    <div class="reply-body">
                        <div class="reply-header">
                            <span class="author-name">
                                <?php echo htmlspecialchars($reply['author_name']); ?>
                                <?php if($reply['author_role'] !== 'student'): ?>
                                    <span class="author-role"><?php echo ucwords(str_replace('_', ' ', $reply['author_role'])); ?></span>
                                <?php endif; ?>
                            </span>
                            <span><?php echo date("M j, Y, g:i a", strtotime($reply['created_at'])); ?></span>
                        </div>
                        <div class="reply-content"><?php echo nl2br(htmlspecialchars($reply['content'])); ?></div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <hr style="border:0; border-top: 1px solid var(--border-color); margin: 40px 0;">

        <div class="reply-form-card">
            <h3 style="margin-top:0;">Post Your Reply</h3>
            <form action="view_topic.php?id=<?php echo $topic_id; ?>" method="POST">
                <div class="form-group">
                    <textarea name="content" placeholder="Type your comment here..." required></textarea>
                </div>
                <button type="submit" name="post_reply" class="btn">Submit Reply</button>
            </form>
        </div>
    </div>
</body>
</html>
