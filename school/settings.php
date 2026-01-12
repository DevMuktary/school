<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

// This is a School Admin-only page.
if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

// --- CSRF TOKEN GENERATION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$logo_upload_dir = '../uploads/logos/';
$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- CSRF TOKEN VALIDATION ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again from the original page.";
    } else {
        $brand_color = $_POST['brand_color'];
        $school_name_update = trim($_POST['school_name']);
        $school_address_update = trim($_POST['address']);
        $current_logo = $_POST['current_logo_path'];
        $logo_path = $current_logo; // Keep the old logo by default

        // Handle file upload
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            if (in_array($_FILES['logo_file']['type'], $allowed_types)) {
                $file_name = 'logo_' . $school_id . '_' . time() . '.' . pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
                $target_file = $logo_upload_dir . $file_name;
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $target_file)) {
                    $logo_path = $file_name;
                    if (!empty($current_logo) && file_exists($logo_upload_dir . $current_logo)) {
                        unlink($logo_upload_dir . $current_logo);
                    }
                } else { $error = "Could not upload the logo."; }
            } else { $error = "Invalid file type. Please upload a JPG, GIF or PNG image."; }
        }
        
        if (empty($error)) {
            $stmt = $conn->prepare("UPDATE schools SET name = ?, logo_path = ?, brand_color = ?, address = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $school_name_update, $logo_path, $brand_color, $school_address_update, $school_id);
            if ($stmt->execute()) {
                $message = "Settings updated successfully! The changes will be visible on your next page load.";
            } else {
                $error = "Failed to update settings.";
            }
            $stmt->close();
        }
    }
}

// PART 2: LOAD VISUALS & DISPLAY DATA
require_once 'layout_header.php'; 

// Re-fetch school data after potential update to show new values
$school_stmt_refresh = $conn->prepare("SELECT name, logo_path, brand_color, address FROM schools WHERE id = ?");
$school_stmt_refresh->bind_param("i", $school_id);
$school_stmt_refresh->execute();
$school = $school_stmt_refresh->get_result()->fetch_assoc();
$school_stmt_refresh->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 30px; border-radius: 8px; }
    .form-group { margin-bottom: 25px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
    .form-group input, .form-group textarea { 
        width: 100%; padding: 12px; box-sizing: border-box; border-radius: 5px; 
        border: 1px solid var(--border-color); background-color: var(--bg-color); 
        color: var(--text-color); font-size: 16px; 
    }
    input[type="color"] { height: 50px; padding: 5px; cursor: pointer; }
    .current-logo-preview { 
        margin-bottom: 10px; border: 1px solid var(--border-color); padding: 10px; 
        border-radius: 5px; background-color: var(--bg-color);
        min-height: 50px; display: inline-block;
    }
    .current-logo-preview img { max-width: 200px; max-height: 50px; display: block; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
    .message, .error { padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
</style>

<div class="page-header">
    <h1>School Settings</h1>
</div>

<?php if($message): ?><p class="message" style="color: #155724; background-color: #d4edda;"><?php echo $message; ?></p><?php endif; ?>
<?php if($error): ?><p class="error" style="color: #D8000C; background-color: #FFD2D2;"><?php echo $error; ?></p><?php endif; ?>

<div class="card">
    <form action="settings.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="current_logo_path" value="<?php echo htmlspecialchars($school['logo_path'] ?? ''); ?>">
        
        <div class="form-group">
            <label for="school_name">School Name</label>
            <input type="text" id="school_name" name="school_name" value="<?php echo htmlspecialchars($school['name'] ?? ''); ?>" required>
        </div>
        
        <div class="form-group">
            <label for="address">School Address</label>
            <textarea id="address" name="address" rows="3"><?php echo htmlspecialchars($school['address'] ?? ''); ?></textarea>
        </div>

        <div class="form-group">
            <label>Current Logo</label>
            <div class="current-logo-preview">
                <?php if (!empty($school['logo_path'])): ?>
                    <img src="../uploads/logos/<?php echo htmlspecialchars($school['logo_path']); ?>" alt="Current Logo">
                <?php else: ?>
                    <span style="color: var(--text-muted);">No logo uploaded.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="logo_file">Upload New Logo (PNG, JPG, GIF)</label>
            <input type="file" id="logo_file" name="logo_file" accept="image/png, image/jpeg, image/gif">
        </div>
        
        <div class="form-group">
            <label for="brand_color">Brand Color</label>
            <input type="color" id="brand_color" name="brand_color" value="<?php echo htmlspecialchars($school['brand_color'] ?? '#E74C3C'); ?>">
        </div>

        <button type="submit" class="btn">Save Settings</button>
    </form>
</div>

<?php 
require_once 'layout_footer.php'; 
?>
