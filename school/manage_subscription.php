<?php
require_once 'layout_header.php';

// This is a School Admin-only page.
if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

$error_message = "Could not retrieve subscription link. Please try again or contact support.";

// Get the school's subscription code from our database
$stmt = $conn->prepare("SELECT paystack_subscription_code FROM schools WHERE id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $subscription_code = $result->fetch_assoc()['paystack_subscription_code'];

    if (!empty($subscription_code)) {
        // Use cURL to ask Paystack for a management link for this subscription
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/subscription/" . rawurlencode($subscription_code) . "/manage/link",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
                "Cache-Control: no-cache"
            ],
        ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        
        if ($err) {
            $error_message = "A connection error occurred. Please try again later.";
        } else {
            $result_data = json_decode($response);
            
            // Check if the API call was successful and a link was returned
            if (isset($result_data->status) && $result_data->status == true && !empty($result_data->data->link)) {
                // If Paystack gives us the link, redirect the user to it immediately using JavaScript
                echo "<script>window.location.href = '" . addslashes($result_data->data->link) . "';</script>";
                exit();
            } else {
                 $error_message = "Could not generate a management link at this time. Please contact support.";
            }
        }
    } else {
        $error_message = "No active subscription found for your school.";
    }
}
$stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 30px; border-radius: 8px; text-align: center; }
    .error-message { color: #dc3545; font-weight: 500; }
    .btn-back { display: inline-block; margin-top: 20px; padding: 10px 25px; background-color: var(--brand-primary); color: white; text-decoration: none; border-radius: 5px; }
</style>

<div class="page-header">
    <h1>Manage Subscription</h1>
</div>

<div class="card">
    <h2>Redirecting...</h2>
    <p>Please wait while we securely redirect you to the customer portal.</p>
    <p class="error-message"><?php echo $error_message; ?></p>
    <a href="billing.php" class="btn-back">Go Back to Billing</a>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
