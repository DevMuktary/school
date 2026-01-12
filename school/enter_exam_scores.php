<?php
// PART 1: LOAD PAGE
require_once 'auth_check.php';

if (!$is_admin && !$is_instructor) { header('Location: index.php'); exit(); }

// PART 2: LOAD DATA FOR FORM
require_once 'layout_header.php'; 

// Fetch courses for the dropdown
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
if ($course_result) { while($row = $course_result->fetch_assoc()) { $courses[] = $row; } }
$stmt->close();
$conn->close();

if (empty($courses)) {
    $error = $is_admin ? "No courses found. Please create a course first." : "You have not been assigned to any courses.";
}
?>
<style>
    .page-header h1 { margin: 0; font-size: 28px; }
    .card { background-color: var(--card-bg-color); border: 1px solid var(--border-color); padding: 25px; border-radius: 8px; margin-bottom: 25px; }
    .card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid var(--brand-primary); padding-bottom: 10px; margin-bottom: 20px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
    .btn { padding: 12px 25px; border: none; border-radius: 5px; background-color: var(--brand-primary); color: white; font-weight: 600; cursor: pointer; font-size: 16px; }
    .btn-sm { padding: 8px 12px; font-size: 14px; }
    .btn-remove { background-color: #dc3545; color:white; border:none; width:40px; height:40px; border-radius:5px; cursor:pointer; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
    th { background-color: var(--bg-color); }
    .status-indicator { margin-left: 10px; font-size: 14px; }
    .status-saving { color: #007bff; }
    .status-saved { color: #28a745; font-weight: bold; }
    .status-error { color: #dc3545; font-weight: bold; }
    .subject-entry { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; }

    /* --- STRONGER ZOOM FIXES --- */
    
    /* Fix 1: Setup Card Inputs (Course, Title) */
    #setup-card .form-group input, 
    #setup-card .form-group select {
        font-size: 16px !important; /* Force 16px to stop zoom */
        padding: 12px;
        box-sizing: border-box; 
    }
    
    /* Fix 2: Subject Name Inputs */
    #subject-list-container .subject-entry input {
        font-size: 16px !important; /* Force 16px to stop zoom */
        padding: 12px;
        box-sizing: border-box;
        border: 1px solid var(--border-color);
        background-color: var(--bg-color);
        color: var(--text-color);
        border-radius: 5px;
    }

    /* Fix 3: Gradebook Score Inputs */
    #gradebook-table td input[type="number"] { 
        width: 80px; 
        padding: 8px; 
        text-align: center; 
        font-size: 16px !important; /* Force 16px to stop zoom */
        box-sizing: border-box; 
        border: 1px solid var(--border-color);
        background-color: var(--bg-color);
        color: var(--text-color);
        border-radius: 5px;
    }
</style>

<div class="page-header"><h1>Enter Exam Scores (Gradebook)</h1></div>

<?php if(isset($error)): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

<div class="card" id="setup-card">
    <h2>Step 1: Set Up Exam</h2>
    <div class="form-group">
        <label>Select Course</label>
        <select id="course_id" class="form-control">
            <option value="">-- Choose a course --</option>
            <?php foreach($courses as $course): ?>
                <option value="<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['title']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Exam Title</label>
        <input type="text" id="exam_title" placeholder="e.g., First Term Examination 2025">
    </div>
    
    <hr style="border:0; border-top: 1px solid var(--border-color); margin: 30px 0;">
    <h4>Add Exam Subjects</h4>
    <div id="subject-list-container">
        <div class="subject-entry">
            <input type="text" class="subject-name" placeholder="Subject Name (e.g., Mathematics)">
            <button type="button" class="btn-remove" onclick="this.parentElement.remove()">×</button>
        </div>
    </div>
    <button type="button" id="add-subject-btn" class="btn" style="background-color: #6c757d; width: auto; margin-top: 10px;">+ Add Subject</button>
    <hr style="border:0; border-top: 1px solid var(--border-color); margin: 30px 0;">
    
    <button type="button" id="load-gradebook-btn" class="btn">Load Gradebook</button>
</div>

<div class="card" id="gradebook-card" style="display: none;">
    <h2 id="gradebook-title"></h2>
    <div class="table-wrapper">
        <table id="gradebook-table">
            <thead></thead>
            <tbody></tbody>
        </table>
    </div>
    
    <a href="manage_results.php" class="btn" style="background-color: #28a745; margin-top: 20px; text-decoration: none;">
        Done? Go to Release Results
    </a>
    
    <button type="button" id="back-to-setup-btn" class="btn" style="background-color: #6c757d; margin-top: 20px;">Back to Setup</button>
</div>

<template id="subject-row-template">
    <div class="subject-entry">
        <input type="text" class="subject-name" placeholder="Subject Name">
        <button type="button" class="btn-remove" onclick="this.parentElement.remove()">×</button>
    </div>
</template>

<?php require_once 'layout_footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Setup form elements
    const setupCard = document.getElementById('setup-card');
    const courseSelect = document.getElementById('course_id');
    const examTitleInput = document.getElementById('exam_title');
    const subjectListContainer = document.getElementById('subject-list-container');
    const addSubjectBtn = document.getElementById('add-subject-btn');
    const subjectTemplate = document.getElementById('subject-row-template');
    const loadGradebookBtn = document.getElementById('load-gradebook-btn');

    // Gradebook card elements
    const gradebookCard = document.getElementById('gradebook-card');
    const gradebookTitle = document.getElementById('gradebook-title');
    const gradebookTable = document.getElementById('gradebook-table');
    const gradebookThead = gradebookTable.querySelector('thead');
    const gradebookTbody = gradebookTable.querySelector('tbody');
    const backToSetupBtn = document.getElementById('back-to-setup-btn');

    // Add Subject button
    addSubjectBtn.addEventListener('click', () => {
        subjectListContainer.appendChild(subjectTemplate.content.cloneNode(true));
    });

    // Back to Setup button
    backToSetupBtn.addEventListener('click', () => {
        gradebookCard.style.display = 'none';
        setupCard.style.display = 'block';
    });

    // Load Gradebook button
    loadGradebookBtn.addEventListener('click', async () => {
        const courseId = courseSelect.value;
        const examTitle = examTitleInput.value.trim();
        const subjectInputs = subjectListContainer.querySelectorAll('.subject-name');
        
        let subjects = [];
        subjectInputs.forEach(input => {
            const subjectName = input.value.trim();
            if (subjectName) subjects.push(subjectName);
        });

        if (!courseId || !examTitle || subjects.length === 0) {
            alert('Please select a course, enter an exam title, and add at least one subject.');
            return;
        }

        // Fetch students for the course
        let students;
        try {
            const response = await fetch('api_get_students.php?course_id=' + courseId);
            if (!response.ok) throw new Error('Failed to load students.');
            students = await response.json();
            if (students.length === 0) {
                alert('No students are enrolled in this course.');
                return;
            }
        } catch (error) {
            alert(error.message);
            return;
        }

        // --- Build the Gradebook Table ---
        gradebookThead.innerHTML = ''; // Clear old header
        gradebookTbody.innerHTML = ''; // Clear old body
        
        // Build Header Row
        let headerRow = '<tr><th>Student Name</th>';
        subjects.forEach(subject => {
            headerRow += `<th>${subject}</th>`;
        });
        headerRow += '<th>Actions</th></tr>';
        gradebookThead.innerHTML = headerRow;

        // Build Student Rows
        students.forEach(student => {
            let studentRow = `<tr data-student-id="${student.id}">`;
            studentRow += `<td>${student.full_name_eng}</td>`;
            
            subjects.forEach(subject => {
                // --- THIS IS THE 20-POINT FIX ---
                studentRow += `<td><input type="number" class="score-input" data-subject="${subject}" min="0" max="20" placeholder="0"></td>`;
            });
            
            studentRow += `
                <td>
                    <button class="btn btn-sm btn-save-row">Save</button>
                    <span class="status-indicator"></span>
                </td>`;
            studentRow += '</tr>';
            gradebookTbody.innerHTML += studentRow;
        });

        // Show gradebook
        gradebookTitle.textContent = `${examTitle} - ${courseSelect.options[courseSelect.selectedIndex].text}`;
        setupCard.style.display = 'none';
        gradebookCard.style.display = 'block';
    });

    // --- AJAX Save Button (Event Delegation) ---
    gradebookTbody.addEventListener('click', async (e) => {
        if (!e.target.classList.contains('btn-save-row')) {
            return; // Click was not on a save button
        }

        const saveButton = e.target;
        const studentRow = saveButton.closest('tr');
        const statusIndicator = studentRow.querySelector('.status-indicator');
        
        const studentId = studentRow.dataset.studentId;
        const courseId = courseSelect.value;
        const examTitle = examTitleInput.value.trim();

        // 1. Collect scores from this row
        let scores = [];
        const scoreInputs = studentRow.querySelectorAll('.score-input');
        scoreInputs.forEach(input => {
            scores.push({
                subject: input.dataset.subject,
                score: input.value || 0 // Default to 0 if empty
            });
        });

        // 2. Prepare data for AJAX
        const data = {
            course_id: courseId,
            student_id: studentId,
            result_title: examTitle,
            scores: scores
        };

        // 3. Send AJAX request (to api_save_student_result.php)
        try {
            saveButton.disabled = true;
            statusIndicator.textContent = 'Saving...';
            statusIndicator.className = 'status-indicator status-saving';

            const response = await fetch('api_save_student_result.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.message || 'Server error');
            }

            const result = await response.json();
            
            if (result.success) {
                statusIndicator.textContent = 'Saved!';
                statusIndicator.className = 'status-indicator status-saved';
            } else {
                throw new Error(result.message || 'Unknown error');
            }

        } catch (error) {
            statusIndicator.textContent = `Error: ${error.message}`;
            statusIndicator.className = 'status-indicator status-error';
        } finally {
            saveButton.disabled = false;
        }
    });

    // --- NEW: Visual Feedback for Score Entry (20-point scale) ---
    gradebookTbody.addEventListener('input', (e) => {
        if (e.target.classList.contains('score-input')) {
            const score = parseInt(e.target.value, 10);
            const statusIndicator = e.target.closest('tr').querySelector('.status-indicator');
            
            // Clear any "Saved!" or "Error" message
            if (!statusIndicator.classList.contains('status-saving')) {
                statusIndicator.textContent = '';
                statusIndicator.className = 'status-indicator';
            }
            
            if (isNaN(score) || score < 0 || score > 20) {
                statusIndicator.textContent = 'Invalid';
                statusIndicator.className = 'status-indicator status-error';
                return;
            }

            // Show a temporary remark
            let remark = '';
            if (score >= 19) { remark = 'A1'; }
            else if (score >= 17) { remark = 'B2'; }
            else if (score >= 16) { remark = 'B3'; }
            else if (score >= 14) { remark = 'C4'; }
            else if (score >= 13) { remark = 'C5'; }
            else if (score >= 11) { remark = 'C6'; }
            else if (score >= 10) { remark = 'D7'; }
            else if (score >= 9) { remark = 'E8'; }
            else { remark = 'F9'; }
            
            // Show the grade as temporary feedback
            statusIndicator.textContent = `Grade: ${remark}`;
            statusIndicator.className = 'status-indicator';
        }
    });

});
</script>
