<?php
// Load your database connection
require 'db_connect.php';

echo "<h1>Starting Database Setup...</h1>";

// The SQL command to create your tables
$sql = "
-- 1. Create Schools Table
CREATE TABLE IF NOT EXISTS schools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    brand_color VARCHAR(20) DEFAULT '#000000',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Create Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('student', 'admin', 'super_admin') DEFAULT 'student',
    full_name_eng VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone_number VARCHAR(50),
    level VARCHAR(50),
    account_status ENUM('active', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id)
);

-- 3. Create Login Attempts
CREATE TABLE IF NOT EXISTS login_attempts (
    ip_address VARCHAR(45) PRIMARY KEY,
    failed_attempts INT DEFAULT 0,
    last_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 4. Create Courses Table
CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    enrollment_key VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id)
);

-- 5. Create Enrollments Table
CREATE TABLE IF NOT EXISTS enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    school_id INT NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id),
    FOREIGN KEY (course_id) REFERENCES courses(id)
);

-- --- INSERT TEST DATA (Only if table is empty) ---
INSERT IGNORE INTO schools (id, name, slug, brand_color) 
VALUES (1, 'Test School', 'test-school', '#4F46E5');

INSERT IGNORE INTO courses (id, school_id, title, enrollment_key) 
VALUES (1, 1, 'General Arabic 101', '12345');
";

// Execute the SQL
if ($conn->multi_query($sql)) {
    echo "<h2 style='color:green'>✅ Success! Tables created.</h2>";
    echo "<p>Test School Created. You can now try to register.</p>";
    echo "<p><strong>Important:</strong> Delete this file (setup_db.php) from GitHub now.</p>";
    
    // Cycle through results to clear buffer
    do {
        if ($res = $conn->store_result()) $res->free();
    } while ($conn->more_results() && $conn->next_result());
    
} else {
    echo "<h2 style='color:red'>❌ Error creating tables:</h2>";
    echo $conn->error;
}

$conn->close();
?>
