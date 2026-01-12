<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

// This is a School Admin-only page.
if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

$upload_dir = '../uploads/';
$message = ''; $error = '';

// Handle deleting a certificate
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_to_delete = intval($_GET['id']);

    // Get file path to delete the actual file
    $stmt_file = $conn->prepare("SELECT file_path FROM certificates WHERE id = ? AND school_id = ?");
    $stmt_file->bind_param("ii", $id_to_delete, $school_id);
    $stmt_file->execute();
    $result = $stmt_file->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (!empty($row['file_path']) && file_exists($upload_dir . $row['file_path'])) {
            unlink($upload_dir . $row['file_path']);
        }
    }
    $stmt_file->close();
    
    // Delete the record from the database
    $stmt = $conn->prepare("DELETE FROM certificates WHERE id = ? AND school_id = ?");
    $stmt->bind_param("ii", $id_to_delete, $school_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) { $message = "Certificate deleted successfully."; }
    else { $error = "Failed to delete certificate."; }
    $stmt->close();
}

// Handle issuing a certificate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_certificate'])) {
    $student_id = intval($_POST['student_id']);
    $title = trim($_POST['certificate_title']);
    if (empty($student_id) || empty($title) || !isset($_FILES['certificate_file']) || $_FILES['certificate_file']['error'] != 0) {
        $error = "Please select a student, provide a title, and upload a certificate file.";
    } else {
        $file_name = 'cert_' . $school_id . '_' . $student_id . '_' . time() . '.' . pathinfo($_FILES['certificate_file']['name'], PATHINFO_EXTENSION);
        $target_file = $upload_dir . $file_name;
        if (move_uploaded_file($_FILES['certificate_file']['tmp_name'], $target_file)) {
            $stmt = $conn->prepare("INSERT INTO certificates (student_id, school_id, certificate_title, file_path, issue_date) VALUES (?, ?, ?, ?, CURDATE())");
            $stmt->bind_param("iiss", $student_id, $school_id, $title, $file_name);
            if ($stmt->execute()) { $message = "Certificate issued successfully!"; }
            else { $error = "Failed to issue certificate. The student may already have one."; unlink($target_file); }
            $stmt->close();
        } else { $error = "Error uploading file."; }
    }
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php';

// Fetch students for THIS SCHOOL ONLY to populate the dropdown
$students_list = [];
$s_sql = "SELECT id, full_name_eng FROM users WHERE school_id = ? AND role = 'student' AND id NOT IN (SELECT student_id FROM certificates WHERE school_id = ?) ORDER BY full_name_eng ASC";
$s_stmt = $conn->prepare($s_sql);
$s_stmt->bind_param("ii", $school_id, $school_id);
$s_stmt->execute();
$s_result = $s_stmt->get_result();
if ($s_result) { while($row = $s_result->fetch_assoc()) { $students_list[] = $row; } }
$s_stmt->close();

// Fetch issued certificates for THIS SCHOOL ONLY
$issued_certificates = [];
$c_sql = "SELECT c.id, u.full_name_eng, c.certificate_title, c.issue_date, c.file_path FROM certificates c JOIN users u ON c.student_id = u.id WHERE c.school_id = ? ORDER BY c.issue_date DESC";
$c_stmt = $conn->prepare($c_sql);
$c_stmt->bind_param("i", $school_id);
$c_stmt->execute();
$c_result = $c_stmt->get_result();
if ($c_result) { while($row = $c_result->fetch_assoc()) { $issued_certificates[] = $row; } }
$c_stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .grid-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 25px; align-items: start; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    .form-group input, .form-group select { width: 100%; padding: 12px; box-sizing: border-box; border-radius: 5px; border: 1px solid var(--border-color); background-color: var(--bg-color); color: var(--text-color); font-size: 16px; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 600; cursor: pointer; width: 100%; font-size: 16px; }
    .message, .error { padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
    .error { color: #D8000C; background-color: #FFD2D2; } .message { color: #155724; background-color: #d4edda; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
    th { font-weight: 600; }
    .action-link { text-decoration: none; font-weight: 500; }
    .action-link.view { color: var(--brand-primary); }
    .action-link.delete { color: #dc3545; margin-left: 15px; }

    @media (max-width: 992px) { .grid-layout { grid-template-columns: 1fr; } }
    
    /* --- NEW: MOBILE-FIT TABLE STYLES --- */
    @media (max-width: 768px) {
        .table-wrapper thead { display: none; }
        .table-wrapper tr { 
            display: block; 
            margin-bottom: 15px; 
            border: 1px solid var(--border-color); 
            border-radius: 8px; 
            padding: 10px;
        }
        .table-wrapper td { 
            display: flex; 
            justify-content: space-between;
            align-items: center;
            text-align: right; 
            padding: 10px 0; 
            border-bottom: 1px solid var(--border-color);
        }
        .table-wrapper td:last-child { border-bottom: none; }
        .table-wrapper td::before {
            content: attr(data-label);
            font-weight: 600;
            text-align: left;
            margin-right: 15px;
        }
    }
</style>

<div class="page-header">
    <h1>Manage Certificates</h1>
</div>

<?php if($message): ?><p class="message"><?php echo $message; ?></p><?php endif; ?>
<?php if($error): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>

<div class="grid-layout">
    <div class="card">
        <h2>Issue New Certificate</h2>
        <form action="manage_certificates.php" method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="student_id">Select Student</label>
                <select name="student_id" id="student_id" required>
                    <option value="">-- Choose a student --</option>
                    <?php foreach($students_list as $student): ?>
                        <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['full_name_eng']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label for="certificate_title">Certificate Title</label><input type="text" name="certificate_title" id="certificate_title" value="Certificate of Completion" required></div>
            <div class="form-group"><label for="certificate_file">Upload Certificate File (PDF)</label><input type="file" name="certificate_file" id="certificate_file" accept=".pdf" required></div>
            <button type="submit" name="issue_certificate" class="btn">Issue Certificate</button>
        </form>
    </div>
    <div class="card">
        <h2>Issued Certificates</h2>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Student Name</th><th>Certificate Title</th><th>Issue Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if(empty($issued_certificates)): ?>
                        <tr><td colspan="4" style="text-align:center; padding: 20px;">No certificates have been issued yet.</td></tr>
                    <?php else: foreach($issued_certificates as $cert): ?>
                        <tr>
                            <td data-label="Student"><?php echo htmlspecialchars($cert['full_name_eng']); ?></td>
                            <td data-label="Title"><?php echo htmlspecialchars($cert['certificate_title']); ?></td>
                            <td data-label="Date"><?php echo date("F j, Y", strtotime($cert['issue_date'])); ?></td>
                            <td data-label="Actions">
                                <a href="../uploads/<?php echo htmlspecialchars($cert['file_path']); ?>" class="action-link view" target="_blank">View</a>
                                <a href="manage_certificates.php?action=delete&id=<?php echo $cert['id']; ?>" class="action-link delete" onclick="return confirm('Are you sure?');">Delete</a>
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
