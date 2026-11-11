<?php
// Start session with output buffering to prevent premature output
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in and has an admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Redirecting to login: user_id not set or role not admin. Role: " . ($_SESSION['user_role'] ?? 'not set'));
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once __DIR__ . '/../config/db.php';
$db = db();

// Include helpers and functions
try {
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../functions/csrf.php';
    
    // Check and create log directory if it doesn't exist
    $logDir = __DIR__ . '/../logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    require_once __DIR__ . '/../functions/log_activity.php';
} catch (Exception $e) {
    error_log("Error loading helpers/functions: " . $e->getMessage());
    $_SESSION['error'] = 'System error: Unable to load required files. Please check logs.';
    header("Location: users.php");
    exit();
}

// Clear any output that might have been sent
ob_clean();

// Check for auto-export command in URL
$auto_export = isset($_GET['export']) && $_GET['export'] === 'all';
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';

// If auto-export is requested, export immediately
if ($auto_export) {
    // Fetch ALL users without any filters
    $query = "SELECT id, name, email, role, created_at, status, profile_picture, phone_number, national_id, admission_date, department, gender, whatsapp_number, address, last_login, last_ip, department_id, advisor_id, updated_at, class_id 
              FROM users ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log the export action
    if (function_exists('log_activity')) {
        log_activity($_SESSION['user_id'], 'export_users', "Auto-exported all users in $format format (no filters)");
    }
    
    // Clear any output before sending headers
    if (ob_get_length()) {
        ob_clean();
    }
    
    // Export based on format
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="all_users_export_' . date('Y-m-d_H-i-s') . '.csv"');
        $output = fopen('php://output', 'w');
        // Headers
        fputcsv($output, ['ID', 'Name', 'Email', 'Role', 'Created At', 'Status', 'Profile Picture', 'Phone Number', 'National ID', 'Admission Date', 'Department', 'Gender', 'WhatsApp Number', 'Address', 'Last Login', 'Last IP', 'Department ID', 'Advisor ID', 'Updated At', 'Class ID']);
        foreach ($users as $user) {
            fputcsv($output, [
                $user['id'], $user['name'], $user['email'], $user['role'], $user['created_at'], $user['status'],
                $user['profile_picture'], $user['phone_number'], $user['national_id'], $user['admission_date'],
                $user['department'], $user['gender'], $user['whatsapp_number'], $user['address'],
                $user['last_login'], $user['last_ip'], $user['department_id'], $user['advisor_id'],
                $user['updated_at'], $user['class_id']
            ]);
        }
        fclose($output);
        exit();
    } elseif ($format === 'pdf') {
        // Clear output buffer completely for PDF
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        require_once __DIR__ . '/../assets/fpdf/fpdf.php';
        
        class PDF extends FPDF {
            function Header() {
                $this->SetFont('Arial', 'B', 12);
                $this->Cell(0, 10, 'EduSync - All Users Export', 0, 1, 'C');
                $this->SetFont('Arial', '', 10);
                $this->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
                $this->Ln(5);
            }
            function Footer() {
                $this->SetY(-15);
                $this->SetFont('Arial', 'I', 8);
                $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
            }
            function FancyTable($header, $data) {
                $this->SetFillColor(23, 162, 184);
                $this->SetTextColor(255);
                $this->SetDrawColor(8, 31, 44);
                $this->SetLineWidth(.3);
                $this->SetFont('', 'B');
                
                // Header
                $widths = [10, 40, 50, 20, 20, 30, 20, 25];
                foreach ($header as $key => $col) {
                    if (isset($widths[$key])) {
                        $this->Cell($widths[$key], 7, $col, 1, 0, 'C', true);
                    }
                }
                $this->Ln();
                
                // Data
                $this->SetFillColor(224, 235, 255);
                $this->SetTextColor(0);
                $this->SetFont('');
                $fill = false;
                
                foreach ($data as $row) {
                    foreach ($header as $key => $col) {
                        if (isset($widths[$key]) && isset($row[$col])) {
                            $this->Cell($widths[$key], 6, utf8_decode(substr($row[$col] ?? '', 0, 30)), 'LR', 0, 'L', $fill);
                        }
                    }
                    $this->Ln();
                    $fill = !$fill;
                }
                $this->Cell(array_sum($widths), 0, '', 'T');
            }
        }
        
        $pdf = new PDF('L');
        $pdf->AliasNbPages();
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);
        
        // Headers
        $header = ['ID', 'Name', 'Email', 'Role', 'Status', 'Phone', 'Department', 'Last Login'];
        
        // Data mapping
        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'ID' => $user['id'],
                'Name' => $user['name'],
                'Email' => $user['email'],
                'Role' => $user['role'],
                'Status' => $user['status'],
                'Phone' => $user['phone_number'] ?? '',
                'Department' => $user['department'] ?? '',
                'Last Login' => $user['last_login'] ?? ''
            ];
        }
        
        $pdf->FancyTable($header, $data);
        $pdf->Output('D', 'all_users_export_' . date('Y-m-d_H-i-s') . '.pdf');
        exit();
    }
}

// Handle POST for filtered export
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: export_users.php");
        exit();
    }
    
    $format = $_POST['format'] ?? 'csv';
    $search = isset($_POST['search']) ? '%' . trim($_POST['search']) . '%' : '%';
    $role_filter = isset($_POST['role']) ? $_POST['role'] : '';
    $status_filter = isset($_POST['status']) ? $_POST['status'] : '';
    $gender_filter = isset($_POST['gender']) ? $_POST['gender'] : '';
    $department_filter = isset($_POST['department']) ? $_POST['department'] : '';
    $last_login_filter = isset($_POST['last_login']) ? $_POST['last_login'] : '';
    
    $query = "SELECT id, name, email, role, created_at, status, profile_picture, phone_number, national_id, admission_date, department, gender, whatsapp_number, address, last_login, last_ip, department_id, advisor_id, updated_at, class_id 
              FROM users WHERE (name LIKE ? OR phone_number LIKE ? OR whatsapp_number LIKE ?)";
    $params = [$search, $search, $search];
    
    if ($role_filter && in_array($role_filter, ['admin', 'teacher', 'student', 'parent'])) {
        $query .= " AND role = ?";
        $params[] = $role_filter;
    }
    if ($status_filter && in_array($status_filter, ['active', 'inactive', 'suspended', 'graduated'])) {
        $query .= " AND status = ?";
        $params[] = $status_filter;
    }
    if ($gender_filter && in_array($gender_filter, ['male', 'female', 'other'])) {
        $query .= " AND gender = ?";
        $params[] = $gender_filter;
    }
    if ($department_filter) {
        $query .= " AND department = ?";
        $params[] = $department_filter;
    }
    if ($last_login_filter === 'last_week') {
        $query .= " AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($last_login_filter === 'last_month') {
        $query .= " AND last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
    
    $query .= " ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (function_exists('log_activity')) {
        log_activity($_SESSION['user_id'], 'export_users', "Exported users in $format format with filters");
    }
    
    // Clear any output before sending headers
    if (ob_get_length()) {
        ob_clean();
    }
    
    // Export based on format
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="filtered_users_export_' . date('Y-m-d_H-i-s') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Name', 'Email', 'Role', 'Created At', 'Status', 'Profile Picture', 'Phone Number', 'National ID', 'Admission Date', 'Department', 'Gender', 'WhatsApp Number', 'Address', 'Last Login', 'Last IP', 'Department ID', 'Advisor ID', 'Updated At', 'Class ID']);
        foreach ($users as $user) {
            fputcsv($output, [
                $user['id'], $user['name'], $user['email'], $user['role'], $user['created_at'], $user['status'],
                $user['profile_picture'], $user['phone_number'], $user['national_id'], $user['admission_date'],
                $user['department'], $user['gender'], $user['whatsapp_number'], $user['address'],
                $user['last_login'], $user['last_ip'], $user['department_id'], $user['advisor_id'],
                $user['updated_at'], $user['class_id']
            ]);
        }
        fclose($output);
        exit();
    } elseif ($format === 'pdf') {
        // Clear output buffer completely for PDF
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        require_once __DIR__ . '/../assets/fpdf/fpdf.php';
        
        class PDF extends FPDF {
            function Header() {
                $this->SetFont('Arial', 'B', 12);
                $this->Cell(0, 10, 'EduSync - Filtered Users Export', 0, 1, 'C');
                $this->SetFont('Arial', '', 10);
                $this->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
                $this->Ln(5);
            }
            function Footer() {
                $this->SetY(-15);
                $this->SetFont('Arial', 'I', 8);
                $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
            }
            function FancyTable($header, $data) {
                $this->SetFillColor(23, 162, 184);
                $this->SetTextColor(255);
                $this->SetDrawColor(8, 31, 44);
                $this->SetLineWidth(.3);
                $this->SetFont('', 'B');
                
                $widths = [10, 40, 50, 20, 20, 30, 20, 25];
                foreach ($header as $key => $col) {
                    if (isset($widths[$key])) {
                        $this->Cell($widths[$key], 7, $col, 1, 0, 'C', true);
                    }
                }
                $this->Ln();
                
                $this->SetFillColor(224, 235, 255);
                $this->SetTextColor(0);
                $this->SetFont('');
                $fill = false;
                
                foreach ($data as $row) {
                    foreach ($header as $key => $col) {
                        if (isset($widths[$key]) && isset($row[$col])) {
                            $this->Cell($widths[$key], 6, utf8_decode(substr($row[$col] ?? '', 0, 30)), 'LR', 0, 'L', $fill);
                        }
                    }
                    $this->Ln();
                    $fill = !$fill;
                }
                $this->Cell(array_sum($widths), 0, '', 'T');
            }
        }
        
        $pdf = new PDF('L');
        $pdf->AliasNbPages();
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 10);
        
        $header = ['ID', 'Name', 'Email', 'Role', 'Status', 'Phone', 'Department', 'Last Login'];
        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'ID' => $user['id'],
                'Name' => $user['name'],
                'Email' => $user['email'],
                'Role' => $user['role'],
                'Status' => $user['status'],
                'Phone' => $user['phone_number'] ?? '',
                'Department' => $user['department'] ?? '',
                'Last Login' => $user['last_login'] ?? ''
            ];
        }
        
        $pdf->FancyTable($header, $data);
        $pdf->Output('D', 'filtered_users_export_' . date('Y-m-d_H-i-s') . '.pdf');
        exit();
    }
}

// If we get here, we need to output the HTML page
ob_start(); // Start fresh buffer for HTML output

// Fetch departments for filter
$departments = $db->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

// Generate CSRF token
$csrf_token = generate_csrf_token();
?>
<?php include '../templates/admin_header.php'; ?>
    <section class="export-users">
        <h1 class="page-title">User Export Tool</h1>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <div class="quick-export-section">
            <h2>Quick Export All Users</h2>
            <p>Export all users immediately without any filters:</p>
            <div class="quick-export-buttons">
                <a href="export_users.php?export=all&format=csv" class="export-button quick" onclick="return confirmExport('all')">Export All as CSV</a>
                <a href="export_users.php?export=all&format=pdf" class="export-button quick" onclick="return confirmExport('all')">Export All as PDF</a>
            </div>
        </div>
        
        <div class="filtered-export-section">
            <h2>Filtered Export</h2>
            <p>Apply filters before exporting:</p>
            <form method="POST" class="export-form" onsubmit="return validateExport()">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <div class="filter-group">
                    <label for="search">Search by Name/Phone/WhatsApp:</label>
                    <input type="text" id="search" name="search" placeholder="Enter search term">
                </div>
                
                <div class="filter-group">
                    <label for="role">Role:</label>
                    <select id="role" name="role">
                        <option value="">All</option>
                        <option value="admin">Admin</option>
                        <option value="teacher">Teacher</option>
                        <option value="student">Student</option>
                        <option value="parent">Parent</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="status">Status:</label>
                    <select id="status" name="status">
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                        <option value="graduated">Graduated</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="gender">Gender:</label>
                    <select id="gender" name="gender">
                        <option value="">All</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="department">Department:</label>
                    <select id="department" name="department">
                        <option value="">All</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="last_login">Last Login:</label>
                    <select id="last_login" name="last_login">
                        <option value="">All</option>
                        <option value="last_week">Last Week</option>
                        <option value="last_month">Last Month</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="format">Export Format:</label>
                    <select id="format" name="format">
                        <option value="csv">CSV (Excel Compatible)</option>
                        <option value="pdf">PDF</option>
                    </select>
                </div>
                
                <button type="submit" class="export-button">Export Filtered Users</button>
            </form>
        </div>
    </section>
    
    <script>
        function confirmExport(type) {
            return confirm(`This will export ${type} users immediately. Continue?`);
        }
        
        function validateExport() {
            const format = document.getElementById('format').value;
            if (format === 'pdf') {
                return confirm('PDF export may take a moment to generate. Continue?');
            }
            return true;
        }
        
        // Button animation
        const exportButtons = document.querySelectorAll('.export-button');
        exportButtons.forEach(button => {
            button.addEventListener('mouseenter', () => {
                button.style.animation = 'pulse 0.5s';
            });
            button.addEventListener('animationend', () => {
                button.style.animation = '';
            });
        });
    </script>
    
    <style>
        .export-users {
            display: flex;
            flex-direction: column;
            gap: 30px;
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
            font-family: var(--font-content);
        }
        
        .page-title {
            font-family: var(--font-ui);
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-black);
            text-align: center;
            margin-bottom: 20px;
        }
        
        .quick-export-section, .filtered-export-section {
            background-color: var(--primary-white);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .quick-export-section h2, .filtered-export-section h2 {
            color: var(--primary-sea-blue);
            margin-bottom: 15px;
            font-size: 24px;
        }
        
        .quick-export-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .export-button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .export-button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
        }
        
        .export-button.quick {
            background-color: #28a745;
        }
        
        .export-button.quick:hover {
            background-color: #218838;
        }
        
        .export-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-size: 14px;
            color: var(--accent-slate-gray);
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .filter-group input, .filter-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .filter-group input:focus, .filter-group select:focus {
            border-color: var(--primary-sea-blue);
            outline: none;
            box-shadow: 0 0 0 2px rgba(23, 162, 184, 0.1);
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .alert.error {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        @media (max-width: 768px) {
            .export-form {
                grid-template-columns: 1fr;
            }
            
            .quick-export-buttons {
                flex-direction: column;
            }
            
            .export-button {
                width: 100%;
            }
        }
    </style>
<?php include '../templates/admin_footer.php'; ?>