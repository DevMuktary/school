<?php
// This script is called by Paystack's servers, not by a user.
require_once 'db_connect.php';

// Only respond to POST requests
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405); // Method Not Allowed
    exit();
}

// Retrieve and verify the request from Paystack
$input = @file_get_contents("php://input");
if (!isset($_SERVER['HTTP_X_PAYSTACK_SIGNATURE']) || ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac('sha512', $input, PAYSTACK_SECRET_KEY))) {
    http_response_code(401); // Unauthorized
    exit();
}

// If the request is legitimate, process the event
http_response_code(200); // Acknowledge receipt of the event immediately
$event = json_decode($input);

if (isset($event->event)) {
    
    // --- HANDLE SUCCESSFUL SUBSCRIPTION CREATION ---
    if ($event->event == 'subscription.create') {
        $data = $event->data;
        if (isset($data->customer->metadata->school_id)) {
            $school_id = (int)$data->customer->metadata->school_id;
            $customer_code = $data->customer->customer_code;
            $subscription_code = $data->subscription_code;
            $plan_code = $data->plan->plan_code;
            $plan_interval = $data->plan->interval;
            $end_date = ($plan_interval === 'annually') ? date('Y-m-d', strtotime('+1 year')) : date('Y-m-d', strtotime('+1 month'));

            $update_stmt = $conn->prepare("UPDATE schools SET subscription_status = 'active', plan = ?, next_billing_date = ?, paystack_customer_code = ?, paystack_subscription_code = ? WHERE id = ?");
            $update_stmt->bind_param("ssssi", $plan_code, $end_date, $customer_code, $subscription_code, $school_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
    }

    // --- HANDLE DISABLED SUBSCRIPTIONS ---
    elseif ($event->event == 'subscription.disabled') {
        $data = $event->data;
        if (isset($data->customer->customer_code)) {
            $customer_code = $data->customer->customer_code;
            $update_stmt = $conn->prepare("UPDATE schools SET subscription_status = 'past_due' WHERE paystack_customer_code = ?");
            $update_stmt->bind_param("s", $customer_code);
            $update_stmt->execute();
            $update_stmt->close();
        }
    }
    
    // --- NEW: HANDLE SUCCESSFUL STUDENT FEE PAYMENT ---
    elseif ($event->event == 'charge.success') {
        $data = $event->data;
        // Check if it's a student fee payment via metadata
        if (isset($data->metadata->type) && $data->metadata->type == 'student_fee') {
            $student_id = (int)$data->metadata->student_id;
            $school_id = (int)$data->metadata->school_id;
            $course_id = (int)$data->metadata->course_id;
            $fee_id = (int)$data->metadata->fee_id;
            $amount_paid = $data->amount / 100; // Amount is in kobo
            $paystack_ref = $data->reference;
            
            // Log the successful student payment
            $log_stmt = $conn->prepare("INSERT INTO student_payments (student_id, school_id, course_id, fee_id, amount_paid, paystack_reference) VALUES (?, ?, ?, ?, ?, ?)");
            $log_stmt->bind_param("iiiids", $student_id, $school_id, $course_id, $fee_id, $amount_paid, $paystack_ref);
            $log_stmt->execute();
            $log_stmt->close();
        }
    }
}

$conn->close();
exit();
?>