<?php
ob_start();
session_start();
require_once '../config/db.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: ../login.php');
    exit;
}

// Check for report IDs from bulk print or single print
$reports = [];
if (isset($_POST['bulk_print']) && isset($_POST['report_ids']) && is_array($_POST['report_ids'])) {
    $reports = array_filter($_POST['report_ids'], 'is_numeric');
} elseif (isset($_GET['report_id']) && is_numeric($_GET['report_id'])) {
    $reports = [(int)$_GET['report_id']];
}

if (empty($reports)) {
    error_log("No reports selected for printing.");
    $_SESSION['error_msg'] = "No reports selected for printing.";
    header('Location: report_card.php');
    exit;
}

// Define footer messages
$footer_messages = [
    "Prepared with diligence and care.",
    "End of report — beginning of action.",
    "Data-driven decisions start here.",
    "Compiled for clarity and insight.",
    "Report finalized: let the results speak.",
    "Every detail matters.",
    "From observation to transformation.",
    "Learning through reflection.",
    "Documenting progress, inspiring change.",
    "A snapshot of growth and goals."
];

// Function to get random footer
function getRandomFooter($footer_messages) {
    $random_footer = $footer_messages[array_rand($footer_messages)];
    if (strpos($random_footer, '–') !== false) {
        $random_footer = '"' . $random_footer . '"';
    }
    return $random_footer;
}

// Function to calculate grade and remark
function getGradeAndRemark($total) {
    if ($total >= 90) return ['grade' => 'A+', 'remark' => 'Distinction', 'class' => 'grade-ap'];
    if ($total >= 80) return ['grade' => 'A', 'remark' => 'Excellence', 'class' => 'grade-a'];
    if ($total >= 75) return ['grade' => 'B2', 'remark' => 'Very Good', 'class' => 'grade-b2'];
    if ($total >= 65) return ['grade' => 'B3', 'remark' => 'Good', 'class' => 'grade-b3'];
    if ($total >= 55) return ['grade' => 'C4', 'remark' => 'Credit', 'class' => 'grade-c'];
    if ($total >= 50) return ['grade' => 'C5', 'remark' => 'Credit', 'class' => 'grade-c'];
    if ($total >= 45) return ['grade' => 'C6', 'remark' => 'Credit', 'class' => 'grade-c'];
    if ($total >= 35) return ['grade' => 'D7', 'remark' => 'Pass', 'class' => 'grade-d'];
    if ($total >= 30) return ['grade' => 'E8', 'remark' => 'Weak Pass', 'class' => 'grade-e'];
    return ['grade' => 'Fa', 'remark' => 'Fail', 'class' => 'grade-f'];
}

// Prepare data for each report
$reports_data = [];
$reports_html = '';
$index = 0;

foreach ($reports as $report_id) {
    // Fetch report basic info
    $stmt = $conn->prepare("SELECT user_id, term_id, class_id, promoted_to_class_id, attendance, reopening_date, conduct, attitude, interest, teacher_remarks, headmaster_remarks FROM student_reports WHERE id = ?");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $report_basic = $result->fetch_assoc();
    $stmt->close();

    if (!$report_basic) {
        error_log("Report ID $report_id not found.");
        continue;
    }

    $user_id = $report_basic['user_id'];
    $term_id = $report_basic['term_id'];

    // Fetch user data
    $stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        error_log("User ID $user_id not found for report ID $report_id.");
        continue;
    }

    // Fetch report details
    $stmt = $conn->prepare("SELECT c.class_name, ac.term_name, ac.year FROM classes c JOIN academic_calendar ac ON ? = ac.id WHERE c.id = ?");
    $stmt->bind_param("ii", $term_id, $report_basic['class_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $report_details = $result->fetch_assoc();
    $stmt->close();

    if (!$report_details) {
        error_log("Report details not found for report ID $report_id, term ID $term_id, class ID {$report_basic['class_id']}.");
        continue;
    }

    // Fetch promoted class name
    $next_class_name = 'N/A';
    if ($report_basic['promoted_to_class_id']) {
        $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
        $stmt->bind_param("i", $report_basic['promoted_to_class_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $next_class_name = $row['class_name'];
        }
        $stmt->close();
    }

    // Format attendance
    $attendance_display = $report_basic['attendance'] ? $report_basic['attendance'] . ' out of 57' : 'N/A';
    $attendance_percentage = $report_basic['attendance'] && is_numeric($report_basic['attendance']) && $report_basic['attendance'] > 0 ? number_format(($report_basic['attendance'] / 57) * 100, 1) . '%' : 'N/A';

    // Format reopening date
    $reopening_display = $report_basic['reopening_date'] ? date('l, F j, Y', strtotime($report_basic['reopening_date'])) : 'N/A';

    // Fetch grades
    $stmt = $conn->prepare("SELECT g.mid_term_exam, g.end_term_exam, s.subject_name FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.user_id = ? AND g.term_id = ?");
    $stmt->bind_param("ii", $user_id, $term_id);
    $stmt->execute();
    $grades_result = $stmt->get_result();
    $grades = $grades_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($grades)) {
        error_log("No grades found for user ID $user_id, term ID $term_id.");
        continue;
    }

    // Calculate totals and subject data
    $student_grand_total = 0;
    $subject_data = [];
    $grades_html = '';
    $grand_total = 0;
    foreach ($grades as $grade) {
        $mid_term = is_numeric($grade['mid_term_exam']) ? (float)$grade['mid_term_exam'] : 0;
        $end_term = is_numeric($grade['end_term_exam']) ? (float)$grade['end_term_exam'] : 0;
        $sba_scaled = $mid_term * 0.5;
        $exam_scaled = $end_term * 0.5;
        $total = $sba_scaled + $exam_scaled;
        $grade_info = getGradeAndRemark($total);
        $grand_total += $total;
        $student_grand_total += $total;
        $subject_data[] = ['label' => $grade['subject_name'], 'value' => $total];

        $grades_html .= '<tr class="' . $grade_info['class'] . '">
            <td align="left"><font color="black">' . htmlspecialchars($grade['subject_name'], ENT_QUOTES, 'UTF-8') . '</font></td>
            <td align="center"><font color="black">' . number_format($sba_scaled, 1) . '</font></td>
            <td align="center"><font color="black">' . number_format($exam_scaled, 1) . '</font></td>
            <td align="center"><font color="black">' . number_format($total, 1) . '</font></td>
            <td align="center"><font color="black">' . $grade_info['grade'] . '</font></td>
            <td align="center"><font color="black">' . $grade_info['remark'] . '</font></td>
        </tr>';
    }

    // Total row
    $grades_html .= '<tr align="left" class="total-row">
        <th align="left"><font color="black"><h3>TOTAL</h3></font></th>
        <th align="center"><font color="black"></font></th>
        <th align="center"><font color="black"></font></th>
        <th align="center"><font color="black">' . number_format($grand_total, 1) . '</font></th>
        <th align="center"><font color="black"></font></th>
        <th align="center"><font color="black"></font></th>
    </tr>';

    // Aggregate
    $max_score = count($grades) * 100;
    $aggregate_percentage = ($grand_total / $max_score) * 100;
    $aggregate_grade = getGradeAndRemark($aggregate_percentage)['grade'];
    $aggregate_display = number_format($aggregate_percentage, 1) . '% (' . $aggregate_grade . ') [' . number_format($grand_total, 1) . ' / ' . $max_score . ']';

    // Fetch class performance
    $class_query = "SELECT 
                    AVG(student_total) as class_average,
                    MAX(student_total) as class_highest,
                    MIN(student_total) as class_lowest
                FROM (
                    SELECT g.user_id, SUM((g.mid_term_exam * 0.5 + g.end_term_exam * 0.5)) as student_total
                    FROM grades g 
                    JOIN student_classes sc ON g.user_id = sc.user_id 
                    WHERE g.term_id = ? 
                    AND sc.academic_year = ?
                    AND sc.class_id = ?
                    GROUP BY g.user_id
                ) as sub";
    $class_stmt = $conn->prepare($class_query);
    $class_stmt->bind_param('isi', $term_id, $report_details['year'], $report_basic['class_id']);
    $class_stmt->execute();
    $class_results = $class_stmt->get_result()->fetch_assoc();
    $class_average = (float)($class_results['class_average'] ?? 0);
    $class_highest = (float)($class_results['class_highest'] ?? 0);
    $class_lowest = (float)($class_results['class_lowest'] ?? 0);
    $class_stmt->close();

    // Check images
    $signature_path = '/assets/images/signature_head.png';
    $stamp_path = '/assets/images/stamp.png';
    $seal_path = '/assets/images/seal.png';
    $base_path = $_SERVER['DOCUMENT_ROOT'];
    $signature_exists = file_exists($base_path . $signature_path);
    $stamp_exists = file_exists($base_path . $stamp_path);
    $seal_exists = file_exists($base_path . $seal_path);

    // Random footer
    $random_footer = getRandomFooter($footer_messages);

    // Collect data for JS charts
    $reports_data[] = [
        'subject_data' => $subject_data,
        'student_grand_total' => $student_grand_total,
        'class_highest' => $class_highest,
        'class_average' => $class_average,
        'class_lowest' => $class_lowest,
        'year' => $report_details['year'],
        'term_name' => $report_details['term_name']
    ];

    // Generate HTML for this report
    $reports_html .= '
    <div class="container" id="report_' . $index . '">
        <div class="header-section">
            <table border="0" cellspacing="0" cellpadding="0" align="center" width="100%" class="header-table">
                <tr>
                    <td width="100%" valign="top">
                        <table width="100%" align="center" cellspacing="0">
                            <tr>
                                <td width="120" align="left" valign="top" class="logo-section">
                                    <img src="/assets/images/logo.png" alt="School Logo" width="85" height="90">
                                </td>
                                <td width="400" align="left" valign="top" class="school-info">
                                    <h1>Queen\'s Paradise Montessori School</h1>
                                    <p>"Christ, Our Foundation Integrity, Discipline and Excellence our hallmark"</p>
                                </td>
                                <td width="358" align="left" valign="top" class="contact-info">
                                    <strong>CONTACT</strong>
                                    <p>P. O. Box AM 42 Amasaman</p>
                                    <p>0507390755</p>
                                    <p>info.queenspa49@gmail.com</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>
        <div class="report-title">
            ' . htmlspecialchars($report_details['year'] . " Term " . $report_details['term_name'] . " - END OF TERM EXAMINATION Report", ENT_QUOTES, 'UTF-8') . '
        </div>
        <div class="report-details">
            <table width="100%" border="0" align="center" cellpadding="" cellspacing="">
                <tr>
                    <td width="326" align="left" valign="top"><strong>Report Date:</strong> August 21, 2025</td>
                    <td width="432" align="left" valign="top"><strong>Student Name:</strong> ' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . '</td>
                    <td width="542" align="left" valign="top"><strong>Student ID:</strong> QPMS' . $report_id . '</td>
                </tr>
                <tr>
                    <td align="left" valign="top"><strong>Class/Grade:</strong> ' . htmlspecialchars($report_details['class_name'], ENT_QUOTES, 'UTF-8') . '</td>
                    <td align="left" valign="top"><strong>Term:</strong> ' . htmlspecialchars($report_details['term_name'], ENT_QUOTES, 'UTF-8') . '</td>
                    <td align="left" valign="top"><strong>Year:</strong> ' . htmlspecialchars($report_details['year'], ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
                <tr>
                    <td width="432" align="left" valign="top"><strong><span class="subtitle">Attendance (Days):</span></strong> ' . htmlspecialchars($attendance_display . ' (' . $attendance_percentage . ')', ENT_QUOTES, 'UTF-8') . '</td>
                    <td width="542" align="left" valign="top"><strong><span class="subtitle">Reopening:</span></strong> ' . htmlspecialchars($reopening_display, ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
            </table>
            <div class="student-photo">
                <img src="' . htmlspecialchars($user['profile_picture'] ?? '/assets/images/default_profile.png', ENT_QUOTES, 'UTF-8') . '" alt="Student Photo">
            </div>
        </div>
        <table width="100%" align="center" border="1" cellspacing="0" bordercolor="black" class="grades-table">
            <tr align="left">
                <th align="left"><font color="black">SUBJECT NAME</font></th>
                <th align="center"><font color="black">SBA 50%</font></th>
                <th align="center"><font color="black">EXAMS 50%</font></th>
                <th align="center"><font color="black">TOTAL 100%</font></th>
                <th align="center"><font color="black">GRADE</font></th>
                <th align="center"><font color="black">REMARKS</font></th>
            </tr>
            ' . $grades_html . '
        </table>
        <div class="additional-details">
            <table width="100%" border="0" align="center" cellpadding="" cellspacing="">
                <tr>
                    <td width="549" align="left" valign="top">
                        <strong>Total Aggregate:</strong> 
                        ' . $aggregate_display . '
                    </td>
                    <td width="745" align="left" valign="top">
                        <strong>Class Teacher\'s Signature</strong><br>
                        <div style="height: 55px;"></div>
                        <hr align="left" size="6" color="black" width="60%">
                    </td>
                </tr>
                <tr>
                    <td width="549" align="left" valign="top">
                        <strong>Report for:</strong> <font color="#2e7d32">' . htmlspecialchars($report_details['class_name'], ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                    <td width="745" align="left" valign="top">
                        <strong>Promoted to:</strong> <font color="#d81b60">' . htmlspecialchars($next_class_name, ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                </tr>
                <tr>
                    <td width="549" align="left" valign="top">
                        <strong>Conduct:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['conduct'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                    <td width="745" align="left" valign="top">
                        <strong>Attitude:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['attitude'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                </tr>
                <tr>
                    <td width="549" align="left" valign="top">
                        <strong>Interest:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['interest'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                    <td width="745" align="left" valign="top">
                        <strong>HeadMistress / Master\'s Signature</strong><br>
                        ' . ($signature_exists ? '<img src="' . $signature_path . '" width="210" height="55"><br>' : '<span style="color: #d32f2f;">Signature image not found</span><br>') . '
                        <hr align="left" size="6" color="black" width="60%">
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="left" valign="top">
                        <strong>Class Teacher\'s Remark:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['teacher_remarks'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="left" valign="top">
                        <strong><span class="subtitle">Head Teacher\'s Remark:</span></strong> <font color="#d81b60">' . htmlspecialchars($report_basic['headmaster_remarks'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                </tr>
            </table>
            <table width="100%" border="0" align="left" cellpadding="" cellspacing="">
                <tr>
                    <td width="300" align="left" valign="top">
                        Grace-Joan Kissi-Afare<br>
                        (<strong>Executive Director,</strong> Queen\'s Paradise Montessori School)<br>
                        <hr align="left" size="6" color="black" width="80%">
                    </td>
                    <td width="200" align="left" valign="top">
                        ' . ($stamp_exists ? '<img src="' . $stamp_path . '" width="105" height="105">' : '<span style="color: #d32f2f;">Stamp image not found</span>') . '
                    </td>
                </tr>
            </table>
        </div>
        <div class="charts-container">
            <div class="chart-card">
                <div class="chart-section" id="subject_pie_chart_' . $index . '"></div>
                <div class="chart-section" id="barcharter_' . $index . '"></div>
            </div>
        </div>
        <div class="footer-section">
            ' . htmlspecialchars($random_footer, ENT_QUOTES, 'UTF-8') . '
        </div>
        ' . (!$seal_exists ? '<div class="watermark-error">Seal image not found at ' . $seal_path . '</div>' : '') . '
    </div>';

    $index++;
}

if (empty($reports_html)) {
    error_log("No valid reports generated for printing.");
    $_SESSION['error_msg'] = "No valid reports found for printing.";
    header('Location: report_card.php');
    exit;
}

$page_title = (count($reports) > 1 ? "Bulk " : "") . "Print Report Card - Queen's Paradise Montessori School";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Queen's Paradise Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style type="text/css">
        body {
            display: none;
            margin: 0;
            padding: 0;
            background-color: #ffffff;
            font-family: 'Inter', 'Roboto', 'Arial', sans-serif;
            color: #1a1a1a;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        @media print {
            body {
                display: block;
            }
            .container {
                display: block !important;
            }
            .chart-section {
                width: 50% !important;
            }
            .chart-card {
                height: 200px !important;
            }
        }
        .container {
            display: none;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 6mm;
            box-sizing: border-box;
            position: relative;
            page-break-after: always;
        }
        .container:last-child {
            page-break-after: avoid;
        }
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('<?php echo $seal_exists ? $seal_path : ''; ?>');
            background-repeat: no-repeat;
            background-position: center;
            background-size: 55% auto;
            opacity: 0.45;
            z-index: 1;
        }
        .watermark-error {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #d32f2f;
            font-size: 13px;
            z-index: -1;
            pointer-events: none;
        }
        .header-section {
            margin-bottom: 4mm;
            position: relative;
            z-index: 2;
        }
        .header-table {
            width: 100%;
            max-width: 908px;
            margin: 0 auto;
            border-collapse: collapse;
        }
        .logo-section {
            text-align: left;
            vertical-align: top;
            padding-right: 6px;
        }
        .school-info {
            text-align: left;
            vertical-align: top;
            padding-right: 6px;
        }
        .school-info h1 {
            font-size: 20px;
            color: #003087;
            margin: 0;
            font-weight: 700;
            line-height: 1.2;
        }
        .school-info p {
            font-size: 11px;
            color: #d81b60;
            font-style: italic;
            margin: 2px 0;
            line-height: 1.3;
        }
        .contact-info {
            text-align: left;
            vertical-align: top;
        }
        .contact-info strong {
            font-size: 11px;
            color: #003087;
            text-decoration: underline;
            font-weight: 700;
            display: block;
            margin-bottom: 2px;
        }
        .contact-info p {
            font-size: 10px;
            color: #d81b60;
            margin: 1px 0;
            line-height: 1.3;
        }
        .report-title {
            font-size: 15px;
            color: #003087;
            text-decoration: underline;
            margin: 4mm 0;
            text-align: center;
            position: relative;
            z-index: 2;
            font-weight: 700;
            line-height: 1.2;
        }
        .report-details {
            text-align: left;
            font-size: 11px;
            color: #1a1a1a;
            display: flex;
            align-items: flex-start;
            position: relative;
            z-index: 2;
        }
        .report-details table {
            width: 70%;
            border-collapse: collapse;
        }
        .report-details td {
            padding: 3px 5px;
            vertical-align: top;
        }
        .report-details strong {
            color: #003087;
        }
        .report-details .subtitle {
            color: #555;
        }
        .student-photo {
            width: 30%;
            text-align: right;
            vertical-align: top;
        }
        .student-photo img {
            width: 75px;
            height: 80px;
            object-fit: cover;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
        }
        .grades-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 6px;
            position: relative;
            z-index: 2;
            border-radius: 8px;
            overflow: hidden;
        }
        .grades-table th, .grades-table td {
            border: 1px solid #b0bec5;
            padding: 4px;
            text-align: center;
            font-size: 10px;
            color: #1a1a1a;
        }
        .grades-table th {
            font-weight: 700;
            background-color: rgba(238, 242, 255, 0.8);
        }
        .grades-table th:nth-child(1) { background-color: rgba(200, 230, 255, 0.8); }
        .grades-table th:nth-child(2) { background-color: rgba(200, 255, 200, 0.8); }
        .grades-table th:nth-child(3) { background-color: rgba(255, 245, 200, 0.8); }
        .grades-table th:nth-child(4) { background-color: rgba(255, 200, 200, 0.8); }
        .grades-table th:nth-child(5) { background-color: rgba(245, 200, 255, 0.8); }
        .grades-table th:nth-child(6) { background-color: rgba(200, 245, 255, 0.8); }
        .grades-table tr.grade-ap td { background-color: rgba(200, 230, 255, 0.8); }
        .grades-table tr.grade-a td { background-color: rgba(200, 255, 200, 0.8); }
        .grades-table tr.grade-b2 td { background-color: rgba(255, 245, 200, 0.8); }
        .grades-table tr.grade-b3 td { background-color: rgba(255, 235, 180, 0.8); }
        .grades-table tr.grade-c td { background-color: rgba(255, 200, 200, 0.8); }
        .grades-table tr.grade-d td { background-color: rgba(245, 200, 255, 0.8); }
        .grades-table tr.grade-e td { background-color: rgba(245, 200, 255, 0.8); }
        .grades-table tr.grade-f td { background-color: rgba(235, 235, 235, 0.8); }
        .grades-table .total-row th:nth-child(1) {
            text-align: left;
        }
        .grades-table .total-row h3 {
            margin: 0;
            font-size: 11px;
        }
        .grades-table .total-row th[align="center"] {
            font-size: 11px;
            font-weight: 700;
        }
        .charts-container {
            margin-top: 6px;
            position: relative;
            z-index: 2;
        }
        .chart-card {
            width: 100%;
            max-width: 740px;
            height: 180px;
            background-color: #ffffff;
            border-radius: 8px;
            padding: 6px;
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }
        .chart-section {
            width: 48%;
            height: 100%;
        }
        .additional-details {
            margin-top: 6px;
            text-align: left;
            font-size: 11px;
            color: #1a1a1a;
            position: relative;
            z-index: 2;
        }
        .additional-details table {
            width: 100%;
            border-collapse: collapse;
        }
        .additional-details td {
            padding: 3px 5px;
            vertical-align: top;
        }
        .additional-details hr {
            border: 0;
            border-top: 3px solid #003087;
            margin: 3px 0;
        }
        .additional-details img {
            width: 85px !important;
            height: 45px !important;
        }
        .style1 {
            color: #d81b60;
        }
        .footer-section {
            font-size: 11px;
            padding: 4mm 0;
            text-align: center;
            color: #003087;
            font-style: italic;
            position: relative;
            z-index: 2;
            border-top: 2px solid #e0e0e0;
            margin-top: 6px;
        }
        .chart-fallback {
            font-size: 11px;
            color: #d32f2f;
            text-align: center;
            margin-top: 10px;
        }
    </style>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">
        google.charts.load("current", {"packages":["corechart", "bar"]});
        google.charts.setOnLoadCallback(drawCharts);

        var reportsData = <?php echo json_encode($reports_data); ?>;

        function drawCharts() {
            try {
                reportsData.forEach(function(report, index) {
                    var subjectData = new google.visualization.DataTable({
                        cols: [{label: "Subject", type: "string"}, {label: "Score", type: "number"}],
                        rows: report.subject_data.map(function(s) {
                            return {c: [{v: s.label}, {v: s.value}]};
                        })
                    });
                    var subjectPieOptions = {
                        title: "Subject Performance",
                        is3D: true,
                        width: 360,
                        height: 180,
                        titleTextStyle: {fontSize: 13, color: '#003087'},
                        legend: {
                            position: 'right',
                            textStyle: {fontSize: 11, color: '#1a1a1a'},
                            alignment: 'center',
                            pagingTextStyle: null // Disable pagination
                        },
                        pieSliceText: 'percentage',
                        pieSliceTextStyle: {fontSize: 11, color: '#1a1a1a'},
                        chartArea: {width: '65%', height: '80%'}, // Adjusted for more legend space
                        colors: ['#003087', '#d81b60', '#2e7d32', '#f4b400', '#6a1b9a', '#0288d1', '#c2185b', '#388e3c']
                    };
                    var subjectPieChart = new google.visualization.PieChart(document.getElementById("subject_pie_chart_" + index));
                    subjectPieChart.draw(subjectData, subjectPieOptions);

                    var subtitle = 'Student: ' + report.student_grand_total.toFixed(1) + ', Highest: ' + report.class_highest.toFixed(1) + ', Average: ' + report.class_average.toFixed(1) + ', Lowest: ' + report.class_lowest.toFixed(1);
                    var barData = google.visualization.arrayToDataTable([
                        ["Metric", "Student Score", "Class Highest", "Class Average", "Class Lowest"],
                        [report.year + '/' + report.term_name, 
                         report.student_grand_total, 
                         report.class_highest, 
                         report.class_average, 
                         report.class_lowest]
                    ]);
                    var barOptions = {
                        title: "Class Performance Comparison",
                        titleTextStyle: {fontSize: 13, color: '#003087'},
                        subtitle: subtitle,
                        width: 360,
                        height: 180,
                        series: {
                            0: {color: '#003087'},
                            1: {color: '#d81b60'},
                            2: {color: '#f4b400'},
                            3: {color: '#2e7d32'}
                        },
                        chartArea: {width: '70%', height: '70%'},
                        legend: {position: 'top', textStyle: {fontSize: 11}},
                        hAxis: {textStyle: {fontSize: 11}},
                        vAxis: {textStyle: {fontSize: 11}, format: '#.#', title: 'Score'},
                        bar: {groupWidth: '20%'}
                    };
                    var barChart = new google.visualization.ColumnChart(document.getElementById("barcharter_" + index));
                    barChart.draw(barData, barOptions);
                });

                document.body.style.display = 'block';
                document.querySelectorAll('.container').forEach(function(container) {
                    container.style.display = 'block';
                });
                setTimeout(function() {
                    window.print();
                }, 500);
            } catch (error) {
                console.error('Error rendering charts:', error);
                document.body.style.display = 'block';
                document.querySelectorAll('.container').forEach(function(container) {
                    container.style.display = 'block';
                });
                document.querySelectorAll('.chart-section').forEach(function(chart) {
                    chart.innerHTML = '<div class="chart-fallback">Chart failed to load. Please try again.</div>';
                });
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        }
    </script>
</head>
<body>
    <?php echo $reports_html; ?>
</body>
</html>
<?php
$conn->close();
ob_end_flush();
?>