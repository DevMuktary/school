<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

// This is a School Admin-only page.
if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

$message = ''; $error = '';
$edit_fee = null;

// Handle POST request for adding/editing a fee
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fee_id = intval($_POST['fee_id'] ?? 0);
    $course_id = intval($_POST['course_id']);
    $fee_title = trim($_POST['fee_title']);
    $amount = floatval($_POST['amount']);
    $payment_deadline = !empty($_POST['payment_deadline']) ? $_POST['payment_deadline'] : null;

    if(empty($course_id) || empty($fee_title) || $amount <= 0) {
        $error = "Please select a course, provide a title, and enter a valid amount.";
    } else {
        if ($fee_id > 0) {
            // Update existing fee
            $stmt = $conn->prepare("UPDATE fee_structures SET course_id = ?, fee_title = ?, amount = ?, payment_deadline = ? WHERE id = ? AND school_id = ?");
            $stmt->bind_param("isdsii", $course_id, $fee_title, $amount, $payment_deadline, $fee_id, $school_id);
            if($stmt->execute()) { $message = "Fee updated successfully!"; } else { $error = "Failed to update fee."; }
        } else {
            // Insert new fee
            $stmt = $conn->prepare("INSERT INTO fee_structures (school_id, course_id, fee_title, amount, payment_deadline) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisds", $school_id, $course_id, $fee_title, $amount, $payment_deadline);
            if($stmt->execute()) { $message = "Fee created successfully!"; } else { $error = "Failed to create fee."; }
        }
        $stmt->close();
    }
}

// Handle GET request for deleting a fee
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $fee_id_to_delete = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM fee_structures WHERE id = ? AND school_id = ?");
    $stmt->bind_param("ii", $fee_id_to_delete, $school_id);
    if($stmt->execute() && $stmt->affected_rows > 0) { $message = "Fee deleted successfully."; }
    else { $error = "Failed to delete fee."; }
    $stmt->close();
}

// Handle GET request for editing a fee
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $fee_id_to_edit = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM fee_structures WHERE id = ? AND school_id = ?");
    $stmt->bind_param("ii", $fee_id_to_edit, $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows > 0) { $edit_fee = $result->fetch_assoc(); }
    $stmt->close();
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

// --- SECURITY FIX: Fetch courses using a prepared statement ---
$courses = [];
$stmt = $conn->prepare("SELECT id, title FROM courses WHERE school_id = ? ORDER BY title ASC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$course_result = $stmt->get_result();
if ($course_result) { while($row = $course_result->fetch_assoc()) { $courses[] = $row; } }
$stmt->close();


$fees = [];
$fee_sql = "SELECT fs.*, c.title as course_name FROM fee_structures fs JOIN courses c ON fs.course_id = c.id WHERE fs.school_id = ? ORDER BY c.title ASC, fs.fee_title ASC";
$fee_stmt = $conn->prepare($fee_sql);
$fee_stmt->bind_param("i", $school_id);
$fee_stmt->execute();
$fee_result = $fee_stmt->get_result();
if($fee_result) { while($row = $fee_result->fetch_assoc()) { $fees[] = $row; } }
$fee_stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .grid-layout { display: grid; grid-template-columns: 350px 1fr; gap: 25px; align-items: start; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    .form-group input, .form-group select { width: 100%; padding: 12px; box-sizing: border-box; border-radius: 5px; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); font-size: 16px; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 600; cursor: pointer; width: 100%; font-size: 16px; }
    .message, .error { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
    .message { color: #155724; background-color: #d4edda; } .error { color: #721c24; background-color: #f8d7da; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    .action-links a { text-decoration: none; font-weight: 500; margin-right: 10px; }
    .action-links .edit { color: var(--brand-primary); }
    .action-links .delete { color: #dc3545; }
    
    @media (max-width: 992px) { .grid-layout { grid-template-columns: 1fr; } }
    @media (max-width: 768px) {
        .table-wrapper thead { display: none; }
        .table-wrapper tr { display: block; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 5px; }
        .table-wrapper td { display: block; text-align: right; padding-left: 50%; position: relative; border-bottom: 1px solid var(--border-color); white-space: normal; word-break: break-word; font-size: 14px; }
        .table-wrapper td:last-child { border-bottom: none; }
        .table-wrapper td::before { content: attr(data-label); position: absolute; left: 15px; font-weight: 600; text-align: left; }
    }
</style>

<div class="page-header">
    <h1>Manage School Fees</h1>
</div>

<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<div class="grid-layout">
    <div class="card">
        <h2><?php echo $edit_fee ? 'Edit Fee' : 'Add a Fee'; ?></h2>
        <form action="manage_fees.php" method="POST">
            <input type="hidden" name="fee_id" value="<?php echo $edit_fee['id'] ?? 0; ?>">
            <div class="form-group">
                <label for="course_id">For Course</label>
                <select name="course_id" id="course_id" required>
                    <option value="">-- Select a course --</option>
                    <?php foreach($courses as $course): ?>
                        <option value="<?php echo $course['id']; ?>" <?php if(isset($edit_fee) && $edit_fee['course_id'] == $course['id']) echo 'selected'; ?>><?php echo htmlspecialchars($course['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label for="fee_title">Fee Title</label><input type="text" name="fee_title" id="fee_title" placeholder="e.g., Term 1 Tuition" value="<?php echo htmlspecialchars($edit_fee['fee_title'] ?? ''); ?>" required></div>
            <div class="form-group"><label for="amount">Amount (₦)</label><input type="number" name="amount" id="amount" step="0.01" value="<?php echo $edit_fee['amount'] ?? ''; ?>" required></div>
            <div class="form-group"><label for="payment_deadline">Payment Deadline (Optional)</label><input type="date" name="payment_deadline" id="payment_deadline" value="<?php echo $edit_fee['payment_deadline'] ?? ''; ?>"></div>
            <button type="submit" class="btn"><?php echo $edit_fee ? 'Update Fee' : 'Save Fee'; ?></button>
            <?php if($edit_fee): ?><a href="manage_fees.php" style="display:block; text-align:center; margin-top:10px;">Cancel Edit</a><?php endif; ?>
        </form>
    </div>
    <div class="card">
        <h2>Existing Fees</h2>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Course Name</th><th>Fee Title</th><th>Amount</th><th>Deadline</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if(empty($fees)): ?>
                        <tr><td colspan="5" style="text-align: center;">No fees created yet.</td></tr>
                    <?php else: foreach($fees as $fee): ?>
                        <tr>
                            <td data-label="Course"><?php echo htmlspecialchars($fee['course_name']); ?></td>
                            <td data-label="Title"><?php echo htmlspecialchars($fee['fee_title']); ?></td>
                            <td data-label="Amount">₦<?php echo number_format($fee['amount'], 2); ?></td>
                            <td data-label="Deadline"><?php echo !empty($fee['payment_deadline']) ? date("M j, Y", strtotime($fee['payment_deadline'])) : 'N/A'; ?></td>
                            <td data-label="Actions" class="action-links">
                                <a href="?action=edit&id=<?php echo $fee['id']; ?>" class="edit">Edit</a>
                                <a href="?action=delete&id=<?php echo $fee['id']; ?>" class="delete" onclick="return confirm('Are you sure?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
