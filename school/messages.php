<?php
// PART 1: LOGIC FIRST - Handles all actions before any HTML output
require_once 'auth_check.php';

$selected_conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;
$error = '';

// Handle sending a reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    $reply_text = trim($_POST['reply_text']);
    $conversation_id = intval($_POST['conversation_id']);

    if (!empty($reply_text) && $conversation_id > 0) {
        $sender_role = $is_admin ? 'school_admin' : 'instructor';
        
        $stmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_role, message_text) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $conversation_id, $sender_role, $reply_text);
        if ($stmt->execute()) {
            $conn->query("UPDATE conversations SET last_updated = NOW() WHERE id = {$conversation_id}");
            // This redirect now works because it's called before any HTML
            header("Location: messages.php?conversation_id=" . $conversation_id);
            exit();
        } else {
            $error = "Failed to send reply.";
        }
        $stmt->close();
    }
}

// PART 2: LOAD VISUALS & PREPARE DATA FOR DISPLAY
require_once 'layout_header.php'; 

// Fetch conversations based on user role
$conversations = [];
if ($is_admin) {
    $conv_sql = "SELECT c.id, c.last_updated, u.full_name_eng, 
                 (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.sender_role = 'student') as unread_count
                 FROM conversations c 
                 JOIN users u ON c.student_id = u.id 
                 WHERE c.school_id = ? ORDER BY c.last_updated DESC";
    $stmt = $conn->prepare($conv_sql);
    $stmt->bind_param("i", $school_id);
} else { // is_instructor
    $conv_sql = "SELECT DISTINCT c.id, c.last_updated, s.full_name_eng,
                 (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.sender_role = 'student') as unread_count
                 FROM conversations c
                 JOIN users s ON c.student_id = s.id
                 JOIN enrollments e ON s.id = e.student_id
                 JOIN course_assignments ca ON e.course_id = ca.course_id
                 WHERE ca.instructor_id = ? AND c.school_id = ? ORDER BY c.last_updated DESC";
    $stmt = $conn->prepare($conv_sql);
    $stmt->bind_param("ii", $user_id, $school_id);
}
$stmt->execute();
$conv_result = $stmt->get_result();
if ($conv_result) { while($row = $conv_result->fetch_assoc()) { $conversations[] = $row; } }
$stmt->close();

// If a conversation is selected, fetch its messages
$messages = [];
$current_student_name = '';
if ($selected_conversation_id > 0) {
    $conn->query("UPDATE messages SET is_read = 1 WHERE conversation_id = {$selected_conversation_id} AND sender_role = 'student'");
    
    $msg_sql = "SELECT m.*, s.full_name_eng 
                FROM messages m 
                JOIN conversations c ON m.conversation_id = c.id
                JOIN users s ON c.student_id = s.id
                WHERE m.conversation_id = ? ORDER BY m.sent_at ASC";
    $stmt_msg = $conn->prepare($msg_sql);
    $stmt_msg->bind_param("i", $selected_conversation_id);
    $stmt_msg->execute();
    $msg_result = $stmt_msg->get_result();
    if ($msg_result) { 
        $first = true;
        while($row = $msg_result->fetch_assoc()) {
            if($first) { $current_student_name = $row['full_name_eng']; $first = false; }
            $messages[] = $row; 
        } 
    }
    $stmt_msg->close();
}
?>
<style>
    .page-container { padding: 0; }
    .page-wrapper, .main-content { height: 100vh; overflow: hidden; }
    .messaging-layout { display: flex; height: calc(100vh - 70px); }
    .conversations-list { width: 320px; border-right: 1px solid var(--border-color); background-color: var(--card-bg-color); display: flex; flex-direction: column; flex-shrink: 0; }
    .chat-view { flex-grow: 1; display: flex; flex-direction: column; background-color: var(--bg-color); }
    .conv-header, .chat-header { padding: 20px; border-bottom: 1px solid var(--border-color); font-weight: 600; background-color: var(--card-bg-color); flex-shrink: 0;}
    .conv-list { overflow-y: auto; flex-grow: 1; }
    .conv-item { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid var(--border-color); text-decoration: none; color: var(--text-color); }
    .conv-item.active { background-color: var(--bg-color); font-weight: bold; color: var(--brand-primary); }
    .conv-item:hover { background-color: var(--bg-color); }
    .unread-badge { background-color: var(--brand-primary); color: white; font-size: 12px; font-weight: bold; border-radius: 50%; width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; }
    .messages-container { flex-grow: 1; padding: 20px; overflow-y: auto; }
    .message-bubble { max-width: 70%; padding: 10px 15px; border-radius: 15px; margin-bottom: 10px; line-height: 1.5; word-wrap: break-word; }
    .message-bubble.student { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-bottom-left-radius: 0; }
    .message-bubble.school_admin, .message-bubble.instructor { background-color: var(--brand-primary); color: white; border-bottom-right-radius: 0; margin-left: auto; }
    .message-bubble img { max-width: 100%; border-radius: 10px; margin-top: 5px; }
    .reply-form { padding: 15px; border-top: 1px solid var(--border-color); background-color: var(--card-bg-color); display: flex; gap: 10px; align-items: center; flex-shrink: 0;}
    .reply-form textarea { flex-grow: 1; padding: 10px; border-radius: 5px; border: 1px solid var(--border-color); resize: none; font-family: 'Poppins'; font-size: 16px; background-color: var(--bg-color); color: var(--text-color); }
    .reply-form button { padding: 0; border: none; border-radius: 50%; background-color: var(--brand-primary); color: white; cursor: pointer; width: 44px; height: 44px; flex-shrink: 0; font-size: 20px; display: flex; align-items: center; justify-content: center; }
    .mobile-back-btn { display: none; background:none; border:none; font-size:24px; color: var(--text-color); cursor:pointer; }
    @media (max-width: 768px) {
        .conversations-list { position: absolute; left: 0; top: 0; bottom: 0; width: 100%; height: 100%; z-index: 10; background-color: var(--card-bg-color); }
        .chat-view { width: 100%; }
        <?php if ($selected_conversation_id > 0): ?>
            .conversations-list { display: none; } .chat-view { display: flex; } .mobile-back-btn { display: block; }
        <?php else: ?>
            .chat-view { display: none; }
        <?php endif; ?>
        .chat-header { display: flex; align-items: center; gap: 15px; }
    }
</style>

<div class="messaging-layout">
    <aside class="conversations-list">
        <div class="conv-header">Conversations</div>
        <div class="conv-list">
            <?php if(empty($conversations)): ?> <p style="text-align:center; color:var(--text-muted); padding: 20px;">No conversations yet.</p>
            <?php else: foreach($conversations as $conv): ?>
            <a href="?conversation_id=<?php echo $conv['id']; ?>" class="conv-item <?php if($conv['id'] == $selected_conversation_id) echo 'active'; ?>">
                <span><?php echo htmlspecialchars($conv['full_name_eng']); ?></span>
                <?php if($conv['unread_count'] > 0): ?><span class="unread-badge"><?php echo $conv['unread_count']; ?></span><?php endif; ?>
            </a>
            <?php endforeach; endif; ?>
        </div>
    </aside>
    <main class="chat-view">
        <div class="chat-header">
            <a href="messages.php" class="mobile-back-btn">&larr;</a>
            <span><?php echo $selected_conversation_id ? htmlspecialchars($current_student_name) : 'Select a conversation'; ?></span>
        </div>
        <div class="messages-container">
            <?php foreach($messages as $msg): ?>
                <div class="message-bubble <?php echo $msg['sender_role']; ?>">
                    <?php if(!empty($msg['message_text'])) echo nl2br(htmlspecialchars($msg['message_text'])); ?>
                    <?php if(!empty($msg['attachment_path'])):
                        $file_url = '../uploads/attachments/' . htmlspecialchars($msg['attachment_path']);
                        $ext = strtolower(pathinfo($file_url, PATHINFO_EXTENSION));
                        if(in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                            <a href="<?php echo $file_url; ?>" target="_blank"><img src="<?php echo $file_url; ?>" alt="Attachment" style="max-width:200px; border-radius:10px; margin-top:5px;"></a>
                        <?php else: ?>
                            <p style="margin:5px 0 0;"><a href="<?php echo $file_url; ?>" target="_blank" style="color:inherit; font-weight:600;">📄 View Attachment</a></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if($selected_conversation_id > 0): ?>
        <form class="reply-form" action="messages.php?conversation_id=<?php echo $selected_conversation_id; ?>" method="POST">
            <input type="hidden" name="conversation_id" value="<?php echo $selected_conversation_id; ?>">
            <textarea name="reply_text" rows="1" placeholder="Type your reply..." required></textarea>
            <button type="submit" name="send_reply" title="Send Reply">➤</button>
        </form>
        <?php endif; ?>
    </main>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const messagesContainer = document.querySelector('.messages-container');
        if (messagesContainer) { messagesContainer.scrollTop = messagesContainer.scrollHeight; }
    });
</script>

<?php 
$conn->close();
require_once 'layout_footer.php'; 
?>