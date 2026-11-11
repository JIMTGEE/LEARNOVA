<?php
ob_start(); // Start output buffering
session_start();

// Enable error logging, disable display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');

// Restrict to students only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    error_log("Unauthorized access attempt to games.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'));
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

// Check if games table exists
try {
    $result = $conn->query("SHOW TABLES LIKE 'games'");
    if ($result->num_rows === 0) {
        $create_table = "CREATE TABLE games (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,
            image_url VARCHAR(255) NOT NULL,
            game_url VARCHAR(255) NOT NULL,
            category VARCHAR(50) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB";
        $conn->query($create_table) or throw new Exception("Failed to create games table: " . $conn->error);
        log_activity($conn, $user_id, 'Table Creation', 'Created games table');
    }
} catch (Exception $e) {
    error_log("Error checking/creating games table: " . $e->getMessage());
    die(json_encode(['error' => 'Failed to initialize database. Please contact support.']));
}

// Sync games from /student/games directory with database
$games_dir = $_SERVER['DOCUMENT_ROOT'] . '/student/games';
$default_games = [
    [
        'title' => 'Solar System Explorer Quest',
        'description' => 'Explore planets in a 3D solar system, answer quizzes, and try the Orbit Challenge!',
        'image_url' => '/images/solar_system_quest.jpg',
        'game_url' => '/student/games/solar-system-quest.html',
        'category' => 'Science'
    ],
    [
        'title' => 'Ultimate Math Quest',
        'description' => 'Embark on a Ghanaian market-inspired math adventure with varying difficulty levels.',
        'image_url' => '/images/math_quiz.jpg',
        'game_url' => '/student/games/math-quiz.html',
        'category' => 'Math'
    ],
    [
        'title' => '3D Word Search Adventure',
        'description' => 'Find words in a 3D cube with Ghanaian-inspired themes like Science and History.',
        'image_url' => '/images/word_search.jpg',
        'game_url' => '/student/games/word-search.html',
        'category' => 'Language'
    ],
    [
        'title' => 'Memory Match Mastery',
        'description' => 'Match cards with Ghanaian learning themes in this memory challenge.',
        'image_url' => '/images/memory_match.jpg',
        'game_url' => '/student/games/memory-match.html',
        'category' => 'Memory'
    ]
];

try {
    // Scan games directory for .html files
    $game_files = glob($games_dir . '/*.html');
    $existing_game_urls = array_column($default_games, 'game_url');
    
    // Add any new games from directory to default_games
    foreach ($game_files as $file) {
        $relative_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $file);
        if (!in_array($relative_path, $existing_game_urls)) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            $title = ucwords(str_replace('-', ' ', $filename));
            $default_games[] = [
                'title' => $title,
                'description' => "Enjoy the exciting $title game to enhance your learning!",
                'image_url' => '/images/' . $filename . '.jpg',
                'game_url' => $relative_path,
                'category' => 'General'
            ];
        }
    }

    // Sync default games with database
    foreach ($default_games as $game) {
        $stmt = $conn->prepare("SELECT id FROM games WHERE game_url = ?");
        $stmt->bind_param("s", $game['game_url']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO games (title, description, image_url, game_url, category) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $game['title'], $game['description'], $game['image_url'], $game['game_url'], $game['category']);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, $user_id, 'Game Added', "Added game: {$game['title']}");
        } else {
            $stmt->close();
        }
    }
} catch (Exception $e) {
    error_log("Error syncing games from directory: " . $e->getMessage());
    $error_msg = "Error syncing games. Please contact support.";
}

// Handle game play tracking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'play_game') {
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

    $game_id = (int)($_POST['game_id'] ?? 0);
    try {
        $sql = "INSERT INTO game_plays (user_id, game_id, played_at) VALUES (?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("ii", $user_id, $game_id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, $user_id, 'Game Play', "Played game ID: $game_id");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log("Error tracking game play for user_id: $user_id, game_id: $game_id, Error: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to track game play. Please try again.']);
    }
    exit();
}

// Fetch games
$games = [];
try {
    $sql = "SELECT id, title, description, image_url, game_url, category FROM games WHERE game_url IN (SELECT game_url FROM games WHERE EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'games')) ORDER BY created_at DESC";
    $result = $conn->query($sql);
    if (!$result) throw new Exception("Query failed: " . $conn->error);
    while ($row = $result->fetch_assoc()) {
        // Verify game file exists
        $file_path = $_SERVER['DOCUMENT_ROOT'] . $row['game_url'];
        if (file_exists($file_path)) {
            $games[] = $row;
        } else {
            error_log("Game file not found: " . $row['game_url']);
        }
    }
} catch (Exception $e) {
    error_log("Error fetching games for user_id: $user_id, Error: " . $e->getMessage());
    $error_msg = "Error loading games: " . htmlspecialchars($e->getMessage());
}

// Fetch student details
$student_name = 'Student';
try {
    $sql = "SELECT student_name FROM students WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $student_name = $user['student_name'] ?? '';
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
} catch (Exception $e) {
    error_log("Error fetching student data for user_id: $user_id, Error: " . $e->getMessage());
    $error_msg = "Error loading profile: " . htmlspecialchars($e->getMessage());
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
    <meta name="description" content="Learning Games for QueensPa School students.">
    <meta name="keywords" content="QueensPa School, learning games, educational games, math games">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Learning Games">
    <meta property="og:description" content="Play educational games to enhance your learning experience.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/student/games.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Learning Games">
    <meta name="twitter:description" content="Play educational games to enhance your learning experience.">
    <title>Learning Games - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.9.1/gsap.min.js"></script>
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
            min-height: 100vh;
        }
        .games-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .games-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .games-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        .game-card {
            background: white;
            border-radius: 8px;
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        .game-card:hover {
            transform: translateY(-5px);
        }
        .game-preview {
            width: 100%;
            height: 150px;
            border: none;
            background: #f0f0f0;
        }
        .game-content {
            padding: 1rem;
        }
        .game-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }
        .game-description {
            font-size: 0.9rem;
            color: #4b5563;
            margin-bottom: 1rem;
            line-height: 1.4;
        }
        .play-button {
            background: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-align: center;
            display: inline-block;
            transition: background 0.3s ease;
        }
        .play-button:hover {
            background: var(--secondary-color);
        }
        .play-button:disabled {
            background: #cccccc;
            cursor: not-allowed;
        }
        .error {
            color: #dc3545;
            font-size: 0.875rem;
            text-align: center;
            margin-top: 1rem;
        }
        .alert {
            position: relative;
            padding: 1rem;
            margin: 1rem auto;
            max-width: 600px;
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
        .alert button {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 1rem;
        }
        @media (max-width: 768px) {
            .games-grid {
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            }
            .game-preview {
                height: 120px;
            }
            .game-title {
                font-size: 1.1rem;
            }
            .game-description {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="games-container">
        <div class="games-header">
            <h1 class="text-2xl font-semibold">Learning Games - Welcome, <?php echo htmlspecialchars($student_name ?? 'Student'); ?>!</h1>
            <p class="text-sm mt-1">Explore fun and educational games to boost your skills.</p>
        </div>
        <?php if ($error_msg): ?>
            <div class="alert alert-error animate__animated animate__shakeX">
                <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
        <?php endif; ?>
        <div class="games-grid">
            <?php foreach ($games as $game): ?>
                <div class="game-card animate__animated animate__fadeIn">
                    <iframe src="<?php echo htmlspecialchars($game['game_url']); ?>" class="game-preview" sandbox="allow-same-origin" title="<?php echo htmlspecialchars($game['title']); ?> Preview"></iframe>
                    <div class="game-content">
                        <h2 class="game-title"><?php echo htmlspecialchars($game['title']); ?></h2>
                        <p class="game-description"><?php echo htmlspecialchars($game['description']); ?></p>
                        <a href="<?php echo htmlspecialchars($game['game_url']); ?>" class="play-button" data-game-id="<?php echo $game['id']; ?>" target="_blank"><i class="fas fa-play"></i> Play Now</a>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($games)): ?>
                <p class="text-gray-500 text-center col-span-full">No games available yet. Check back soon!</p>
            <?php endif; ?>
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
        $(document).ready(function() {
            // Track game plays
            $('.play-button').click(function(e) {
                const gameId = $(this).data('game-id');
                $.ajax({
                    url: '/student/games.php',
                    type: 'POST',
                    data: {
                        action: 'play_game',
                        game_id: gameId,
                        csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                    },
                    contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
                    dataType: 'json',
                    success: function(response) {
                        if (!response.success) {
                            console.log('Error tracking game play:', response.error);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX Error:', xhr.responseText);
                    }
                });
            });
        });
    </script>
</body>
</html>