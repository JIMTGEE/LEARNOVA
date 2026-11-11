<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to students only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a student.";
    header("Location: /login.php");
    exit();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Validate user_id
$student_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id, name, profile_picture FROM users WHERE id = ? AND role = 'student'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $student_id);
    $user_check_stmt->execute();
    $user_result = $user_check_stmt->get_result();
    if ($user_result->num_rows === 0) {
        error_log("Invalid student_id in session: $student_id");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user = $user_result->fetch_assoc();
    $student_name = htmlspecialchars($user['name'] ?? 'Student');
    $profile_picture = $user['profile_picture'] ? htmlspecialchars($user['profile_picture']) : '/assets/default_avatar.png';
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$action = "Accessed View Grades Page";
$description = "Student accessed view grades page";
logActivity($conn, $student_id, $action, $description);

// Current academic year
$current_academic_year = date('Y') . '/' . (date('Y') + 1);

// Fetch classes for filter
$classes_sql = "SELECT c.id, c.class_name 
                FROM classes c 
                JOIN student_classes sc ON c.id = sc.class_id 
                WHERE sc.user_id = ? AND sc.academic_year = ?";
$classes_stmt = $conn->prepare($classes_sql);
if (!$classes_stmt) {
    error_log("Error preparing classes query: " . $conn->error);
}
$classes_stmt->bind_param("is", $student_id, $current_academic_year);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

// Fetch subjects for filter
$subjects_sql = "SELECT id, subject_name FROM subjects WHERE id IN (
                 SELECT DISTINCT subject_id FROM grades WHERE user_id = ?
                )";
$subjects_stmt = $conn->prepare($subjects_sql);
if (!$subjects_stmt) {
    error_log("Error preparing subjects query: " . $conn->error);
}
$subjects_stmt->bind_param("i", $student_id);
$subjects_stmt->execute();
$subjects = $subjects_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$subjects_stmt->close();

// Fetch terms for filter
$terms_sql = "SELECT id, term_name FROM academic_calendar WHERE year = ?";
$terms_stmt = $conn->prepare($terms_sql);
if (!$terms_stmt) {
    error_log("Error preparing terms query: " . $conn->error);
}
$terms_stmt->bind_param("s", $current_academic_year);
$terms_stmt->execute();
$terms = $terms_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$terms_stmt->close();

// Handle filters
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: 0;
$term_id = filter_input(INPUT_GET, 'term_id', FILTER_VALIDATE_INT) ?: 0;

// Fetch grades grouped by subject
$grades_sql = "
    SELECT g.id, COALESCE(g.total, g.score) AS score, g.class_id, g.subject_id, g.term_id,
           c.class_name, s.subject_name, ac.term_name, g.mid_term_exam, g.end_term_exam
    FROM grades g
    JOIN classes c ON g.class_id = c.id
    JOIN subjects s ON g.subject_id = s.id
    JOIN academic_calendar ac ON g.term_id = ac.id
    JOIN student_classes sc ON g.class_id = sc.class_id
    WHERE g.user_id = ? AND sc.user_id = ? AND sc.academic_year = ?
";
$params = [$student_id, $student_id, $current_academic_year];
$types = "iis";

if ($class_id > 0) {
    $grades_sql .= " AND g.class_id = ?";
    $params[] = $class_id;
    $types .= "i";
}
if ($subject_id > 0) {
    $grades_sql .= " AND g.subject_id = ?";
    $params[] = $subject_id;
    $types .= "i";
}
if ($term_id > 0) {
    $grades_sql .= " AND g.term_id = ?";
    $params[] = $term_id;
    $types .= "i";
}
$grades_sql .= " ORDER BY s.subject_name, ac.term_name, c.class_name";

$grades_stmt = $conn->prepare($grades_sql);
if (!$grades_stmt) {
    error_log("Error preparing grades query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch grades.";
    header("Location: /student/dashboard.php");
    exit();
}
$grades_stmt->bind_param($types, ...$params);
$grades_stmt->execute();
$grades = $grades_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$grades_stmt->close();

// Group grades by subject and calculate summary stats
$subject_grades = [];
foreach ($grades as $grade) {
    $subject_id = $grade['subject_id'];
    if (!isset($subject_grades[$subject_id])) {
        $subject_grades[$subject_id] = [
            'subject_name' => $grade['subject_name'],
            'grades' => [],
            'total_score' => 0,
            'count' => 0,
        ];
    }
    $subject_grades[$subject_id]['grades'][] = $grade;
    $subject_grades[$subject_id]['total_score'] += $grade['score'];
    $subject_grades[$subject_id]['count']++;
}
foreach ($subject_grades as &$subject) {
    $subject['average_score'] = $subject['count'] > 0 ? $subject['total_score'] / $subject['count'] : 0;
    // Generate comment based on average score
    if ($subject['average_score'] >= 90) {
        $subject['comment'] = "Outstanding performance! You're excelling in {$subject['subject_name']}!";
    } elseif ($subject['average_score'] >= 80) {
        $subject['comment'] = "Great work in {$subject['subject_name']}! Keep pushing to reach the top!";
    } elseif ($subject['average_score'] >= 70) {
        $subject['comment'] = "Good effort in {$subject['subject_name']}. Focus on key areas to improve.";
    } else {
        $subject['comment'] = "You're making progress in {$subject['subject_name']}. Let's work on boosting your scores!";
    }
}

// Fetch assignments and submissions for AI recommendations
$assignments_sql = "
    SELECT a.id, a.title, a.description, a.subject_id, a.max_points, a.questions, 
           s.subject_name, sub.score, sub.answers
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id AND sub.user_id = ?
    JOIN student_classes sc ON a.class_id = sc.class_id
    WHERE sc.user_id = ? AND sc.academic_year = ?
    ORDER BY s.subject_name, a.due_date
";
$assignments_stmt = $conn->prepare($assignments_sql);
if (!$assignments_stmt) {
    error_log("Error preparing assignments query: " . $conn->error);
}
$assignments_stmt->bind_param("iis", $student_id, $student_id, $current_academic_year);
$assignments_stmt->execute();
$assignments = $assignments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$assignments_stmt->close();

// Prepare data for Gemini API
$recommendations = [];
$gemini_api_key = 'AIzaSyAScYAtUKitr8D3o1RdGA0bUKn-1qeXYSg';
$gemini_endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent';

foreach ($subject_grades as $subject_id => $subject) {
    $subject_assignments = array_filter($assignments, fn($a) => $a['subject_id'] == $subject_id);
    $prompt = "You are an educational assistant. Based on the following data for a student in the subject {$subject['subject_name']}, recommend specific lessons or topics the student should focus on to improve their performance. The student's average score in this subject is {$subject['average_score']}%. Here are the assignments and their performance:\n";
    foreach ($subject_assignments as $assignment) {
        $questions = json_decode($assignment['questions'], true) ?? [];
        $answers = json_decode($assignment['answers'], true) ?? [];
        $prompt .= "- Assignment: {$assignment['title']}\n  Description: {$assignment['description']}\n  Score: " . ($assignment['score'] !== null ? "{$assignment['score']}/{$assignment['max_points']}" : "Not submitted") . "\n";
        foreach ($questions as $index => $q) {
            $student_answer = $answers[$index]['answer'] ?? 'Not answered';
            $correct = ($q['type'] === 'multiple_choice' && $q['correct_answer'] === $student_answer) ? 'Correct' : 'Incorrect';
            $prompt .= "  - Question: {$q['question']}\n    Student Answer: {$student_answer} ({$correct})\n";
        }
    }
    $prompt .= "Provide concise, specific lesson recommendations (max 100 words) for the student to improve in {$subject['subject_name']} based on their performance.";

    // Make API call to Gemini
    $ch = curl_init($gemini_endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "x-goog-api-key: $gemini_api_key"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ]));
    $response = curl_exec($ch);
    if ($response === false) {
        error_log("Gemini API error: " . curl_error($ch));
        $recommendations[$subject_id] = "Unable to generate recommendations at this time.";
    } else {
        $response_data = json_decode($response, true);
        $recommendations[$subject_id] = $response_data['candidates'][0]['content']['parts'][0]['text'] ?? "No recommendations available.";
    }
    curl_close($ch);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View your grades and personalized lesson recommendations at QueensPa School.">
    <meta name="keywords" content="QueensPa School, student grades, lesson recommendations">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Grades - QueensPa School">
    <meta property="og:description" content="Track your grades and get personalized lesson recommendations to shine at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/student/view_grades.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Grades - QueensPa School">
    <meta name="twitter:description" content="Track your grades and get personalized lesson recommendations to shine at QueensPa School.">
    <title>View Grades - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'ui-sans-serif', 'system-ui'],
                    },
                    colors: {
                        primary: {
                            500: '#ff4500',
                            600: '#e03e00',
                        },
                        accent: {
                            500: '#00c4b4',
                            600: '#00a398',
                        },
                    },
                    animation: {
                        'slide-up': 'slideUp 0.3s ease-out',
                        'fade-in': 'fadeIn 0.3s ease-out',
                        'zoom-in': 'zoomIn 0.3s ease-out',
                    },
                    keyframes: {
                        slideUp: {
                            '0%': { transform: 'translateY(20px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        zoomIn: {
                            '0%': { transform: 'scale(0.95)', opacity: '0' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                    },
                },
                darkMode: 'class',
            },
        };
    </script>

    <style>
        body {
            background: linear-gradient(135deg, #f8f9fa, #fff3e0);
            background-image: url('/assets/images/anime_sparkles.png');
            background-size: cover;
            background-attachment: fixed;
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
            overflow-x: hidden;
        }
        .hero-card {
            background: #ffffff;
            border: 2px solid #ff4500;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }
        .hero-card::before {
            content: '';
            position: absolute;
            background: url('/assets/images/sparkle_effect.png');
            background-size: cover;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            opacity: 0.1;
            z-index: 0;
        }
        .mascot-img {
            position: absolute;
            right: 20px;
            bottom: -10px;
            animation: float 3s ease-in-out infinite;
            z-index: 1;
        }
        .summary-card, .filter-card, .grade-card {
            background: #ffffff;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(5px);
        }
        .grade-card {
            border: 2px solid transparent;
            border-image: linear-gradient(45deg, #ff4500, #00c4b4) 1;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .grade-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }
        .star-accent {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 30px;
            height: 30px;
            background: url('/assets/images/anime_star.png');
            background-size: cover;
            opacity: 0.7;
        }
        .btn-primary {
            background: linear-gradient(45deg, #ff4500, #ff6b00);
            border: none;
            border-radius: 25px;
            padding: 0.5rem 1.5rem;
            transition: transform 0.2s ease;
        }
        .btn-primary:hover {
            transform: scale(1.05);
        }
        .btn-outline-primary {
            border-color: #ff4500;
            color: #ff4500;
            border-radius: 25px;
        }
        .btn-outline-primary:hover {
            background: linear-gradient(45deg, #ff4500, #ff6b00);
            color: white;
        }
        [data-tooltip] {
            position: relative;
        }
        [data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute;
            background: #ff4500;
            color: white;
            padding: 0.5rem;
            border-radius: 8px;
            font-size: 0.8rem;
            top: -40px;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
            z-index: 1000;
            white-space: nowrap;
        }
        [data-tooltip]:hover::after {
            opacity: 1;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
        .ripple span {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }
        @media (max-width: 767px) {
            .hero-card h1 {
                font-size: 2rem;
            }
            .mascot-img {
                width: 80px;
                right: 10px;
            }
            .grade-card {
                margin-bottom: 1rem;
            }
        }
        .collapsible-header {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .collapsible-header:hover {
            background-color: #f1f5f9;
        }
        .dark .collapsible-header:hover {
            background-color: #374151;
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-primary-500 text-5xl"></i>
    </div>

    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
    try {
        if (!file_exists($header_path)) {
            throw new Exception("Student header file missing: $header_path");
        }
        include $header_path;
    } catch (Exception $e) {
        error_log("Failed to include student_header.php: " . $e->getMessage());
        logActivity($conn, $student_id, "Error", "Failed to load student header template: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page header.";
        header("Location: /login.php");
        exit();
    }
    ?>

    <main class="container mx-auto px-4 py-12 max-w-7xl">
        <!-- Header Section -->
        <section class="mb-8 text-center animate-slide-up">
            <div class="hero-card relative">
                <div class="card-body p-6">
                    <h1 class="text-4xl font-bold text-gray-800 dark:text-gray-100">Your Grades, <?= $student_name ?>! 🌟</h1>
                    <p class="text-lg text-gray-600 dark:text-gray-300 mt-2">Shine like an anime hero with your awesome scores!</p>
                    <img src="/assets/images/chibi_mascot_grades.png" alt="Chibi Mascot" class="mascot-img" width="120">
                </div>
            </div>
        </section>

        <!-- Filters -->
        <section class="mb-8">
            <div class="filter-card p-6">
                <form class="grid grid-cols-1 md:grid-cols-4 gap-4" method="GET" action="">
                    <div>
                        <label for="class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Filter by Class</label>
                        <select id="class_id" name="class_id" class="form-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-gray-200">
                            <option value="0">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $class_id == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="subject_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Filter by Subject</label>
                        <select id="subject_id" name="subject_id" class="form-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-gray-200">
                            <option value="0">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= $subject['id'] ?>" <?= $subject_id == $subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['subject_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="term_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Filter by Term</label>
                        <select id="term_id" name="term_id" class="form-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-gray-200">
                            <option value="0">All Terms</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= $term['id'] ?>" <?= $term_id == $term['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($term['term_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="btn-primary text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-tooltip="Apply filters">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                        <a href="/student/view_grades.php" class="btn-outline-primary px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-tooltip="Reset filters">
                            <i class="fas fa-undo mr-2"></i> Reset
                        </a>
                        <a href="/student/dashboard.php" class="btn-outline-primary px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-tooltip="Back to Dashboard">
                            <i class="fas fa-arrow-left mr-2"></i> Back
                        </a>
                    </div>
                </form>
            </div>
        </section>

        <!-- Grades by Subject -->
        <section>
            <?php if (empty($subject_grades)): ?>
                <div class="text-center">
                    <div class="summary-card p-6 animate-zoom-in">
                        <img src="/assets/images/no_grades.png" alt="No Grades" width="100" class="mb-3 mx-auto">
                        <h5 class="text-xl font-semibold text-gray-800 dark:text-gray-100">No Grades Yet!</h5>
                        <p class="text-gray-600 dark:text-gray-300">Your teachers are still grading, or try adjusting the filters!</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($subject_grades as $subject_id => $subject): ?>
                    <div class="summary-card mb-6 p-6 animate-zoom-in">
                        <div class="collapsible-header bg-gray-50 dark:bg-gray-700 px-4 py-2 flex justify-between items-center rounded-t-lg">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100"><?= htmlspecialchars($subject['subject_name']) ?></h3>
                            <i class="fas fa-chevron-down text-gray-500 dark:text-gray-400"></i>
                        </div>
                        <div class="collapsible-content p-4">
                            <p class="text-gray-600 dark:text-gray-300 mb-2"><strong>Average Score:</strong> <?= number_format($subject['average_score'], 1) ?>%</p>
                            <p class="text-gray-600 dark:text-gray-300 mb-4"><strong>Comment:</strong> <?= htmlspecialchars($subject['comment']) ?></p>
                            <h4 class="text-md font-medium text-gray-700 dark:text-gray-200 mb-2">Grades</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($subject['grades'] as $grade): ?>
                                    <div class="grade-card relative p-4">
                                        <h5 class="text-md font-medium text-gray-800 dark:text-gray-100"><?= htmlspecialchars($grade['class_name']) ?></h5>
                                        <p class="text-gray-600 dark:text-gray-300"><i class="fas fa-book mr-1"></i> <?= htmlspecialchars($grade['subject_name']) ?></p>
                                        <p class="text-gray-600 dark:text-gray-300"><i class="fas fa-star mr-1"></i> Score: <?= htmlspecialchars($grade['score']) ?>%</p>
                                        <?php if ($grade['mid_term_exam'] !== null): ?>
                                            <p class="text-gray-600 dark:text-gray-300"><i class="fas fa-clipboard mr-1"></i> Mid-Term: <?= htmlspecialchars($grade['mid_term_exam']) ?>%</p>
                                        <?php endif; ?>
                                        <?php if ($grade['end_term_exam'] !== null): ?>
                                            <p class="text-gray-600 dark:text-gray-300"><i class="fas fa-clipboard-check mr-1"></i> End-Term: <?= htmlspecialchars($grade['end_term_exam']) ?>%</p>
                                        <?php endif; ?>
                                        <p class="text-gray-600 dark:text-gray-300"><i class="fas fa-calendar-alt mr-1"></i> Term: <?= htmlspecialchars($grade['term_name']) ?></p>
                                        <span class="star-accent"></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <h4 class="text-md font-medium text-gray-700 dark:text-gray-200 mt-4 mb-2">Recommended Lessons</h4>
                            <p class="text-gray-600 dark:text-gray-300"><?= htmlspecialchars($recommendations[$subject_id]) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php';
    try {
        if (!file_exists($footer_path)) {
            throw new Exception("Student footer file missing: $footer_path");
        }
        include $footer_path;
    } catch (Exception $e) {
        error_log("Failed to include student_footer.php: " . $e->getMessage());
        logActivity($conn, $student_id, "Error", "Failed to load student footer template: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page footer.";
        header("Location: /login.php");
        exit();
    }
    ob_end_flush();
    // Close the connection after all operations, including template inclusions
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
    ?>

    <script>
        // Cache DOM elements
        const loadingOverlay = document.getElementById('loading-overlay');
        const filterInputs = document.querySelectorAll('#class_id, #subject_id, #term_id');

        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Initialize page
        window.addEventListener('load', () => {
            loadingOverlay.classList.add('hidden');
        });

        // Ripple effects for buttons
        document.querySelectorAll('.btn-primary, .btn-outline-primary').forEach(button => {
            button.addEventListener('click', function(e) {
                const rect = button.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;

                const ripple = document.createElement('span');
                ripple.style.width = ripple.style.height = `${size}px`;
                ripple.style.left = `${x}px`;
                ripple.style.top = `${y}px`;
                ripple.classList.add('ripple');
                button.style.position = 'relative';
                button.style.overflow = 'hidden';
                button.appendChild(ripple);

                setTimeout(() => ripple.remove(), 600);
            });
        });

        // Filter and search
        const updateFilters = debounce(() => {
            const classId = document.getElementById('class_id').value;
            const subjectId = document.getElementById('subject_id').value;
            const termId = document.getElementById('term_id').value;
            window.location.href = `?class_id=${classId}&subject_id=${subjectId}&term_id=${termId}`;
        }, 300);
        filterInputs.forEach(input => input.addEventListener('change', updateFilters));

        // Collapsible sections
        document.querySelectorAll('.collapsible-header').forEach(header => {
            header.addEventListener('click', () => {
                const content = header.nextElementSibling;
                const icon = header.querySelector('i');
                content.style.display = content.style.display === 'none' ? 'block' : 'none';
                icon.classList.toggle('fa-chevron-down');
                icon.classList.toggle('fa-chevron-up');
            });
        });
    </script>
</body>
</html>