<?php 
require_once 'layout_header.php';

if (!$is_admin) {
    header('Location: instructor_dashboard.php?error=access_denied');
    exit();
}

$message = ''; $error = '';

// Handle un-assigning a single instructor from a course
if (isset($_GET['action']) && $_GET['action'] == 'unassign') {
    $course_id_to_edit = intval($_GET['course_id']);
    $instructor_id_to_edit = intval($_GET['instructor_id']);
    $stmt = $conn->prepare("DELETE FROM course_assignments WHERE course_id = ? AND instructor_id = ? AND school_id = ?");
    $stmt->bind_param("iii", $course_id_to_edit, $instructor_id_to_edit, $school_id);
    if ($stmt->execute()) { $message = "Instructor unassigned successfully."; }
    $stmt->close();
}

// Handle saving assignments from the modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assignments'])) {
    $course_id = intval($_POST['course_id']);
    $assigned_instructors = $_POST['instructors'] ?? [];

    $check_stmt = $conn->prepare("SELECT id FROM courses WHERE id = ? AND school_id = ?");
    $check_stmt->bind_param("ii", $course_id, $school_id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows === 0) { die("Error: Course not found in your school."); }
    $check_stmt->close();
    
    $conn->begin_transaction();
    try {
        $delete_stmt = $conn->prepare("DELETE FROM course_assignments WHERE course_id = ? AND school_id = ?");
        $delete_stmt->bind_param("ii", $course_id, $school_id);
        $delete_stmt->execute();
        $delete_stmt->close();

        if (!empty($assigned_instructors)) {
            $insert_stmt = $conn->prepare("INSERT INTO course_assignments (course_id, instructor_id, school_id) VALUES (?, ?, ?)");
            foreach ($assigned_instructors as $instructor_id) {
                $iid = intval($instructor_id);
                $insert_stmt->bind_param("iii", $course_id, $iid, $school_id);
                $insert_stmt->execute();
            }
            $insert_stmt->close();
        }
        $conn->commit();
        $message = "Assignments for the course have been updated successfully!";
    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        $error = "An error occurred while saving the assignments.";
    }
}

// --- SECURITY FIX: Fetch all data using prepared statements ---
$instructors = [];
$courses_with_assignments = [];

// Fetch Instructors
$stmt = $conn->prepare("SELECT id, full_name_eng FROM users WHERE school_id = ? AND role = 'instructor'");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$instructor_result = $stmt->get_result();
if($instructor_result){ while($row = $instructor_result->fetch_assoc()){ $instructors[] = $row; } }
$stmt->close();

// Fetch Courses
$stmt = $conn->prepare("SELECT id, title FROM courses WHERE school_id = ? ORDER BY title ASC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$courses_result = $stmt->get_result();
if ($courses_result) {
    while ($course_row = $courses_result->fetch_assoc()) {
        $courses_with_assignments[$course_row['id']] = [ 'title' => $course_row['title'], 'instructors' => [] ];
    }
}
$stmt->close();

// Fetch existing assignments and populate the courses array
$stmt = $conn->prepare("SELECT ca.course_id, u.id as instructor_id, u.full_name_eng as instructor_name FROM course_assignments ca JOIN users u ON ca.instructor_id = u.id WHERE ca.school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$assignments_result = $stmt->get_result();
if ($assignments_result) {
    while ($assign_row = $assignments_result->fetch_assoc()) {
        if (isset($courses_with_assignments[$assign_row['course_id']])) {
            $courses_with_assignments[$assign_row['course_id']]['instructors'][$assign_row['instructor_id']] = $assign_row['instructor_name'];
        }
    }
}
$stmt->close();
$conn->close();
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; margin-bottom: 25px; }
    .card h2 { margin-top: 0; font-size: 20px; }
    .btn { padding: 10px 20px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 600; cursor: pointer; font-size: 14px; }
    .message, .error { padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; color: #155724; background-color: #d4edda; }
    .error { color: #721c24; background-color: #f8d7da; }
    .assignment-list { list-style: none; padding: 0; }
    .assignment-list > li { margin-bottom: 15px; background-color: var(--bg-color); padding: 20px; border-radius: 8px; border: 1px solid var(--border-color); }
    .course-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .course-header strong { font-size: 18px; }
    .assignment-list ul { list-style: none; padding-left: 0; margin-top: 5px; font-size: 14px; }
    .assignment-list li li { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border-color); }
    .assignment-list li li:last-child { border-bottom: none; }
    .unassign-link { color: #dc3545; text-decoration: none; font-size: 12px; font-weight: 500; }
    
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1200; display: none; }
    .modal { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: var(--card-bg-color); border-radius: 8px; width: 90%; max-width: 500px; z-index: 1201; display: none; }
    .modal-header { padding: 15px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .modal-header h2 { margin: 0; font-size: 18px; }
    .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-color); }
    .modal-body { padding: 20px; max-height: 60vh; overflow-y: auto; }
    .modal-footer { padding: 15px 20px; border-top: 1px solid var(--border-color); text-align: right; }
    .checkbox-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .checkbox-group { display: flex; align-items: center; gap: 10px; }
</style>

<div class="page-header">
    <h1>Manage Course Assignments</h1>
</div>

<?php if($message): ?><p class="message"><?php echo $message; ?></p><?php endif; ?>
<?php if($error): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>

<div class="card">
    <h2>Course Assignment Overview</h2>
    <?php if(empty($courses_with_assignments)): ?>
        <p>No courses found. Please <a href="manage_courses.php">create a course</a> first.</p>
    <?php else: ?>
        <ul class="assignment-list">
        <?php foreach($courses_with_assignments as $course_id => $course_data): ?>
            <li>
                <div class="course-header">
                    <strong><?php echo htmlspecialchars($course_data['title']); ?></strong>
                    <button class="btn edit-assignments-btn" 
                            data-course-id="<?php echo $course_id; ?>" 
                            data-course-title="<?php echo htmlspecialchars($course_data['title']); ?>"
                            data-assigned-instructors='<?php echo json_encode(array_keys($course_data['instructors'])); ?>'>
                        Edit
                    </button>
                </div>
                <ul>
                    <?php if(empty($course_data['instructors'])): ?>
                        <li style="border:none; color:var(--text-muted);">No instructors assigned.</li>
                    <?php else: foreach($course_data['instructors'] as $instructor_id => $instructor_name): ?>
                        <li>
                            <span><?php echo htmlspecialchars($instructor_name); ?></span>
                            <a href="?action=unassign&course_id=<?php echo $course_id; ?>&instructor_id=<?php echo $instructor_id; ?>" class="unassign-link" onclick="return confirm('Are you sure you want to unassign this instructor?');">[unassign]</a>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="modal-overlay"></div>
<div class="modal" id="assignment-modal">
    <div class="modal-header">
        <h2 id="modal-title">Edit Assignments</h2>
        <button class="modal-close" id="modal-close">&times;</button>
    </div>
    <form action="manage_assignments.php" method="POST">
        <div class="modal-body">
            <input type="hidden" name="course_id" id="modal-course-id">
            <p>Select the instructors who should teach this course:</p>
            <div class="checkbox-grid" id="modal-instructor-list"></div>
        </div>
        <div class="modal-footer">
            <button type="submit" name="save_assignments" class="btn">Save Changes</button>
        </div>
    </form>
</div>

<script>
    const allInstructors = <?php echo json_encode($instructors); ?>;
    const editButtons = document.querySelectorAll('.edit-assignments-btn');
    const modal = document.getElementById('assignment-modal');
    const modalOverlay = document.getElementById('modal-overlay');
    const modalCloseBtn = document.getElementById('modal-close');
    const modalTitle = document.getElementById('modal-title');
    const modalCourseIdInput = document.getElementById('modal-course-id');
    const modalInstructorList = document.getElementById('modal-instructor-list');

    function openModal() { modal.style.display = 'block'; modalOverlay.style.display = 'block'; }
    function closeModal() { modal.style.display = 'none'; modalOverlay.style.display = 'none'; }

    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const courseId = this.dataset.courseId;
            const courseTitle = this.dataset.courseTitle;
            const assignedInstructors = JSON.parse(this.dataset.assignedInstructors).map(String);

            modalTitle.textContent = 'Edit Assignments for "' + courseTitle + '"';
            modalCourseIdInput.value = courseId;
            modalInstructorList.innerHTML = '';
            
            if(allInstructors.length === 0) {
                 modalInstructorList.innerHTML = '<p>No instructors have been created yet.</p>';
            } else {
                allInstructors.forEach(instructor => {
                    const isChecked = assignedInstructors.includes(instructor.id) ? 'checked' : '';
                    const instructorHtml = `
                        <div class="checkbox-group">
                            <input type="checkbox" name="instructors[]" value="${instructor.id}" id="inst_${courseId}_${instructor.id}" ${isChecked}>
                            <label for="inst_${courseId}_${instructor.id}">${instructor.full_name_eng}</label>
                        </div>`;
                    modalInstructorList.insertAdjacentHTML('beforeend', instructorHtml);
                });
            }
            openModal();
        });
    });
    modalCloseBtn.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', closeModal);
</script>

<?php 
require_once 'layout_footer.php'; 
?>
