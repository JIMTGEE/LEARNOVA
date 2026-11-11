<?php
ob_start(); // Start output buffering
session_start();

// Enable error logging, disable display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');

// Restrict to students only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    error_log("Unauthorized access attempt to learn.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'));
    header("Location: /login.php");
    exit();
}

// Include dependencies
$include_success = true;
if (!@include_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php')) {
    error_log("Failed to include db.php");
    $include_success = false;
}
if (!@include_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php')) {
    error_log("Failed to include log_activity.php");
    $include_success = false;
}

// Fallback for log_activity
if (!function_exists('log_activity')) {
    function log_activity($conn, $user_id, $action, $description) {
        try {
            $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param("iss", $user_id, $action, $description);
                $stmt->execute();
                $stmt->close();
            } else {
                error_log("log_activity fallback failed to prepare statement: " . $conn->error);
            }
        } catch (Exception $e) {
            error_log("log_activity fallback error: " . $e->getMessage());
        }
    }
}

// Verify database connection
if (!isset($conn) || !$conn || !$include_success) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error or missing db.php"));
    die(json_encode(['error' => 'Database connection failed. Please try again later.']));
}

$user_id = (int)$_SESSION['user_id'];
$success_msg = $error_msg = "";
$session_id = session_id();

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$stmt->bind_param("is", $user_id, $csrf_token);
$stmt->execute();
$stmt->close();

// Check if learning_history table exists
try {
    $result = $conn->query("SHOW TABLES LIKE 'learning_history'");
    if ($result->num_rows === 0) {
        $create_table = "CREATE TABLE learning_history (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            prompt TEXT,
            response TEXT,
            subject_id INT(11) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            session_id VARCHAR(50),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
            INDEX idx_user_session (user_id, session_id)
        ) ENGINE=InnoDB";
        $conn->query($create_table) or throw new Exception("Failed to create learning_history: " . $conn->error);
        log_activity($conn, $user_id, 'Table Creation', 'Created learning_history table');
    }
} catch (Exception $e) {
    error_log("Error checking/creating learning_history: " . $e->getMessage());
    die(json_encode(['error' => 'Failed to initialize database. Please contact support.']));
}

// Handle AJAX request for AI chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    header('Content-Type: application/json');
    ob_clean();

    // Verify CSRF token
    $received_token = $_POST['csrf_token'] ?? '';
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $stmt->bind_param("is", $user_id, $received_token);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        error_log("Invalid CSRF token for user_id: $user_id");
        echo json_encode(['error' => 'Invalid CSRF token. Please refresh the page.']);
        exit();
    }
    $stmt->close();

    // Verify session
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $user_id) {
        error_log("Session invalid for user_id: $user_id");
        echo json_encode(['error' => 'Session expired. Please <a href="javascript:location.reload()">refresh</a> the page.']);
        exit();
    }

    $prompt = trim($_POST['prompt'] ?? '');
    if (empty($prompt)) {
        echo json_encode(['error' => 'Please enter a question']);
        exit();
    }

    // Fetch student context (name, grades, quizzes, assignments, progress, resources)
    $context = "";
    try {
        // Student name
        $sql = "SELECT student_name FROM students WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $student = $result->fetch_assoc();
        $student_name = $student['student_name'] ?? '';
        $stmt->close();

        if (empty($student_name)) {
            $sql = "SELECT name FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $student_name = $user['name'] ?? 'Student';
            $stmt->close();
        }

        // Grades
        $sql = "SELECT g.score, g.total, s.subject_name, c.class_name
                FROM grades g
                JOIN subjects s ON g.subject_id = s.id
                JOIN classes c ON g.class_id = c.id
                WHERE g.user_id = ?
                ORDER BY g.created_at DESC
                LIMIT 5";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $grades = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Quiz submissions
        $sql = "SELECT q.title, qs.score, qs.total_score, s.subject_name
                FROM quiz_submissions qs
                JOIN quizzes q ON qs.quiz_id = q.id
                JOIN subjects s ON q.subject_id = s.id
                WHERE qs.user_id = ?
                ORDER BY qs.submitted_at DESC
                LIMIT 5";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $quizzes = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Assignment submissions
        $sql = "SELECT a.title, a.subject_id, s.subject_name, a.due_date, a_s.score, a_s.feedback, a_s.late_submission_reason
                FROM assignment_submissions a_s
                JOIN assignments a ON a_s.assignment_id = a.id
                JOIN subjects s ON a.subject_id = s.id
                WHERE a_s.user_id = ?
                ORDER BY a_s.submission_date DESC
                LIMIT 5";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $assignments = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Student progress and badges
        $sql = "SELECT sp.points_earned, b.name AS badge_name
                FROM student_progress sp
                LEFT JOIN badges b ON sp.badge_id = b.id
                WHERE sp.user_id = ?
                ORDER BY sp.created_at DESC
                LIMIT 3";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $progress = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Learning resources (for suggestion)
        $sql = "SELECT title, resource_type, url, subject_id
                FROM learning_resources
                WHERE access_level = 'public' OR access_level = 'class'
                ORDER BY created_at DESC
                LIMIT 10";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->execute();
        $result = $stmt->get_result();
        $resources = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Build context
        $context .= "Student Name: $student_name\n";
        $context .= "Student Context:\n";
        if ($grades) {
            $context .= "Recent Grades:\n";
            foreach ($grades as $grade) {
                $percentage = $grade['total'] ? round(($grade['score'] / $grade['total']) * 100, 2) : 0;
                $context .= "- {$grade['subject_name']} ({$grade['class_name']}): {$grade['score']}/{$grade['total']} ($percentage%)\n";
            }
        }
        if ($quizzes) {
            $context .= "Recent Quizzes:\n";
            foreach ($quizzes as $quiz) {
                $percentage = $quiz['total_score'] ? round(($quiz['score'] / $quiz['total_score']) * 100, 2) : 0;
                $context .= "- {$quiz['title']} ({$quiz['subject_name']}): {$quiz['score']}/{$quiz['total_score']} ($percentage%)\n";
            }
        }
        if ($assignments) {
            $context .= "Recent Assignment Submissions:\n";
            foreach ($assignments as $assignment) {
                $status = $assignment['score'] !== null ? "Scored {$assignment['score']}" : "Pending";
                $late = $assignment['late_submission_reason'] ? "(Late: {$assignment['late_submission_reason']})" : "";
                $feedback = $assignment['feedback'] ? "Feedback: {$assignment['feedback']}" : "";
                $context .= "- {$assignment['title']} ({$assignment['subject_name']}): $status $late $feedback\n";
            }
        }
        if ($progress) {
            $context .= "Recent Achievements:\n";
            foreach ($progress as $item) {
                $badge = $item['badge_name'] ? "Earned badge: {$item['badge_name']}" : "";
                $points = $item['points_earned'] ? "Points: {$item['points_earned']}" : "";
                if ($badge || $points) {
                    $context .= "- $badge $points\n";
                }
            }
        }
        if ($resources) {
            $context .= "Available Resources:\n";
            foreach ($resources as $resource) {
                $context .= "- {$resource['title']} ({$resource['resource_type']}): {$resource['url']}\n";
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching context for user_id: $user_id, Error: " . $e->getMessage());
        $context .= "Error loading student context.\n";
    }

  // Query Gemini API with improved logic
try {
    // Validate and sanitize prompt
    $prompt = filter_var(trim($_POST['prompt'] ?? ''), FILTER_SANITIZE_STRING);
    if (empty($prompt)) {
        throw new Exception('Please enter a valid question.');
    }

    // Check for cached response (simple in-memory cache for this session)
    $cache_key = md5($prompt . $user_id . $session_id);
    $cache_file = $_SERVER['DOCUMENT_ROOT'] . '/cache/ai_responses/' . $cache_key . '.json';
    $cache_duration = 3600; // Cache for 1 hour
    $cached_response = null;

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_duration) {
        $cached_response = json_decode(file_get_contents($cache_file), true);
        if ($cached_response && isset($cached_response['response'])) {
            log_activity($conn, $user_id, 'AI Interaction', "Retrieved cached response for prompt: $prompt");
            echo json_encode([
                'success' => true,
                'prompt' => htmlspecialchars($prompt),
                'response' => nl2br(htmlspecialchars($cached_response['response'])),
                'created_at' => date('Y-m-d H:i:s'),
                'cached' => true
            ]);
            exit();
        }
    }

    // Prepare contextual prompt with conversation history
    $history_context = "";
    if (!empty($current_session_messages)) {
        $history_context .= "Conversation History:\n";
        foreach ($current_session_messages as $msg) {
            $history_context .= "Student: {$msg['prompt']}\nEduBot: {$msg['response']}\n";
        }
        $history_context .= "\n";
    }

    // Enhanced prompt with clear instructions
    $full_prompt = <<<EOD
You are EduBot, an AI assistant for QueensPa School, designed to assist students with academic queries. Your responses should be clear, concise, accurate, and tailored to the student's context (e.g., grades, quiz performance, assignments). Use a friendly and encouraging tone, and provide step-by-step explanations for complex topics. Suggest relevant resources from the provided list when applicable. If the question is unclear, ask for clarification. Always end with a follow-up question to deepen understanding. Avoid non-educational content and sensitive topics.

Student Context:
$context

Conversation History:
$history_context

Student Question:
$prompt
EOD;

    // Gemini API configuration
    $api_key = "AIzaSyAScYAtUKitr8D3o1RdGA0bUKn-1qeXYSg";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=$api_key";
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $full_prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7, // Balanced creativity and accuracy
            'maxOutputTokens' => 500, // Limit response length
            'topP' => 0.9, // Control diversity
            'topK' => 40 // Control randomness
        ],
        'safetySettings' => [
            [
                'category' => 'HARM_CATEGORY_HATE_SPEECH',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_HARASSMENT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ]
        ]
    ];

    // Retry logic for API requests
    $max_retries = 3;
    $retry_delay = 2; // Seconds
    $attempt = 0;
    $success = false;
    $ai_response = null;

    while ($attempt < $max_retries && !$success) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Client-IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Set timeout
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($http_code === 200 && $response) {
            $ai_data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($ai_data['candidates'][0]['content']['parts'][0]['text'])) {
                $ai_response = $ai_data['candidates'][0]['content']['parts'][0]['text'];
                $success = true;
            } else {
                error_log("JSON decode or structure error for Gemini API response: " . json_last_error_msg() . ", Response: " . $response);
            }
        } else {
            error_log("Gemini API request failed for user_id: $user_id, Attempt: " . ($attempt + 1) . ", HTTP Code: $http_code, Error: $curl_error");
            $attempt++;
            if ($attempt < $max_retries) {
                sleep($retry_delay * pow(2, $attempt)); // Exponential backoff
            }
        }
    }

    if (!$success) {
        throw new Exception('Failed to get a valid response from EduBot after ' . $max_retries . ' attempts.');
    }

    // Sanitize and validate AI response
    $ai_response = strip_tags($ai_response, '<b><i><strong><em><ul><ol><li>'); // Allow basic formatting
    $ai_response = trim($ai_response);
    if (empty($ai_response)) {
        throw new Exception('EduBot returned an empty response.');
    }

    // Save to cache
    if (!is_dir($_SERVER['DOCUMENT_ROOT'] . '/cache/ai_responses')) {
        mkdir($_SERVER['DOCUMENT_ROOT'] . '/cache/ai_responses', 0755, true);
    }
    file_put_contents($cache_file, json_encode(['response' => $ai_response, 'timestamp' => time()]));

    // Save to learning_history (unchanged from original)
    try {
        $sql = "INSERT INTO learning_history (user_id, prompt, response, session_id) 
                VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("isss", $user_id, $prompt, $ai_response, $session_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $stmt->close();
        log_activity($conn, $user_id, 'AI Interaction', "Sent prompt to EduBot: $prompt");
    } catch (Exception $e) {
        error_log("Error saving to learning_history for user_id: $user_id, Error: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to save interaction. Please try again.']);
        exit();
    }

    // Return response
    echo json_encode([
        'success' => true,
        'prompt' => htmlspecialchars($prompt),
        'response' => nl2br(htmlspecialchars($ai_response)),
        'created_at' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    error_log("AI interaction error for user_id: $user_id, Error: " . $e->getMessage());
    echo json_encode(['error' => htmlspecialchars($e->getMessage())]);
}
exit();
}

// Handle clear history request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_history') {
    header('Content-Type: application/json');
    ob_clean();

    // Verify CSRF token
    $received_token = $_POST['csrf_token'] ?? '';
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $stmt->bind_param("is", $user_id, $received_token);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        error_log("Invalid CSRF token for user_id: $user_id");
        echo json_encode(['error' => 'Invalid CSRF token. Please refresh the page.']);
        exit();
    }
    $stmt->close();

    try {
        $sql = "DELETE FROM learning_history WHERE user_id = ? AND session_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("is", $user_id, $session_id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, $user_id, 'Clear History', "Cleared session history for session_id: $session_id");
        echo json_encode(['success' => true, 'message' => 'Chat history cleared for this session.']);
    } catch (Exception $e) {
        error_log("Error clearing history for user_id: $user_id, session_id: $session_id, Error: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to clear history. Please try again.']);
    }
    exit();
}

// Fetch student details
try {
    $sql = "SELECT s.student_name, d.name AS department_name
            FROM students s
            LEFT JOIN users u ON s.id = u.id
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE s.id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $sql = "SELECT name, department_id FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $user['student_name'] = $user['name'] ?? 'Student';
        $user['department_name'] = $user['department_id'] ? ($conn->query("SELECT name FROM departments WHERE id = {$user['department_id']}")->fetch_assoc()['name'] ?? 'No Department') : 'No Department';
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Error fetching student data for user_id: $user_id, Error: " . $e->getMessage());
    $error_msg = "Error loading profile: " . htmlspecialchars($e->getMessage());
}

// Fetch recent achievements
$achievements = [];
try {
    $sql = "SELECT sp.points_earned, b.name AS badge_name
            FROM student_progress sp
            LEFT JOIN badges b ON sp.badge_id = b.id
            WHERE sp.user_id = ?
            ORDER BY sp.created_at DESC
            LIMIT 3";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $achievements = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching achievements for user_id: $user_id, Error: " . $e->getMessage());
}

// Fetch chat history (all sessions)
$chat_history = [];
try {
    $sql = "SELECT lh.id, lh.prompt, lh.response, lh.created_at, lh.session_id
            FROM learning_history lh
            WHERE lh.user_id = ?
            ORDER BY lh.created_at DESC
            LIMIT 100";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $chat_history[$row['session_id']][] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching chat history for user_id: $user_id, Error: " . $e->getMessage());
    $error_msg = "Error loading chat history: " . htmlspecialchars($e->getMessage());
}

// Fetch current session messages
$current_session_messages = [];
try {
    $sql = "SELECT lh.id, lh.prompt, lh.response, lh.created_at
            FROM learning_history lh
            WHERE lh.user_id = ? AND lh.session_id = ?
            ORDER BY lh.created_at ASC
            LIMIT 50";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("is", $user_id, $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $current_session_messages[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching current session messages for user_id: $user_id, Error: " . $e->getMessage());
    $error_msg = "Error loading current session messages: " . htmlspecialchars($e->getMessage());
}

// Include header
if (!@include_once($_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php')) {
    error_log("Failed to include student_header.php");
    $error_msg = "Error loading page template.";
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="EduBot: AI Learning Assistant for QueensPa School students.">
    <meta name="keywords" content="QueensPa School, EduBot, AI assistant, student learning, chat">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School EduBot">
    <meta property="og:description" content="Chat with EduBot to learn about any academic topic.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/student/learn.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School EduBot">
    <meta name="twitter:description" content="Chat with EduBot to learn about any academic topic.">
    <title>EduBot: AI Learning Assistant - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.9.1/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.9.1/Draggable.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #ff6b00;
            --accent-color: #ffc107;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .chat-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            max-width: 900px;
            margin: 1rem auto;
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .chat-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: move;
        }
        .achievements {
            font-size: 0.85rem;
            max-width: 50%;
            text-align: right;
        }
        .chat-messages {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-color) #f8f9fa;
        }
        .chat-message {
            margin-bottom: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            max-width: 80%;
            line-height: 1.5;
        }
        .chat-message.user {
            background: #fef3e7;
            margin-left: auto;
            border: 1px solid var(--primary-color);
        }
        .chat-message.ai {
            background: #e6f3ff;
            margin-right: auto;
            border: 1px solid #007bff;
        }
        .chat-message.motivational {
            background: #fff3cd;
            border: 1px solid var(--accent-color);
            text-align: center;
            margin: 1rem auto;
            font-style: italic;
        }
        .chat-message p {
            margin: 0;
        }
        .chat-message .timestamp {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }
        .history-section {
            background: #f8f9fa;
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .history-session {
            margin-bottom: 1rem;
        }
        .history-toggle {
            background: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .history-toggle:hover {
            background: var(--secondary-color);
        }
        .history-content {
            display: none;
            padding: 1rem;
            background: white;
            border-radius: 4px;
            margin-top: 0.5rem;
        }
        .history-content.active {
            display: block;
        }
        .chat-input-container {
            padding: 1rem;
            background: #f8f9fa;
            border-top: 1px solid #e5e7eb;
        }
        .chat-input {
            resize: none;
            width: 100%;
            border-radius: 4px;
            border: 1px solid #ccc;
            padding: 0.5rem;
            font-size: 1rem;
        }
        .chat-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(255, 69, 0, 0.2);
        }
        .send-button, .clear-button {
            background: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            transition: all 0.3s ease;
            margin-left: 0.5rem;
        }
        .send-button:hover:not(:disabled), .clear-button:hover:not(:disabled) {
            background: var(--secondary-color);
            transform: scale(1.05);
        }
        .send-button:disabled, .clear-button:disabled {
            background: #cccccc;
            cursor: not-allowed;
        }
        .error {
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 0.5rem;
            text-align: center;
        }
        .alert {
            position: relative;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .alert-error {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #b91c1c;
        }
        .alert-success {
            background: #d1fae5;
            border: 1px solid #10b981;
            color: #065f46;
        }
        .alert button {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 1rem;
        }
        @media (max-width: 768px) {
            .chat-container {
                margin: 0.5rem;
                border-radius: 8px;
            }
            .chat-messages {
                padding: 1rem;
            }
            .chat-message {
                max-width: 90%;
            }
            .chat-input {
                font-size: 0.9rem;
            }
            .history-section {
                padding: 0.5rem;
            }
            .achievements {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="chat-container" id="chat-container">
        <div class="chat-header">
            <span class="text-lg font-semibold">EduBot: Your Learning Companion - <?php echo htmlspecialchars($user['student_name'] ?? 'Student'); ?></span>
            <div class="achievements">
                <?php if ($achievements): ?>
                    Recent Achievements: 
                    <?php foreach ($achievements as $ach): ?>
                        <?php echo htmlspecialchars($ach['badge_name'] ?? ($ach['points_earned'] ? "{$ach['points_earned']} points" : '')); ?><?php echo end($achievements) !== $ach ? ', ' : ''; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    No recent achievements.
                <?php endif; ?>
            </div>
        </div>
        <div class="history-section">
            <div class="flex justify-between items-center mb-2">
                <h3 class="text-lg font-semibold">Chat History</h3>
                <button class="clear-button" id="clear-history"><i class="fas fa-trash"></i> Clear Session</button>
            </div>
            <?php if (empty($chat_history)): ?>
                <p class="text-gray-500">No past conversations found. Start chatting with EduBot!</p>
            <?php else: ?>
                <?php foreach ($chat_history as $session_id => $messages): ?>
                    <div class="history-session">
                        <div class="history-toggle" data-session="<?php echo htmlspecialchars($session_id); ?>">
                            <span>Session <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($messages[0]['created_at']))); ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="history-content" id="history-<?php echo htmlspecialchars($session_id); ?>">
                            <?php foreach ($messages as $msg): ?>
                                <div class="chat-message user">
                                    <p><strong>You:</strong> <?php echo htmlspecialchars($msg['prompt']); ?></p>
                                    <p class="timestamp"><?php echo htmlspecialchars($msg['created_at']); ?></p>
                                </div>
                                <div class="chat-message ai">
                                    <p><strong>EduBot:</strong> <?php echo nl2br(htmlspecialchars($msg['response'])); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="chat-messages" id="chat-messages">
            <?php if ($error_msg): ?>
                <div class="alert alert-error animate__animated animate__shakeX">
                    <?php echo htmlspecialchars($error_msg); ?>
                    <button type="button" onclick="this.parentElement.remove()">✕</button>
                </div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="alert alert-success animate__animated animate__fadeIn">
                    <?php echo htmlspecialchars($success_msg); ?>
                    <button type="button" onclick="this.parentElement.remove()">✕</button>
                </div>
            <?php endif; ?>
            <div class="chat-message motivational animate__animated animate__fadeIn">
                <p id="motivational-quote"></p>
            </div>
            <?php foreach ($current_session_messages as $msg): ?>
                <div class="chat-message user animate__animated animate__fadeInRight">
                    <p><strong>You:</strong> <?php echo htmlspecialchars($msg['prompt']); ?></p>
                    <p class="timestamp"><?php echo htmlspecialchars($msg['created_at']); ?></p>
                </div>
                <div class="chat-message ai animate__animated animate__fadeInLeft">
                    <p><strong>EduBot:</strong> <?php echo nl2br(htmlspecialchars($msg['response'])); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="chat-input-container">
            <form id="ai-chat-form">
                <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($session_id); ?>">
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="flex gap-2">
                    <textarea name="prompt" id="prompt" class="chat-input" rows="2" placeholder="Ask EduBot anything academic (e.g., 'What is photosynthesis?' or 'Solve 2x + 3 = 7')"></textarea>
                    <button type="submit" class="send-button" id="send-button"><i class="fas fa-paper-plane"></i></button>
                </div>
                <div id="prompt-error" class="error"></div>
            </form>
        </div>
    </div>
    <?php 
    if (!@include_once($_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php')) {
        error_log("Failed to include student_footer.php");
        $error_msg = "Error loading page template.";
    }
    $conn->close();
    ob_end_flush();
    ?>

    <script>
        // Motivational quotes
        const quotes = [
            "Your curiosity fuels your success!",
            "Every question you ask is a step toward knowledge.",
            "Keep pushing forward; your hard work will pay off!",
            "Learning today builds a brighter tomorrow.",
            "Mistakes are proof that you're trying—keep going!",
            "Your effort today is the foundation for your future.",
            "Stay focused, and you'll achieve greatness.",
            "One small step today leads to big successes tomorrow.",
            "Embrace challenges—they make you stronger!",
            "Believe in yourself, and you'll conquer any challenge!",
            "Success starts with showing up every day.",
            "Your potential is limitless—explore it!",
            "Every setback is a setup for a comeback.",
            "You are capable of amazing things.",
            "Progress is progress, no matter how small.",
            "Your dreams deserve your dedication.",
            "Confidence grows with every challenge you face.",
            "You’re writing your own success story—keep going!",
            "Hard work beats talent when talent doesn’t work hard.",
            "You’ve got this—one step at a time.",
            "Push past the doubt and trust your journey.",
            "Your mindset shapes your reality.",
            "Be proud of how far you’ve come.",
            "Small wins lead to big victories.",
            "You’re stronger than you think.",
            "Let your passion be your compass.",
            "Every day is a new chance to grow.",
            "Your energy creates your environment.",
            "Stay curious, stay driven.",
            "You’re building something powerful—keep at it.",
            "Success is built on consistency.",
            "Your effort matters more than perfection.",
            "You’re not behind—you’re on your own path.",
            "Keep learning, keep evolving.",
            "Your focus is your superpower.",
            "Celebrate progress, not just results.",
            "You’re becoming the person you admire.",
            "Let your goals guide your actions.",
            "You’re a creator—make something meaningful.",
            "Your work has impact—don’t forget that.",
            "You’re growing through every challenge.",
            "Stay grounded, stay ambitious.",
            "You’re the architect of your future.",
            "Let your resilience shine.",
            "You’re turning effort into excellence.",
            "Your dedication inspires others.",
            "Keep showing up for yourself.",
            "You’re building momentum—don’t stop now.",
            "Your ideas matter—share them boldly.",
            "You’re learning faster than you realize.",
            "Let your grit lead the way.",
            "You’re not alone—your effort echoes.",
            "You’re shaping your legacy with every step.",
            "Let your vision fuel your discipline.",
            "You’re mastering your craft—one day at a time.",
            "Your creativity is your strength.",
            "You’re making progress even when it’s invisible.",
            "Let your values guide your work.",
            "You’re capable of solving hard problems.",
            "Your persistence is your power.",
            "You’re building something worth being proud of.",
            "Let your purpose drive your process.",
            "You’re turning challenges into opportunities.",
            "Your growth is your greatest investment.",
            "You’re becoming unstoppable.",
            "Let your effort speak louder than your doubts.",
            "You’re creating your own momentum.",
            "Your discipline is your freedom.",
            "You’re rising—keep climbing.",
            "Let your passion lead your progress.",
            "You’re rewriting your limits.",
            "Your consistency creates confidence.",
            "You’re making the impossible possible.",
            "Let your courage guide your choices.",
            "You’re leveling up every day.",
            "Your work is a reflection of your strength.",
            "You’re building a foundation for greatness.",
            "Let your ambition be your anchor.",
            "You’re turning vision into reality.",
            "Your effort today shapes your tomorrow.",
            "You’re becoming a master of your mindset.",
            "Let your hustle be your habit.",
            "You’re creating a ripple of excellence.",
            "Your journey is worth celebrating.",
            "You’re proving your potential daily.",
            "Let your focus silence the noise.",
            "You’re designing your destiny.",
            "Your momentum is magnetic.",
            "You’re crafting a life of meaning.",
            "Let your goals stretch your limits.",
            "You’re transforming effort into achievement.",
            "Your work ethic is your edge.",
            "You’re building brilliance from the inside out.",
            "Let your drive define your direction.",
            "You’re cultivating greatness.",
            "Your ambition is your advantage.",
            "You’re becoming who you were meant to be.",
            "Let your progress be your proof.",
            "You’re turning effort into elevation.",
            "Your growth is your gift.",
            "You’re making mastery your mission.",
            "Let your purpose push you forward.",
            "You’re unstoppable when you believe.",
            "Your journey is your masterpiece.",
            "You’re creating impact with intention.",
            "Let your fire fuel your focus.",
            "You’re rising with resilience.",
            "Your story is just beginning."
        ];

        // Display random motivational quote
        document.getElementById('motivational-quote').innerText = quotes[Math.floor(Math.random() * quotes.length)];

        // Initialize GSAP Draggable
        Draggable.create("#chat-container", {
            trigger: ".chat-header",
            bounds: "body",
            inertia: true
        });

        // Scroll to bottom of chat
        function scrollToBottom() {
            const chatMessages = document.getElementById('chat-messages');
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        scrollToBottom();

        // Toggle history sections
        document.querySelectorAll('.history-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const sessionId = this.getAttribute('data-session');
                const content = document.getElementById(`history-${sessionId}`);
                const icon = this.querySelector('i');
                if (content.classList.contains('active')) {
                    gsap.to(content, { height: 0, opacity: 0, duration: 0.3, onComplete: () => {
                        content.classList.remove('active');
                        content.style.display = 'none';
                        icon.classList.replace('fa-chevron-up', 'fa-chevron-down');
                    }});
                } else {
                    content.style.display = 'block';
                    gsap.fromTo(content, 
                        { height: 0, opacity: 0 }, 
                        { height: 'auto', opacity: 1, duration: 0.3, onComplete: () => {
                            content.classList.add('active');
                            icon.classList.replace('fa-chevron-down', 'fa-chevron-up');
                        }}
                    );
                }
            });
        });

        // Handle form submission
        $(document).ready(function() {
            $('#ai-chat-form').submit(function(e) {
                e.preventDefault();
                const prompt = $('#prompt').val().trim();
                const sessionId = $('input[name="session_id"]').val();
                const csrfToken = $('input[name="csrf_token"]').val();
                const sendButton = $('#send-button');

                if (!prompt) {
                    $('#prompt-error').text('Please enter a question');
                    return;
                }
                $('#prompt-error').text('');
                sendButton.prop('disabled', true);

                // Add user message
                const userMessage = document.createElement('div');
                userMessage.className = 'chat-message user animate__animated animate__fadeInRight';
                const userText = document.createElement('p');
                userText.innerHTML = `<strong>You:</strong> ${document.createTextNode(prompt).textContent}`;
                const timestamp = document.createElement('p');
                timestamp.className = 'timestamp';
                timestamp.textContent = new Date().toLocaleString();
                userMessage.appendChild(userText);
                userMessage.appendChild(timestamp);
                $('#chat-messages').append(userMessage);
                scrollToBottom();

                // Send AJAX request
                $.ajax({
                    url: '/student/learn.php',
                    type: 'POST',
                    data: {
                        action: 'send_message',
                        prompt: prompt,
                        session_id: sessionId,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            const aiMessage = `
                                <div class="chat-message ai animate__animated animate__fadeInLeft">
                                    <p><strong>EduBot:</strong> ${response.response}</p>
                                </div>
                            `;
                            $('#chat-messages').append(aiMessage);
                            scrollToBottom();
                            $('#prompt').val('');
                        } else {
                            $('#prompt-error').html(response.error || 'Failed to get EduBot response. Please try again.');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#prompt-error').html('Error communicating with EduBot: ' + error + '. Please <a href="javascript:location.reload()">refresh</a>.');
                        console.log('AJAX Error:', xhr.responseText);
                    },
                    complete: function() {
                        sendButton.prop('disabled', false);
                    }
                });
            });

            // Handle clear history
            $('#clear-history').click(function() {
                if (confirm('Are you sure you want to clear this session’s chat history?')) {
                    $.ajax({
                        url: '/student/learn.php',
                        type: 'POST',
                        data: {
                            action: 'clear_history',
                            session_id: '<?php echo htmlspecialchars($session_id); ?>',
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                $('#chat-messages').html(`
                                    <div class="chat-message motivational animate__animated animate__fadeIn">
                                        <p id="motivational-quote">${quotes[Math.floor(Math.random() * quotes.length)]}</p>
                                    </div>
                                `);
                                $('#prompt-error').html('<div class="alert alert-success animate__animated animate__fadeIn">' + response.message + '<button type="button" onclick="this.parentElement.remove()">✕</button></div>');
                                scrollToBottom();
                            } else {
                                $('#prompt-error').html(response.error || 'Failed to clear history. Please try again.');
                            }
                        },
                        error: function(xhr, status, error) {
                            $('#prompt-error').html('Error clearing history: ' + error + '. Please try again.');
                        }
                    });
                }
            });

            // Auto-resize textarea
            $('#prompt').on('input', function() {
                this.style.height = 'auto';
                this.style.height = `${this.scrollHeight}px`;
            });
        });
    </script>
</body>
</html>