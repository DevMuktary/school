<?php
require_once 'db_connect.php'; // Includes the $conn variable
$message = 'Verifying your payment...';
$status = 'info';

if (isset($_GET['reference'])) {
    $reference = $_GET['reference'];
    
    // Use cURL to call the Paystack API to verify the transaction
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "accept: application/json",
            "authorization: Bearer " . PAYSTACK_SECRET_KEY,
            "cache-control: no-cache"
        ],
    ));
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        $message = "cURL Error. Payment verification failed. Please contact support.";
        $status = 'error';
    } else {
        $result = json_decode($response);
        
        if ($result->data->status == 'success') {
            
            // --- START: Updated Database Logic ---

            // 1. Get data from Paystack's response
            // --> We MUST get these IDs from the metadata you sent
            $student_id = $result->data->metadata->student_id ?? null;
            $school_id = $result->data->metadata->school_id ?? null;
            $course_id = $result->data->metadata->course_id ?? null;
            $fee_id = $result->data->metadata->fee_id ?? null;
            
            // Get amount in NAIRA (Paystack sends in kobo)
            $amount_paid = $result->data->amount / 100; 
            $transaction_ref = $result->data->reference;
            // Get the exact time of payment from Paystack
            $payment_date = $result->data->paid_at; 

            // 2. Check if we have all the required metadata
            if (empty($student_id) || empty($school_id) || empty($course_id) || empty($fee_id)) {
                
                $message = "Payment Successful, but critical metadata (student, school, course, or fee ID) was missing. Please contact support with reference: $transaction_ref";
                $status = 'error';

            } else {
                
                // 3. Insert the payment record into YOUR 'student_payments' table
                $sql = "INSERT INTO student_payments (student_id, school_id, course_id, fee_id, amount_paid, paystack_reference, payment_date) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                if ($stmt = $conn->prepare($sql)) {
                    // Bind params: i=integer, d=double, s=string
                    $stmt->bind_param("iiiidss", $student_id, $school_id, $course_id, $fee_id, $amount_paid, $transaction_ref, $payment_date);
                    
                    if ($stmt->execute()) {
                        // Success!
                        $message = "Payment Successful! Your payment has been recorded. You will be redirected shortly...";
                        $status = 'success';
                        // Redirect to the STUDENT portal
                        header("refresh:5;url=dashboard.php");
                    } else {
                        // Check if this is a duplicate entry (user refreshed the page)
                        // 1062 is the MySQL error code for "Duplicate entry"
                        if ($conn->errno == 1062) {
                             $message = "Payment already verified and recorded. Redirecting...";
                             $status = 'success'; // It's not an error
                             header("refresh:5;url=dashboard.php");
                        } else {
                             // Another database error
                             $message = "Database error. Payment successful, but not recorded. Please contact support with reference: $transaction_ref";
                             $status = 'error';
                        }
                    }
                    $stmt->close();
                } else {
                    // SQL query preparation failed (e.g., table/column names wrong)
                    $message = "Database prepare error. Payment successful, but not recorded. Please contact support. Ref: $transaction_ref";
                    $status = 'error';
                }
            }
            // --- END: Updated Database Logic ---

        } else {
            // Payment was not successful on Paystack's end
            $message = "Payment verification failed. Status: " . htmlspecialchars($result->data->status);
            $status = 'error';
        }
    }
} else {
    $message = "No payment reference provided.";
    $status = 'error';
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Verification</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap');
        body { font-family: 'Poppins', sans-serif; background-color: #f7f9fc; display:flex; justify-content:center; align-items:center; height:100vh; margin:0; }
        .message-box { background: #fff; padding: 40px; border-radius: 8px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .success { border-top: 4px solid #28a745; }
        .error { border-top: 4px solid #dc3545; }
        .info { border-top: 4px solid #17a2b8; }
    </style>
</head>
<body>
    <div class="message-box <?php echo $status; ?>">
        <h2><?php echo $message; ?></h2>
        <p>Please wait while we confirm your subscription.</p>
    </div>
</body>
</html>
