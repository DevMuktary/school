<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: forum.php'); exit(); }
$topic_id = intval($_GET['id']);
$message = ''; $error = '';

// Fetch main topic details to get course_id for security check
$topic_check_stmt = $conn->prepare("SELECT course_id FROM forum_topics WHERE id = ? AND school_id = ?");
$topic_check_stmt->bind_param("ii", $topic_id, $school_id);
$topic_check_stmt->execute();
$topic_check_result = $topic_check_stmt->get_result();
if($topic_check_result->num_rows === 0) { die("Topic not found."); }
$course_id = $topic_check_result->fetch_assoc()['course_id'];
$topic_check_stmt->close();
verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);

// Handle moderation (deleting a reply)
if (isset($_GET['action']) && $_GET['action'] == 'delete_reply' && isset($_GET['reply_id'])) {
    $reply_id_to_delete = intval($_GET['reply_id']);
    $stmt = $conn->prepare("DELETE FROM forum_replies WHERE id = ? AND topic_id = ?");
    $stmt->bind_param("ii", $reply_id_to_delete, $topic_id);
    if($stmt->execute() && $stmt->affected_rows > 0) { $message = "Reply deleted successfully."; }
    else { $error = "Failed to delete reply."; }
    $stmt->close();
}

// Handle adding a new reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_reply'])) {
    $content = trim($_POST['content']);
    if (empty($content)) {
        $error = "Reply cannot be empty.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO forum_replies (topic_id, user_id, content) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $topic_id, $user_id, $content);
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

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

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
<style>
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
    .reply-header { display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: var(--text-muted); margin-bottom: 10px; flex-wrap: wrap; gap: 10px;}
    .author-name { font-weight: 600; color: var(--text-color); }
    .author-role { background-color: var(--bg-color); padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; border: 1px solid var(--border-color);}
    .reply-content { line-height: 1.6; }
    .reply-form-card { background-color: var(--card-bg-color); border-radius: 8px; padding: 25px; border-top: 3px solid var(--brand-primary); }
    .form-group textarea { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid var(--border-color); border-radius: 5px; font-size: 16px; min-height: 100px; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
    .delete-link { font-size:12px; color: #dc3545; text-decoration:none; font-weight:500; }
</style>

<div class="page-header">
    <a href="forum.php?course_id=<?php echo $course_id; ?>">&larr; Back to All Topics</a>
</div>

<?php if($message): ?><div class="message" style="color:#155724; background-color:#d4edda; padding:15px; border-radius:5px; margin-bottom:20px;"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error" style="color:#721c24; background-color:#f8d7da; padding:15px; border-radius:5px; margin-bottom:20px;"><?php echo $error; ?></div><?php endif; ?>

<div class="original-post">
    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <h1><?php echo htmlspecialchars($topic['title']); ?></h1>
        <a href="forum.php?course_id=<?php echo $course_id; ?>&action=delete_topic&id=<?php echo $topic['id']; ?>" class="delete-link" onclick="return confirm('Are you sure? This will delete the entire topic and all replies.');">Delete Topic</a>
    </div>
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
                <div class="author-avatar" title="<?php echo htmlspecialchars($reply['author_name']); ?>"><?php echo strtoupper(substr($reply['author_name'], 0, 1)); ?></div>
            </div>
            <div class="reply-body">
                <div class="reply-header">
                    <div>
                        <span class="author-name"><?php echo htmlspecialchars($reply['author_name']); ?></span>
                        <?php if($reply['author_role'] !== 'student'): ?>
                            <span class="author-role"><?php echo ucwords(str_replace('_', ' ', $reply['author_role'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; align-items:center; gap: 15px;">
                        <span><?php echo date("M j, Y, g:i a", strtotime($reply['created_at'])); ?></span>
                        <a href="?id=<?php echo $topic_id; ?>&action=delete_reply&reply_id=<?php echo $reply['id']; ?>" class="delete-link" onclick="return confirm('Are you sure?');">Delete</a>
                    </div>
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

<?php 
require_once 'layout_footer.php'; 
?>
