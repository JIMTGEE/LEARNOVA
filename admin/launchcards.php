<?php
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Fetch classes for the dropdown
$class_query = "SELECT id, class_name FROM classes ORDER BY class_name ASC";
$class_result = $conn->query($class_query);
$classes = $class_result ? $class_result->fetch_all(MYSQLI_ASSOC) : [];

// Fetch users based on selected class (if any)
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$query = "SELECT u.id, u.name, u.email, c.class_name 
          FROM users u 
          LEFT JOIN student_classes sc ON u.id = sc.user_id 
          LEFT JOIN classes c ON sc.class_id = c.id 
          WHERE sc.class_id = ? OR ? = 0 
          ORDER BY u.name ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $class_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>

<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">🎴 Generate Login Cards</h1>
        <a href="user_management.php" class="btn btn-primary">⬅️ Back to User Management</a>
    </div>

    <p class="text-muted">Select a class and users to generate printable login cards. Click 'Print Selected' to print.</p>

    <!-- Class Filter -->
    <div class="mb-4">
        <form method="GET" action="">
            <div class="row g-2 align-items-center">
                <div class="col-auto">
                    <label for="class_id" class="form-label">Filter by Class:</label>
                </div>
                <div class="col-auto">
                    <select name="class_id" id="class_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= htmlspecialchars($class['id']) ?>" <?= $class_id == $class['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($class['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>

    <div class="mb-3">
        <button class="btn btn-outline-secondary btn-sm" id="selectAll">Select All</button>
        <button class="btn btn-outline-secondary btn-sm" id="deselectAll">Deselect All</button>
    </div>

    <form id="printForm">
        <div class="row row-cols-1 row-cols-md-3 g-2" id="card-container">
            <?php foreach ($users as $user): ?>
                <div class="col">
                    <div class="card h-100 border-0 shadow" style="width: 9cm; height: 5.5cm;">
                        <div class="card-body text-center" style="background: linear-gradient(to bottom, #ff6b00, #ff4500); color: white; padding: 0.8rem; border-radius: 8px 8px 0 0;">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input print-checkbox" name="print_users[]" value="<?= htmlspecialchars($user['id']) ?>" id="user_<?= htmlspecialchars($user['id']) ?>">
                                <label class="form-check-label" for="user_<?= htmlspecialchars($user['id']) ?>" style="font-size: 0.8rem; color: white;">Select</label>
                            </div>
                            <h5 class="card-title mb-2" style="font-weight: 600; font-size: 1.2rem;"><?= htmlspecialchars($user['name']) ?></h5>
                            <p class="card-text mb-1" style="font-size: 0.9rem;"><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
                            <p class="card-text mb-1" style="font-size: 0.9rem;"><strong>Password:</strong> 12345678</p>
                            <p class="card-text mb-1" style="font-size: 0.9rem;"><strong>Class:</strong> <?= htmlspecialchars($user['class_name'] ?? 'N/A') ?></p>
                            <p class="card-text text-light" style="font-size: 0.7rem; font-style: italic;">Note: Update password securely after printing.</p>
                        </div>
                        <div class="card-footer bg-dark text-white text-center" style="border-radius: 0 0 8px 8px; padding: 0.5rem;">
                            <small style="font-size: 0.7rem;">User ID: <?= htmlspecialchars($user['id']) ?> | Site: qpmsedu.com | Contact: 0531475205</small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
                <p class="text-center text-muted">No users found in the selected class.</p>
            <?php endif; ?>
        </div>

        <div class="mt-4 text-center">
            <button type="button" class="btn btn-success" id="printButton" style="padding: 0.75rem 2rem; font-size: 1.1rem; border-radius: 8px;">Print Selected</button>
        </div>
    </form>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>

<style>
    body {
        font-family: Arial, sans-serif;
        background-color: #f8f9fa;
    }
    .container {
        max-width: 1200px;
    }
    .card {
        transition: transform 0.2s;
    }
    .card:hover {
        transform: translateY(-5px);
    }
    .form-select {
        max-width: 300px;
    }
    @media print {
        @page {
            size: A4;
            margin: 1cm;
        }
        body { 
            background-color: #ffffff; 
            color: #000000; 
            margin: 0;
        }
        .container { 
            max-width: 21cm; 
            padding: 0; 
        }
        .card { 
            border: 1px solid #000; 
            page-break-inside: avoid; 
            width: 9cm; 
            height: 5.5cm; 
            margin: 0.25cm; 
            box-shadow: none; 
            border-radius: 8px; 
        }
        .card-body { 
            background: linear-gradient(to bottom, #ff6b00, #ff4500); 
            color: white; 
            padding: 0.8rem; 
            border-radius: 8px 8px 0 0; 
            text-align: center;
        }
        .form-check, .form-select, .btn, .mt-4, .text-muted, .d-flex { 
            display: none; 
        }
        #card-container { 
            display: grid;
            grid-template-columns: repeat(2, 9.5cm);
            gap: 0.5cm;
            margin: 0;
        }
        .col { 
            break-inside: avoid; 
            margin: 0; 
        }
        #card-container .col:not(:has(.print-checkbox:checked)) { 
            display: none; 
        }
        .card-title { 
            font-size: 1.2rem; 
            font-weight: bold; 
            margin-bottom: 0.5rem; 
        }
        .card-text { 
            font-size: 0.9rem; 
            margin-bottom: 0.5rem; 
        }
        .card-text.text-light { 
            font-size: 0.7rem; 
            font-style: italic; 
        }
        .card-footer { 
            background-color: #333; 
            color: white; 
            font-size: 0.7rem; 
            padding: 0.5rem; 
            border-radius: 0 0 8px 8px; 
        }
    }
    .night-mode { 
        background-color: #121212; 
        color: #ffffff; 
    }
    .night-mode .card { 
        background-color: #1e1e1e; 
        border-color: #444; 
    }
    .night-mode .card-body { 
        background: linear-gradient(to bottom, #333, #222); 
        color: #ffffff; 
    }
    .night-mode .card-footer { 
        background-color: #111; 
        color: #ffffff; 
    }
    .night-mode .form-select, .night-mode .form-control {
        background-color: #2c2c2c;
        color: #ffffff;
        border-color: #444;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Night mode toggle
        const toggleButton = document.createElement('button');
        toggleButton.textContent = 'Toggle Night Mode';
        toggleButton.className = 'btn btn-secondary position-fixed top-0 end-0 m-2';
        document.body.appendChild(toggleButton);
        const isNightMode = localStorage.getItem('nightMode') === 'true';
        if (isNightMode) document.body.classList.add('night-mode');
        toggleButton.addEventListener('click', () => {
            document.body.classList.toggle('night-mode');
            localStorage.setItem('nightMode', document.body.classList.contains('night-mode'));
        });

        // Print button and selection logic
        const printButton = document.querySelector('#printButton');
        const checkboxes = document.querySelectorAll('.print-checkbox');
        const selectAll = document.querySelector('#selectAll');
        const deselectAll = document.querySelector('#deselectAll');

        if (printButton) {
            printButton.addEventListener('click', () => {
                const anyChecked = Array.from(checkboxes).some(checkbox => checkbox.checked);
                if (!anyChecked) {
                    alert('Please select at least one user to print.');
                    return;
                }
                window.print();
            });
        } else {
            console.error('Print button not found');
        }

        if (selectAll) {
            selectAll.addEventListener('click', () => {
                checkboxes.forEach(checkbox => checkbox.checked = true);
            });
        }

        if (deselectAll) {
            deselectAll.addEventListener('click', () => {
                checkboxes.forEach(checkbox => checkbox.checked = false);
            });
        }
    });
</script>