<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    die("Connection failed. Please try again later.");
}

// Log activity
$admin_id = $_SESSION['user_id'];
$action = "Viewed Class Management Dashboard";
$description = "Admin viewed class management dashboard";
logActivity($conn, $admin_id, $action, $description);

// Fetch analytics
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM classes");
$stmt->execute();
$total_classes = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'");
$stmt->execute();
$total_students = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(DISTINCT user_id) as total FROM student_classes");
$stmt->execute();
$assigned_students = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$assignment_percentage = $total_students > 0 ? round(($assigned_students / $total_students) * 100, 2) : 0;

$stmt = $conn->prepare("SELECT c.id, c.class_name, d.name AS department_name, COUNT(sc.user_id) as student_count 
                        FROM classes c 
                        LEFT JOIN departments d ON c.department_id = d.id 
                        LEFT JOIN student_classes sc ON c.id = sc.class_id 
                        GROUP BY c.id, c.class_name, d.name 
                        ORDER BY c.class_name");
$stmt->execute();
$students_per_class = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$avg_students_per_class = $total_classes > 0 ? round($assigned_students / $total_classes, 2) : 0;

$stmt = $conn->prepare("SELECT d.name AS department_name, COUNT(c.id) as class_count 
                        FROM departments d 
                        LEFT JOIN classes c ON d.id = c.department_id 
                        GROUP BY d.id, d.name 
                        ORDER BY d.name");
$stmt->execute();
$classes_by_dept = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activities = [];
$search_query = isset($_GET['activity_search']) ? trim($_GET['activity_search']) : '';
if ($search_query) {
    $stmt = $conn->prepare("SELECT action, description, created_at 
                            FROM activity_logs 
                            WHERE action LIKE ? AND action LIKE '%Class%' 
                            ORDER BY created_at DESC 
                            LIMIT 10");
    $search_term = "%" . $search_query . "%";
    $stmt->bind_param("s", $search_term);
} else {
    $stmt = $conn->prepare("SELECT action, description, created_at 
                            FROM activity_logs 
                            WHERE action LIKE '%Class%' 
                            ORDER BY created_at DESC 
                            LIMIT 10");
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $activities[] = $row;
}
$stmt->close();

// Set SEO meta tags
$page_title = "Class Management - QueensPa School";
$page_description = "Manage classes and view analytics for QueensPa School administrators.";
$page_keywords = "QueensPa School, class management, admin dashboard, school management, analytics";
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<div class="content min-h-screen bg-gray-50 dark:bg-gray-900" id="content">
    <main class="container mx-auto px-4 py-8 sm:px-6 lg:px-12">
        <!-- Hero Header -->
        <header class="mb-10 text-center bg-gradient-to-r from-orange-500 to-orange-300 dark:from-gray-800 dark:to-gray-700 rounded-xl py-8 animate__fadeIn">
            <h2 class="text-3xl sm:text-4xl font-bold text-white dark:text-gray-100 flex items-center justify-center">
                <i class="fas fa-chalkboard-teacher mr-3 text-orange-200"></i> Class Management Dashboard
            </h2>
            <p class="mt-3 text-lg text-orange-100 dark:text-gray-300 max-w-2xl mx-auto">Streamline class operations, assign students, and monitor analytics with ease.</p>
        </header>

        <!-- Action Bar -->
        <nav class="sticky top-16 bg-white dark:bg-gray-800 rounded-xl shadow-lg dark:shadow-gray-900 p-4 mb-10 z-10 flex flex-col sm:flex-row gap-4 justify-center animate__fadeInUp transition-shadow" aria-label="Class management actions" onscroll="updateActionBarShadow(this)">
            <a href="add_class.php" class="group relative bg-orange-500 text-white px-6 py-3 rounded-md hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 transform hover:scale-105 transition-all ripple animate-pulse" aria-label="Add a new class">
                <i class="fas fa-plus mr-2"></i> Add Class
                <span class="tooltip absolute top-full mt-2 bg-gray-800 dark:bg-gray-600 text-white text-sm rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity transform -translate-x-1/2 left-1/2">Create a new class for the school.</span>
            </a>
            <a href="view_classes.php" class="group relative bg-orange-500 text-white px-6 py-3 rounded-md hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 transform hover:scale-105 transition-all ripple animate-pulse" aria-label="View all classes">
                <i class="fas fa-eye mr-2"></i> View Classes
                <span class="tooltip absolute top-full mt-2 bg-gray-800 dark:bg-gray-600 text-white text-sm rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity transform -translate-x-1/2 left-1/2">View and manage all classes.</span>
            </a>
            <a href="manage_student_classes.php" class="group relative bg-orange-500 text-white px-6 py-3 rounded-md hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 transform hover:scale-105 transition-all ripple animate-pulse" aria-label="Manage student class assignments">
                <i class="fas fa-user-plus mr-2"></i> Manage Assignments
                <span class="tooltip absolute top-full mt-2 bg-gray-800 dark:bg-gray-600 text-white text-sm rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity transform -translate-x-1/2 left-1/2">Assign students to classes for specific academic years.</span>
            </a>
        </nav>

        <!-- Analytics Section -->
        <section class="mb-12 animate__fadeInUp" aria-labelledby="analytics-heading" style="animation-delay: 0.1s;">
            <h3 id="analytics-heading" class="text-2xl font-bold text-gray-700 dark:text-gray-200 mb-6 flex items-center">
                <i class="fas fa-chart-bar mr-2 text-orange-500"></i> Class Analytics
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="card relative bg-white dark:bg-gray-800 rounded-xl p-6 text-center transform transition-all hover:scale-105 shadow-xl dark:shadow-gray-900 group">
                    <h4 class="text-lg font-semibold text-orange-500 dark:text-orange-400">Total Classes</h4>
                    <p class="text-4xl sm:text-5xl font-bold text-gray-800 dark:text-gray-100 mt-2"><?php echo $total_classes; ?></p>
                    <span class="tooltip absolute top-full mt-2 bg-gray-800 dark:bg-gray-600 text-white dark:text-gray-200 text-sm rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity transform -translate-x-1/2 left-1/2">Number of active classes</span>
                </div>
                <div class="card relative bg-white dark:bg-gray-800 rounded-xl p-6 text-center transform transition-all hover:scale-105 shadow-xl dark:shadow-gray-900 group">
                    <h4 class="text-lg font-semibold text-orange-500 dark:text-orange-400">Avg. Students/Class</h4>
                    <p class="text-4xl sm:text-5xl font-bold text-gray-800 dark:text-gray-100 mt-2"><?php echo $avg_students_per_class; ?></p>
                    <span class="tooltip absolute top-full mt-2 bg-gray-800 dark:bg-gray-600 text-white dark:text-gray-200 text-sm rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity transform -translate-x-1/2 left-1/2">Average students per class</span>
                </div>
                <div class="card relative bg-white dark:bg-gray-800 rounded-xl p-6 text-center transform transition-all hover:scale-105 shadow-xl dark:shadow-gray-900 group">
                    <h4 class="text-lg font-semibold text-orange-500 dark:text-orange-400">Students Assigned</h4>
                    <p class="text-4xl sm:text-5xl font-bold text-gray-800 dark:text-gray-100 mt-2"><?php echo $assigned_students; ?> / <?php echo $total_students; ?></p>
                    <span class="tooltip absolute top-full mt-2 bg-gray-800 dark:bg-gray-600 text-white dark:text-gray-200 text-sm rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity transform -translate-x-1/2 left-1/2">Students with class assignments</span>
                </div>
                <div class="card relative bg-white dark:bg-gray-800 rounded-xl p-6 text-center transform transition-all hover:scale-105 shadow-xl dark:shadow-gray-900 group">
                    <h4 class="text-lg font-semibold text-orange-500 dark:text-orange-400">Assignment Coverage</h4>
                    <p class="text-4xl sm:text-5xl font-bold text-gray-800 dark:text-gray-100 mt-2"><?php echo $assignment_percentage; ?>%</p>
                    <span class="tooltip absolute top-full mt-2 bg-gray-800 dark:bg-gray-600 text-white dark:text-gray-200 text-sm rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity transform -translate-x-1/2 left-1/2">Percentage of students assigned</span>
                </div>
            </div>
        </section>

        <!-- Chart Section -->
        <section class="mb-12 animate__fadeInUp" aria-labelledby="chart-heading" style="animation-delay: 0.2s;">
            <h3 id="chart-heading" class="text-2xl font-bold text-gray-700 dark:text-gray-200 mb-6 flex items-center">
                <i class="fas fa-chart-pie mr-2 text-orange-500"></i> Students per Class
            </h3>
            <div class="card bg-white dark:bg-gray-800 rounded-xl p-6 shadow-xl dark:shadow-gray-900">
                <canvas id="studentsPerClassChart" class="w-full h-80"></canvas>
            </div>
        </section>

        <!-- Classes by Department -->
        <section class="mb-12 animate__fadeInUp" aria-labelledby="departments-heading" style="animation-delay: 0.3s;">
            <h3 id="departments-heading" class="text-2xl font-bold text-gray-700 dark:text-gray-200 mb-6 flex items-center">
                <i class="fas fa-building mr-2 text-orange-500"></i> Classes by Department
            </h3>
            <div class="card bg-white dark:bg-gray-800 rounded-xl p-6 shadow-xl dark:shadow-gray-900">
                <div class="overflow-x-auto">
                    <table id="deptTable" class="w-full text-left" role="grid" aria-describedby="departments-heading">
                        <thead class="bg-orange-500 text-white sticky top-0">
                            <tr>
                                <th class="p-4 font-semibold cursor-pointer" scope="col" onclick="sortTable('deptTable', 0)">Department <i class="fas fa-sort ml-1"></i></th>
                                <th class="p-4 font-semibold cursor-pointer" scope="col" onclick="sortTable('deptTable', 1)">Classes <i class="fas fa-sort ml-1"></i></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php if (empty($classes_by_dept)): ?>
                                <tr><td colspan="2" class="p-4 text-center text-gray-500 dark:text-gray-400">No departments found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($classes_by_dept as $dept): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                        <td class="p-4 truncate"><?php echo htmlspecialchars($dept['department_name'] ?? 'Unassigned'); ?></td>
                                        <td class="p-4"><?php echo $dept['class_count']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Recent Activities -->
        <section class="mb-12 animate__fadeInUp" aria-labelledby="activities-heading" style="animation-delay: 0.4s;">
            <h3 id="activities-heading" class="text-2xl font-bold text-gray-700 dark:text-gray-200 mb-6 flex items-center">
                <i class="fas fa-history mr-2 text-orange-500"></i> Recent Class Activities
            </h3>
            <div class="card bg-white dark:bg-gray-800 rounded-xl p-6 shadow-xl dark:shadow-gray-900">
                <form method="GET" class="mb-6 flex" role="search" aria-label="Search class activities">
                    <input type="text" name="activity_search" value="<?php echo htmlspecialchars($search_query); ?>" 
                           class="flex-grow p-3 rounded-l-md border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-orange-500" 
                           placeholder="Search activities..." aria-label="Search activities">
                    <button type="submit" class="bg-orange-500 text-white p-3 rounded-r-md hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 ripple" aria-label="Search">
                        <i class="fas fa-search"></i>
                    </button>
                </form>
                <div class="overflow-x-auto">
                    <table id="activityTable" class="w-full text-left" role="grid" aria-describedby="activities-heading">
                        <thead class="bg-orange-500 text-white sticky top-0">
                            <tr>
                                <th class="p-4 font-semibold cursor-pointer" scope="col" onclick="sortTable('activityTable', 0)">Action <i class="fas fa-sort ml-1"></i></th>
                                <th class="p-4 font-semibold cursor-pointer" scope="col" onclick="sortTable('activityTable', 1)">Description <i class="fas fa-sort ml-1"></i></th>
                                <th class="p-4 font-semibold cursor-pointer" scope="col" onclick="sortTable('activityTable', 2)">Date <i class="fas fa-sort ml-1"></i></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php if (empty($activities)): ?>
                                <tr><td colspan="3" class="p-4 text-center text-gray-500 dark:text-gray-400">No recent class activities found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($activities as $activity): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                        <td class="p-4 truncate"><?php echo htmlspecialchars($activity['action']); ?></td>
                                        <td class="p-4 truncate"><?php echo htmlspecialchars($activity['description']); ?></td>
                                        <td class="p-4"><?php echo htmlspecialchars($activity['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Manage Classes -->
        <section class="mb-12 animate__fadeInUp" aria-labelledby="manage-classes-heading" style="animation-delay: 0.5s;">
            <h3 id="manage-classes-heading" class="text-2xl font-bold text-gray-700 dark:text-gray-200 mb-6 flex items-center">
                <i class="fas fa-tools mr-2 text-orange-500"></i> Manage Classes
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <div class="card bg-white dark:bg-gray-800 rounded-xl overflow-hidden transform transition-all hover:scale-105 shadow-xl dark:shadow-gray-900">
                    <div class="bg-orange-500 text-white p-4">
                        <h5 class="text-lg font-semibold"><i class="fas fa-plus-circle mr-2"></i> Add New Class</h5>
                    </div>
                    <div class="p-6 text-center">
                        <p class="text-gray-600 dark:text-gray-300 mb-4">Create a new class for the school.</p>
                        <a href="add_class.php" class="group relative inline-block bg-orange-500 text-white px-6 py-2 rounded-md hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 ripple animate-pulse" aria-label="Add a new class">
                            <i class="fas fa-plus mr-2"></i> Add Class
                            <span class="tooltip absolute top-full mt-2 bg-gray-800 dark:bg-gray-600 text-white text-sm rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity transform -translate-x-1/2 left-1/2">Create a new class for the school.</span>
                        </a>
                    </div>
                </div>
                <div class="card bg-white dark:bg-gray-800 rounded-xl overflow-hidden transform transition-all hover:scale-105 shadow-xl dark:shadow-gray-900">
                    <div class="bg-orange-500 text-white p-4">
                        <h5 class="text-lg font-semibold"><i class="fas fa-list mr-2"></i> View Classes</h5>
                    </div>
                    <div class="p-6 text-center">
                        <p class="text-gray-600 dark:text-gray-300 mb-4">View and manage all classes.</p>
                        <a href="view_classes.php" class="group relative inline-block bg-orange-500 text-white px-6 py-2 rounded-md hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 ripple animate-pulse" aria-label="View all classes">
                            <i class="fas fa-eye mr-2"></i> View Classes
                            <span class="tooltip absolute top-full mt-2 bg-gray-800 dark:bg-gray-600 text-white text-sm rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity transform -translate-x-1/2 left-1/2">View and manage all classes.</span>
                        </a>
                    </div>
                </div>
                <div class="card bg-white dark:bg-gray-800 rounded-xl overflow-hidden transform transition-all hover:scale-105 shadow-xl dark:shadow-gray-900">
                    <div class="bg-orange-500 text-white p-4">
                        <h5 class="text-lg font-semibold"><i class="fas fa-users mr-2"></i> Manage Student Classes</h5>
                    </div>
                    <div class="p-6 text-center">
                        <p class="text-gray-600 dark:text-gray-300 mb-4">Assign students to classes for specific academic years.</p>
                        <a href="manage_student_classes.php" class="group relative inline-block bg-orange-500 text-white px-6 py-2 rounded-md hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 ripple animate-pulse" aria-label="Manage student class assignments">
                            <i class="fas fa-user-plus mr-2"></i> Manage Assignments
                            <span class="tooltip absolute top-full mt-2 bg-gray-800 dark:bg-gray-600 text-white text-sm rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity transform -translate-x-1/2 left-1/2">Assign students to classes for specific academic years.</span>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Navigation -->
        <nav class="text-center" aria-label="Navigation back to dashboard">
            <button onclick="showConfirmModal()" class="bg-gray-500 text-white px-6 py-3 rounded-md hover:bg-gray-600 focus:ring-2 focus:ring-gray-500 transform hover:scale-105 transition-all ripple" aria-label="Return to main dashboard">
                <i class="fas fa-arrow-left mr-2"></i> Back to Main Dashboard
            </button>
        </nav>

        <!-- Confirmation Modal -->
        <div id="confirmModal" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center hidden transition-opacity duration-300" role="dialog" aria-modal="true" aria-labelledby="modal-title">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-8 max-w-md w-full transform transition-transform duration-300 scale-90 relative" id="modal-content">
                <button onclick="hideConfirmModal()" class="absolute top-4 right-4 text-gray-500 dark:text-gray-300 hover:text-gray-700 dark:hover:text-gray-100 focus:ring-2 focus:ring-orange-500 rounded-full p-1" aria-label="Close modal">
                    <i class="fas fa-times"></i>
                </button>
                <h3 id="modal-title" class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">Confirm Navigation</h3>
                <p class="text-gray-600 dark:text-gray-300 mb-6">Are you sure you want to return to the main dashboard?</p>
                <div class="flex justify-end space-x-4">
                    <button onclick="hideConfirmModal()" class="bg-gray-300 dark:bg-gray-600 text-gray-800 dark:text-gray-100 px-4 py-2 rounded-md hover:bg-gray-400 dark:hover:bg-gray-500 focus:ring-2 focus:ring-gray-500" aria-label="Cancel navigation">Cancel</button>
                    <a href="dashboard.php" class="bg-orange-500 text-white px-4 py-2 rounded-md hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 ripple" aria-label="Confirm navigation to main dashboard">Confirm</a>
                </div>
            </div>
        </div>
    </main>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; mysqli_close($conn); ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script>
        // Ensure content is interactive and clear
        document.addEventListener('DOMContentLoaded', () => {
            const content = document.getElementById('content');
            const navMenu = document.getElementById('navMenu');
            if (content) {
                content.style.filter = 'none';
                content.style.pointerEvents = 'auto';
                if (!navMenu.classList.contains('open')) {
                    content.classList.remove('nav-open');
                }
            }
            // Ripple effect
            document.querySelectorAll('.ripple').forEach(el => {
                el.addEventListener('click', (e) => {
                    const rect = el.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    const ripple = document.createElement('span');
                    ripple.style.position = 'absolute';
                    ripple.style.background = 'rgba(255, 255, 255, 0.3)';
                    ripple.style.borderRadius = '50%';
                    ripple.style.transform = 'scale(0)';
                    ripple.style.animation = 'ripple 0.6s linear';
                    ripple.style.left = `${x}px`;
                    ripple.style.top = `${y}px`;
                    el.appendChild(ripple);
                    setTimeout(() => ripple.remove(), 600);
                });
            });
            // Observe nav-open changes
            const observer = new MutationObserver(() => {
                if (!navMenu.classList.contains('open')) {
                    content.classList.remove('nav-open');
                    content.style.filter = 'none';
                }
            });
            observer.observe(navMenu, { attributes: true, attributeFilter: ['class'] });
        });

        // Chart.js with gradient and data labels
        const ctx = document.getElementById('studentsPerClassChart').getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, '#ff4500');
        gradient.addColorStop(1, '#ff6b00');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($students_per_class, 'class_name')); ?>,
                datasets: [{
                    label: 'Students per Class',
                    data: <?php echo json_encode(array_column($students_per_class, 'student_count')); ?>,
                    backgroundColor: gradient,
                    borderColor: '#ff4500',
                    borderWidth: 1,
                    hoverBackgroundColor: '#ff6b00'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { 
                        beginAtZero: true, 
                        title: { display: true, text: 'Number of Students', color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#e2e8f0' : '#1a202c' },
                        ticks: { color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#e2e8f0' : '#1a202c' }
                    },
                    x: { 
                        title: { display: true, text: 'Class Name', color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#e2e8f0' : '#1a202c' },
                        ticks: { color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#e2e8f0' : '#1a202c' }
                    }
                },
                plugins: {
                    legend: { labels: { color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#e2e8f0' : '#1a202c' } },
                    tooltip: { 
                        backgroundColor: document.documentElement.getAttribute('data-theme') === 'dark' ? '#2d3748' : '#1a202c', 
                        titleColor: '#fff', 
                        bodyColor: '#fff',
                        callbacks: {
                            label: (context) => `${context.parsed.y} students`
                        }
                    },
                    datalabels: {
                        anchor: 'end',
                        align: 'top',
                        color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#e2e8f0' : '#1a202c',
                        formatter: (value) => value
                    }
                }
            }
        });

        // Modal controls
        function showConfirmModal() {
            const modal = document.getElementById('confirmModal');
            const modalContent = document.getElementById('modal-content');
            modal.classList.remove('hidden');
            modalContent.classList.remove('scale-90');
            modalContent.classList.add('scale-100');
            modalContent.querySelector('button[aria-label="Close modal"]').focus();
        }

        function hideConfirmModal() {
            const modal = document.getElementById('confirmModal');
            const modalContent = document.getElementById('modal-content');
            modalContent.classList.remove('scale-100');
            modalContent.classList.add('scale-90');
            setTimeout(() => modal.classList.add('hidden'), 300);
        }

        // Table sorting
        function sortTable(tableId, colIndex) {
            const table = document.getElementById(tableId);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const isNumeric = colIndex === 1 && tableId === 'deptTable' || colIndex === 2 && tableId === 'activityTable';
            
            rows.sort((a, b) => {
                let aValue = a.children[colIndex].textContent.trim();
                let bValue = b.children[colIndex].textContent.trim();
                
                if (isNumeric) {
                    return parseFloat(aValue) - parseFloat(bValue);
                } else {
                    return aValue.localeCompare(bValue);
                }
            });

            // Toggle sort direction
            const header = table.querySelector(`thead th:nth-child(${colIndex + 1}) i`);
            const isAscending = header.classList.contains('fa-sort-up');
            if (isAscending) {
                rows.reverse();
                header.classList.remove('fa-sort-up');
                header.classList.add('fa-sort-down');
            } else {
                header.classList.remove('fa-sort-down');
                header.classList.add('fa-sort-up');
            }

            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
        }

        // Action bar shadow on scroll
        function updateActionBarShadow(nav) {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            nav.classList.toggle('shadow-xl', scrollTop > 50);
            nav.classList.toggle('shadow-lg', scrollTop <= 50);
        }

        window.addEventListener('scroll', () => {
            const nav = document.querySelector('nav[aria-label="Class management actions"]');
            if (nav) updateActionBarShadow(nav);
        });
    </script>
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
        }
        @keyframes ripple {
            to { transform: scale(4); opacity: 0; }
        }
        .animate__fadeIn {
            animation: fadeIn 0.6s ease-in;
        }
        .animate__fadeInUp {
            animation: fadeInUp 0.6s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .card {
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
        }
        [data-theme="dark"] .card {
            background: rgba(45, 55, 72, 0.95);
            backdrop-filter: blur(12px);
        }
        .content, .content * {
            filter: none !important;
        }
        .content.nav-open {
            filter: blur(3px) !important;
        }
        .tooltip {
            visibility: hidden;
            opacity: 0;
            transition: opacity 0.3s, visibility 0.3s;
            z-index: 10;
        }
        .group:hover .tooltip {
            visibility: visible;
            opacity: 1;
        }
        .animate-pulse:hover {
            animation: pulse 1.5s infinite;
        }
        @media (max-width: 640px) {
            .card {
                padding: 1rem;
            }
            .text-5xl {
                font-size: 2.25rem;
            }
            .text-4xl {
                font-size: 1.875rem;
            }
            nav[aria-label="Class management actions"] {
                top: 4rem;
            }
        }
        @media (min-width: 640px) {
            .tooltip {
                transform: translateX(-50%);
                left: 50%;
            }
        }
        .truncate {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</div>
<?php ob_end_flush(); ?>