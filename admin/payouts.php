<?php
require_once '../db_connect.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
require_once '../phpmailer/src/Exception.php';
require_once '../phpmailer/src/PHPMailer.php';
require_once '../phpmailer/src/SMTP.php';

if (!isset($_SESSION['super_admin_id'])) {
    header('Location: index.php');
    exit();
}

$message = ''; $error = '';

// Handle marking a payout as paid
if (isset($_GET['action']) && $_GET['action'] == 'mark_paid' && isset($_GET['id'])) {
    $payout_id = intval($_GET['id']);

    // Fetch payout details and admin email for the notification
    $payout_info_stmt = $conn->prepare("
        SELECT pr.amount, s.owner_email, u.full_name_eng
        FROM payout_requests pr
        JOIN schools s ON pr.school_id = s.id
        JOIN users u ON s.owner_email = u.email
        WHERE pr.id = ? AND u.role = 'school_admin'
    ");
    $payout_info_stmt->bind_param("i", $payout_id);
    $payout_info_stmt->execute();
    $payout_info = $payout_info_stmt->get_result()->fetch_assoc();
    $payout_info_stmt->close();

    if ($payout_info) {
        $update_stmt = $conn->prepare("UPDATE payout_requests SET status = 'paid', paid_at = NOW() WHERE id = ?");
        $update_stmt->bind_param("i", $payout_id);
        if ($update_stmt->execute()) {
            $message = "Payout marked as paid successfully!";

            // Send confirmation email to the School Admin
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'mail.universityofmutoon.com.ng';
                $mail->SMTPAuth = true;
                $mail->Username = 'no-reply@universityofmutoon.com.ng';
                $mail->Password = 'Olalekan@100';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port = 465;
                $mail->setFrom('no-reply@universityofmutoon.com.ng', 'INTRA-EDU Platform');
                $mail->addAddress($payout_info['owner_email'], $payout_info['full_name_eng']);
                $mail->isHTML(true);
                $mail->Subject = 'Your Payout has been Processed';
                $mail->Body    = "Hello " . $payout_info['full_name_eng'] . ",<br><br>Your withdrawal request for <strong>₦" . number_format($payout_info['amount'], 2) . "</strong> has been processed.<br><br>The funds should reflect in your account shortly.<br><br>Thank you for using INTRA-EDU.";
                $mail->send();
            } catch (Exception $e) {
                // Email failed, but the action succeeded. Log error if necessary.
                $error = "Payout was marked as paid, but the confirmation email could not be sent. Mailer Error: {$mail->ErrorInfo}";
            }
        }
        $update_stmt->close();
    }
}

// Fetch Pending Payout Requests
$pending_payouts = [];
$pending_sql = "SELECT pr.*, s.name as school_name FROM payout_requests pr JOIN schools s ON pr.school_id = s.id WHERE pr.status = 'pending' ORDER BY pr.requested_at ASC";
$pending_result = $conn->query($pending_sql);
if($pending_result) { while($row = $pending_result->fetch_assoc()){ $pending_payouts[] = $row; } }

// Fetch Paid Payout History
$paid_payouts = [];
$paid_sql = "SELECT pr.*, s.name as school_name FROM payout_requests pr JOIN schools s ON pr.school_id = s.id WHERE pr.status = 'paid' ORDER BY pr.paid_at DESC LIMIT 50";
$paid_result = $conn->query($paid_sql);
if($paid_result) { while($row = $paid_result->fetch_assoc()){ $paid_payouts[] = $row; } }

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Payouts - Super Admin</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root { --brand-red: #E74C3C; --text-dark: #2c3e50; }
        body { font-family: 'Poppins', sans-serif; background-color: #f7f9fc; margin: 0; color: var(--text-dark); }
        .header { background-color: var(--text-dark); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .header .logo { font-size: 22px; font-weight: 700; }
        .header .logo span { color: var(--brand-red); }
        .container { max-width: 1200px; margin: 0 auto; padding: 25px 15px; }
        .page-header h1 { margin: 0; font-size: 28px; }
        .card { background-color: #FFFFFF; border-radius: 8px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-red); padding-bottom: 10px; }
        .message, .error { padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
        .error { color: #D8000C; background-color: #FFD2D2; }
        .message { color: #155724; background-color: #d4edda; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        .btn-paid { padding: 8px 15px; border: none; border-radius: 5px; background-color: #28a745; color: white; text-decoration: none; font-weight: 500; }
        @media (max-width: 768px) {
            .table-wrapper thead { display: none; }
            .table-wrapper tr { display: block; margin-bottom: 15px; border: 1px solid #eee; border-radius: 5px; }
            .table-wrapper td { display: block; text-align: right; padding-left: 50%; position: relative; border-bottom: 1px solid #eee; }
            .table-wrapper td:last-child { border-bottom: none; }
            .table-wrapper td::before { content: attr(data-label); position: absolute; left: 15px; font-weight: 600; text-align: left; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo">INTRA-EDU<span>.</span> Super Admin</div>
        <div>
            <a href="dashboard.php" style="color:white; text-decoration:none;">Dashboard</a>
            <a href="logout.php" style="color:white; text-decoration:none; margin-left:20px;">Logout</a>
        </div>
    </header>
    <div class="container">
        <div class="page-header"><h1>Manage Payouts</h1></div>

        <?php if($message): ?><p class="message"><?php echo $message; ?></p><?php endif; ?>
        <?php if($error): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>

        <div class="card">
            <h2>Pending Withdrawal Requests</h2>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>School Name</th><th>Amount</th><th>Date Requested</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php if(empty($pending_payouts)): ?>
                            <tr><td colspan="4" style="text-align: center;">No pending requests.</td></tr>
                        <?php else: foreach($pending_payouts as $payout): ?>
                            <tr>
                                <td data-label="School"><?php echo htmlspecialchars($payout['school_name']); ?></td>
                                <td data-label="Amount">₦<?php echo number_format($payout['amount'], 2); ?></td>
                                <td data-label="Date"><?php echo date("M j, Y, g:ia", strtotime($payout['requested_at'])); ?></td>
                                <td data-label="Action">
                                    <a href="?action=mark_paid&id=<?php echo $payout['id']; ?>" class="btn-paid" onclick="return confirm('Have you sent the money to this school? This action cannot be undone.');">Mark as Paid</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>Payout History</h2>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>School Name</th><th>Amount</th><th>Date Requested</th><th>Date Paid</th></tr></thead>
                    <tbody>
                         <?php if(empty($paid_payouts)): ?>
                            <tr><td colspan="4" style="text-align: center;">No paid requests yet.</td></tr>
                        <?php else: foreach($paid_payouts as $payout): ?>
                            <tr>
                                <td data-label="School"><?php echo htmlspecialchars($payout['school_name']); ?></td>
                                <td data-label="Amount">₦<?php echo number_format($payout['amount'], 2); ?></td>
                                <td data-label="Requested"><?php echo date("M j, Y", strtotime($payout['requested_at'])); ?></td>
                                <td data-label="Paid"><?php echo date("M j, Y", strtotime($payout['paid_at'])); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
