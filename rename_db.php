<?php
require 'db_connect.php';
echo "<h1>Restructuring Database...</h1>";

$sql = "
    -- 1. Rename 'courses' table to 'classes'
    ALTER TABLE courses RENAME TO classes;

    -- 2. Rename the column 'title' to 'class_name' (e.g., 'Primary 1')
    ALTER TABLE classes CHANGE COLUMN title class_name VARCHAR(255) NOT NULL;

    -- 3. Update the 'enrollments' table to match
    ALTER TABLE enrollments CHANGE COLUMN course_id class_id INT NOT NULL;
";

if ($conn->multi_query($sql)) {
    echo "<h2 style='color:green'>✅ Success! 'Courses' are now 'Classes'.</h2>";
    echo "<p>You can now delete this file.</p>";
} else {
    // If it fails, it might be because you already ran it.
    echo "<h2 style='color:orange'>⚠️ Notice:</h2>";
    echo $conn->error;
}
?>
