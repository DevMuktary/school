<?php 
require_once 'layout_header.php';

// This is a School Admin-only page.
if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

// Fetch current subscription status and plan details for this school
$sub_stmt = $conn->prepare("SELECT plan, subscription_status, next_billing_date, student_limit FROM schools WHERE id = ?");
$sub_stmt->bind_param("i", $school_id);
$sub_stmt->execute();
$subscription_details = $sub_stmt->get_result()->fetch_assoc();
$sub_stmt->close();

// Fetch current student count for this school
$student_count_stmt = $conn->prepare("SELECT COUNT(id) as count FROM users WHERE school_id = ? AND role = 'student'");
$student_count_stmt->bind_param("i", $school_id);
$student_count_stmt->execute();
$student_count = $student_count_stmt->get_result()->fetch_assoc()['count'];
$student_count_stmt->close();

// Fetch admin details needed for Paystack
$admin_stmt = $conn->prepare("SELECT full_name_eng, email FROM users WHERE id = ?");
$admin_stmt->bind_param("i", $user_id);
$admin_stmt->execute();
$admin_details = $admin_stmt->get_result()->fetch_assoc();
$admin_stmt->close();

// --- Paystack Plan Codes ---
$plans = [
    'starter_monthly' => ['name' => 'Starter Plan (Monthly)', 'price' => 10000, 'code' => 'PLN_zghlexbbcaxwrif'],
    'starter_yearly' => ['name' => 'Starter Plan (Yearly)', 'price' => 100000, 'code' => 'PLN_mn6808ry7uubqo4'],
    'growth_monthly' => ['name' => 'Growth Plan (Monthly)', 'price' => 20000, 'code' => 'PLN_ryddlp823nxut5u'],
    'growth_yearly' => ['name' => 'Growth Plan (Yearly)', 'price' => 200000, 'code' => 'PLN_ht1uywmljlot6i6'],
];
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 30px; border-radius: 8px; margin-bottom: 25px; }
    .welcome-box { text-align: center; padding: 30px; }
    .plans-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; }
    .plan-card { padding: 30px; border: 1px solid var(--border-color); border-radius: 8px; transition: all 0.3s ease; display: flex; flex-direction: column; text-align: center; }
    .plan-card:hover { transform: translateY(-5px); border-color: var(--brand-primary); }
    .plan-card h3 { margin-top: 0; font-size: 22px; color: var(--brand-primary); }
    .plan-card .price { font-size: 28px; font-weight: 700; color: var(--text-color); margin: 15px 0; }
    .plan-card .price span { font-size: 14px; font-weight: 400; color: var(--text-muted); }
    .plan-card ul { list-style: none; padding: 0; margin: 20px 0; text-align: left; font-size: 14px; flex-grow: 1; }
    .plan-card li { margin-bottom: 10px; }
    .plan-card li::before { content: '✓'; color: var(--brand-primary); margin-right: 10px; font-weight: bold; }
    .btn { width: 100%; padding: 12px; margin-top: 10px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; text-align: center;}
    .btn.outline { background: none; color: var(--brand-primary); border: 2px solid var(--brand-primary); }
    
    .status-card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 30px; border-radius: 8px; }
    .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 25px; }
    .status-item .label { font-size: 14px; color: var(--text-muted); }
    .status-item .value { font-size: 18px; font-weight: 600; }
    .status-item .value.active { color: #28a745; }
</style>

<div class="page-header">
    <h1>Billing & Subscription</h1>
</div>

<?php if ($subscription_details && $subscription_details['subscription_status'] === 'active'): ?>
    
    <div class="status-card">
        <h2>Your Subscription</h2>
        <div class="status-grid">
            <div class="status-item">
                <span class="label">Current Plan</span>
                <p class="value"><?php echo htmlspecialchars($subscription_details['plan'] ?? 'N/A'); ?></p>
            </div>
            <div class="status-item">
                <span class="label">Status</span>
                <p class="value active">Active</p>
            </div>
            <div class="status-item">
                <span class="label">Next Billing Date</span>
                <p class="value"><?php echo !empty($subscription_details['next_billing_date']) ? date("F j, Y", strtotime($subscription_details['next_billing_date'])) : 'N/A'; ?></p>
            </div>
             <div class="status-item">
                <span class="label">Student Usage</span>
                <p class="value"><?php echo $student_count; ?> / <?php echo htmlspecialchars($subscription_details['student_limit'] ?? 'N/A'); ?> Students</p>
            </div>
        </div>
        <hr style="border:0; border-top: 1px solid var(--border-color); margin: 30px 0;">
        <a href="manage_subscription.php" class="btn outline" style="width: auto;">Manage Subscription</a>
        <p style="font-size: 12px; color: var(--text-muted); margin-top: 15px;">To change or cancel your plan, you will be redirected to our payment provider.</p>
    </div>

<?php else: ?>

    <div class="card welcome-box">
        <h2>Welcome to INTRA-EDU!</h2>
        <p>Your school is almost ready. Please choose a plan to activate your account and unlock all features.</p>
    </div>
    <div class="plans-container">
        <div class="plan-card">
            <h3>Starter Plan</h3>
            <ul><li>Up to 100 Students</li><li>All Core Features</li></ul>
            <div class="price">₦10,000 <span>/ month</span></div>
            <button class="btn" onclick="payWithPaystack('<?php echo $plans['starter_monthly']['code']; ?>', 1000000)">Choose Monthly</button>
            <div class="price" style="margin-top:20px;">₦100,000 <span>/ year</span></div>
            <button class="btn outline" onclick="payWithPaystack('<?php echo $plans['starter_yearly']['code']; ?>', 10000000)">Choose Yearly & Save</button>
        </div>
        <div class="plan-card">
            <h3>Growth Plan</h3>
            <ul><li>Up to 500 Students</li><li>All Core Features</li><li>Priority Support</li><li>Custom Subdomain</li></ul>
            <div class="price">₦20,000 <span>/ month</span></div>
            <button class="btn" onclick="payWithPaystack('<?php echo $plans['growth_monthly']['code']; ?>', 2000000)">Choose Monthly</button>
            <div class="price" style="margin-top:20px;">₦200,000 <span>/ year</span></div>
            <button class="btn outline" onclick="payWithPaystack('<?php echo $plans['growth_yearly']['code']; ?>', 20000000)">Choose Yearly & Save</button>
        </div>
        <div class="plan-card">
            <h3>Institution Plan</h3>
            <ul><li>Unlimited Students</li><li>All Features</li><li>Dedicated Support</li><li>Onboarding Assistance</li></ul>
            <div class="price">Contact Us</div>
            <a href="mailto:sales@yourdomain.com" class="btn">Contact Sales</a>
        </div>
    </div>

<?php endif; ?>

<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
function payWithPaystack(planCode, amountInKobo) {
  if(!planCode || planCode.includes('REPLACE_WITH')) {
    alert('Error: Plan codes have not been configured by the site administrator.');
    return;
  }
  var handler = PaystackPop.setup({
    key: '<?php echo PAYSTACK_PUBLIC_KEY; ?>',
    email: '<?php echo $admin_details['email']; ?>',
    amount: amountInKobo,
    plan: planCode,
    metadata: { school_id: <?php echo $school_id; ?> },
    callback: function(response){
        // Redirect to a verification page on your site
        window.location = '../payment_verify.php?reference=' + response.reference;
    },
    onClose: function(){
        alert('Transaction was not completed.');
    }
  });
  handler.openIframe();
}
</script>

<?php 
require_once 'layout_footer.php'; 
?>
