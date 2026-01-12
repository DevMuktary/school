<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

$course_id = 0;
if ($is_admin) { $course_id = $_SESSION['selected_course_id'] ?? 0; } 
elseif ($is_instructor) { $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0; }
if ($course_id == 0) {
    $redirect_url = $is_admin ? 'dashboard.php' : 'instructor_dashboard.php';
    header("Location: $redirect_url?error=no_course_selected"); 
    exit();
}
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

$message = ''; $error = '';

// Handle deleting a topic
if (isset($_GET['action']) && $_GET['action'] == 'delete_topic' && isset($_GET['id'])) {
    $topic_id_to_delete = intval($_GET['id']);
    // Security check: ensure topic belongs to this school before deleting
    $stmt = $conn->prepare("DELETE FROM forum_topics WHERE id = ? AND school_id = ?");
    $stmt->bind_param("ii", $topic_id_to_delete, $school_id);
    if($stmt->execute() && $stmt->affected_rows > 0) { 
        $message = "Topic and all its replies have been deleted successfully."; 
    } else {
        $error = "Failed to delete topic.";
    }
    $stmt->close();
}

// Handle creating a new topic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_topic'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    if (empty($title) || empty($content)) { $error = "Title and content are required."; } 
    else {
        $stmt = $conn->prepare("INSERT INTO forum_topics (school_id, course_id, user_id, title, content) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiss", $school_id, $course_id, $user_id, $title, $content);
        if ($stmt->execute()) { $message = "Topic created successfully!"; } 
        else { $error = "Failed to create topic."; }
        $stmt->close();
    }
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

// Fetch all topics for this course
$topics = [];
$sql = "SELECT t.*, u.full_name_eng as author_name,
               (SELECT COUNT(id) FROM forum_replies WHERE topic_id = t.id) as reply_count
        FROM forum_topics t
        JOIN users u ON t.user_id = u.id
        WHERE t.course_id = ? ORDER BY t.last_reply_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) { while($row = $result->fetch_assoc()) { $topics[] = $row; } }
$stmt->close();

$course_title_stmt = $conn->prepare("SELECT title FROM courses WHERE id = ?"); 
$course_title_stmt->bind_param("i", $course_id); 
$course_title_stmt->execute();
$course_title = $course_title_stmt->get_result()->fetch_assoc()['title']; 
$course_title_stmt->close();
$conn->close();
?>
<style>
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;}
    .page-header h1 { margin: 0; font-size: 28px; }
    .btn { padding: 10px 20px; border-radius: 5px; text-decoration: none; border: none; font-weight: 500; cursor: pointer; background-color: var(--brand-primary); color: white; font-size: 16px;}
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 25px; margin-bottom: 25px; }
    #create-topic-card { display: none; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: 500; margin-bottom: 5px; }
    .form-group input, .form-group textarea { width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--border-color); border-radius: 5px; background-color: var(--bg-color); color: var(--text-color); font-family: 'Poppins', sans-serif; }
    .topic-list a { text-decoration: none; }
    .topic-item { display: flex; align-items: center; gap: 15px; background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; margin-bottom: 15px; transition: all 0.2s ease; }
    .topic-item:hover { border-color: var(--brand-primary); }
    .topic-item-main { flex-grow: 1; color:var(--text-color); }
    .topic-title { font-size: 18px; font-weight: 600; color: var(--text-color); }
    .topic-meta { font-size: 13px; color: var(--text-muted); }
    .topic-stats { text-align: center; flex-shrink: 0; padding-left: 15px; }
    .stat-number { font-size: 20px; font-weight: 600; color: var(--brand-primary); }
    .delete-btn { color:#dc3545; text-decoration:none; font-weight: 500; font-size: 14px; }
    .message, .error { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
</style>

<div class="page-header">
    <h1>Discussion Forum <span style="font-weight:400; color:var(--text-muted); font-size: 20px;">for <?php echo htmlspecialchars($course_title); ?></span></h1>
    <button id="new-topic-btn" class="btn">＋ Create New Topic</button>
</div>

<?php if($message): ?><div class="message" style="color: #155724; background-color: #d4edda;"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error" style="color: #721c24; background-color: #f8d7da;"><?php echo $error; ?></div><?php endif; ?>

<div id="create-topic-card" class="card">
    <h2 style="margin-top:0;">Start a New Discussion</h2>
    <form action="forum.php?course_id=<?php echo $course_id; ?>" method="POST">
        <div class="form-group"><label for="title">Topic Title</label><input type="text" name="title" id="title" required></div>
        <div class="form-group"><label for="content">Your Question / Post</label><textarea name="content" id="content" rows="5" required></textarea></div>
        <button type="submit" name="create_topic" class="btn">Post Topic</button>
    </form>
</div>

<div class="card">
    <h2>All Topics</h2>
    <div class="topic-list">
        <?php if (empty($topics)): ?>
            <p>No topics have been created for this course yet. Be the first!</p>
        <?php else: foreach ($topics as $topic): ?>
            <div class="topic-item">
                <a href="view_topic.php?id=<?php echo $topic['id']; ?>" class="topic-item-main">
                    <div class="topic-title"><?php echo htmlspecialchars($topic['title']); ?></div>
                    <div class="topic-meta">Started by <?php echo htmlspecialchars($topic['author_name']); ?></div>
                </a>
                <div class="topic-stats">
                    <div class="stat-number"><?php echo $topic['reply_count']; ?></div>
                    <div class="topic-meta">Replies</div>
                </div>
                <a href="forum.php?course_id=<?php echo $course_id; ?>&action=delete_topic&id=<?php echo $topic['id']; ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this entire topic and all its replies?');">Delete</a>
            </div>
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

<?php 
require_once 'layout_footer.php'; 
?>
