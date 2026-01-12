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

// 4. Fetch School and Current Course details for branding and IDs
$details_stmt = $conn->prepare("
    SELECT s.id as school_id, s.name as school_name, s.logo_path, s.brand_color
    FROM courses c
    JOIN schools s ON c.school_id = s.id
    WHERE c.id = ? LIMIT 1
");
$details_stmt->bind_param("i", $current_course_id);
$details_stmt->execute();
$details_data = $details_stmt->get_result()->fetch_assoc();
$current_school_id = $details_data['school_id'];
$school = [ 'name' => $details_data['school_name'], 'logo_path' => $details_data['logo_path'], 'brand_color' => $details_data['brand_color'] ];
$school_brand_color = !empty($school['brand_color']) ? $school['brand_color'] : '#001232';
$details_stmt->close();

// 5. Fetch other page-specific data
// Fetch student's email for Paystack
$student_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student_email = $student_stmt->get_result()->fetch_assoc()['email'];
$student_stmt->close();

// Fetch all UNPAID fees for this student AND the CURRENTLY VIEWED course
$unpaid_fees = [];
$fees_sql = "SELECT fs.*
             FROM fee_structures fs
             WHERE fs.course_id = ? AND fs.school_id = ?
             AND fs.id NOT IN (
                SELECT sp.fee_id FROM student_payments sp WHERE sp.student_id = ?
             )";
$fees_stmt = $conn->prepare($fees_sql);
$fees_stmt->bind_param("iii", $current_course_id, $current_school_id, $student_id);
$fees_stmt->execute();
$fees_result = $fees_stmt->get_result();
if ($fees_result) { while($row = $fees_result->fetch_assoc()) { $unpaid_fees[] = $row; } }
$fees_stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay School Fees - <?php echo htmlspecialchars($school['name']); ?></title>
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
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; }
        .header { background-color: var(--card-bg-color); border-bottom: 1px solid var(--border-color); padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1000; }
        .header .logo img { max-height: 35px; }
        .header .logo span { font-size: 20px; font-weight: 700; color: var(--brand-primary); }
        .header-controls { display: flex; align-items: center; gap: 10px; }
        .header-btn { padding: 6px 14px; border-radius: 50px; font-weight: 500; text-decoration: none; border: 1px solid var(--border-color); color: var(--text-color); background-color: transparent; cursor: pointer; font-size: 13px; white-space: nowrap; }
        .header-btn.primary { background-color: var(--brand-primary); color: white; border-color: var(--brand-primary); }
        
        .main-container { max-width: 900px; margin: 0 auto; padding: 30px 20px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 600; }
        
        .fees-list { display: flex; flex-direction: column; gap: 15px; }
        .fee-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: var(--card-bg-color);
            border-radius: 8px;
            padding: 20px;
            border: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 15px;
        }
        .fee-details .title { font-size: 18px; font-weight: 600; }
        .fee-details .amount { font-size: 22px; font-weight: 700; color: var(--brand-primary); }
        .btn-pay { padding: 12px 30px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body class="">
    <header class="header">
        <div class="logo">
            <?php if (!empty($school['logo_path'])): ?>
                <img src="uploads/logos/<?php echo htmlspecialchars($school['logo_path']); ?>" alt="<?php echo htmlspecialchars($school['name']); ?> Logo">
            <?php else: ?>
                <span><?php echo htmlspecialchars($school['name']); ?></span>
            <?php endif; ?>
        </div>
        <div class="header-controls">
            <button id="theme-toggle" class="header-btn">🌙</button>
            <a href="dashboard.php" class="header-btn">Dashboard</a>
            <a href="logout.php" class="header-btn primary">Logout</a>
        </div>
    </header>

    <div class="main-container">
        <div class="page-header">
            <h1>Pay School Fees</h1>
        </div>
        <div class="fees-list">
            <?php if (empty($unpaid_fees)): ?>
                <div class="fee-card" style="justify-content: center;">
                    <p style="color: var(--text-muted); font-size: 18px;">✅ You have no outstanding payments for this course.</p>
                </div>
            <?php else: foreach ($unpaid_fees as $fee): ?>
                <div class="fee-card">
                    <div class="fee-details">
                        <div class="title"><?php echo htmlspecialchars($fee['fee_title']); ?></div>
                        <div class="amount">₦<?php echo number_format($fee['amount'], 2); ?></div>
                    </div>
                    <button class="btn-pay" onclick="payFee('<?php echo $fee['id']; ?>', <?php echo $fee['amount'] * 100; ?>)">Pay Now with Paystack</button>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    
    <script src="https://js.paystack.co/v1/inline.js"></script>
    <script>
        function payFee(feeId, amountInKobo) {
            var handler = PaystackPop.setup({
                key: '<?php echo PAYSTACK_PUBLIC_KEY; ?>',
                email: '<?php echo $student_email; ?>',
                amount: amountInKobo,
                metadata: {
                    student_id: <?php echo $student_id; ?>,
                    school_id: <?php echo $current_school_id; ?>,
                    course_id: <?php echo $current_course_id; ?>,
                    fee_id: feeId,
                    type: 'student_fee' // Important for the webhook
                },
                callback: function(response){
                    window.location = 'payment_verify.php?reference=' + response.reference;
                },
                onClose: function(){
                    alert('Transaction was not completed.');
                }
            });
            handler.openIframe();
        }

        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('theme-toggle');
            const body = document.body;
            const setTheme = (theme) => { if (theme === 'dark') { body.classList.add('dark-mode'); themeToggle.textContent = '☀️'; } else { body.classList.remove('dark-mode'); themeToggle.textContent = '🌙'; } };
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) { setTheme(savedTheme); }
            themeToggle.addEventListener('click', () => { const newTheme = body.classList.contains('dark-mode') ? 'light' : 'dark'; setTheme(newTheme); localStorage.setItem('theme', newTheme); });
        });
    </script>
</body>
</html>
