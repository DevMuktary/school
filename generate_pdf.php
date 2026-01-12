<?php
// 1. Load Dependencies & Start Session
require_once 'db_connect.php';
require_once 'dompdf/autoload.inc.php';
use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['student_id'])) { die("Access Denied. Please log in."); }
$student_id = $_SESSION['student_id'];

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { die("Invalid Result ID specified."); }
$result_set_id = intval($_GET['id']);

// 2. Security Check & Fetch All Necessary Data
$result_set = null;
$rs_sql = "SELECT rs.*, u.full_name_eng as student_name, c.title as course_name, s.name as school_name, s.logo_path, s.address as school_address
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

$line_items = [];
$li_stmt = $conn->prepare("SELECT * FROM result_line_items WHERE result_set_id = ? ORDER BY id ASC");
$li_stmt->bind_param("i", $result_set_id);
$li_stmt->execute();
$li_result = $li_stmt->get_result();
if($li_result) { while($row = $li_result->fetch_assoc()) { $line_items[] = $row; } }
$li_stmt->close();
$conn->close();

// 3. Helper function to determine CSS class for coloring
function getGradeClass($grade) {
    $first_char = strtoupper(substr(trim($grade), 0, 1));
    if ($first_char == 'A' || $first_char == 'B') return 'grade-good';
    if ($first_char == 'C') return 'grade-avg';
    if ($first_char == 'F') return 'grade-fail';
    return '';
}

// 4. Build the HTML for the PDF
$logo_path = !empty($result_set['school_logo_override']) ? 'uploads/logos/' . $result_set['school_logo_override'] : (!empty($result_set['logo_path']) ? 'uploads/logos/' . $result_set['logo_path'] : '');
$school_address = !empty($result_set['school_address_override']) ? $result_set['school_address_override'] : $result_set['school_address'];

$table_rows = '';
foreach($line_items as $item) {
    $gradeClass = getGradeClass($item['grade']);
    $table_rows .= "<tr class='{$gradeClass}'>
        <td>" . htmlspecialchars($item['subject_name']) . "</td>
        <td class='center'>" . htmlspecialchars($item['score']) . "</td>
        <td class='center'><b>" . htmlspecialchars($item['grade']) . "</b></td>
        <td>" . htmlspecialchars($item['remarks']) . "</td>
    </tr>";
}

$logo_html = '';
if (!empty($logo_path) && file_exists($logo_path)) {
    $logo_data = base64_encode(file_get_contents($logo_path));
    $logo_src = 'data:image/png;base64,' . $logo_data;
    $logo_html = "<img src='{$logo_src}' alt='School Logo'>";
}

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{$result_set['result_title']}</title>
    <style>
        @page { margin: 25px; }
        
        /* FIXED: Changed font to DejaVu Sans to support special characters */
        body { 
            font-family: 'DejaVu Sans', sans-serif; 
            color: #333; 
            font-size: 11pt; 
        }
        
        .result-sheet { width: 100%; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 25px; }
        .header img { max-height: 70px; max-width: 250px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 24pt; color: #000; }
        .header h2 { margin: 5px 0 0; font-size: 16pt; color: #444; font-weight: normal; }
        .header p { margin: 5px 0 0; font-size: 10pt; color: #555; }
        .student-details { margin-bottom: 25px; font-size: 12pt; }
        .student-details table { width: 100%; }
        table.results { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #f2f2f2; font-weight: bold; }
        tbody tr:nth-child(even) { background-color: #f9f9f9; } /* Alternating row colors */
        .center { text-align: center; }
        .watermark { position: fixed; bottom: 10px; left: 0; width: 100%; text-align: center; font-size: 9pt; color: #aaa; }
        /* Grade-based coloring */
        .grade-good { color: #28a745; }
        .grade-avg { color: #e69500; }
        .grade-fail { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>
    <div class="result-sheet">
        <div class="header">
            {$logo_html}
            <h1>{$result_set['school_name']}</h1>
            <p>{$school_address}</p>
            <h2>{$result_set['result_title']}</h2>
        </div>
        <div class="student-details">
            <table>
                <tr>
                    <td><strong>Student:</strong> {$result_set['student_name']}</td>
                    <td><strong>Course:</strong> {$result_set['course_name']}</td>
                </tr>
            </table>
        </div>
        <table class="results">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th class="center">Score</th>
                    <th class="center">Grade</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                {$table_rows}
            </tbody>
        </table>
    </div>
    <div class="watermark">
        Downloaded from official institute of mutoon for {$result_set['school_name']}
    </div>
</body>
</html>
HTML;

// 5. Instantiate Dompdf and generate the PDF
$options = new Options();
$options->set('isRemoteEnabled', true); // Allows loading images
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// 6. Stream the file to the browser for download
$file_name = strtolower(str_replace(' ', '_', $result_set['result_title'])) . ".pdf";
$dompdf->stream($file_name, ["Attachment" => true]);
?>
