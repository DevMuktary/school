<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

// This is an admin-only page.
if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

// --- CSRF TOKEN GENERATION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$message = ''; $error = '';

// Handle form submissions (Create and Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- CSRF TOKEN VALIDATION ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again from the original page.";
    } else {
        // Handle deleting an instructor
        if (isset($_POST['delete_instructor'])) {
            $instructor_id_to_delete = intval($_POST['delete_instructor']);
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND school_id = ? AND role = 'instructor'");
            $stmt->bind_param("ii", $instructor_id_to_delete, $school_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = "Instructor account deleted successfully.";
            } else {
                $error = "Failed to delete instructor or instructor not found.";
            }
            $stmt->close();
        }

        // Handle creating a new instructor
        if (isset($_POST['add_instructor'])) {
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $password = $_POST['password'];

            if (empty($full_name) || empty($email) || empty($password)) { $error = "All fields are required."; }
            elseif (strlen($password) < 6) { $error = "Password must be at least 6 characters long."; }
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = "Invalid email format."; }
            else {
                $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $check_stmt->bind_param("s", $email);
                $check_stmt->execute();
                if($check_stmt->get_result()->num_rows > 0) {
                    $error = "An account with this email address already exists.";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (school_id, role, full_name_eng, email, password, username) VALUES (?, 'instructor', ?, ?, ?, ?)");
                    $stmt->bind_param("issss", $school_id, $full_name, $email, $hashed_password, $email);
                    if ($stmt->execute()) { $message = "Instructor account created successfully."; } 
                    else { $error = "Failed to create instructor account."; }
                    $stmt->close();
                }
                $check_stmt->close();
            }
        }
    }
}


// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

// Fetch all instructors for this school
$instructors = [];
$stmt = $conn->prepare("SELECT id, full_name_eng, email, reg_date FROM users WHERE school_id = ? AND role = 'instructor' ORDER BY full_name_eng ASC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) { while ($row = $result->fetch_assoc()) { $instructors[] = $row; } }
$stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .grid-layout { display: grid; grid-template-columns: 350px 1fr; gap: 25px; align-items: start; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    .form-group input { width: 100%; padding: 12px; box-sizing: border-box; border-radius: 5px; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); font-size: 16px; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 600; cursor: pointer; width: 100%; font-size: 16px; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    .action-link { background: none; border: none; padding: 0; font-family: 'Poppins', sans-serif; color: #dc3545; text-decoration: none; font-weight: 500; font-size: 14px; cursor: pointer;}
    @media (max-width: 992px) { .grid-layout { grid-template-columns: 1fr; } }
    @media (max-width: 768px) {
        .table-wrapper thead { display: none; }
        .table-wrapper tr { display: block; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 5px; }
        .table-wrapper td { display: block; text-align: right; padding-left: 50%; position: relative; border-bottom: 1px solid var(--border-color); }
        .table-wrapper td:last-child { border-bottom: none; }
        .table-wrapper td::before { content: attr(data-label); position: absolute; left: 15px; font-weight: 600; text-align: left; }
    }
</style>

<div class="page-header">
    <h1>Manage Instructors</h1>
</div>

<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<div class="grid-layout">
    <div class="card">
        <h2>Add New Instructor</h2>
        <form action="manage_instructors.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
            <div class="form-group"><label>Email Address</label><input type="email" name="email" required></div>
            <div class="form-group"><label>Create Password</label><input type="password" name="password" required></div>
            <button type="submit" name="add_instructor" class="btn">Create Account</button>
        </form>
    </div>
    <div class="card">
        <h2>Existing Instructors</h2>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Name</th><th>Email</th><th>Registered On</th><th>Action</th></tr></thead>
                <tbody>
                    <?php if(empty($instructors)): ?>
                        <tr><td colspan="4" style="text-align: center; padding: 20px;">No instructors have been added yet.</td></tr>
                    <?php else: foreach($instructors as $inst): ?>
                    <tr>
                        <td data-label="Name"><?php echo htmlspecialchars($inst['full_name_eng']); ?></td>
                        <td data-label="Email"><?php echo htmlspecialchars($inst['email']); ?></td>
                        <td data-label="Registered"><?php echo date("M j, Y", strtotime($inst['reg_date'])); ?></td>
                        <td data-label="Action">
                            <form action="manage_instructors.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this instructor?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="delete_instructor" value="<?php echo $inst['id']; ?>">
                                <button type="submit" class="action-link">Delete</button>
                            </form>
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
