<?php
// This script is meant to be run by a cron job, not a browser.
require_once 'db_connect.php';

// Find all fees where the deadline was yesterday
$yesterday = date('Y-m-d', strtotime('-1 day'));
$fees_due_sql = "SELECT id, course_id FROM fee_structures WHERE payment_deadline = '{$yesterday}'";
$fees_result = $conn->query($fees_due_sql);

if ($fees_result && $fees_result->num_rows > 0) {
    while ($fee = $fees_result->fetch_assoc()) {
        $fee_id = $fee['id'];
        $course_id = $fee['course_id'];

        // Find all students enrolled in this course who have NOT paid this fee
        $defaulters_sql = "SELECT e.student_id 
                           FROM enrollments e 
                           WHERE e.course_id = {$course_id}
                           AND e.student_id NOT IN (
                               SELECT sp.student_id FROM student_payments sp WHERE sp.fee_id = {$fee_id}
                           )";
        
        $defaulters_result = $conn->query($defaulters_sql);
        if ($defaulters_result && $defaulters_result->num_rows > 0) {
            while ($defaulter = $defaulters_result->fetch_assoc()) {
                $student_id_to_suspend = $defaulter['student_id'];
                
                // Suspend the student's account
                $conn->query("UPDATE users SET account_status = 'suspended' WHERE id = {$student_id_to_suspend}");
                echo "Suspended student ID: {$student_id_to_suspend}\n";
            }
        }
    }
}
echo "Payment check complete.\n";
$conn->close();
?>