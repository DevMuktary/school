<?php
require_once 'db_connect.php'; // This also starts the session

// 1. Check if the user is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: index.php');
    exit();
}

// 2. Check if a course has been selected from the dashboard for consistent branding
if (!isset($_SESSION['current_course_id'])) {
    // If no course is selected, send them back to the dashboard to choose one.
    header('Location: dashboard.php');
    exit();
}

// 3. Use these variables on the rest of the page
$student_id = $_SESSION['student_id'];
$current_course_id = $_SESSION['current_course_id'];
$upload_dir = 'uploads/attachments/';
$error = '';

// 4. Fetch School details based on the current course for branding
$details_stmt = $conn->prepare("
    SELECT s.id as school_id, s.name as school_name, s.logo_path, s.brand_color
    FROM courses c
    JOIN schools s ON c.school_id = s.id
    WHERE c.id = ? LIMIT 1
");
$details_stmt->bind_param("i", $current_course_id);
$details_stmt->execute();
$details_data = $details_stmt->get_result()->fetch_assoc();
$current_school_id = $details_data['school_id']; // Get the school_id for creating a conversation if needed
$school = [ 'name' => $details_data['school_name'], 'logo_path' => $details_data['logo_path'], 'brand_color' => $details_data['brand_color'] ];
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#001232';
$details_stmt->close();

// --- Find or Create a Conversation for this student (Logic is per-student, not per-course) ---
$conv_stmt = $conn->prepare("SELECT id FROM conversations WHERE student_id = ?");
$conv_stmt->bind_param("i", $student_id);
$conv_stmt->execute();
$conv_result = $conv_stmt->get_result();
if ($conv_result->num_rows > 0) {
    $conversation_id = $conv_result->fetch_assoc()['id'];
} else {
    // If a new conversation is created, it's tied to the school of the course they are currently viewing
    $insert_stmt = $conn->prepare("INSERT INTO conversations (student_id, school_id) VALUES (?, ?)");
    $insert_stmt->bind_param("ii", $student_id, $current_school_id);
    $insert_stmt->execute();
    $conversation_id = $conn->insert_id;
    $insert_stmt->close();
}
$conv_stmt->close();

// --- Handle sending a message with potential attachment ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message_text = trim($_POST['message_text']);
    $attachment_path = null;
    $has_content = false;

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $file = $_FILES['attachment'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        if(in_array($file['type'], $allowed_types) && $file['size'] < 5000000) { // Max 5MB
            $file_name = time() . '_' . uniqid() . '_' . basename($file['name']);
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $file_name)) {
                $attachment_path = $file_name;
                $has_content = true;
            } else {
                $error = "Error: Could not upload the file. Please check folder permissions.";
            }
        } else {
             $error = "Error: Invalid file type or file is too large (Max 5MB). Allowed types: JPG, PNG, GIF, PDF.";
        }
    }
    if(!empty($message_text)) { $has_content = true; }

    if ($has_content && empty($error)) {
        $stmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_role, message_text, attachment_path) VALUES (?, 'student', ?, ?)");
        if($stmt) {
            $stmt->bind_param("iss", $conversation_id, $message_text, $attachment_path);
            if ($stmt->execute()) {
                $conn->query("UPDATE conversations SET last_updated = NOW() WHERE id = {$conversation_id}");
                header("Location: messages.php");
                exit();
            } else {
                $error = "Database Error: Could not save the message.";
            }
            $stmt->close();
        } else {
            $error = "Database Error: Could not prepare the statement.";
        }
    } elseif(empty($error)) {
        $error = "You cannot send an empty message.";
    }
}

// Mark admin/instructor messages as read
$conn->query("UPDATE messages SET is_read = 1 WHERE conversation_id = {$conversation_id} AND sender_role != 'student'");
// Fetch all messages for this conversation
$messages = [];
$msg_stmt = $conn->prepare("SELECT sender_role, message_text, attachment_path, sent_at FROM messages WHERE conversation_id = ? ORDER BY sent_at ASC");
$msg_stmt->bind_param("i", $conversation_id);
$msg_stmt->execute();
$msg_result = $msg_stmt->get_result();
if ($msg_result) { while($row = $msg_result->fetch_assoc()) { $messages[] = $row; } }
$msg_stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Messages - <?php echo htmlspecialchars($school['name']); ?></title>
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
        html, body { height: 100%; margin: 0; overflow: hidden; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); display: flex; flex-direction: column; }
        
        .chat-wrapper { flex-grow: 1; display: flex; justify-content: center; overflow: hidden; background-color: var(--card-bg-color); }
        .chat-container { width: 100%; max-width: 800px; display: flex; flex-direction: column; }
        .chat-header { 
            padding: 10px 20px; 
            border-bottom: 1px solid var(--border-color); 
            font-weight: 600; font-size: 18px; 
            flex-shrink: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .close-btn { font-size: 32px; text-decoration: none; color: var(--text-muted); font-weight: 300; line-height: 1; }
        .messages-container { flex-grow: 1; padding: 20px; overflow-y: auto; }
        .message-bubble { max-width: 75%; padding: 10px 15px; border-radius: 18px; margin-bottom: 10px; line-height: 1.5; word-wrap: break-word; }
        .message-bubble.student { background-color: var(--brand-primary); color: white; border-bottom-right-radius: 4px; margin-left: auto; }
        .message-bubble.admin, .message-bubble.school_admin, .message-bubble.instructor { background-color: var(--bg-color); color: var(--text-color); border-bottom-left-radius: 4px; }
        .message-bubble img { max-width: 100%; border-radius: 10px; margin-top: 5px; cursor: pointer; }
        .message-bubble a { color: inherit; font-weight: 600; text-decoration: underline; }
        .message-time { font-size: 11px; color: var(--text-muted); margin-top: 5px; text-align: right; }
        .message-bubble.student .message-time { color: rgba(255,255,255,0.7); }
        .reply-form { padding: 10px 15px; border-top: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .reply-form textarea { flex-grow: 1; padding: 10px 15px; border-radius: 20px; border: 1px solid var(--border-color); resize: none; font-family: 'Poppins', sans-serif; font-size: 16px; background-color: var(--bg-color); color: var(--text-color); }
        .reply-form button { padding: 10px; border: none; border-radius: 50%; background-color: var(--brand-primary); color: white; cursor: pointer; font-size: 18px; width: 42px; height: 42px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;}
        .attachment-label { cursor: pointer; padding: 10px; }
        .attachment-label svg { width: 24px; height: 24px; color: var(--text-muted); }
        #attachment-input { display: none; }
        #file-name-display { font-size: 12px; color: var(--text-muted); margin-left: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; text-align: right; flex-shrink: 0; padding-right: 15px;}
        .error { padding: 15px; border-radius: 5px; color: #D8000C; background-color: #FFD2D2; text-align: center; }
    </style>
</head>
<body class="">
    <div class="chat-wrapper">
        <div class="chat-container">
            <div class="chat-header">
                <span>Conversation with Admin</span>
                <a href="javascript:history.back()" class="close-btn">&times;</a>
            </div>
            <?php if (!empty($error)): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>
            <div class="messages-container">
                <?php if (empty($messages)): ?>
                    <p style="text-align:center; color:var(--text-muted);">Your conversation will appear here. Send a message to get started.</p>
                <?php else: foreach($messages as $msg): ?>
                    <div class="message-bubble <?php echo $msg['sender_role']; ?>">
                        <?php if(!empty($msg['message_text'])) echo nl2br(htmlspecialchars($msg['message_text'])); ?>
                        <?php if(!empty($msg['attachment_path'])):
                            $file_url = 'uploads/attachments/' . htmlspecialchars($msg['attachment_path']);
                            $ext = strtolower(pathinfo($file_url, PATHINFO_EXTENSION));
                            if(in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                <a href="<?php echo $file_url; ?>" target="_blank"><img src="<?php echo $file_url; ?>" alt="Attachment"></a>
                            <?php else: ?>
                                <p style="margin-top:5px;"><a href="<?php echo $file_url; ?>" target="_blank">📄 View Attachment</a></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="message-time"><?php echo date("D, g:i A", strtotime($msg['sent_at'])); ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <form class="reply-form" action="messages.php" method="POST" enctype="multipart/form-data">
                <label for="attachment-input" class="attachment-label" title="Add Attachment">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                </label>
                <input type="file" name="attachment" id="attachment-input">
                <textarea name="message_text" rows="1" placeholder="Type your message..."></textarea>
                <button type="submit" name="send_message" title="Send Message">➤</button>
            </form>
            <div id="file-name-display"></div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const messagesContainer = document.querySelector('.messages-container');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
            const attachmentInput = document.getElementById('attachment-input');
            const fileNameDisplay = document.getElementById('file-name-display');
            if(attachmentInput) {
                attachmentInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        fileNameDisplay.textContent = 'File: ' + this.files[0].name;
                    } else {
                        fileNameDisplay.textContent = '';
                    }
                });
            }
        });
    </script>
</body>
</html>
