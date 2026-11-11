<?php
// Start session only if not active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Debug session variables
$session_debug = [
    'user_id_isset' => isset($_SESSION['user_id']),
    'user_id' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set',
    'user_role_isset' => isset($_SESSION['user_role']),
    'user_role' => isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'not set',
    'timestamp' => date('Y-m-d H:i:s')
];
error_log("Session Debug: " . json_encode($session_debug), 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');

// Include dependencies
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// Check database connection
if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    die("<div class='container my-5'><div class='alert alert-danger'>System error. Please contact support.</div></div>");
}

// Verify user session and role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    error_log("Session verification failed: user_id or user_role not set", 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    header("Location: /parent/login_error.php?error=session_not_set");
    exit();
}

// Verify user exists and has parent role
$stmt = $conn->prepare("SELECT id, role FROM users WHERE id = ? AND role = 'parent' AND status = 'active'");
$stmt->bind_param("i", $_SESSION['user_id']);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        error_log("User verification failed: ID {$_SESSION['user_id']} not found or not a parent", 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
        header("Location: /parent/login_error.php?error=invalid_user");
        exit();
    }
    $stmt->close();
} else {
    error_log("User verification query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    header("Location: /parent/login_error.php?error=database_error");
    exit();
}

// Log page access
logActivity($conn, $_SESSION['user_id'], "Accessed parent dashboard", "Parent ID: " . $_SESSION['user_id']);

// Fetch mapped students
$students = [];
$stmt = $conn->prepare("SELECT u.id, u.name, c.class_name 
                        FROM users u 
                        JOIN parent_student_mapping psm ON u.id = psm.student_id 
                        JOIN student_classes sc ON u.id = sc.user_id 
                        JOIN classes c ON sc.class_id = c.id 
                        WHERE psm.parent_id = ? AND sc.academic_year = '2024/2025'");
$stmt->bind_param("i", $_SESSION['user_id']);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
} else {
    error_log("Fetch students query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch student metrics and fee history
$student_data = [];
foreach ($students as $student) {
    $data = [
        'id' => $student['id'],
        'name' => $student['name'],
        'class_name' => $student['class_name'],
        'attendance' => 0,
        'term' => 'N/A',
        'grades' => [],
        'badges' => [],
        'debts' => [],
        'fee_history' => []
    ];
    
    // Attendance and term
    $stmt = $conn->prepare("SELECT sr.attendance, ac.term_name
                            FROM student_reports sr
                            JOIN academic_calendar ac ON sr.term_id = ac.id
                            WHERE sr.user_id = ? ORDER BY sr.created_at DESC LIMIT 1");
    $stmt->bind_param("i", $student['id']);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $data['attendance'] = $row['attendance'] ?? 0;
            $data['term'] = $row['term_name'] ?? 'N/A';
        }
        $stmt->close();
    } else {
        error_log("Attendance query failed for student {$student['id']}: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    }
    
    // Recent grades
    $stmt = $conn->prepare("SELECT g.score, g.total, s.subject_name, ac.term_name
                            FROM grades g
                            JOIN subjects s ON g.subject_id = s.id
                            JOIN academic_calendar ac ON g.term_id = ac.id
                            WHERE g.user_id = ? ORDER BY g.created_at DESC LIMIT 3");
    $stmt->bind_param("i", $student['id']);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data['grades'][] = $row;
        }
        $stmt->close();
    } else {
        error_log("Grades query failed for student {$student['id']}: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    }
    
    // Recent badges
    $stmt = $conn->prepare("SELECT b.name, b.description, sp.created_at
                            FROM student_progress sp
                            JOIN badges b ON sp.badge_id = b.id
                            WHERE sp.user_id = ? ORDER BY sp.created_at DESC LIMIT 3");
    $stmt->bind_param("i", $student['id']);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data['badges'][] = $row;
        }
        $stmt->close();
    } else {
        error_log("Badges query failed for student {$student['id']}: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    }
    
    // Recent debts
    $stmt = $conn->prepare("SELECT d.amount_due, f.fee_type, f.due_date
                            FROM debts d
                            JOIN fees f ON d.fee_id = f.id
                            WHERE d.user_id = ? AND d.amount_due > 0
                            ORDER BY f.due_date ASC LIMIT 3");
    $stmt->bind_param("i", $student['id']);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data['debts'][] = $row;
        }
        $stmt->close();
    } else {
        error_log("Debts query failed for student {$student['id']}: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    }
    
    // Fee payment history
    $stmt = $conn->prepare("SELECT p.amount_paid AS amount, p.payment_date AS paid_date, f.fee_type
                            FROM payments p
                            JOIN fees f ON p.fee_id = f.id
                            WHERE p.user_id = ? ORDER BY p.payment_date DESC LIMIT 3");
    $stmt->bind_param("i", $student['id']);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data['fee_history'][] = $row;
        }
        $stmt->close();
    } else {
        error_log("Fee history query failed for student {$student['id']}: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    }
    
    $student_data[] = $data;
}

// Fetch recent behavior comments
$comments = [];
$stmt = $conn->prepare("SELECT bc.id, bc.comment, bc.created_at, bc.term, bc.is_read, u.name AS student_name, c.class_name, t.name AS teacher_name
                        FROM behavior_comments bc
                        JOIN parent_student_mapping psm ON bc.user_id = psm.student_id
                        JOIN users u ON bc.user_id = u.id
                        JOIN classes c ON bc.class_id = c.id
                        JOIN users t ON bc.created_by = t.id
                        WHERE psm.parent_id = ? 
                        ORDER BY bc.created_at DESC LIMIT 5");
$stmt->bind_param("i", $_SESSION['user_id']);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $comments[] = $row;
    }
    $stmt->close();
} else {
    error_log("Behavior comments query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch upcoming activities
$activities = [];
$stmt = $conn->prepare("SELECT se.id, se.title, se.description, se.event_date, se.event_type, se.location, se.start_time
                        FROM school_events se
                        WHERE se.event_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
                        UNION
                        SELECT ce.id, ce.title, ce.description, ce.event_date, 'club' AS event_type, ce.location, NULL AS start_time
                        FROM club_events ce
                        JOIN club_memberships cm ON ce.club_id = cm.club_id
                        JOIN parent_student_mapping psm ON cm.user_id = psm.student_id
                        WHERE psm.parent_id = ? AND ce.event_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
                        ORDER BY event_date ASC LIMIT 5");
$stmt->bind_param("i", $_SESSION['user_id']);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    $stmt->close();
} else {
    error_log("Activities query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch notifications
$notifications = [];
$stmt = $conn->prepare("SELECT n.id, n.title, n.message, n.type, n.is_read, n.created_at, n.link
                        FROM notifications n
                        WHERE n.user_id = ? OR n.target_role = 'parent'
                        ORDER BY n.created_at DESC LIMIT 5");
$stmt->bind_param("i", $_SESSION['user_id']);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
} else {
    error_log("Notifications query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch upcoming assignments
$assignments = [];
$stmt = $conn->prepare("SELECT a.id, a.title, a.description, a.due_date, a.type, s.subject_name, c.class_name
                        FROM assignments a
                        JOIN class_assignments ca ON a.id = ca.assignment_id
                        JOIN parent_student_mapping psm ON ca.student_id = psm.student_id
                        JOIN subjects s ON a.subject_id = s.id
                        JOIN classes c ON a.class_id = c.id
                        WHERE psm.parent_id = ? AND a.due_date >= CURDATE() AND a.status = 'published'
                        ORDER BY a.due_date ASC LIMIT 5");
$stmt->bind_param("i", $_SESSION['user_id']);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $assignments[] = $row;
    }
    $stmt->close();
} else {
    error_log("Assignments query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch upcoming exam schedules
$exams = [];
$stmt = $conn->prepare("SELECT es.id, es.exam_date, es.start_time, es.room, s.subject_name, c.class_name
                        FROM exam_schedules es
                        JOIN parent_student_mapping psm ON es.class_id = psm.student_id
                        JOIN subjects s ON es.subject_id = s.id
                        JOIN classes c ON es.class_id = c.id
                        WHERE psm.parent_id = ? AND es.exam_date >= CURDATE()
                        ORDER BY es.exam_date ASC LIMIT 5");
$stmt->bind_param("i", $_SESSION['user_id']);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $exams[] = $row;
    }
    $stmt->close();
} else {
    error_log("Exam schedules query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch library loans and fines
$library_loans = [];
$stmt = $conn->prepare("SELECT ll.id, ll.loan_date, ll.due_date, ll.status, lb.title, lf.amount AS fine_amount
                        FROM library_loans ll
                        JOIN parent_student_mapping psm ON ll.user_id = psm.student_id
                        JOIN library_books lb ON ll.book_id = lb.id
                        LEFT JOIN library_fines lf ON ll.id = lf.loan_id AND lf.status = 'pending'
                        WHERE psm.parent_id = ? AND ll.status IN ('active', 'overdue')
                        ORDER BY ll.due_date ASC LIMIT 5");
$stmt->bind_param("i", $_SESSION['user_id']);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $library_loans[] = $row;
    }
    $stmt->close();
} else {
    error_log("Library loans query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch wellness resources
$wellness_resources = [];
$stmt = $conn->prepare("SELECT wr.id, wr.title, wr.description, wr.type, wr.content_url
                        FROM wellness_resources wr
                        WHERE wr.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        ORDER BY wr.created_at DESC LIMIT 3");
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $wellness_resources[] = $row;
    }
    $stmt->close();
} else {
    error_log("Wellness resources query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/parent_header.php'; ?>

<div class="container-fluid my-5">
    <div class="row">
        <!-- Sidebar for Quick Links (Large Screens) -->
        <div class="col-lg-3 d-none d-lg-block">
            <div class="card shadow-sm sticky-top" style="top: 20px;">
                <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                    <h3 class="card-title mb-0 fs-5"><i class="fas fa-link me-2"></i>Quick Links</h3>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item"><a href="/parent/fees.php" class="text-decoration-none">Fees</a></li>
                        <li class="list-group-item"><a href="/parent/reports.php" class="text-decoration-none">Reports</a></li>
                        <li class="list-group-item"><a href="/parent/behavior_comments.php" class="text-decoration-none">Behavior Comments</a></li>
                        <li class="list-group-item"><a href="/parent/activities.php" class="text-decoration-none">Activities</a></li>
                        <li class="list-group-item"><a href="/parent/assignments.php" class="text-decoration-none">Assignments</a></li>
                        <li class="list-group-item"><a href="/parent/exams.php" class="text-decoration-none">Exam Schedules</a></li>
                        <li class="list-group-item"><a href="/parent/library.php" class="text-decoration-none">Library</a></li>
                        <li class="list-group-item"><a href="/parent/wellness.php" class="text-decoration-none">Wellness</a></li>
                        <li class="list-group-item"><a href="/parent/profile.php" class="text-decoration-none">Profile</a></li>
                        <li class="list-group-item"><a href="/logout.php" class="text-decoration-none text-danger">Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="col-lg-9">
            <!-- Dashboard Header -->
            <h1 class="display-5 fw-bold text-center mb-5 animate__animated animate__fadeIn" style="color: #ff4500;">
                <i class="fas fa-tachometer-alt me-2"></i>Parent Dashboard
            </h1>
            
            <!-- Quick Links (Mobile/Tablet) -->
            <div class="card mb-4 shadow-sm animate__animated animate__fadeInUp d-lg-none">
                <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                    <h3 class="card-title mb-0 fs-5"><i class="fas fa-link me-2"></i>Quick Links</h3>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-4"><a href="/parent/fees.php" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">Fees</a></div>
                        <div class="col-6 col-md-4"><a href="/parent/reports.php" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">Reports</a></div>
                        <div class="col-6 col-md-4"><a href="/parent/behavior_comments.php" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">Behavior</a></div>
                        <div class="col-6 col-md-4"><a href="/parent/activities.php" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">Activities</a></div>
                        <div class="col-6 col-md-4"><a href="/parent/assignments.php" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">Assignments</a></div>
                        <div class="col-6 col-md-4"><a href="/parent/exams.php" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">Exams</a></div>
                        <div class="col-6 col-md-4"><a href="/parent/library.php" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">Library</a></div>
                        <div class="col-6 col-md-4"><a href="/parent/wellness.php" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">Wellness</a></div>
                        <div class="col-6 col-md-4"><a href="/parent/profile.php" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">Profile</a></div>
                        <div class="col-6 col-md-4"><a href="/logout.php" class="btn btn-secondary w-100">Logout</a></div>
                    </div>
                </div>
            </div>
            
            <!-- Notifications -->
            <div class="card mb-4 shadow-sm animate__animated animate__fadeInUp">
                <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                    <h3 class="card-title mb-0 fs-5"><i class="fas fa-bell me-2"></i>Notifications</h3>
                </div>
                <div class="card-body">
                    <?php if ($notifications): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($notifications as $notification): ?>
                                <li class="list-group-item <?php echo $notification['is_read'] ? '' : 'bg-light'; ?>">
                                    <p class="mb-1 fs-6"><strong><?php echo htmlspecialchars($notification['title']); ?></strong> (<?php echo htmlspecialchars($notification['type']); ?>)</p>
                                    <p class="mb-1 fs-6"><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <?php if ($notification['link']): ?>
                                        <a href="<?php echo htmlspecialchars($notification['link']); ?>" class="text-primary fs-6">View Details</a>
                                    <?php endif; ?>
                                    <small class="text-muted d-block fs-6">Received: <?php echo date('d M Y, H:i', strtotime($notification['created_at'])); ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="/parent/notifications.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Notifications</a>
                    <?php else: ?>
                        <p class="card-text text-muted fs-6">No recent notifications.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Student Metrics -->
            <h2 class="mb-4 animate__animated animate__fadeIn" style="color: #ff4500;"><i class="fas fa-user-graduate me-2"></i>Student Academic Metrics</h2>
            <div class="row mb-4">
                <?php foreach ($student_data as $index => $data): ?>
                    <div class="col-12 col-md-6 col-lg-4 mb-4">
                        <div class="card shadow-sm animate__animated animate__fadeInUp" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                            <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                                <h4 class="card-title mb-0 fs-6"><?php echo htmlspecialchars($data['name']); ?> (<?php echo htmlspecialchars($data['class_name']); ?>)</h4>
                            </div>
                            <div class="card-body">
                                <p class="card-text fs-6"><strong>Attendance (<?php echo htmlspecialchars($data['term']); ?>):</strong> <?php echo $data['attendance']; ?>%</p>
                                <h5 class="mt-3 fs-6">Recent Grades:</h5>
                                <?php if ($data['grades']): ?>
                                    <ul class="list-group list-group-flush mb-3">
                                        <?php foreach ($data['grades'] as $grade): ?>
                                            <li class="list-group-item fs-6"><?php echo htmlspecialchars($grade['subject_name']); ?> (<?php echo htmlspecialchars($grade['term_name']); ?>): <?php echo number_format($grade['score'], 1); ?>/<?php echo number_format($grade['total'], 1); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="card-text text-muted fs-6">No recent grades available.</p>
                                <?php endif; ?>
                                <h5 class="mt-3 fs-6">Recent Badges:</h5>
                                <?php if ($data['badges']): ?>
                                    <ul class="list-group list-group-flush mb-3">
                                        <?php foreach ($data['badges'] as $badge): ?>
                                            <li class="list-group-item fs-6"><?php echo htmlspecialchars($badge['name']); ?>: <?php echo htmlspecialchars($badge['description'] ?: 'No description'); ?> (Earned: <?php echo date('d M Y', strtotime($badge['created_at'])); ?>)</li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="card-text text-muted fs-6">No recent badges earned.</p>
                                <?php endif; ?>
                                <a href="/parent/reports.php?student_id=<?php echo $data['id']; ?>" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View Full Report</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($student_data)): ?>
                    <div class="col-12">
                        <div class="alert alert-warning text-center fs-6">No students mapped to your account. Please contact the school administration.</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Fee History -->
            <h2 class="mb-4 animate__animated animate__fadeIn" style="color: #ff4500;"><i class="fas fa-money-bill-wave me-2"></i>Fee History & Debts</h2>
            <div class="row mb-4">
                <?php foreach ($student_data as $index => $data): ?>
                    <div class="col-12 col-md-6 col-lg-4 mb-4">
                        <div class="card shadow-sm animate__animated animate__fadeInUp" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                            <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                                <h4 class="card-title mb-0 fs-6"><?php echo htmlspecialchars($data['name']); ?> (<?php echo htmlspecialchars($data['class_name']); ?>)</h4>
                            </div>
                            <div class="card-body">
                                <h5 class="mt-3 fs-6">Outstanding Debts:</h5>
                                <?php if ($data['debts']): ?>
                                    <ul class="list-group list-group-flush mb-3">
                                        <?php foreach ($data['debts'] as $debt): ?>
                                            <li class="list-group-item fs-6"><?php echo htmlspecialchars($debt['fee_type']); ?>: GH₵ <?php echo number_format($debt['amount_due'], 2); ?> (Due: <?php echo date('d M Y', strtotime($debt['due_date'])); ?>)</li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="card-text text-muted fs-6">No outstanding debts.</p>
                                <?php endif; ?>
                                <h5 class="mt-3 fs-6">Payment History:</h5>
                                <?php if ($data['fee_history']): ?>
                                    <ul class="list-group list-group-flush mb-3">
                                        <?php foreach ($data['fee_history'] as $payment): ?>
                                            <li class="list-group-item fs-6"><?php echo htmlspecialchars($payment['fee_type']); ?>: GH₵ <?php echo number_format($payment['amount'], 2); ?> (Paid: <?php echo date('d M Y', strtotime($payment['paid_date'])); ?>)</li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="card-text text-muted fs-6">No payment history available.</p>
                                <?php endif; ?>
                                <a href="/parent/fees.php?student_id=<?php echo $data['id']; ?>" class="btn btn-primary w-100" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View Full Fee History</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($student_data)): ?>
                    <div class="col-12">
                        <div class="alert alert-warning text-center fs-6">No students mapped to your account. Please contact the school administration.</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Behavior Comments -->
            <div class="card mb-4 shadow-sm animate__animated animate__fadeInUp">
                <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                    <h3 class="card-title mb-0 fs-5"><i class="fas fa-comment me-2"></i>Recent Behavior Comments</h3>
                </div>
                <div class="card-body">
                    <?php if ($comments): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($comments as $comment): ?>
                                <li class="list-group-item <?php echo $comment['is_read'] ? '' : 'bg-light'; ?>">
                                    <p class="mb-1 fs-6"><strong><?php echo htmlspecialchars($comment['student_name']); ?> (<?php echo htmlspecialchars($comment['class_name']); ?>)</strong></p>
                                    <p class="mb-1 fs-6"><?php echo htmlspecialchars($comment['comment']); ?></p>
                                    <p class="mb-1"><small class="text-muted fs-6">Teacher: <?php echo htmlspecialchars($comment['teacher_name']); ?> | Term: <?php echo htmlspecialchars($comment['term']); ?> | Date: <?php echo date('d M Y', strtotime($comment['created_at'])); ?></small></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="/parent/behavior_comments.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Comments</a>
                    <?php else: ?>
                        <p class="card-text text-muted fs-6">No recent behavior comments.</p>
                        <a href="/parent/behavior_comments.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Comments</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Upcoming Activities -->
            <div class="card mb-4 shadow-sm animate__animated animate__fadeInUp">
                <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                    <h3 class="card-title mb-0 fs-5"><i class="fas fa-calendar-alt me-2"></i>Upcoming Activities</h3>
                </div>
                <div class="card-body">
                    <?php if ($activities): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($activities as $activity): ?>
                                <li class="list-group-item">
                                    <p class="mb-1 fs-6"><strong><?php echo htmlspecialchars($activity['title']); ?></strong> (<?php echo htmlspecialchars($activity['event_type']); ?>)</p>
                                    <p class="mb-1 fs-6"><?php echo htmlspecialchars($activity['description'] ?: 'No description available'); ?></p>
                                    <p class="mb-1"><small class="text-muted fs-6">Date: <?php echo date('d M Y', strtotime($activity['event_date'])); ?> <?php echo $activity['start_time'] ? '| Time: ' . date('H:i', strtotime($activity['start_time'])) : ''; ?> | Location: <?php echo htmlspecialchars($activity['location'] ?: 'TBD'); ?></small></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="/parent/activities.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Activities</a>
                    <?php else: ?>
                        <p class="card-text text-muted fs-6">No upcoming activities.</p>
                        <a href="/parent/activities.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Activities</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Upcoming Assignments -->
            <div class="card mb-4 shadow-sm animate__animated animate__fadeInUp">
                <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                    <h3 class="card-title mb-0 fs-5"><i class="fas fa-tasks me-2"></i>Upcoming Assignments</h3>
                </div>
                <div class="card-body">
                    <?php if ($assignments): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($assignments as $assignment): ?>
                                <li class="list-group-item">
                                    <p class="mb-1 fs-6"><strong><?php echo htmlspecialchars($assignment['title']); ?></strong> (<?php echo htmlspecialchars($assignment['type']); ?>)</p>
                                    <p class="mb-1 fs-6"><?php echo htmlspecialchars($assignment['description'] ?: 'No description available'); ?></p>
                                    <p class="mb-1"><small class="text-muted fs-6">Subject: <?php echo htmlspecialchars($assignment['subject_name']); ?> | Class: <?php echo htmlspecialchars($assignment['class_name']); ?> | Due: <?php echo date('d M Y', strtotime($assignment['due_date'])); ?></small></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="/parent/assignments.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Assignments</a>
                    <?php else: ?>
                        <p class="card-text text-muted fs-6">No upcoming assignments.</p>
                        <a href="/parent/assignments.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Assignments</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Exam Schedules -->
            <div class="card mb-4 shadow-sm animate__animated animate__fadeInUp">
                <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                    <h3 class="card-title mb-0 fs-5"><i class="fas fa-book me-2"></i>Upcoming Exam Schedules</h3>
                </div>
                <div class="card-body">
                    <?php if ($exams): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($exams as $exam): ?>
                                <li class="list-group-item">
                                    <p class="mb-1 fs-6"><strong><?php echo htmlspecialchars($exam['subject_name']); ?></strong> (<?php echo htmlspecialchars($exam['class_name']); ?>)</p>
                                    <p class="mb-1"><small class="text-muted fs-6">Date: <?php echo date('d M Y', strtotime($exam['exam_date'])); ?> | Time: <?php echo date('H:i', strtotime($exam['start_time'])); ?> | Room: <?php echo htmlspecialchars($exam['room'] ?: 'TBD'); ?></small></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="/parent/exams.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Exams</a>
                    <?php else: ?>
                        <p class="card-text text-muted fs-6">No upcoming exams.</p>
                        <a href="/parent/exams.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Exams</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Library Loans -->
            <div class="card mb-4 shadow-sm animate__animated animate__fadeInUp">
                <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                    <h3 class="card-title mb-0 fs-5"><i class="fas fa-book-open me-2"></i>Library Loans</h3>
                </div>
                <div class="card-body">
                    <?php if ($library_loans): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($library_loans as $loan): ?>
                                <li class="list-group-item">
                                    <p class="mb-1 fs-6"><strong><?php echo htmlspecialchars($loan['title']); ?></strong> (<?php echo htmlspecialchars($loan['status']); ?>)</p>
                                    <p class="mb-1"><small class="text-muted fs-6">Loaned: <?php echo date('d M Y', strtotime($loan['loan_date'])); ?> | Due: <?php echo date('d M Y', strtotime($loan['due_date'])); ?> | Fine: <?php echo $loan['fine_amount'] ? 'GH₵ ' . number_format($loan['fine_amount'], 2) : 'None'; ?></small></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="/parent/library.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Loans</a>
                    <?php else: ?>
                        <p class="card-text text-muted fs-6">No active library loans.</p>
                        <a href="/parent/library.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View Library</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Wellness Resources -->
            <div class="card mb-4 shadow-sm animate__animated animate__fadeInUp">
                <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                    <h3 class="card-title mb-0 fs-5"><i class="fas fa-heart me-2"></i>Wellness Resources</h3>
                </div>
                <div class="card-body">
                    <?php if ($wellness_resources): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($wellness_resources as $resource): ?>
                                <li class="list-group-item">
                                    <p class="mb-1 fs-6"><strong><?php echo htmlspecialchars($resource['title']); ?></strong> (<?php echo htmlspecialchars($resource['type']); ?>)</p>
                                    <p class="mb-1 fs-6"><?php echo htmlspecialchars($resource['description'] ?: 'No description available'); ?></p>
                                    <?php if ($resource['content_url']): ?>
                                        <a href="<?php echo htmlspecialchars($resource['content_url']); ?>" class="text-primary fs-6" target="_blank">Access Resource</a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="/parent/wellness.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Resources</a>
                    <?php else: ?>
                        <p class="card-text text-muted fs-6">No recent wellness resources.</p>
                        <a href="/parent/wellness.php" class="btn btn-primary mt-3" style="background: linear-gradient(to right, #ff4500, #ff6b00); border: none;">View All Resources</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/parent_footer.php'; ?>