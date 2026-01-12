<?php 
require_once 'layout_header.php';

// This is a School Admin-only page.
if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

$message = ''; $error = '';

// --- Calculate Current Balance ---
$total_payments_stmt = $conn->prepare("SELECT SUM(amount_paid) as total FROM student_payments WHERE school_id = ?");
$total_payments_stmt->bind_param("i", $school_id);
$total_payments_stmt->execute();
$total_payments = $total_payments_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$total_payments_stmt->close();

$total_payouts_stmt = $conn->prepare("SELECT SUM(amount) as total FROM payout_requests WHERE school_id = ? AND status = 'paid'");
$total_payouts_stmt->bind_param("i", $school_id);
$total_payouts_stmt->execute();
$total_payouts = $total_payouts_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$total_payouts_stmt->close();

$pending_payouts_stmt = $conn->prepare("SELECT SUM(amount) as total FROM payout_requests WHERE school_id = ? AND status = 'pending'");
$pending_payouts_stmt->bind_param("i", $school_id);
$pending_payouts_stmt->execute();
$pending_payouts = $pending_payouts_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$pending_payouts_stmt->close();

// Available balance is total revenue minus what has been paid out AND what is pending
$current_balance = $total_payments - $total_payouts - $pending_payouts;

// --- Handle Withdrawal Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_withdrawal'])) {
    $amount_to_withdraw = floatval($_POST['amount']);
    if ($amount_to_withdraw <= 0) {
        $error = "Please enter a valid amount to withdraw.";
    } elseif ($amount_to_withdraw > $current_balance) {
        $error = "Withdrawal amount cannot be greater than your available balance.";
    } else {
        $stmt = $conn->prepare("INSERT INTO payout_requests (school_id, amount) VALUES (?, ?)");
        $stmt->bind_param("id", $school_id, $amount_to_withdraw);
        if ($stmt->execute()) {
            $message = "Your withdrawal request of ₦" . number_format($amount_to_withdraw, 2) . " has been submitted. It will be processed shortly.";
            $current_balance -= $amount_to_withdraw; // Immediately reflect the new balance
            $pending_payouts += $amount_to_withdraw;
        } else {
            $error = "Could not submit your request. Please try again.";
        }
        $stmt->close();
    }
}

// --- Fetch Payment and Payout Histories ---
$payment_history = [];
$payment_sql = "SELECT sp.*, u.full_name_eng, fs.fee_title FROM student_payments sp JOIN users u ON sp.student_id = u.id JOIN fee_structures fs ON sp.fee_id = fs.id WHERE sp.school_id = ? ORDER BY sp.payment_date DESC LIMIT 20";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("i", $school_id);
$payment_stmt->execute();
$payment_result = $payment_stmt->get_result();
if($payment_result) { while($row = $payment_result->fetch_assoc()) { $payment_history[] = $row; } }
$payment_stmt->close();

$payout_history = [];
$payout_sql = "SELECT * FROM payout_requests WHERE school_id = ? ORDER BY requested_at DESC LIMIT 20";
$payout_stmt = $conn->prepare($payout_sql);
$payout_stmt->bind_param("i", $school_id);
$payout_stmt->execute();
$payout_result = $payout_stmt->get_result();
if($payout_result) { while($row = $payout_result->fetch_assoc()) { $payout_history[] = $row; } }
$payout_stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 25px; }
    .stat-card .stat-title { font-size: 15px; color: var(--text-muted); }
    .stat-card .stat-number { font-size: 32px; font-weight: 600; color: var(--brand-primary); }
    .grid-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 25px; align-items: start; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    .form-group input { width: 100%; padding: 12px; box-sizing: border-box; border-radius: 5px; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); font-size: 16px; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 600; cursor: pointer; width: 100%; font-size: 16px; }
    .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { padding: 10px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    .status-pending { color: #ffc107; font-weight: bold; } .status-paid { color: #28a745; font-weight: bold; }
    @media (max-width: 992px) { .grid-layout { grid-template-columns: 1fr; } }
</style>

<div class="page-header"><h1>Finance & Payouts</h1></div>
<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-title">Current Available Balance</div>
        <div class="stat-number">₦<?php echo number_format($current_balance, 2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Pending Payouts</div>
        <div class="stat-number">₦<?php echo number_format($pending_payouts, 2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Total Revenue Collected</div>
        <div class="stat-number">₦<?php echo number_format($total_payments, 2); ?></div>
    </div>
</div>

<div class="grid-layout">
    <div class="card">
        <h2>Request a Withdrawal</h2>
        <form action="payouts.php" method="POST">
            <div class="form-group">
                <label for="amount">Amount to Withdraw</label>
                <input type="number" name="amount" step="0.01" max="<?php echo $current_balance; ?>" placeholder="Available: ₦<?php echo number_format($current_balance, 2); ?>" required>
            </div>
            <button type="submit" name="request_withdrawal" class="btn" <?php if($current_balance <= 0) echo 'disabled'; ?>>Submit Request</button>
        </form>
    </div>
    <div class="card">
        <h2>Withdrawal History</h2>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Date Requested</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if(empty($payout_history)): ?>
                        <tr><td colspan="3" style="text-align: center;">No withdrawal requests yet.</td></tr>
                    <?php else: foreach($payout_history as $payout): ?>
                        <tr>
                            <td><?php echo date("M j, Y", strtotime($payout['requested_at'])); ?></td>
                            <td>₦<?php echo number_format($payout['amount'], 2); ?></td>
                            <td><span class="status-<?php echo $payout['status']; ?>"><?php echo ucfirst($payout['status']); ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <h2>Recent Student Payments</h2>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Student</th><th>Fee Title</th><th>Amount</th><th>Date</th></tr></thead>
            <tbody>
                <?php if(empty($payment_history)): ?>
                    <tr><td colspan="4" style="text-align: center;">No student payments have been recorded yet.</td></tr>
                <?php else: foreach($payment_history as $payment): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($payment['full_name_eng']); ?></td>
                        <td><?php echo htmlspecialchars($payment['fee_title']); ?></td>
                        <td>₦<?php echo number_format($payment['amount_paid'], 2); ?></td>
                        <td><?php echo date("M j, Y", strtotime($payment['payment_date'])); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
