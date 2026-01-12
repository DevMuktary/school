<?php
// PART 1: LOGIC FIRST
require_once 'auth_check.php';

if (!$is_admin && !$is_instructor) { header('Location: index.php'); exit(); }
$message = ''; $error = '';
$upload_dir_logos = '../uploads/logos/';

// -----------------------------------------------------------------
// --- LOGIC 1: HANDLE POST for CREATING a new exam ---
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_exam'])) {
    $course_id = intval($_POST['course_id']);
    $exam_title = trim($_POST['exam_title']);

    if (empty($exam_title)) {
        $error = "Exam title cannot be empty.";
    } else {
        try {
            verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);
            $stmt = $conn->prepare("INSERT INTO exams (school_id, course_id, title, created_by_user_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iisi", $school_id, $course_id, $exam_title, $user_id);
            
            if ($stmt->execute()) {
                $new_exam_id = $conn->insert_id;
                // Success! Redirect to the gradebook for this new exam
                header('Location: gradebook.php?exam_id=' . $new_exam_id);
                exit();
            } else { $error = "Failed to create exam. It might already exist."; }
            $stmt->close();
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
}

// -----------------------------------------------------------------
// --- LOGIC 2: HANDLE POST for COMPILING results ---
// -----------------------------------------------------------------
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compile_results'])) {
    $exam_id = intval($_POST['exam_id']);
    $course_id = intval($_POST['course_id']);
    $exam_title = trim($_POST['exam_title']);

    if (empty($exam_id) || empty($course_id) || empty($exam_title)) {
        $error = "Missing data. Could not start compilation.";
    } else {
        try {
            verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);
            $conn->begin_transaction();

            // -----------------------------------------------------------------
            // --- 1. Get students (FIXED QUERY) ---
            // -----------------------------------------------------------------
            $sql = "SELECT e.student_id, u.full_name_eng 
                    FROM enrollments e 
                    JOIN users u ON e.student_id = u.id 
                    WHERE e.course_id = ? AND u.school_id = ?";
            $params = [$course_id, $school_id];
            $types = "ii";

            if ($is_instructor) {
                $sql .= " AND e.course_id IN (SELECT course_id FROM course_assignments WHERE instructor_id = ?)";
                $params[] = $user_id;
                $types .= "i";
            }
            $stmt_students = $conn->prepare($sql);
            $stmt_students->bind_param($types, ...$params);
            $stmt_students->execute();
            $students_result = $stmt_students->get_result();
            $students = [];
            while($row = $students_result->fetch_assoc()) { $students[] = $row; }
            $stmt_students->close();
            // --- END OF FIX ---

            // 2. Get all scores for this exam
            $scores_map = [];
            $stmt_scores = $conn->prepare("SELECT es.student_id, exs.subject_name, es.score, es.grade, es.remark 
                                           FROM exam_scores es
                                           JOIN exam_subjects exs ON es.exam_subject_id = exs.id
                                           WHERE exs.exam_id = ?");
            $stmt_scores->bind_param("i", $exam_id); $stmt_scores->execute();
            $scores_result = $stmt_scores->get_result();
            while ($score = $scores_result->fetch_assoc()) {
                $scores_map[$score['student_id']][$score['subject_name']] = $score;
            }
            $stmt_scores->close();

            $compiled_count = 0;
            if (empty($students)) { throw new Exception("No students enrolled in this course to compile."); }

            // 3. Loop through each student and build their result sheet
            foreach ($students as $student) {
                $student_id = $student['student_id'];
                $student_scores = $scores_map[$student_id] ?? [];

                // A. Delete any OLD result_set with this exact title
                $stmt_find_old = $conn->prepare("SELECT id FROM result_sets WHERE school_id = ? AND course_id = ? AND student_id = ? AND result_title = ?");
                $stmt_find_old->bind_param("iiis", $school_id, $course_id, $student_id, $exam_title);
                $stmt_find_old->execute();
                $old_result = $stmt_find_old->get_result();
                if ($old_set = $old_result->fetch_assoc()) {
                    $old_set_id = $old_set['id'];
                    $stmt_del_items = $conn->prepare("DELETE FROM result_line_items WHERE result_set_id = ?");
                    $stmt_del_items->bind_param("i", $old_set_id); $stmt_del_items->execute(); $stmt_del_items->close();
                    $stmt_del_set = $conn->prepare("DELETE FROM result_sets WHERE id = ?");
                    $stmt_del_set->bind_param("i", $old_set_id); $stmt_del_set->execute(); $stmt_del_set->close();
                }
                $stmt_find_old->close();

                // B. Create the new parent result_set
                $stmt_set = $conn->prepare("INSERT INTO result_sets (school_id, course_id, student_id, result_title, status) VALUES (?, ?, ?, ?, 'draft')");
                $stmt_set->bind_param("iiis", $school_id, $course_id, $student_id, $exam_title);
                $stmt_set->execute();
                $new_result_set_id = $conn->insert_id;
                $stmt_set->close();

                // C. Insert all the line items
                if ($new_result_set_id > 0 && !empty($student_scores)) {
                    $stmt_item = $conn->prepare("INSERT INTO result_line_items (result_set_id, subject_name, score, grade, remarks) VALUES (?, ?, ?, ?, ?)");
                    foreach ($student_scores as $subject_name => $score_data) {
                        $stmt_item->bind_param("isiss", $new_result_set_id, $subject_name, $score_data['score'], $score_data['grade'], $score_data['remark']);
                        $stmt_item->execute();
                    }
                    $stmt_item->close();
                }
                $compiled_count++;
            }

            $conn->commit();
            $message = "Success! Compiled $compiled_count result sheet(s) for '$exam_title'. They are now available as drafts below.";

        } catch (Exception $e) { $conn->rollback(); $error = "Compilation Error: " . $e->getMessage(); }
    }
}

// -----------------------------------------------------------------
// --- LOGIC 3: HANDLE POST for RELEASING a single result ---
// -----------------------------------------------------------------
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['release_results'])) {
    $result_set_id = intval($_POST['result_set_id']);
    $address_override = trim($_POST['school_address_override']);
    $logo_override = null;
    if (isset($_FILES['school_logo_override']) && $_FILES['school_logo_override']['error'] == 0) {
        $file_name = 'result_logo_' . $school_id . '_' . time() . '.' . pathinfo($_FILES['school_logo_override']['name'], PATHINFO_EXTENSION);
        if (move_uploaded_file($_FILES['school_logo_override']['tmp_name'], $upload_dir_logos . $file_name)) {
            $logo_override = $file_name;
        } else { $error = "Could not upload logo override."; }
    }
    if (empty($error)) {
        $stmt = $conn->prepare("UPDATE result_sets SET status = 'released', release_date = NOW(), school_logo_override = ?, school_address_override = ? WHERE id = ? AND school_id = ?");
        $stmt->bind_param("ssii", $logo_override, $address_override, $result_set_id, $school_id);
        if ($stmt->execute()) { $message = "Result has been successfully released."; } else { $error = "Failed to release result."; }
        $stmt->close();
    }
}

// -----------------------------------------------------------------
// --- LOGIC 4: HANDLE POST for BATCH RELEASING all results ---
// -----------------------------------------------------------------
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_release_results'])) {
    $course_id = intval($_POST['course_id']);
    $address_override = trim($_POST['school_address_override']);
    $logo_override = null;
    verify_course_access($conn, $course_id, $is_instructor, $user_id, $school_id);
    if (isset($_FILES['school_logo_override']) && $_FILES['school_logo_override']['error'] == 0) {
        $file_name = 'result_logo_' . $school_id . '_' . time() . '.' . pathinfo($_FILES['school_logo_override']['name'], PATHINFO_EXTENSION);
        if (move_uploaded_file($_FILES['school_logo_override']['tmp_name'], $upload_dir_logos . $file_name)) {
            $logo_override = $file_name;
        } else { $error = "Could not upload logo override."; }
    }
    if (empty($error)) {
        $stmt = $conn->prepare("UPDATE result_sets SET status = 'released', release_date = NOW(), school_logo_override = ?, school_address_override = ? WHERE course_id = ? AND school_id = ? AND status = 'draft'");
        $stmt->bind_param("ssii", $logo_override, $address_override, $course_id, $school_id);
        if ($stmt->execute()) { $message = "Successfully released $stmt->affected_rows result(s)."; } else { $error = "Failed to batch release results."; }
        $stmt->close();
    }
}

// =================================================================
// --- PART 2: LOAD ALL DATA FOR DISPLAY ---
// =================================================================
require_once 'layout_header.php'; 

// 1. Fetch courses (for all dropdowns)
$courses = [];
if ($is_admin) {
    $stmt = $conn->prepare("SELECT id, title FROM courses WHERE school_id = ? ORDER BY title ASC");
    $stmt->bind_param("i", $school_id);
} else { // Instructor
    $stmt = $conn->prepare("SELECT c.id, c.title FROM courses c JOIN course_assignments ca ON c.id = ca.course_id WHERE ca.instructor_id = ? AND c.school_id = ? ORDER BY c.title ASC");
    $stmt->bind_param("ii", $user_id, $school_id);
}
$stmt->execute();
$course_result = $stmt->get_result();
$course_ids = [];
if ($course_result) { 
    while($row = $course_result->fetch_assoc()) { 
        $courses[] = $row;
        $course_ids[] = $row['id'];
    } 
}
$stmt->close();

if (empty($courses) && empty($error)) {
    $error = $is_admin ? "No courses found. Please create a course first." : "You have not been assigned to any courses.";
}

// 2. Fetch all available exams for the Compile dropdown AND the Manage card
$exams_by_course = [];
$js_available_exams = [];
if (!empty($course_ids)) {
    $placeholders = implode(',', array_fill(0, count($course_ids), '?'));
    $types = str_repeat('i', count($course_ids));
    
    $stmt_exams = $conn->prepare("SELECT id, course_id, title, created_at FROM exams WHERE course_id IN ($placeholders) ORDER BY created_at DESC");
    $stmt_exams->bind_param($types, ...$course_ids);
    $stmt_exams->execute();
    $exams_result = $stmt_exams->get_result();
    while ($exam = $exams_result->fetch_assoc()) {
        $exams_by_course[$exam['course_id']][] = $exam;
        // For the "Compile" dropdown
        $js_available_exams[$exam['course_id']][] = ['id' => $exam['id'], 'title' => $exam['title']];
    }
    $stmt_exams->close();
}
$js_available_exams = json_encode($js_available_exams);


// 3. Fetch existing COMPILED result sets (for the bottom table)
$result_sets = [];
$rs_sql = "SELECT rs.*, u.full_name_eng as student_name, c.title as course_name 
           FROM result_sets rs 
           JOIN users u ON rs.student_id = u.id
           JOIN courses c ON rs.course_id = c.id";
$params = []; $types = ""; $where_clauses = [];
$where_clauses[] = "rs.school_id = ?";
$params[] = $school_id; $types .= "i";
if ($is_instructor) {
    $rs_sql .= " JOIN course_assignments ca ON rs.course_id = ca.course_id";
    $where_clauses[] = "ca.instructor_id = ?";
    $params[] = $user_id; $types .= "i";
}
$filter_course = $_GET['filter_course'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
if (!empty($filter_course)) {
    $where_clauses[] = "rs.course_id = ?";
    $params[] = intval($filter_course); $types .= "i";
}
if (!empty($filter_status)) {
    $where_clauses[] = "rs.status = ?";
    $params[] = $filter_status; $types .= "s";
}
$rs_sql .= " WHERE " . implode(" AND ", $where_clauses);
$rs_sql .= " ORDER BY rs.created_at DESC";
$rs_stmt = $conn->prepare($rs_sql);
if (count($params) > 0) { $rs_stmt->bind_param($types, ...$params); }
$rs_stmt->execute();
$rs_res = $rs_stmt->get_result();
if($rs_res) { while($row = $rs_res->fetch_assoc()) { $result_sets[] = $row; } }
$rs_stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; margin-bottom: 25px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    .card h3 { margin-top: 0; font-size: 18px; font-weight: 600; margin-bottom: 15px; }
    
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    
    /* --- NO-ZOOM FIX --- */
    .form-group input[type="text"],
    .form-group select,
    .form-group textarea {
        width: 100%; padding: 12px; box-sizing: border-box; 
        border-radius: 5px; border: 1px solid var(--border-color); 
        background-color: var(--bg-color); color: var(--text-color); 
        font-size: 16px !important; 
    }
    
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 600; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block; }
    .btn-sm { padding: 8px 12px; font-size: 14px; }
    .btn-secondary { background-color: #6c757d; }

    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    .status-draft { font-weight: bold; color: var(--text-muted); }
    .status-released { font-weight: bold; color: #28a745; }
    .message, .error { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .error { color: #721c24; background-color: #f8d7da; } .message { color: #155724; background-color: #d4edda; }
    
    /* Styles from manage_exams.php */
    .course-card { background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 15px; }
    .course-card-header { padding: 15px 20px; border-bottom: 1px solid var(--border-color); }
    .course-card-body { padding: 20px; }
    
    .exam-list { list-style: none; padding: 0; margin: 0; }
    .exam-list li { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color); }
    .exam-list li:last-child { border-bottom: none; }
    .exam-list .exam-title { font-weight: 600; font-size: 18px; }
    .exam-list .exam-date { font-size: 14px; color: var(--text-muted); }
    
    .create-exam-form { padding: 20px; background: var(--bg-color-secondary); border-radius: 8px; margin-top: 20px; }
    
    /* --- MODAL STYLES (FIXED) --- */
    .modal-overlay { 
        position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
        background: rgba(0,0,0,0.6); z-index: 1200; display: none; 
    }
    .modal { 
        position: fixed; top: 50%; left: 50%; 
        transform: translate(-50%, -50%); 
        background: var(--card-bg-color); 
        border-radius: 8px; width: 90%; max-width: 500px; 
        z-index: 1201; display: none; 
    }
    .modal-header, .modal-body, .modal-footer { padding: 20px; }
    .modal-header { 
        display: flex; justify-content: space-between; 
        align-items: center; border-bottom: 1px solid var(--border-color); 
    }
    .modal-header h2 { margin: 0; font-size: 18px; }
    .modal-close { 
        background: none; border: none; font-size: 24px; 
        cursor: pointer; color: var(--text-color); 
    }
    .modal-footer { text-align: right; border-top: 1px solid var(--border-color); }

    /* Responsive Table Styles */
    @media (max-width: 992px) {
        .table-wrapper thead { display: none; }
        .table-wrapper tr { 
            display: block; margin-bottom: 15px; 
            border: 1px solid var(--border-color); border-radius: 8px; padding: 10px; 
        }
        .table-wrapper td { 
            display: flex; justify-content: space-between; align-items: center; 
            text-align: right; padding: 10px 0; border-bottom: 1px solid var(--border-color); 
        }
        .table-wrapper td:last-child { border-bottom: none; }
        .table-wrapper td::before { 
            content: attr(data-label); font-weight: 600; text-align: left; 
        }
    }
</style>

<div class="page-header"><h1>Results Dashboard</h1></div>

<?php if($message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<?php if (!empty($courses)): ?>
<div class="card">
    <h2>Section 1: Manage Exams & Enter Scores</h2>
    <p>Create new exams or open existing ones to enter scores in the gradebook.</p>
    
    <?php foreach ($courses as $course): 
        $course_id = $course['id'];
        $course_title = htmlspecialchars($course['title']);
        $existing_exams = $exams_by_course[$course_id] ?? [];
    ?>
    <div class="course-card" id="course-<?php echo $course_id; ?>">
        <div class="course-card-header">
            <h3><?php echo $course_title; ?></h3>
        </div>
        <div class="course-card-body">
            <?php if (empty($existing_exams)): ?>
                <p>No exams have been created for this course yet.</p>
            <?php else: ?>
                <ul class="exam-list">
                    <?php foreach ($existing_exams as $exam): ?>
                        <li>
                            <div>
                                <span class="exam-title"><?php echo htmlspecialchars($exam['title']); ?></span>
                                <span class="exam-date">Created: <?php echo date('d M, Y', strtotime($exam['created_at'])); ?></span>
                            </div>
                            <a href="gradebook.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-sm">
                                Open Gradebook
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <div class="create-exam-form">
                <h4 style="margin: 0 0 10px 0;">Create New Exam</h4>
                <form action="manage_results.php" method="POST">
                    <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                    <div class="form-group">
                        <label for="exam_title_<?php echo $course_id; ?>" class="sr-only">New Exam Title</label>
                        <input type="text" name="exam_title" id="exam_title_<?php echo $course_id; ?>" placeholder="e.g., First Term 2025" required>
                    </div>
                    <button type="submit" name="create_exam" class="btn">Create & Open Gradebook</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h2>Section 2: Compile Results</h2>
    <p>Select a course and an exam to compile all saved scores into final result sheets. This creates the "Drafts" listed in Section 3.</p>
    
    <form action="manage_results.php" method="POST" id="compile-form">
        <div class="form-group">
            <label for="compile-course-select">Step 1: Select Course</label>
            <select id="compile-course-select">
                <option value="">-- Select a course --</option>
                <?php foreach($courses as $course): ?>
                    <option value="<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['title']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="compile-exam-select">Step 2: Select Exam to Compile</label>
            <select id="compile-exam-select" name="exam_id" disabled>
                <option value="">-- Select course first --</option>
            </select>
        </div>

        <input type="hidden" name="course_id" id="compile-hidden-course-id">
        <input type="hidden" name="exam_title" id="compile-hidden-exam-title">
        
        <button type="submit" name="compile_results" id="compile-btn" class="btn" style="background-color: #007bff;" disabled>
            Compile Results
        </button>
    </form>
</div>

<div class="card">
    <h2>Section 3: Release Compiled Results</h2>
    <p>All "Drafts" from your compilations appear here. You can release them one-by-one or in a batch.</p>

    <?php if ($is_admin): ?>
    <div class="create-exam-form" style="margin-bottom: 20px;">
        <h4 style="margin: 0 0 10px 0;">Batch Release</h4>
        <div class="form-group">
            <label>Select Course</label>
            <select id="batch-release-course-select" class="form-control">
                <option value="">-- Choose a course to batch release --</option>
                <?php foreach($courses as $course): ?>
                    <option value="<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['title']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" id="batch-release-btn" class="btn" style="background-color: #17a2b8;" disabled>Release All Drafts for this Course...</button>
    </div>
    <?php endif; ?>

    <form action="manage_results.php" method="GET" style="margin-bottom: 20px; background: var(--bg-color); padding: 15px; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
        <div class="form-group" style="margin-bottom: 0; flex-grow: 1; min-width: 200px;">
            <label style="font-size: 14px;">Filter by Course</label>
            <select name="filter_course" onchange="this.form.submit()" style="padding: 10px; font-size: 16px;">
                <option value="">-- All Courses --</option>
                <?php foreach($courses as $course): ?>
                    <option value="<?php echo $course['id']; ?>" <?php echo ($filter_course == $course['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($course['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; flex-grow: 1; min-width: 200px;">
            <label style="font-size: 14px;">Filter by Status</label>
            <select name="filter_status" onchange="this.form.submit()" style="padding: 10px; font-size: 16px;">
                <option value="">-- All Statuses --</option>
                <option value="draft" <?php echo ($filter_status == 'draft') ? 'selected' : ''; ?>>Draft</option>
                <option value="released" <?php echo ($filter_status == 'released') ? 'selected' : ''; ?>>Released</option>
            </select>
        </div>
        <a href="manage_results.php" style="padding: 10px; text-decoration: none; height: 47px; box-sizing: border-box;">Clear Filters</a>
    </form>

    <div class="table-wrapper">
        <table>
            <thead><tr><th>Student</th><th>Result Title</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if(empty($result_sets)): ?><tr><td colspan="4" style="text-align: center;">No compiled result sheets match your filters.</td></tr>
                <?php else: foreach($result_sets as $rs): ?>
                    <tr>
                        <td data-label="Student"><?php echo htmlspecialchars($rs['student_name']); ?> (<?php echo htmlspecialchars($rs['course_name']); ?>)</td>
                        <td data-label="Title"><?php echo htmlspecialchars($rs['result_title']); ?></td>
                        <td data-label="Status"><span class="status-<?php echo $rs['status']; ?>"><?php echo ucfirst($rs['status']); ?></span></td>
                        <td data-label="Actions">
                            <?php if($rs['status'] == 'draft'): ?>
                                <button class="btn btn-sm release-btn" data-id="<?php echo $rs['id']; ?>">Release</button>
                            <?php else: ?><span>Released</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="modal-overlay" id="release-modal-overlay"></div>
<div class="modal" id="release-modal">
    <div class="modal-header"><h2 id="modal-title">Release Result</h2><button class="modal-close" id="close-release-modal-btn">&times;</button></div>
    <form action="manage_results.php" method="POST" enctype="multipart/form-data">
        <div class="modal-body">
            <input type="hidden" name="result_set_id" id="modal-result-set-id">
            <p>You can optionally override the default school logo and address for this result sheet.</p>
            <div class="form-group"><label>Result Sheet Logo (Optional)</label><input type="file" name="school_logo_override"></div>
            <div class="form-group"><label>School Address (Optional)</label><textarea name="school_address_override" rows="3" style="font-size: 16px !important;"></textarea></div>
        </div>
        <div class="modal-footer"><button type="submit" name="release_results" class="btn">Finalize & Release</button></div>
    </form>
</div>

<div class="modal-overlay" id="batch-release-modal-overlay"></div>
<div class="modal" id="batch-release-modal">
    <div class="modal-header"><h2 id="batch-modal-title">Batch Release Results</h2><button class="modal-close" id="close-batch-release-modal-btn">&times;</button></div>
    <form action="manage_results.php" method="POST" enctype="multipart/form-data">
        <div class="modal-body">
            <input type="hidden" name="course_id" id="batch-modal-course-id">
            <p>You are about to release all <strong>draft</strong> results for the selected course.</p>
            <div class="form-group"><label>Result Sheet Logo (Optional)</label><input type="file" name="school_logo_override"></div>
            <div class="form-group"><label>School Address (Optional)</label><textarea name="school_address_override" rows="3" style="font-size: 16px !important;"></textarea></div>
        </div>
        <div class="modal-footer"><button type="submit" name="batch_release_results" class="btn" style="background-color: #17a2b8;">Finalize & Batch Release</button></div>
    </form>
</div>


<?php require_once 'layout_footer.php'; ?>
<script>
// We are passing the PHP array of exams to JavaScript
const availableExams = <?php echo $js_available_exams; ?>;

document.addEventListener('DOMContentLoaded', function() {
    
    // --- SCRIPT for Section 2: Compilation Form ---
    const compileCourseSelect = document.getElementById('compile-course-select');
    const compileExamSelect = document.getElementById('compile-exam-select');
    const compileBtn = document.getElementById('compile-btn');
    const hiddenCourseId = document.getElementById('compile-hidden-course-id');
    const hiddenExamTitle = document.getElementById('compile-hidden-exam-title');

    if(compileCourseSelect) {
        compileCourseSelect.addEventListener('change', function() {
            const courseId = this.value;
            compileExamSelect.innerHTML = '<option value="">-- Loading... --</option>'; // Clear
            
            if (courseId && availableExams[courseId]) {
                let options = '<option value="">-- Select an exam --</option>';
                availableExams[courseId].forEach(exam => {
                    options += `<option value="${exam.id}" data-title="${exam.title}">${exam.title}</option>`;
                });
                compileExamSelect.innerHTML = options;
                compileExamSelect.disabled = false;
            } else {
                compileExamSelect.innerHTML = '<option value="">-- No exams for this course --</option>';
                compileExamSelect.disabled = true;
            }
            compileBtn.disabled = true;
        });
    }

    if(compileExamSelect) {
        compileExamSelect.addEventListener('change', function() {
            if (this.value) {
                const selectedOption = this.options[this.selectedIndex];
                hiddenCourseId.value = compileCourseSelect.value;
                hiddenExamTitle.value = selectedOption.dataset.title;
                compileBtn.disabled = false;
            } else {
                compileBtn.disabled = true;
            }
        });
    }


    // --- SCRIPT for Section 3: Release Modals (Unchanged) ---
    // JS for SINGLE release modal
    const releaseButtons = document.querySelectorAll('.release-btn');
    const modal = document.getElementById('release-modal');
    const modalOverlay = document.getElementById('release-modal-overlay');
    const closeModalBtn = document.getElementById('close-release-modal-btn');
    const modalResultSetId = document.getElementById('modal-result-set-id');
    function openModal() { modal.style.display = 'block'; modalOverlay.style.display = 'block'; }
    function closeModal() { modal.style.display = 'none'; modalOverlay.style.display = 'none'; }
    releaseButtons.forEach(btn => {
        btn.addEventListener('click', () => { modalResultSetId.value = btn.dataset.id; openModal(); });
    });
    if(closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
    if(modalOverlay) modalOverlay.addEventListener('click', closeModal);

    // JS for BATCH release modal
    const batchCourseSelect = document.getElementById('batch-release-course-select');
    const batchReleaseBtn = document.getElementById('batch-release-btn');
    const batchModal = document.getElementById('batch-release-modal');
    const batchModalOverlay = document.getElementById('batch-release-modal-overlay');
    const closeBatchModalBtn = document.getElementById('close-batch-release-modal-btn');
    const batchModalCourseId = document.getElementById('batch-modal-course-id');
    
    if (batchReleaseBtn) {
        batchCourseSelect.addEventListener('change', function() {
            batchReleaseBtn.disabled = !this.value;
        });
    
        function openBatchModal() { 
            batchModalCourseId.value = batchCourseSelect.value;
            batchModal.style.display = 'block'; 
            batchModalOverlay.style.display = 'block'; 
        }
        function closeBatchModal() { 
            batchModal.style.display = 'none'; 
            batchModalOverlay.style.display = 'none'; 
        }
        
        batchReleaseBtn.addEventListener('click', openBatchModal);
        if(closeBatchModalBtn) closeBatchModalBtn.addEventListener('click', closeBatchModal);
        if(batchModalOverlay) batchModalOverlay.addEventListener('click', closeBatchModal);
    }
});
</script>
