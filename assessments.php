<?php
require_once 'db_connect.php'; // This also starts the session

// --- MODIFIED: Session Check ---
// If session is dead, check for a cookie to redirect to the correct school's login page
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
    header('Location: dashboard.php');
    exit();
}

// 3. Use these variables on the rest of the page
$student_id = $_SESSION['student_id'];
$current_course_id = $_SESSION['current_course_id'];

// --- MODIFIED: Section 4 - Fetch School ID ---
// Added 's.id as school_id' to the query so we can set the cookie
$details_stmt = $conn->prepare("SELECT s.id as school_id, s.name as school_name, s.logo_path, s.brand_color FROM courses c JOIN schools s ON c.school_id = s.id WHERE c.id = ? LIMIT 1");
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

// 5. Fetch Unread Messages
$unread_messages_count = 0;
$unread_stmt = $conn->prepare("SELECT COUNT(m.id) as count FROM messages m JOIN conversations c ON m.conversation_id = c.id WHERE c.student_id = ? AND m.sender_role != 'student' AND m.is_read = 0");
$unread_stmt->bind_param("i", $student_id);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
if ($unread_result) { $unread_messages_count = $unread_result->fetch_assoc()['count']; }
$unread_stmt->close();


// --- MODIFIED: Optimized Quiz Query ---
// Added 'AND (q.available_to IS NULL OR q.available_to >= NOW())'
// This stops the DB from sending quizzes that have already expired.
$quizzes = [];
$quiz_sql = "SELECT q.id, q.title, q.duration_minutes, q.type, q.description, q.available_from, q.available_to
             FROM quizzes q 
             WHERE q.status = 'published' 
             AND q.course_id = ? 
             AND q.id NOT IN (SELECT qs.quiz_id FROM quiz_submissions qs WHERE qs.student_id = ?)
             AND (q.available_to IS NULL OR q.available_to >= NOW()) 
             ORDER BY q.created_at DESC";
$quiz_stmt = $conn->prepare($quiz_sql);
$quiz_stmt->bind_param("ii", $current_course_id, $student_id);
$quiz_stmt->execute();
$quiz_result = $quiz_stmt->get_result();
if ($quiz_result) { while ($row = $quiz_result->fetch_assoc()) { $quizzes[] = $row; } }
$quiz_stmt->close();
// --- END MODIFICATION ---


// Fetch COMPLETED Assessments for the CURRENT course
$completed_submissions = [];
$comp_sql = "SELECT q.title, q.result_status, qs.id as submission_id, qs.score 
             FROM quiz_submissions qs 
             JOIN quizzes q ON qs.quiz_id = q.id 
             WHERE qs.student_id = ? AND q.course_id = ? 
             ORDER BY qs.end_time DESC";
$comp_stmt = $conn->prepare($comp_sql);
$comp_stmt->bind_param("ii", $student_id, $current_course_id);
$comp_stmt->execute();
$comp_result = $comp_stmt->get_result();
if ($comp_result) { while ($row = $comp_result->fetch_assoc()) { $completed_submissions[] = $row; } }
$comp_stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assessments - <?php echo htmlspecialchars($school['name']); ?></title>
    <style>
        /* ... (All your CSS styles remain unchanged) ... */
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root {
            --brand-primary: <?php echo $school_brand_color; ?>;
            --bg-color: #f7f9fc; 
            --card-bg-color: #FFFFFF; 
            --text-color: #2c3e50;
            --text-muted: #6c757d; 
            --border-color: #e9ecef;
            --subtle-bg: #f1f3f5;
        }
        body.dark-mode {
            --bg-color: #121212; 
            --card-bg-color: #1e1e1e; 
            --text-color: #e0e0e0;
            --text-muted: #a0a0a0; 
            --border-color: #333;
            --subtle-bg: #2a2a2a;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; line-height: 1.6; }
        .header { background-color: var(--card-bg-color); border-bottom: 1px solid var(--border-color); padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1000; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .header .logo img { max-height: 35px; }
        .header .logo span { font-size: 20px; font-weight: 700; color: var(--brand-primary); vertical-align: middle; }
        .header-controls { display: flex; align-items: center; gap: 10px; }
        .header-btn { padding: 6px 14px; border-radius: 50px; font-weight: 500; text-decoration: none; border: 1px solid var(--border-color); color: var(--text-color); background-color: transparent; cursor: pointer; font-size: 13px; white-space: nowrap; transition: background-color 0.2s, color 0.2s; }
        .header-btn:hover { background-color: var(--subtle-bg); }
        .header-btn.primary { background-color: var(--brand-primary); color: white; border-color: var(--brand-primary); }
        .main-container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .page-header h1 { margin: 0 0 30px 0; font-size: 28px; font-weight: 600; }
        .assessment-layout { display: grid; grid-template-columns: 1fr; gap: 30px; }
        @media (min-width: 992px) { .assessment-layout { grid-template-columns: 1fr 1fr; } }
        .card { background-color: var(--card-bg-color); border-radius: 12px; padding: 25px; border: 1px solid var(--border-color); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .card h2 { margin-top: 0; font-size: 22px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 10px; font-weight: 600; }
        .assessment-list { display: flex; flex-direction: column; }
        .assessment-item { padding: 20px 0; border-bottom: 1px solid var(--border-color); }
        .assessment-list > .assessment-item:last-child { border-bottom: none; }
        .assessment-details h3 { margin: 0 0 10px; font-size: 18px; font-weight: 600; }
        .assessment-meta { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
        .meta-badge { display: inline-flex; align-items: center; gap: 6px; background-color: var(--subtle-bg); color: var(--text-muted); padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .meta-badge svg { width: 14px; height: 14px; }
        .assessment-action .btn {
            display: block; width: 100%; text-align: center; background-color: var(--brand-primary);
            color: white; padding: 12px; border: none; border-radius: 8px; text-decoration: none;
            font-weight: 600; font-size: 16px; cursor: pointer; transition: background-color 0.2s;
        }
        .assessment-action .btn.view-result { background-color: var(--brand-primary); }
        .assessment-action .btn.pending, .assessment-action .btn:disabled { background-color: var(--text-muted); cursor: not-allowed; opacity: 0.7; }
        .empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
        .success-message { background-color: var(--brand-primary); color: white; border: 1px solid var(--brand-primary); padding: 15px 20px; margin-bottom: 20px; border-radius: 8px; text-align: center; font-size: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .countdown-timer {
            font-size: 14px;
            font-weight: 600;
            color: var(--brand-primary);
            background-color: var(--subtle-bg);
            padding: 10px;
            border-radius: 8px;
            text-align: center;
        }
        /* Floating Chat Button */
        .floating-chat-btn { position: fixed; bottom: 25px; right: 25px; width: 60px; height: 60px; background-color: var(--brand-primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; text-decoration: none; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); z-index: 1000; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background-color: #E74C3C; color: white; width: 24px; height: 24px; border-radius: 50%; font-size: 14px; font-weight: bold; display: flex; align-items: center; justify-content: center; border: 2px solid white; }
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
            <a href="dashboard.php" class.="header-btn">Dashboard</a>
            <a href="logout.php" class.="header-btn primary">Logout</a>
        </div>
    </header>

    <div class="main-container">
        <?php if (isset($_GET['status']) && $_GET['status'] === 'submitted'): ?>
            <div class="success-message">
                <strong>Success!</strong> Your assessment has been submitted.
            </div>
        <?php endif; ?>
        <div class="page-header"><h1>My Assessments</h1></div>

        <div class="assessment-layout">
            <div class="card">
                <h2>Upcoming & Available</h2>
                <div class="assessment-list">
                    <?php if (empty($quizzes)): ?>
                        <p class="empty-state">No new assessments are available at this time.</p>
                    <?php else: 
                        foreach ($quizzes as $quiz): ?>
                        <?php
                            // --- MODIFIED: Simplified availability logic ---
                            // We no longer need to check for $is_expired, as the SQL query handles it.
                            $is_available = false;
                            $is_scheduled = false;
                            $now = new DateTime();
                            $from_time = $quiz['available_from'] ? new DateTime($quiz['available_from']) : null;

                            if ($from_time && $now < $from_time) {
                                $is_scheduled = true;
                            } else {
                                $is_available = true;
                            }
                        ?>
                        <div class="assessment-item">
                            <div class="assessment-details">
                                <h3 dir="auto"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                                <div class="assessment-meta">
                                    <span class="meta-badge">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>
        <?php echo htmlspecialchars($quiz['type']); ?>
                                    </span>
                                    <span class="meta-badge">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        <?php echo $quiz['duration_minutes']; ?> mins
                                    </span>
                                </div>
                            </div>
                            <div class="assessment-action">
                                <?php if ($is_scheduled): ?>
                                    <div class="countdown-timer" 
                                         data-starttime="<?php echo $from_time->getTimestamp(); ?>"
                                         data-quiz-id="<?php echo $quiz['id']; ?>"
                                         id="countdown-<?php echo $quiz['id']; ?>">
                                        Loading timer...
                                    </div>
                                    <a href="take_quiz.php?id=<?php echo $quiz['id']; ?>" class="btn" id="btn-<?php echo $quiz['id']; ?>" style="display: none;">Begin Assessment</a>
                                <?php elseif ($is_available): ?>
                                    <a href="take_quiz.php?id=<?php echo $quiz['id']; ?>" class="btn">Begin Assessment</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; 
                    endif; ?>
                </div>
            </div>
            <div class="card">
                <h2>Assessment History</h2>
                <div class="assessment-list">
                     <?php if (empty($completed_submissions)): ?>
                        <p class="empty-state">You have not completed any assessments yet.</p>
                    <?php else: foreach ($completed_submissions as $sub): ?>
                        <div class="assessment-item">
                            <div class="assessment-details">
                                <h3 dir="auto"><?php echo htmlspecialchars($sub['title']); ?></h3>
                                <div class="assessment-meta">
                                     <?php if ($sub['result_status'] == 'Released'): ?>
                                        <span class="meta-badge" style="background-color: #d1e7dd; color: #0a3622;">Score: <?php echo round($sub['score']); ?>%</span>
                                    <?php else: ?>
                                        <span class="meta-badge">Result Pending</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="assessment-action">
                                <?php if ($sub['result_status'] == 'Released'): ?>
                                    <a href="quiz_result.php?id=<?php echo $sub['submission_id']; ?>" class="btn view-result">View Result</a>
                                <?php else: ?>
                                    <button class="btn pending" disabled>Submitted</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
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
            // --- MODIFIED: Added localStorage to Theme Toggle ---
            const themeToggle = document.getElementById('theme-toggle');
            if(themeToggle) {
                const body = document.body;
                // Function to set the theme
                const setTheme = (theme) => { 
                    if (theme === 'dark') { 
                        body.classList.add('dark-mode'); 
                        themeToggle.textContent = '☀️'; 
                    } else { 
                        body.classList.remove('dark-mode'); 
                        themeToggle.textContent = '🌙'; 
                    } 
                };
                
                // Check for saved theme in localStorage
                const savedTheme = localStorage.getItem('theme');
                if (savedTheme) { 
                    setTheme(savedTheme); 
                }

                // Add click event listener
                themeToggle.addEventListener('click', () => { 
                    const newTheme = body.classList.contains('dark-mode') ? 'light' : 'dark'; 
                    setTheme(newTheme); 
                    localStorage.setItem('theme', newTheme); // Save the new theme
                });
            }
            // --- END MODIFICATION ---

            const timers = document.querySelectorAll('.countdown-timer');
            timers.forEach(timerEl => {
                const startTime = parseInt(timerEl.dataset.starttime, 10) * 1000;
                const quizId = timerEl.dataset.quizId;
                const buttonEl = document.getElementById('btn-' + quizId);

                const interval = setInterval(() => {
                    const now = new Date().getTime();
                    const distance = startTime - now;

                    if (distance < 0) {
                        clearInterval(interval);
                        timerEl.style.display = 'none';
                        if(buttonEl) buttonEl.style.display = 'block';
                        return;
                    }

                    const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((distance % (1000 * 60)) / 1000);

                    let timerText = "Starts in: ";
                    if (days > 0) timerText += days + "d ";
                    if (hours > 0 || days > 0) timerText += hours + "h ";
                    timerText += minutes + "m " + seconds + "s";
                    
                    timerEl.innerHTML = timerText;

                }, 1000);
            });
        });
    </script>
</body>
</html>
