<?php
require_once '../db_connect.php';
if (!isset($_SESSION['super_admin_id'])) {
    header('Location: index.php');
    exit();
}

// --- Fetch Platform-Wide Statistics ---
$total_schools = $conn->query("SELECT COUNT(id) as count FROM schools WHERE subscription_status = 'active'")->fetch_assoc()['count'];
$pending_schools = $conn->query("SELECT COUNT(id) as count FROM schools WHERE subscription_status = 'awaiting_payment'")->fetch_assoc()['count'];
$total_students = $conn->query("SELECT COUNT(id) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$total_payouts_pending_value = $conn->query("SELECT SUM(amount) as total FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['total'] ?? 0;

// --- Fetch Recent Pending Payouts ---
$pending_payouts = [];
$payout_sql = "SELECT pr.*, s.name as school_name FROM payout_requests pr JOIN schools s ON pr.school_id = s.id WHERE pr.status = 'pending' ORDER BY pr.requested_at ASC LIMIT 5";
$payout_result = $conn->query($payout_sql);
if($payout_result) { while($row = $payout_result->fetch_assoc()){ $pending_payouts[] = $row; } }

// --- Fetch Recent Schools Awaiting Payment ---
$recent_schools = [];
$school_sql = "SELECT * FROM schools WHERE subscription_status = 'awaiting_payment' ORDER BY created_at DESC LIMIT 5";
$school_result = $conn->query($school_sql);
if($school_result) { while($row = $school_result->fetch_assoc()){ $recent_schools[] = $row; } }

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { --brand-red: #E74C3C; --text-dark: #2c3e50; }
        body { font-family: 'Poppins', sans-serif; background-color: #f7f9fc; margin: 0; color: var(--text-dark); }
        .header { background-color: var(--text-dark); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .header .logo { font-size: 22px; font-weight: 700; }
        .header .logo span { color: var(--brand-red); }
        .container { max-width: 1200px; margin: 0 auto; padding: 25px 15px; }
        .page-header h1 { margin: 0; font-size: 28px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 25px; }
        .stat-card { background-color: #FFFFFF; border-radius: 8px; padding: 25px; border: 1px solid #e9ecef; }
        .stat-card .stat-title { font-size: 15px; color: #6c757d; }
        .stat-card .stat-number { font-size: 36px; font-weight: 600; color: var(--text-dark); }
        .stat-card .stat-number.highlight { color: var(--brand-red); }
        
        .dashboard-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 30px; align-items: start; }
        @media(max-width: 992px) { .dashboard-layout { grid-template-columns: 1fr; } }
        
        .card { background-color: #FFFFFF; border-radius: 8px; padding: 25px; border: 1px solid #e9ecef; }
        .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-red); padding-bottom: 10px; }
        .card-footer { margin-top: 20px; text-align: right; }
        .card-footer a { text-decoration: none; font-weight: 500; color: var(--brand-red); }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        .btn { padding: 8px 15px; border-radius: 5px; text-decoration: none; font-weight: 500; background-color: var(--brand-red); color: white; }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo">INTRA-EDU<span>.</span> Super Admin</div>
        <div>
            <a href="payouts.php" style="color:white; text-decoration:none;">Manage Payouts</a>
            <a href="logout.php" style="color:white; text-decoration:none; margin-left:20px;">Logout</a>
        </div>
    </header>
    <div class="container">
        <div class="page-header"><h1>Dashboard</h1></div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title">Active Schools</div>
                <div class="stat-number"><?php echo $total_schools; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Total Students</div>
                <div class="stat-number"><?php echo $total_students; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Schools Awaiting Payment</div>
                <div class="stat-number"><?php echo $pending_schools; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Pending Payouts Value</div>
                <div class="stat-number highlight">₦<?php echo number_format($total_payouts_pending_value, 2); ?></div>
            </div>
        </div>

        <div class="dashboard-layout">
            <div class="card">
                <h2>Pending Payout Requests</h2>
                <table>
                    <thead><tr><th>School</th><th>Amount</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php if(empty($pending_payouts)): ?>
                            <tr><td colspan="3" style="text-align: center;">No pending payouts.</td></tr>
                        <?php else: foreach($pending_payouts as $payout): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($payout['school_name']); ?></td>
                                <td>₦<?php echo number_format($payout['amount'], 2); ?></td>
                                <td><a href="payouts.php?action=mark_paid&id=<?php echo $payout['id']; ?>" class="btn">Process</a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <div class="card-footer">
                    <a href="payouts.php">View All Payouts &rarr;</a>
                </div>
            </div>

            <div class="card">
                <h2>Schools Awaiting Payment</h2>
                <table>
                    <thead><tr><th>School Name</th><th>Registered On</th></tr></thead>
                    <tbody>
                        <?php if(empty($recent_schools)): ?>
                            <tr><td colspan="2" style="text-align: center;">No schools are awaiting payment.</td></tr>
                        <?php else: foreach($recent_schools as $school): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($school['name']); ?></td>
                                <td><?php echo date("M j, Y", strtotime($school['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
