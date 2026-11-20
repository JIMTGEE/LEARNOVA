<?php
// Start the session if not already started
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
require_once '../config/db.php';
$db = db(); // Use the helper function to get PDO instance

// Include helpers and functions
require_once '../includes/helpers.php';
require_once '../functions/csrf.php';
require_once '../functions/log_activity.php';

// Handle manual attendance marking (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_manual') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $class_id = intval($_POST['class_id'] ?? 0);
    $date = date('Y-m-d'); // Current date
    $attendances = $_POST['attendance'] ?? []; // Array of user_id => status

    foreach ($attendances as $user_id => $status) {
        if (in_array($status, ['present', 'absent', 'late'])) {
            // Check if already marked
            $check_stmt = $db->prepare("SELECT id FROM attendance WHERE class_id = ? AND user_id = ? AND date = ?");
            $check_stmt->execute([$class_id, $user_id, $date]);
            if (!$check_stmt->fetch()) {
                $stmt = $db->prepare("INSERT INTO attendance (class_id, user_id, date, status, type) VALUES (?, ?, ?, ?, 'in-person')");
                $stmt->execute([$class_id, $user_id, $date, $status]);
                log_activity($_SESSION['user_id'], 'mark_attendance', "Marked attendance for student ID: $user_id in class $class_id as $status");
            }
        }
    }
    echo json_encode(['success' => true, 'message' => 'Attendance marked successfully.']);
    exit();
}

// Handle facial recognition marking (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_facial') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $class_id = intval($_POST['class_id'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? 0);
    $date = date('Y-m-d');
    $status = 'present';

    if ($user_id > 0) {
        // Check if already marked
        $check_stmt = $db->prepare("SELECT id FROM attendance WHERE class_id = ? AND user_id = ? AND date = ?");
        $check_stmt->execute([$class_id, $user_id, $date]);
        if (!$check_stmt->fetch()) {
            $stmt = $db->prepare("INSERT INTO attendance (class_id, user_id, date, status, type) VALUES (?, ?, ?, ?, 'in-person')");
            $stmt->execute([$class_id, $user_id, $date, $status]);
            log_activity($_SESSION['user_id'], 'mark_attendance_facial', "Marked attendance via facial recognition for student ID: $user_id in class $class_id as $status");
            echo json_encode(['success' => true, 'message' => 'Attendance marked for recognized student.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Attendance already marked for this student today.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid student ID.']);
    }
    exit();
}

// Fetch classes
$classes_stmt = $db->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch students for selected class (via GET or default to first)
$selected_class_id = intval($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$students = [];
if ($selected_class_id > 0) {
    // Fetch students in the class (using student_classes for accuracy, assuming current academic year)
    $current_year_stmt = $db->query("SELECT id FROM academic_years ORDER BY year DESC LIMIT 1");
    $current_year_id = $current_year_stmt->fetchColumn() ?? 0;

    $students_stmt = $db->prepare("
        SELECT u.id, u.name, u.profile_picture 
        FROM users u 
        JOIN student_classes sc ON u.id = sc.user_id 
        WHERE sc.class_id = ? AND sc.academic_year_id = ? AND u.role = 'student'
    ");
    $students_stmt->execute([$selected_class_id, $current_year_id]);
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="attendance-management">
        <h1 class="page-title">Mark Student Attendance</h1>

        <!-- Class Selection -->
        <form class="class-select-form" method="GET">
            <label>Select Class:</label>
            <select name="class_id" onchange="this.form.submit()">
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id === $class['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($class['class_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <!-- Manual Attendance Section -->
        <div class="manual-attendance">
            <h2>Manual Attendance Marking</h2>
            <form id="manual-attendance-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="mark_manual">
                <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Late</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="4">No students found in this class.</td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['name']); ?></td>
                                    <td><input type="radio" name="attendance[<?php echo $student['id']; ?>]" value="present" checked></td>
                                    <td><input type="radio" name="attendance[<?php echo $student['id']; ?>]" value="absent"></td>
                                    <td><input type="radio" name="attendance[<?php echo $student['id']; ?>]" value="late"></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <button type="submit">Save Manual Attendance</button>
            </form>
        </div>

        <!-- Facial Recognition Section -->
        <div class="facial-attendance">
            <h2>Facial Recognition Attendance</h2>
            <p>Launch the camera to scan students' faces. The system will automatically recognize and mark them as present if a match is found (threshold: 0.6 Euclidean distance).</p>
            <button id="start-camera">Start Facial Recognition</button>
            <button id="stop-camera" style="display: none;">Stop Facial Recognition</button>
            <div id="video-container" style="display: none;">
                <video id="video" width="640" height="480" autoplay></video>
                <canvas id="canvas" style="display: none;"></canvas>
            </div>
            <div id="recognition-log"></div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/face-landmarks-detection@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.js"></script>
    <script>
        // Load face-api models (assume models are hosted in /assets/models/)
        Promise.all([
            faceapi.nets.ssdMobilenetv1.loadFromUri('/assets/models/'),
            faceapi.nets.faceLandmark68Net.loadFromUri('/assets/models/'),
            faceapi.nets.faceRecognitionNet.loadFromUri('/assets/models/')
        ]).then(() => {
            console.log('Face-api models loaded.');
        }).catch(err => {
            console.error('Error loading models:', err);
            alert('Failed to load facial recognition models. Please check console for details.');
        });

        // Preload known faces (students' profile pictures and descriptors)
        const knownFaces = [];
        async function loadKnownFaces() {
            const students = <?php echo json_encode($students); ?>;
            for (const student of students) {
                try {
                    const img = await faceapi.fetchImage(student.profile_picture || '/assets/images/default-profile.png');
                    const detection = await faceapi.detectSingleFace(img).withFaceLandmarks().withFaceDescriptor();
                    if (detection) {
                        knownFaces.push({
                            label: student.id,
                            name: student.name,
                            descriptor: detection.descriptor
                        });
                    }
                } catch (err) {
                    console.warn(`Failed to load face for ${student.name}:`, err);
                }
            }
            console.log('Known faces loaded:', knownFaces.length);
            if (knownFaces.length === 0) {
                alert('No recognizable faces loaded. Ensure student profiles have clear face images.');
            }
        }
        loadKnownFaces();

        // Start camera and continuous recognition
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const log = document.getElementById('recognition-log');
        let recognitionInterval;
        let stream;

        document.getElementById('start-camera').addEventListener('click', async () => {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: true });
                video.srcObject = stream;
                document.getElementById('video-container').style.display = 'block';
                document.getElementById('stop-camera').style.display = 'inline-block';
                document.getElementById('start-camera').style.display = 'none';

                // Continuous recognition every 1 second (no timeout, runs indefinitely until stopped)
                recognitionInterval = setInterval(async () => {
                    const detections = await faceapi.detectAllFaces(video).withFaceLandmarks().withFaceDescriptors();
                    if (detections.length > 0) {
                        for (const detection of detections) {
                            const bestMatch = findBestMatch(detection.descriptor);
                            if (bestMatch && bestMatch.distance < 0.6) { // Threshold for match
                                log.innerHTML += `<p>Recognized: ${bestMatch.name} (ID: ${bestMatch.label}, Distance: ${bestMatch.distance.toFixed(2)}) - Marking as present.</p>`;
                                markFacialAttendance(bestMatch.label);
                            } else {
                                log.innerHTML += `<p>No match found (Min distance: ${minDistance?.toFixed(2) || 'N/A'}).</p>`;
                            }
                        }
                    } else {
                        log.innerHTML += `<p>No faces detected in frame.</p>`;
                    }
                    log.scrollTop = log.scrollHeight; // Auto-scroll
                }, 1000); // Scan every second, adjustable
            } catch (err) {
                console.error('Error accessing camera:', err);
                alert('Unable to access camera. Please check permissions and ensure HTTPS if remote.');
            }
        });

        // Stop camera and recognition
        document.getElementById('stop-camera').addEventListener('click', () => {
            clearInterval(recognitionInterval);
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
            video.srcObject = null;
            document.getElementById('video-container').style.display = 'none';
            document.getElementById('stop-camera').style.display = 'none';
            document.getElementById('start-camera').style.display = 'inline-block';
            log.innerHTML += `<p>Facial recognition stopped.</p>`;
        });

        // Find best match using Euclidean distance
        function findBestMatch(descriptor) {
            let bestMatch = null;
            let minDistance = Infinity;
            for (const known of knownFaces) {
                const distance = faceapi.euclideanDistance(descriptor, known.descriptor);
                if (distance < minDistance) {
                    minDistance = distance;
                    bestMatch = { ...known, distance };
                }
            }
            return bestMatch;
        }

        // AJAX to mark attendance
        function markFacialAttendance(user_id) {
            const formData = new FormData();
            formData.append('action', 'mark_facial');
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('class_id', <?php echo $selected_class_id; ?>);
            formData.append('user_id', user_id);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).then(response => response.json())
              .then(data => {
                  if (data.success) {
                      log.innerHTML += `<p class="success">${data.message}</p>`;
                  } else {
                      log.innerHTML += `<p class="error">${data.message}</p>`;
                  }
                  log.scrollTop = log.scrollHeight;
              }).catch(err => {
                  console.error('Error marking attendance:', err);
                  log.innerHTML += `<p class="error">Error marking attendance: ${err.message}</p>`;
              });
        }

        // Handle manual form submission via AJAX
        document.getElementById('manual-attendance-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).then(response => response.json())
              .then(data => {
                  alert(data.message);
                  if (data.success) {
                      location.reload(); // Refresh to show updates if needed
                  }
              }).catch(err => {
                  console.error('Error:', err);
                  alert('Error saving attendance.');
              });
        });
    </script>

    <style>
        /* Attendance Styles */
        .attendance-management { padding: 20px; }
        .class-select-form { margin-bottom: 20px; }
        .class-select-form select { padding: 10px; border-radius: 4px; border: 1px solid var(--accent-slate-gray); }
        .manual-attendance, .facial-attendance { margin-bottom: 40px; background: var(--primary-white); padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .data-table th, .data-table td { padding: 12px; text-align: center; border-bottom: 1px solid rgba(0,0,0,0.1); }
        #start-camera, #stop-camera { background: var(--primary-sea-blue); color: var(--primary-white); padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; margin-right: 10px; }
        #stop-camera { background: var(--accent-slate-gray); }
        #video-container { margin-top: 20px; border: 1px solid var(--accent-slate-gray); border-radius: 4px; overflow: hidden; }
        #recognition-log { margin-top: 20px; max-height: 300px; overflow-y: auto; padding: 10px; background: #f8f9fa; border-radius: 4px; }
        #recognition-log p { margin: 5px 0; font-size: 14px; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
    </style>

<?php include '../templates/admin_footer.php'; ?>