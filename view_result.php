<?php
require_once 'db_connect.php';
if (!isset($_SESSION['student_id'])) { header('Location: index.php'); exit(); }
$student_id = $_SESSION['student_id'];
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: results.php'); exit(); }
$result_set_id = intval($_GET['id']);

// --- Security Check & Fetch Data ---
$result_set = null;
$rs_sql = "SELECT rs.*, u.full_name_eng as student_name, c.title as course_name, s.name as school_name, s.logo_path, s.address as school_address, s.brand_color
           FROM result_sets rs 
           JOIN users u ON rs.student_id = u.id
           JOIN courses c ON rs.course_id = c.id
           JOIN schools s ON rs.school_id = s.id
           WHERE rs.id = ? AND rs.student_id = ? AND rs.status = 'released'";
$rs_stmt = $conn->prepare($rs_sql);
$rs_stmt->bind_param("ii", $result_set_id, $student_id);
$rs_stmt->execute();
$rs_result = $rs_stmt->get_result();
if($rs_result->num_rows === 0) { die("Result not found or you do not have permission to view it."); }
$result_set = $rs_result->fetch_assoc();
$rs_stmt->close();
$school_brand_color = !empty($result_set['brand_color']) ? $result_set['brand_color'] : '#001232';

$line_items = [];
$li_stmt = $conn->prepare("SELECT * FROM result_line_items WHERE result_set_id = ? ORDER BY id ASC");
$li_stmt->bind_param("i", $result_set_id);
$li_stmt->execute();
$li_result = $li_stmt->get_result();
if($li_result) { while($row = $li_result->fetch_assoc()) { $line_items[] = $row; } }
$li_stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($result_set['result_title']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        :root {
            --brand-primary: <?php echo $school_brand_color; ?>;
            --bg-color: #f7f9fc; --card-bg-color: #FFFFFF; --text-color: #2c3e50;
            --text-muted: #6c757d; --border-color: #e9ecef;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-color); margin: 0; color: var(--text-color); }
        .header { background-color: var(--card-bg-color); border-bottom: 1px solid var(--border-color); padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; }
        .header .logo img { max-height: 35px; }
        .header .logo span { font-size: 20px; font-weight: 700; color: var(--brand-primary); }
        .header-controls { display: flex; align-items: center; gap: 10px; }
        .header-btn { padding: 6px 14px; border-radius: 50px; font-weight: 500; text-decoration: none; border: 1px solid var(--border-color); color: var(--text-color); background-color: transparent; cursor: pointer; font-size: 13px; white-space: nowrap; }
        .header-btn.primary { background-color: var(--brand-primary); color: white; border-color: var(--brand-primary); }
        
        .result-sheet { max-width: 800px; margin: 30px auto; background: var(--card-bg-color); padding: 40px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
        .result-header { text-align: center; border-bottom: 2px solid var(--border-color); padding-bottom: 20px; margin-bottom: 30px; }
        .result-header img { max-height: 70px; margin-bottom: 15px; }
        .result-header h1 { margin: 0; font-size: 24px; }
        .result-header h2 { margin: 5px 0 0; font-size: 18px; color: var(--text-muted); font-weight: 500;}
        .student-details { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px; margin-bottom: 30px; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { font-weight: 600; }
        .score-col { text-align: center; font-weight: 600; }
        .footer-watermark { text-align: center; margin-top: 40px; font-size: 12px; color: #aaa; }
        
        @media (max-width: 768px) {
            .result-sheet { padding: 20px; margin: 15px; }
            .student-details { flex-direction: column; gap: 5px; }
            .table-wrapper thead { display: none; }
            .table-wrapper tr { display: block; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 5px; }
            .table-wrapper td { display: block; text-align: right; padding-left: 50%; position: relative; border-bottom: 1px solid var(--border-color); }
            .table-wrapper td:last-child { border-bottom: none; }
            .table-wrapper td::before { content: attr(data-label); position: absolute; left: 15px; font-weight: 600; text-align: left; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo">
             <?php if (!empty($result_set['logo_path'])): ?>
                <img src="uploads/logos/<?php echo htmlspecialchars($result_set['logo_path']); ?>" alt="<?php echo htmlspecialchars($result_set['school_name']); ?> Logo">
            <?php else: ?>
                <span><?php echo htmlspecialchars($result_set['school_name']); ?></span>
            <?php endif; ?>
        </div>
        <div class="header-controls">
            <a href="results.php" class="header-btn">&larr; Back to Results</a>
            <a href="generate_pdf.php?id=<?php echo $result_set_id; ?>" class="header-btn primary">Download as PDF</a>
        </div>
    </header>

    <div class="result-sheet">
        <div class="result-header">
            <?php $logo_to_use = !empty($result_set['school_logo_override']) ? $result_set['school_logo_override'] : $result_set['logo_path']; if(!empty($logo_to_use)): ?>
                <img src="uploads/logos/<?php echo htmlspecialchars($logo_to_use); ?>" alt="<?php echo htmlspecialchars($result_set['school_name']); ?> Logo">
            <?php endif; ?>
            <h1><?php echo htmlspecialchars($result_set['school_name']); ?></h1>
            <h2><?php echo htmlspecialchars($result_set['result_title']); ?></h2>
        </div>
        <div class="student-details">
            <div><strong>Student:</strong> <?php echo htmlspecialchars($result_set['student_name']); ?></div>
            <div><strong>Course:</strong> <?php echo htmlspecialchars($result_set['course_name']); ?></div>
        </div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Subject</th><th class="score-col">Score</th><th class="score-col">Grade</th><th>Remarks</th></tr></thead>
                <tbody>
                    <?php if(empty($line_items)): ?>
                        <tr><td colspan="4" style="text-align:center;">No subjects recorded.</td></tr>
                    <?php else: foreach($line_items as $item): ?>
                    <tr>
                        <td data-label="Subject"><?php echo htmlspecialchars($item['subject_name']); ?></td>
                        <td data-label="Score" class="score-col"><?php echo htmlspecialchars($item['score']); ?></td>
                        <td data-label="Grade" class="score-col"><strong><?php echo htmlspecialchars($item['grade']); ?></strong></td>
                        <td data-label="Remarks"><?php echo htmlspecialchars($item['remarks']); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="footer-watermark">
            Downloaded from the official INTRA-EDU portal for <?php echo htmlspecialchars($result_set['school_name']); ?>
        </div>
    </div>
</body>
</html>
