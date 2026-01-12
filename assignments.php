<?php
require_once 'db_connect.php'; // This also starts the session

// --- MODIFIED: Session Check ---
// 1. Check if the user is logged in
if (!isset($_SESSION['student_id'])) {
    if (isset($_COOKIE['last_school_id'])) {
        $last_school_id = $_COOKIE['last_school_id'];
        // Redirect to the login page, passing the school ID
        header("Location: login.php?school_id=" . urlencode($last_school_id));
        exit();
    } else {
        // No session AND no cookie, fallback to main index.
        header('Location: index.php');
        exit();
    }
}
// --- END MODIFICATION ---

// 2. Check if a course has been selected from the dashboard
if (!isset($_SESSION['current_course_id'])) {
    // If no course is selected, send them back to the dashboard to choose one.
    header('Location: dashboard.php');
    exit();
}

// 3. Use these variables on the rest of the page
$student_id = $_SESSION['student_id'];
$current_course_id = $_SESSION['current_course_id'];

// --- MODIFIED: Added s.id as school_id ---
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
$school_id = $details_data['school_id']; // <-- Get the school_id
$school = [ 'name' => $details_data['school_name'], 'logo_path' => $details_data['logo_path'], 'brand_color' => $details_data['brand_color'] ];
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#001232';
$details_stmt->close();

// --- ADDED: Set/Refresh cookie to remember the school ---
setcookie('last_school_id', $school_id, time() + (86400 * 30), "/");
// --- END ADDITION ---

// 5. Fetch other page-specific data
// --- MODIFIED: Added unread messages query back in ---
$unread_messages_count = 0;
$unread_stmt = $conn->prepare("SELECT COUNT(m.id) as count FROM messages m JOIN conversations c ON m.conversation_id = c.id WHERE c.student_id = ? AND m.sender_role != 'student' AND m.is_read = 0");
$unread_stmt->bind_param("i", $student_id);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
if ($unread_result) { $unread_messages_count = $unread_result->fetch_assoc()['count']; }
$unread_stmt->close();
// --- END MODIFICATION ---

// Fetch all assignments for the CURRENT course, and check if they have a submission
$assignments = [];
$sql = "SELECT a.*, asub.id as submission_id, asub.submitted_at, asub.grade, asub.feedback
        FROM assignments a
        LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND asub.student_id = ?
        WHERE a.course_id = ?
        ORDER BY a.due_date DESC, a.created_at DESC";
$stmt = $conn->prepare($sql);
// Use the session variable for the course ID
$stmt->bind_param("ii", $student_id, $current_course_id);
$stmt->execute();
$result = $stmt->get_result();
if($result) { while($row = $result->fetch_assoc()) { $assignments[] = $row; } }
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assignments - <?php echo htmlspecialchars($school['name']); ?></title>
    <style>
        /* ... (All your CSS styles remain unchanged) ... */
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
        .header .logo img { max-height: 35px; } .header .logo span { font-size: 20px; font-weight: 700; color: var(--brand-primary); }
        .header-controls { display: flex; align-items: center; gap: 10px; }
        .header-btn { padding: 6px 14px; border-radius: 50px; font-weight: 500; text-decoration: none; border: 1px solid var(--border-color); color: var(--text-color); background-color: transparent; cursor: pointer; font-size: 13px; white-space: nowrap; }
        .header-btn.primary { background-color: var(--brand-primary); color: white; border-color: var(--brand-primary); }
        
        .main-container { max-width: 900px; margin: 0 auto; padding: 30px 20px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 600; }
        
        .assignment-list { display: flex; flex-direction: column; gap: 25px; }
        .assignment-card { background-color: var(--card-bg-color); border-radius: 8px; padding: 25px; border: 1px solid var(--border-color); }
        .assignment-card h3 { margin: 0 0 5px; font-size: 20px; }
        .assignment-card .meta { font-size: 13px; color: var(--text-muted); margin-bottom: 15px; }
        .assignment-card .instructions { line-height: 1.6; border-left: 3px solid var(--border-color); padding-left: 15px; margin: 20px 0; }
        .attachment-link { display: inline-block; background-color: var(--bg-color); border: 1px solid var(--border-color); padding: 8px 15px; border-radius: 5px; text-decoration: none; color: var(--text-color); font-weight: 500; margin-bottom: 20px;}
        
        .submission-form .form-group { margin-bottom: 15px; }
        .submission-form label { display: block; font-weight: 500; margin-bottom: 5px; }
        .submission-form input, .submission-form textarea { width: 100%; box-sizing: border-box; padding: 10px; border-radius: 5px; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); font-size: 16px; }
        .btn-submit { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
        
        .submission-status { background-color: var(--bg-color); border: 1px solid var(--border-color); border-radius: 5px; padding: 20px; }
        .submission-status h4 { margin-top: 0; }
        .feedback { margin-top: 15px; padding-top: 15px; border-top: 1px dashed var(--border-color); }

        .floating-chat-btn { position: fixed; bottom: 25px; right: 25px; width: 60px; height: 60px; background-color: var(--brand-primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; text-decoration: none; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); z-index: 1000; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background-color: #E74C3C; color: white; width: 24px; height: 24px; border-radius: 50%; font-size: 14px; font-weight: bold; display: flex; align-items: center; justify-content: center; border: 2px solid white; }
        .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center; }
        .message { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

    </style>
</head>
<body class="">
    <header class="header">
        <div class="logo">
            <a href="dashboard.php">
            <?php if (!empty($school['logo_path'])): ?>
                <img src="uploads/logos/<?php echo htmlspecialchars($school['logo_path']); ?>" alt="<?php echo htmlspecialchars($school['name']); ?> Logo">
            <?php else: ?>
                <span><?php echo htmlspecialchars($school['name']); ?></span>
            <?php endif; ?>
            </a>
        </div>
        <div class="header-controls">
            <button id="theme-toggle" class="header-btn">🌙</button>
            <a href="dashboard.php" class="header-btn">Dashboard</a>
            <a href="logout.php" class="header-btn primary">Logout</a>
        </div>
    </header>

    <div class="main-container">
        <div class="page-header"><h1>My Assignments</h1></div>
        
        <?php if(isset($_GET['status']) && $_GET['status'] == 'success'): ?><div class="message">Your assignment was submitted successfully!</div><?php endif; ?>
        <?php if(isset($_GET['error'])): ?><div class="error">There was a problem with your submission. Please try again.</div><?php endif; ?>

        <div class="assignment-list">
            <?php if (empty($assignments)): ?>
                <div class="assignment-card" style="text-align: center; color: var(--text-muted);">No assignments have been posted for this course yet.</div>
            <?php else: foreach ($assignments as $assignment): ?>
                <div class="assignment-card">
                    <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                    <div class="meta"><strong>Due:</strong> <?php echo !empty($assignment['due_date']) ? date("F j, Y, g:i a", strtotime($assignment['due_date'])) : 'No due date'; ?></div>
                    
                    <?php if(!empty($assignment['instructions'])): ?><div class="instructions"><?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?></div><?php endif; ?>
                    <?php if(!empty($assignment['attachment_path'])): ?><a href="uploads/assignments/<?php echo htmlspecialchars($assignment['attachment_path']); ?>" class="attachment-link" download>Download Attached File</a><?php endif; ?>
                    <hr style="border:0; border-top: 1px solid var(--border-color); margin: 20px 0;">

                    <?php if ($assignment['submission_id']): // If already submitted ?>
                        <div class="submission-status">
                            <h4>Your Submission</h4>
                            <p><strong>Submitted On:</strong> <?php echo date("M j, Y, g:i a", strtotime($assignment['submitted_at'])); ?></p>
                            <p><strong>Grade:</strong> <?php echo $assignment['grade'] ? '<strong>'.htmlspecialchars($assignment['grade']).'</strong>' : '<span style="color:var(--text-muted);">Not Graded Yet</span>'; ?></p>
                            <?php if(!empty($assignment['feedback'])): ?>
                                <div class="feedback">
                                    <strong>Instructor Feedback:</strong>
                                    <p style="margin-top:5px; font-style:italic;"><?php echo nl2br(htmlspecialchars($assignment['feedback'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: // If not yet submitted ?>
                        <form class="submission-form" action="submit_assignment.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                            <div class="form-group">
                                <label for="submission_file_<?php echo $assignment['id']; ?>">Upload Your File</label>
                                <input type="file" name="submission_file" id="submission_file_<?php echo $assignment['id']; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="submission_text_<?php echo $assignment['id']; ?>">Comments (Optional)</label>
                                <textarea name="submission_text" id="submission_text_<?php echo $assignment['id']; ?>" rows="3"></textarea>
                            </div>
                            <button type="submit" class="btn-submit">Submit Assignment</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    
    <a href="messages.php" class="floating-chat-btn">
        💬
        <?php if ($unread_messages_count > 0): ?>
            <span class="notification-badge"><?php echo $unread_messages_count; ?></span>
        <?php endif; ?>
    </a>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('theme-toggle');
            if(themeToggle) {
                const body = document.body;
                const setTheme = (theme) => { 
                    if (theme === 'dark') { 
                        body.classList.add('dark-mode'); 
                        themeToggle.textContent = '☀️'; 
                    } else { 
                        body.classList.remove('dark-mode'); 
                        themeToggle.textContent = '🌙'; 
                    } 
                };
                const savedTheme = localStorage.getItem('theme');
                if (savedTheme) { 
                    setTheme(savedTheme); 
                }
                themeToggle.addEventListener('click', () => { 
                    const newTheme = body.classList.contains('dark-mode') ? 'light' : 'dark'; 
                    setTheme(newTheme); 
                    localStorage.setItem('theme', newTheme); 
                });
            }
        });
    </script>
</body>
</html>
