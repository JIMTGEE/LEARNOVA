-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 01, 2025 at 10:17 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `edusync`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_progress_entries`
--

CREATE TABLE `academic_progress_entries` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `mock_number` tinyint(2) UNSIGNED DEFAULT 0,
  `academic_year_id` int(11) NOT NULL,
  `entry_period` enum('weekly','bi-weekly') NOT NULL,
  `period_start_date` date NOT NULL,
  `period_end_date` date NOT NULL,
  `class_practices_raw` decimal(5,2) DEFAULT 0.00,
  `class_practices_max` decimal(5,2) DEFAULT 0.00,
  `assignments_raw` decimal(5,2) DEFAULT 0.00,
  `assignments_max` decimal(5,2) DEFAULT 0.00,
  `cat_raw` decimal(5,2) DEFAULT 0.00,
  `cat_max` decimal(5,2) DEFAULT 0.00,
  `group_project_raw` decimal(5,2) DEFAULT 0.00,
  `group_project_max` decimal(5,2) DEFAULT 0.00,
  `scaled_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scaled_scores`)),
  `total_scaled` decimal(5,2) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `status` enum('draft','submitted','reviewed') DEFAULT 'draft',
  `bulk_max_input` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `academic_progress_entries`
--
DELIMITER $$
CREATE TRIGGER `after_academic_progress_update` AFTER UPDATE ON `academic_progress_entries` FOR EACH ROW BEGIN
    IF OLD.class_practices_raw != NEW.class_practices_raw OR 
       OLD.assignments_raw != NEW.assignments_raw OR 
       OLD.cat_raw != NEW.cat_raw OR 
       OLD.group_project_raw != NEW.group_project_raw OR
       OLD.class_practices_max != NEW.class_practices_max OR 
       OLD.assignments_max != NEW.assignments_max OR 
       OLD.cat_max != NEW.cat_max OR 
       OLD.group_project_max != NEW.group_project_max THEN
        INSERT INTO academic_progress_logs (
            entry_id, 
            changed_by, 
            old_scores, 
            new_scores, 
            change_reason
        )
        VALUES (
            NEW.id,
            NEW.teacher_id,
            JSON_OBJECT(
                'class_practices_raw', OLD.class_practices_raw,
                'class_practices_max', OLD.class_practices_max,
                'assignments_raw', OLD.assignments_raw,
                'assignments_max', OLD.assignments_max,
                'cat_raw', OLD.cat_raw,
                'cat_max', OLD.cat_max,
                'group_project_raw', OLD.group_project_raw,
                'group_project_max', OLD.group_project_max
            ),
            JSON_OBJECT(
                'class_practices_raw', NEW.class_practices_raw,
                'class_practices_max', NEW.class_practices_max,
                'assignments_raw', NEW.assignments_raw,
                'assignments_max', NEW.assignments_max,
                'cat_raw', NEW.cat_raw,
                'cat_max', NEW.cat_max,
                'group_project_raw', NEW.group_project_raw,
                'group_project_max', NEW.group_project_max
            ),
            'Score update by teacher'
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `before_academic_progress_insert` BEFORE INSERT ON `academic_progress_entries` FOR EACH ROW BEGIN
    DECLARE scaled_class_practices DECIMAL(5,2);
    DECLARE scaled_assignments DECIMAL(5,2);
    DECLARE scaled_cat DECIMAL(5,2);
    DECLARE scaled_group_project DECIMAL(5,2);
    
    -- Calculate scaled scores (raw/max * 25, handle zero max)
    SET scaled_class_practices = IF(NEW.class_practices_max > 0, 
        (NEW.class_practices_raw / NEW.class_practices_max) * 25, 0);
    SET scaled_assignments = IF(NEW.assignments_max > 0, 
        (NEW.assignments_raw / NEW.assignments_max) * 25, 0);
    SET scaled_cat = IF(NEW.cat_max > 0, 
        (NEW.cat_raw / NEW.cat_max) * 25, 0);
    SET scaled_group_project = IF(NEW.group_project_max > 0, 
        (NEW.group_project_raw / NEW.group_project_max) * 25, 0);
    
    -- Store scaled scores in JSON
    SET NEW.scaled_scores = JSON_OBJECT(
        'class_practices', scaled_class_practices,
        'assignments', scaled_assignments,
        'cat', scaled_cat,
        'group_project', scaled_group_project
    );
    
    -- Calculate total scaled score
    SET NEW.total_scaled = scaled_class_practices + scaled_assignments + 
                          scaled_cat + scaled_group_project;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `academic_progress_logs`
--

CREATE TABLE `academic_progress_logs` (
  `id` int(11) NOT NULL,
  `entry_id` int(11) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `old_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_scores`)),
  `new_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_scores`)),
  `change_reason` text DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `academic_years`
--

CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL,
  `year` varchar(9) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity` text DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores admin activity logs for QueensPa School';

-- --------------------------------------------------------

--
-- Table structure for table `advisor_appointments`
--

CREATE TABLE `advisor_appointments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `advisor_id` int(11) DEFAULT NULL,
  `appointment_date` datetime NOT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('pending','approved','cancelled','completed') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores advisor meeting appointments';

-- --------------------------------------------------------

--
-- Table structure for table `annual_plans`
--

CREATE TABLE `annual_plans` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `week` int(11) NOT NULL CHECK (`week` >= 1 and `week` <= 16),
  `term_1` text DEFAULT NULL,
  `term_2` text DEFAULT NULL,
  `term_3` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `format` enum('oxford','ges') NOT NULL DEFAULT 'oxford'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_form`
--

CREATE TABLE `application_form` (
  `id` int(11) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `date_of_birth` date NOT NULL,
  `gender` enum('Male','Female','Other') NOT NULL,
  `address` text DEFAULT NULL,
  `department` varchar(255) NOT NULL,
  `position_applied` varchar(255) NOT NULL,
  `status` enum('Pending','Accepted','Rejected') DEFAULT 'Pending',
  `application_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL COMMENT 'References classes.id for the associated class',
  `subject_id` int(11) NOT NULL COMMENT 'References subjects.id for the subject',
  `teacher_id` int(11) NOT NULL COMMENT 'References users.id for the teacher creating the assignment',
  `term_id` int(11) NOT NULL COMMENT 'References academic_calendar.id for the term',
  `title` varchar(255) NOT NULL COMMENT 'Title of the assignment, quiz, or test',
  `description` text DEFAULT NULL COMMENT 'Detailed description or instructions',
  `type` enum('quiz','test','homework','classwork','group_work','essay','multiple_choice','short_answer','file_upload') NOT NULL COMMENT 'Type of assignment or question',
  `questions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of questions, each with: question_text (string), type (enum: multiple_choice, short_answer, essay, file_upload), options (array of strings for multiple_choice, null otherwise), correct_answer (string/array for multiple_choice/short_answer, null for essay/file_upload), points (decimal), instructions (string, optional)' CHECK (json_valid(`questions`)),
  `max_points` decimal(5,2) NOT NULL DEFAULT 100.00 COMMENT 'Maximum points for the assignment',
  `file_path` varchar(255) DEFAULT NULL COMMENT 'Path to attached file (e.g., PDF instructions)',
  `link_url` varchar(500) DEFAULT NULL,
  `is_group_assignment` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if group assignment, 0 if individual',
  `group_id` int(11) DEFAULT NULL COMMENT 'References groups.id for group assignments, if applicable',
  `status` enum('draft','published','closed') NOT NULL DEFAULT 'draft' COMMENT 'Assignment lifecycle status',
  `is_graded` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if fully graded, 0 otherwise',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Creation timestamp',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'Last update timestamp',
  `tags` varchar(255) DEFAULT NULL COMMENT 'Comma-separated tags for assignment categorization',
  `due_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores assignments, quizzes, tests, and questions set by teachers with updated question structure';

-- --------------------------------------------------------

--
-- Table structure for table `assignment_questions`
--

CREATE TABLE `assignment_questions` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_number` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assignment_submissions`
--

CREATE TABLE `assignment_submissions` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL COMMENT 'References assignments.id for the assignment',
  `user_id` int(11) NOT NULL COMMENT 'References users.id for the student submitting (individual or group representative)',
  `group_id` int(11) DEFAULT NULL COMMENT 'References groups.id for group submissions, if applicable',
  `answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON object mapping question indices or IDs to response objects, e.g., {"0": {"response": "4", "score": 5.00, "comment": "Correct"}, "1": {"response": "Paris", "score": 3.00, "comment": "Well done"}} for multiple-choice or short-answer' CHECK (json_valid(`answers`)),
  `submission_text` text CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Text-based submission content, e.g., for essays or open-ended questions',
  `file_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of file paths for file-upload question responses, e.g., ["/uploads/file1.pdf"]' CHECK (json_valid(`file_paths`)),
  `submission_date` datetime DEFAULT NULL COMMENT 'Date and time of submission',
  `late_submission_reason` text CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Reason provided by student for late submission, if applicable',
  `score` decimal(5,2) DEFAULT NULL COMMENT 'Overall score for the submission, aligned with assignments.max_points',
  `feedback` text DEFAULT NULL COMMENT 'Overall teacher feedback for the submission',
  `graded_by` int(11) DEFAULT NULL COMMENT 'References users.id for the teacher who graded the submission',
  `graded_at` datetime DEFAULT NULL COMMENT 'Date and time when the submission was graded',
  `status` enum('pending','submitted','graded','late','returned') CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'Submission status: pending (draft), submitted, graded, late, or returned (needs resubmission)',
  `is_graded` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if graded, 0 otherwise',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Creation timestamp',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'Last update timestamp'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores individual and group submissions with enhanced support for teacher marking and per-question comments';

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('present','absent','late') NOT NULL,
  `type` enum('in-person','live') DEFAULT 'in-person',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores attendance records for students in classes';

-- --------------------------------------------------------

--
-- Table structure for table `badges`
--

CREATE TABLE `badges` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `points_required` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `behavior_comments`
--

CREATE TABLE `behavior_comments` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `term` varchar(50) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores teacher comments on student behavior per term';

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `author` varchar(255) DEFAULT NULL,
  `genre` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `file_url` varchar(255) DEFAULT NULL,
  `cover_image_url` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp(),
  `source` varchar(50) DEFAULT 'local',
  `uploaded_by` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_history`
--

CREATE TABLE `chat_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_id` varchar(50) DEFAULT NULL,
  `prompt` text DEFAULT NULL,
  `response` text DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `chat_session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` longtext DEFAULT NULL,
  `read_status` enum('unread','read') DEFAULT 'unread',
  `message_media` varchar(255) DEFAULT NULL COMMENT 'Path to image or video',
  `is_active` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Tracks if user is actively participating',
  `last_activity` datetime DEFAULT NULL COMMENT 'Last interaction time for activity tracking',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_participants`
--

CREATE TABLE `chat_participants` (
  `id` int(11) NOT NULL,
  `chat_session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_sessions`
--

CREATE TABLE `chat_sessions` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `term_id` int(11) DEFAULT NULL,
  `session_token` varchar(64) NOT NULL,
  `title` varchar(255) NOT NULL,
  `group_picture` varchar(255) DEFAULT NULL,
  `status` enum('draft','active','closed') NOT NULL DEFAULT 'draft',
  `message` longtext DEFAULT NULL COMMENT 'Current teacher message or prompt',
  `message_media` varchar(255) DEFAULT NULL COMMENT 'Path to image or video',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `live_class_id` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `is_real_time` tinyint(1) NOT NULL DEFAULT 1,
  `recipient_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `class_name` varchar(255) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `class_teacher_id` int(11) DEFAULT NULL,
  `department_id` int(11) NOT NULL,
  `department` varchar(50) DEFAULT 'Primary'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores class information with assigned teacher for QueensPa School';

-- --------------------------------------------------------

--
-- Table structure for table `class_assignments`
--

CREATE TABLE `class_assignments` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `submission_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Submitted','Graded') NOT NULL,
  `grade` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_schedules`
--

CREATE TABLE `class_schedules` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `subject_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_stats_cache`
--

CREATE TABLE `class_stats_cache` (
  `id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `student_total` float NOT NULL,
  `class_rank` int(11) NOT NULL,
  `total_students` int(11) NOT NULL,
  `class_highest` float NOT NULL,
  `class_average` float NOT NULL,
  `class_lowest` float NOT NULL,
  `cached_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_subjects`
--

CREATE TABLE `class_subjects` (
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clubs`
--

CREATE TABLE `clubs` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `advisor_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores school clubs and extracurriculars';

-- --------------------------------------------------------

--
-- Table structure for table `club_events`
--

CREATE TABLE `club_events` (
  `id` int(11) NOT NULL,
  `club_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` datetime NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores events for clubs';

-- --------------------------------------------------------

--
-- Table structure for table `club_memberships`
--

CREATE TABLE `club_memberships` (
  `id` int(11) NOT NULL,
  `club_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role` enum('member','leader','co_leader') DEFAULT 'member',
  `joined_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Tracks student membership in clubs';

-- --------------------------------------------------------

--
-- Table structure for table `course_enrollments`
--

CREATE TABLE `course_enrollments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL COMMENT 'Links to subjects or a separate courses table',
  `term_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','waitlisted','rejected') DEFAULT 'pending',
  `enrolled_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Tracks student enrollment in elective courses';

-- --------------------------------------------------------

--
-- Table structure for table `csrf_tokens`
--

CREATE TABLE `csrf_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `debts`
--

CREATE TABLE `debts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `fee_id` int(11) NOT NULL,
  `amount_due` decimal(10,2) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `term_id` int(11) DEFAULT NULL,
  `class_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`) VALUES
(0, 'Pre-School');

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_criteria`
--

CREATE TABLE `evaluation_criteria` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_scores`
--

CREATE TABLE `evaluation_scores` (
  `id` int(11) NOT NULL,
  `evaluation_id` int(11) NOT NULL,
  `criterion_id` int(11) NOT NULL,
  `score` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_rsvps`
--

CREATE TABLE `event_rsvps` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_schedules`
--

CREATE TABLE `exam_schedules` (
  `id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `term_id` int(11) DEFAULT NULL,
  `exam_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `room` varchar(50) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores exam schedules and details';

-- --------------------------------------------------------

--
-- Table structure for table `extracurricular_activities`
--

CREATE TABLE `extracurricular_activities` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `activity_name` varchar(255) NOT NULL,
  `participation_date` date NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fees`
--

CREATE TABLE `fees` (
  `id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `department` enum('Pre-School','Kindergarten','Lower Primary','Upper Primary','Junior High') NOT NULL,
  `fee_type` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `due_date` date NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `target_type` enum('Class','Learner','School') NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forum_posts`
--

CREATE TABLE `forum_posts` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `content` text NOT NULL,
  `parent_post_id` int(11) DEFAULT NULL COMMENT 'For threaded replies',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores posts within forum threads';

-- --------------------------------------------------------

--
-- Table structure for table `forum_reactions`
--

CREATE TABLE `forum_reactions` (
  `id` int(11) NOT NULL,
  `post_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `reaction_type` enum('upvote','downvote') NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores reactions (upvote/downvote) to forum posts';

-- --------------------------------------------------------

--
-- Table structure for table `forum_threads`
--

CREATE TABLE `forum_threads` (
  `id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `is_locked` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores discussion forum threads';

-- --------------------------------------------------------

--
-- Table structure for table `games`
--

CREATE TABLE `games` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `game_url` varchar(255) NOT NULL,
  `category` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `mid_term_exam` decimal(5,2) DEFAULT NULL,
  `end_term_exam` decimal(5,2) DEFAULT NULL,
  `total` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `report` text DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `groups`
--

CREATE TABLE `groups` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `project_title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `deadline` datetime NOT NULL,
  `max_points` int(11) NOT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores group projects for classes';

-- --------------------------------------------------------

--
-- Table structure for table `group_members`
--

CREATE TABLE `group_members` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('leader','member') DEFAULT 'member',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active',
  `left_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores student memberships in group projects';

-- --------------------------------------------------------

--
-- Table structure for table `help_tickets`
--

CREATE TABLE `help_tickets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `category` enum('technical','academic','general','billing') DEFAULT 'general',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores support tickets for help center';

-- --------------------------------------------------------

--
-- Table structure for table `learning_history`
--

CREATE TABLE `learning_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `prompt` text DEFAULT NULL,
  `response` text DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `session_id` varchar(50) DEFAULT NULL,
  `question_type` enum('user','generated') DEFAULT 'user',
  `is_correct` tinyint(1) DEFAULT NULL,
  `explanation` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `learning_resources`
--

CREATE TABLE `learning_resources` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `resource_type` enum('document','video','audio','link','interactive','image','pdf','worksheet','presentation','quiz') NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `url` varchar(512) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) NOT NULL,
  `term_id` int(11) DEFAULT NULL,
  `academic_year_id` int(11) NOT NULL,
  `access_level` enum('public','class','private') NOT NULL DEFAULT 'class',
  `tags` varchar(255) DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `views_count` int(11) NOT NULL DEFAULT 0,
  `downloads_count` int(11) NOT NULL DEFAULT 0,
  `rating` decimal(3,2) DEFAULT 0.00,
  `rating_count` int(11) DEFAULT 0,
  `ghanaian_content` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lesson_observations`
--

CREATE TABLE `lesson_observations` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `observation_date` date NOT NULL,
  `lesson_topic` varchar(255) NOT NULL,
  `score_1` int(11) NOT NULL DEFAULT 0,
  `score_2` int(11) NOT NULL DEFAULT 0,
  `score_3` int(11) NOT NULL DEFAULT 0,
  `score_4` int(11) NOT NULL DEFAULT 0,
  `score_5` int(11) NOT NULL DEFAULT 0,
  `score_6` int(11) NOT NULL DEFAULT 0,
  `score_7` int(11) NOT NULL DEFAULT 0,
  `score_8` int(11) NOT NULL DEFAULT 0,
  `score_9` int(11) NOT NULL DEFAULT 0,
  `score_10` int(11) NOT NULL DEFAULT 0,
  `remarks` text DEFAULT NULL,
  `field_notes` text DEFAULT NULL,
  `question_log` text DEFAULT NULL,
  `interaction_map` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lesson_plans`
--

CREATE TABLE `lesson_plans` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `objectives` text NOT NULL,
  `lesson_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('draft','published') DEFAULT 'draft',
  `resources` text DEFAULT NULL COMMENT 'JSON or comma-separated resource IDs from learning_resources'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `library_books`
--

CREATE TABLE `library_books` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) DEFAULT NULL,
  `isbn` varchar(13) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `publication_year` year(4) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `total_copies` int(11) NOT NULL DEFAULT 1,
  `available_copies` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores library book details';

-- --------------------------------------------------------

--
-- Table structure for table `library_fines`
--

CREATE TABLE `library_fines` (
  `id` int(11) NOT NULL,
  `loan_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `issued_at` date NOT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','paid') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Tracks library fines';

-- --------------------------------------------------------

--
-- Table structure for table `library_loans`
--

CREATE TABLE `library_loans` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `book_id` int(11) DEFAULT NULL,
  `loan_date` date NOT NULL,
  `due_date` date NOT NULL,
  `return_date` date DEFAULT NULL,
  `status` enum('active','returned','overdue') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Tracks library book loans';

-- --------------------------------------------------------

--
-- Table structure for table `live_classes`
--

CREATE TABLE `live_classes` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `duration` int(11) NOT NULL,
  `meeting_link` varchar(255) DEFAULT NULL,
  `recording_link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `recording_url` varchar(255) DEFAULT NULL COMMENT 'URL to recorded session',
  `chat_log` text DEFAULT NULL COMMENT 'Chat or Q&A log'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores live class sessions';

-- --------------------------------------------------------

--
-- Table structure for table `mock_entries`
--

CREATE TABLE `mock_entries` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `mock_number` tinyint(2) UNSIGNED NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `exam_date` date NOT NULL,
  `mock_raw` decimal(5,2) DEFAULT 0.00,
  `mock_max` decimal(5,2) DEFAULT 100.00,
  `mock_grade` varchar(100) DEFAULT '',
  `mock_comments` text DEFAULT NULL,
  `status` enum('draft','submitted','reviewed') DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `target_role` varchar(50) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `link` varchar(255) DEFAULT NULL COMMENT 'Optional link to related resource (e.g., chat session)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `category` enum('academic','extracurricular','administrative','system') DEFAULT 'system'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parent_student_mapping`
--

CREATE TABLE `parent_student_mapping` (
  `id` int(11) NOT NULL,
  `parent_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `fee_id` int(11) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `class_id` int(11) NOT NULL,
  `recorded_by` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `private_chat_messages`
--

CREATE TABLE `private_chat_messages` (
  `id` int(11) NOT NULL,
  `chat_session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `message_media` varchar(255) DEFAULT NULL,
  `read_status` enum('unread','read') DEFAULT 'unread',
  `is_active` tinyint(1) DEFAULT 1,
  `last_activity` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `private_chat_sessions`
--

CREATE TABLE `private_chat_sessions` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `term_id` int(11) NOT NULL,
  `session_token` varchar(32) NOT NULL,
  `title` varchar(255) NOT NULL,
  `status` enum('active','closed') DEFAULT 'active',
  `start_time` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `progress_aggregates`
--

CREATE TABLE `progress_aggregates` (
  `student_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `term_id` int(11) DEFAULT NULL,
  `academic_year_id` int(11) NOT NULL,
  `avg_scaled_score` decimal(9,6) DEFAULT NULL,
  `highest_scaled_score` decimal(5,2) DEFAULT NULL,
  `entry_count` bigint(21) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `promotion_logs`
--

CREATE TABLE `promotion_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `old_class_id` int(11) NOT NULL,
  `new_class_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `session_token` varchar(64) NOT NULL,
  `title` varchar(255) NOT NULL,
  `status` enum('draft','active','closed') NOT NULL DEFAULT 'draft',
  `question` longtext DEFAULT NULL,
  `question_media` varchar(255) DEFAULT NULL,
  `question_type` enum('text','multiple_choice','media') NOT NULL DEFAULT 'text',
  `options` longtext DEFAULT NULL COMMENT 'JSON array for multiple-choice options',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `is_real_time` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_submissions`
--

CREATE TABLE `quiz_submissions` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `answer` longtext DEFAULT NULL,
  `answer_media` varchar(255) DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Tracks if user is actively participating',
  `last_activity` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_summaries`
--

CREATE TABLE `report_summaries` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `summary` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `resource_feedback`
--

CREATE TABLE `resource_feedback` (
  `id` int(11) NOT NULL,
  `resource_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` decimal(3,2) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `response_cache`
--

CREATE TABLE `response_cache` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `prompt_hash` varchar(64) DEFAULT NULL,
  `response` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `school_events`
--

CREATE TABLE `school_events` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `event_type` enum('sports','cultural','academic','holiday','other') NOT NULL,
  `event_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `rsvp_required` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores school-wide events';

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `student_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_classes`
--

CREATE TABLE `student_classes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `academic_year` varchar(9) DEFAULT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores assignments of students to classes for specific academic years';

-- --------------------------------------------------------

--
-- Table structure for table `student_classes_backup`
--

CREATE TABLE `student_classes_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `academic_year` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_feedback`
--

CREATE TABLE `student_feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `assessment_id` int(11) DEFAULT NULL,
  `feedback` text NOT NULL,
  `rating` enum('1','2','3','4','5') NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_progress`
--

CREATE TABLE `student_progress` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assessment_id` int(11) DEFAULT NULL,
  `points_earned` int(11) DEFAULT 0,
  `badge_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `goal_description` text DEFAULT NULL COMMENT 'Description of the goal achieved'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_reports`
--

CREATE TABLE `student_reports` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `attendance` int(11) DEFAULT NULL,
  `conduct` text DEFAULT NULL,
  `attitude` text DEFAULT NULL,
  `interest` text DEFAULT NULL,
  `recommended_subjects` text DEFAULT NULL,
  `encouragement` text DEFAULT NULL,
  `reopening_date` date DEFAULT NULL,
  `teacher_remarks` text DEFAULT NULL,
  `headmaster_remarks` text DEFAULT NULL,
  `admin_comment` text DEFAULT NULL,
  `promoted_to_class_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `department_id` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` int(11) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `hire_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_evaluations`
--

CREATE TABLE `teacher_evaluations` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `term_id` int(11) NOT NULL,
  `lesson_plan_id` int(11) DEFAULT NULL,
  `score_1` int(11) DEFAULT NULL,
  `score_2` int(11) DEFAULT NULL,
  `score_3` int(11) DEFAULT NULL,
  `score_4` int(11) DEFAULT NULL,
  `score_5` int(11) DEFAULT NULL,
  `score_6` int(11) DEFAULT NULL,
  `score_7` int(11) DEFAULT NULL,
  `score_8` int(11) DEFAULT NULL,
  `score_9` int(11) DEFAULT NULL,
  `score_10` int(11) DEFAULT NULL,
  `score_11` int(11) DEFAULT NULL,
  `score_12` int(11) DEFAULT NULL,
  `score_13` int(11) DEFAULT NULL,
  `score_14` int(11) DEFAULT NULL,
  `score_15` int(11) DEFAULT NULL,
  `score_16` int(11) DEFAULT NULL,
  `score_17` int(11) DEFAULT NULL COMMENT 'Score for criterion 17',
  `positive_notes` text DEFAULT NULL,
  `negative_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cuas` varchar(100) DEFAULT NULL,
  `week` varchar(50) DEFAULT NULL,
  `is_read` tinyint(4) DEFAULT 0,
  `admin_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `attendance_status` enum('Absent','Present','Late') NOT NULL DEFAULT 'Present' COMMENT 'Teacher attendance status during evaluation'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_notes`
--

CREATE TABLE `teacher_notes` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `week` int(11) NOT NULL COMMENT 'Week number of the term (e.g., 1 for Week 1)',
  `class_id` int(11) NOT NULL COMMENT 'Foreign key to classes.id',
  `term_id` int(11) NOT NULL COMMENT 'Foreign key to academic_calendar.id',
  `strand` varchar(255) DEFAULT NULL,
  `sub_strand` varchar(255) DEFAULT NULL,
  `content_standard` text DEFAULT NULL,
  `indicator` text DEFAULT NULL,
  `objectives` text DEFAULT NULL,
  `reference` text NOT NULL COMMENT 'Reference materials or citations',
  `resources` text NOT NULL COMMENT 'Resources used for the lesson',
  `keywords` varchar(255) NOT NULL COMMENT 'Keywords for the note',
  `starter` text NOT NULL COMMENT 'Introductory content for the lesson',
  `presentation` mediumtext NOT NULL,
  `summary` text NOT NULL COMMENT 'Summary of the lesson',
  `subject_id` int(11) NOT NULL DEFAULT 0,
  `format` enum('oxford','ges') NOT NULL DEFAULT 'oxford',
  `unit` varchar(255) DEFAULT NULL,
  `topic` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `termly_plans`
--

CREATE TABLE `termly_plans` (
  `id` int(11) NOT NULL,
  `annual_plan_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `term_number` int(11) NOT NULL CHECK (`term_number` between 1 and 3),
  `term_id` int(11) NOT NULL,
  `week` int(11) NOT NULL CHECK (`week` >= 1 and `week` <= 16),
  `unit` varchar(255) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `lesson` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `format` enum('oxford','ges') NOT NULL DEFAULT 'oxford'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `terms`
--

CREATE TABLE `terms` (
  `id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transcript_requests`
--

CREATE TABLE `transcript_requests` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `request_type` enum('official','unofficial') NOT NULL,
  `delivery_method` enum('email','physical','pickup') NOT NULL,
  `delivery_email` varchar(255) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `status` enum('pending','processing','completed','rejected') DEFAULT 'pending',
  `requested_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores transcript request details';

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','teacher','student','parent') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive','suspended','graduated') DEFAULT 'active',
  `profile_picture` varchar(255) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `national_id` varchar(100) DEFAULT NULL,
  `admission_date` date DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `gender` enum('male','female','other') NOT NULL DEFAULT 'male',
  `whatsapp_number` varchar(15) NOT NULL,
  `address` text NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `force_password_change` tinyint(1) DEFAULT 1,
  `advisor_id` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `password`, `role`, `created_at`, `status`, `profile_picture`, `phone_number`, `national_id`, `admission_date`, `department`, `gender`, `whatsapp_number`, `address`, `last_login`, `last_ip`, `department_id`, `force_password_change`, `advisor_id`, `updated_at`, `class_id`) VALUES
(0, 'Samuel Agyare', '$2y$10$JbWDdpmInSvlvyyPTJuOu.ClTGv7F9tWzVpr7LNEnj82EK6uK75jG', 'parent', '2025-12-01 03:46:51', 'active', NULL, '0531475205', NULL, NULL, 'Upper primary', 'male', '0559657622', '', NULL, NULL, NULL, 1, NULL, NULL, 0),
(11, 'Adjei Francis', '$2y$10$J1HI8Wl8U3laEGKefEDl6ucLDgxVJ6YHPo8A1iP5DWFxx8LTOv9C6', 'admin', '2025-05-11 17:52:57', 'active', '11_1763138939.jpeg', '0549444636', '', '2025-05-11', '', 'male', '0549444636', '0', '2025-11-28 02:29:50', '::1', 8, 1, NULL, '2025-11-14 16:48:59', NULL),
(26, 'Agyare Emmanuel Danso', '$2y$10$iFtlDfJcRYya.RDhPirya.5tXTanyWIr3d.dphUB1rzfLcYruVuAm', 'teacher', '2025-05-15 12:25:01', 'active', '/Uploads/profiles/user_26_1753110572.jpg', '0559657622', '', '2024-12-31', '', 'male', '0531475205', '0', '2025-11-17 14:54:39', '154.161.24.8', 8, 1, NULL, '2025-07-21 15:09:32', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_library`
--

CREATE TABLE `user_library` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `book_id` int(11) DEFAULT NULL,
  `added_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_logs`
--

CREATE TABLE `user_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wellness_resources`
--

CREATE TABLE `wellness_resources` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('article','video','audio','link') NOT NULL,
  `content_url` varchar(255) DEFAULT NULL,
  `thumbnail_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Stores mental health and wellness resources';

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_progress_entries`
--
ALTER TABLE `academic_progress_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `idx_academic_year_id` (`academic_year_id`);

--
-- Indexes for table `academic_progress_logs`
--
ALTER TABLE `academic_progress_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `entry_id` (`entry_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `academic_years`
--
ALTER TABLE `academic_years`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `year` (`year`);

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_timestamp` (`user_id`,`timestamp`);

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `advisor_appointments`
--
ALTER TABLE `advisor_appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_appointment` (`advisor_id`,`appointment_date`);

--
-- Indexes for table `annual_plans`
--
ALTER TABLE `annual_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `idx_teacher_class_subject` (`teacher_id`,`class_id`,`subject_id`),
  ADD KEY `idx_week` (`week`);

--
-- Indexes for table `application_form`
--
ALTER TABLE `application_form`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `idx_class_subject` (`class_id`,`subject_id`),
  ADD KEY `idx_teacher` (`teacher_id`);

--
-- Indexes for table `assignment_questions`
--
ALTER TABLE `assignment_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`);

--
-- Indexes for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_assignment_user` (`assignment_id`,`user_id`) COMMENT 'Ensures one submission per user per assignment for individual submissions',
  ADD KEY `graded_by` (`graded_by`),
  ADD KEY `idx_assignment_status` (`assignment_id`,`status`) COMMENT 'Optimizes queries by assignment and status',
  ADD KEY `idx_user` (`user_id`) COMMENT 'Optimizes queries by user',
  ADD KEY `idx_submission_date` (`submission_date`) COMMENT 'Optimizes queries by submission date',
  ADD KEY `idx_group` (`group_id`) COMMENT 'Optimizes queries for group submissions',
  ADD KEY `idx_user_assignment` (`user_id`,`assignment_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`class_id`,`user_id`,`date`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `badges`
--
ALTER TABLE `badges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_points_required` (`points_required`);

--
-- Indexes for table `behavior_comments`
--
ALTER TABLE `behavior_comments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_behavior_comment` (`class_id`,`user_id`,`term`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chat_history`
--
ALTER TABLE `chat_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_session` (`user_id`,`session_id`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_session_id` (`chat_session_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `chat_participants`
--
ALTER TABLE `chat_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chat_session_id` (`chat_session_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `chat_sessions`
--
ALTER TABLE `chat_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `idx_session_token` (`session_token`),
  ADD KEY `idx_teacher_id` (`teacher_id`),
  ADD KEY `idx_class_id` (`class_id`),
  ADD KEY `live_class_id` (`live_class_id`),
  ADD KEY `fk_parent_id` (`parent_id`),
  ADD KEY `fk_recipient_id` (`recipient_id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_teacher_id` (`teacher_id`),
  ADD KEY `fk_classes_department_id` (`department_id`),
  ADD KEY `fk_classes_subject` (`subject_id`),
  ADD KEY `idx_classes_teacher` (`teacher_id`);

--
-- Indexes for table `class_assignments`
--
ALTER TABLE `class_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `class_schedules`
--
ALTER TABLE `class_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `fk_subject_id` (`subject_id`),
  ADD KEY `fk_term_id` (`term_id`),
  ADD KEY `fk_teacher_id_schedules` (`teacher_id`),
  ADD KEY `fk_class_schedules_department_id` (`department_id`);

--
-- Indexes for table `class_stats_cache`
--
ALTER TABLE `class_stats_cache`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_term_class_user` (`term_id`,`class_id`,`user_id`);

--
-- Indexes for table `class_subjects`
--
ALTER TABLE `class_subjects`
  ADD PRIMARY KEY (`class_id`,`subject_id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `clubs`
--
ALTER TABLE `clubs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `advisor_id` (`advisor_id`);

--
-- Indexes for table `club_events`
--
ALTER TABLE `club_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `club_id` (`club_id`),
  ADD KEY `idx_event_date` (`event_date`);

--
-- Indexes for table `club_memberships`
--
ALTER TABLE `club_memberships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_club_user` (`club_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `course_enrollments`
--
ALTER TABLE `course_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_course_term` (`user_id`,`course_id`,`term_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `term_id` (`term_id`);

--
-- Indexes for table `csrf_tokens`
--
ALTER TABLE `csrf_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `debts`
--
ALTER TABLE `debts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fee_id` (`fee_id`),
  ADD KEY `fk_debts_term_id` (`term_id`),
  ADD KEY `fk_debts_class_id` (`class_id`),
  ADD KEY `idx_academic_year_id` (`academic_year_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `evaluation_criteria`
--
ALTER TABLE `evaluation_criteria`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `evaluation_scores`
--
ALTER TABLE `evaluation_scores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evaluation_id` (`evaluation_id`),
  ADD KEY `criterion_id` (`criterion_id`);

--
-- Indexes for table `event_rsvps`
--
ALTER TABLE `event_rsvps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `exam_schedules`
--
ALTER TABLE `exam_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `idx_exam_date` (`exam_date`),
  ADD KEY `idx_exam_schedules_teacher` (`class_id`,`term_id`);

--
-- Indexes for table `extracurricular_activities`
--
ALTER TABLE `extracurricular_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `term_id` (`term_id`);

--
-- Indexes for table `fees`
--
ALTER TABLE `fees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_fee` (`fee_type`,`class_id`,`user_id`,`academic_year`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `fk_fees_user` (`user_id`),
  ADD KEY `idx_academic_year_id` (`academic_year_id`);

--
-- Indexes for table `forum_posts`
--
ALTER TABLE `forum_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `parent_post_id` (`parent_post_id`),
  ADD KEY `idx_thread` (`thread_id`);

--
-- Indexes for table `forum_reactions`
--
ALTER TABLE `forum_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_post_user` (`post_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `forum_threads`
--
ALTER TABLE `forum_threads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_class_subject` (`class_id`,`subject_id`);

--
-- Indexes for table `games`
--
ALTER TABLE `games`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_grade` (`user_id`,`class_id`,`subject_id`,`term_id`),
  ADD KEY `grades_ibfk_2` (`class_id`),
  ADD KEY `grades_ibfk_3` (`subject_id`),
  ADD KEY `grades_ibfk_4` (`term_id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `group_members`
--
ALTER TABLE `group_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_group_member` (`group_id`,`user_id`),
  ADD UNIQUE KEY `uk_group_members` (`group_id`,`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `help_tickets`
--
ALTER TABLE `help_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `learning_history`
--
ALTER TABLE `learning_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `idx_user_session` (`user_id`,`session_id`);

--
-- Indexes for table `learning_resources`
--
ALTER TABLE `learning_resources`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_subject_class_term` (`subject_id`,`class_id`,`term_id`),
  ADD KEY `idx_teacher_created` (`teacher_id`,`created_at`),
  ADD KEY `idx_tags` (`tags`),
  ADD KEY `idx_resource_subject` (`subject_id`),
  ADD KEY `idx_academic_year_id` (`academic_year_id`);

--
-- Indexes for table `lesson_observations`
--
ALTER TABLE `lesson_observations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `term_id` (`term_id`);

--
-- Indexes for table `lesson_plans`
--
ALTER TABLE `lesson_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `term_id` (`term_id`);

--
-- Indexes for table `library_books`
--
ALTER TABLE `library_books`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `isbn` (`isbn`),
  ADD KEY `idx_isbn` (`isbn`);

--
-- Indexes for table `library_fines`
--
ALTER TABLE `library_fines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_id` (`loan_id`),
  ADD KEY `idx_user_fine` (`user_id`,`status`);

--
-- Indexes for table `library_loans`
--
ALTER TABLE `library_loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `book_id` (`book_id`),
  ADD KEY `idx_user_loan` (`user_id`,`due_date`);

--
-- Indexes for table `live_classes`
--
ALTER TABLE `live_classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `mock_entries`
--
ALTER TABLE `mock_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `idx_academic_year_id` (`academic_year_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `parent_student_mapping`
--
ALTER TABLE `parent_student_mapping`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `parent_student_mapping_unique` (`parent_id`,`student_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fee_id` (`fee_id`),
  ADD KEY `fk_payments_class_id` (`class_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `private_chat_messages`
--
ALTER TABLE `private_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_session_id` (`chat_session_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `private_chat_sessions`
--
ALTER TABLE `private_chat_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `recipient_id` (`recipient_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `idx_sender_recipient` (`sender_id`,`recipient_id`),
  ADD KEY `idx_session_token` (`session_token`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `progress_aggregates`
--
ALTER TABLE `progress_aggregates`
  ADD KEY `idx_academic_year_id` (`academic_year_id`);

--
-- Indexes for table `promotion_logs`
--
ALTER TABLE `promotion_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `old_class_id` (`old_class_id`),
  ADD KEY `new_class_id` (`new_class_id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `idx_academic_year_id` (`academic_year_id`);

--
-- Indexes for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `idx_session_token` (`session_token`),
  ADD KEY `idx_teacher_id` (`teacher_id`),
  ADD KEY `idx_class_id` (`class_id`);

--
-- Indexes for table `quiz_submissions`
--
ALTER TABLE `quiz_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_quiz_id` (`quiz_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `report_summaries`
--
ALTER TABLE `report_summaries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `idx_report_id` (`report_id`);

--
-- Indexes for table `resource_feedback`
--
ALTER TABLE `resource_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `resource_id` (`resource_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `response_cache`
--
ALTER TABLE `response_cache`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_prompt_hash` (`prompt_hash`);

--
-- Indexes for table `school_events`
--
ALTER TABLE `school_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_date` (`event_date`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_classes`
--
ALTER TABLE `student_classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_assignment` (`user_id`,`class_id`,`academic_year`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `student_feedback`
--
ALTER TABLE `student_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `assessment_id` (`assessment_id`),
  ADD KEY `idx_user_class` (`user_id`,`class_id`);

--
-- Indexes for table `student_progress`
--
ALTER TABLE `student_progress`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assessment_id` (`assessment_id`),
  ADD KEY `badge_id` (`badge_id`),
  ADD KEY `idx_user_assessment` (`user_id`,`assessment_id`),
  ADD KEY `idx_user_progress` (`user_id`);

--
-- Indexes for table `student_reports`
--
ALTER TABLE `student_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_report` (`user_id`,`class_id`,`term_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `promoted_to_class_id` (`promoted_to_class_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subject_code` (`subject_code`),
  ADD KEY `fk_subjects_department_id` (`department_id`),
  ADD KEY `fk_subjects_updated_by` (`updated_by`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `teacher_evaluations`
--
ALTER TABLE `teacher_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `term_id` (`term_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `fk_teacher_evaluations_department_id` (`department_id`),
  ADD KEY `fk_teacher_evaluations_lesson_plan` (`lesson_plan_id`);

--
-- Indexes for table `teacher_notes`
--
ALTER TABLE `teacher_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `fk_teacher_notes_class_id` (`class_id`),
  ADD KEY `fk_teacher_notes_term_id` (`term_id`);

--
-- Indexes for table `termly_plans`
--
ALTER TABLE `termly_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `idx_annual_plan` (`annual_plan_id`),
  ADD KEY `idx_teacher_class_subject` (`teacher_id`,`class_id`,`subject_id`),
  ADD KEY `idx_term_week` (`term_id`,`week`);

--
-- Indexes for table `terms`
--
ALTER TABLE `terms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `academic_year_id` (`academic_year_id`);

--
-- Indexes for table `transcript_requests`
--
ALTER TABLE `transcript_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_users_department_id` (`department_id`),
  ADD KEY `fk_users_advisor` (`advisor_id`),
  ADD KEY `fk_class_id` (`class_id`);

--
-- Indexes for table `user_library`
--
ALTER TABLE `user_library`
  ADD PRIMARY KEY (`id`),
  ADD KEY `book_id` (`book_id`);

--
-- Indexes for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `wellness_resources`
--
ALTER TABLE `wellness_resources`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_progress_entries`
--
ALTER TABLE `academic_progress_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `academic_progress_logs`
--
ALTER TABLE `academic_progress_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `academic_years`
--
ALTER TABLE `academic_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `annual_plans`
--
ALTER TABLE `annual_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_form`
--
ALTER TABLE `application_form`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignment_questions`
--
ALTER TABLE `assignment_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `badges`
--
ALTER TABLE `badges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `behavior_comments`
--
ALTER TABLE `behavior_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_history`
--
ALTER TABLE `chat_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_participants`
--
ALTER TABLE `chat_participants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_sessions`
--
ALTER TABLE `chat_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_assignments`
--
ALTER TABLE `class_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_schedules`
--
ALTER TABLE `class_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_stats_cache`
--
ALTER TABLE `class_stats_cache`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clubs`
--
ALTER TABLE `clubs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `terms`
--
ALTER TABLE `terms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `academic_progress_entries`
--
ALTER TABLE `academic_progress_entries`
  ADD CONSTRAINT `academic_progress_entries_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `academic_progress_entries_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `debts`
--
ALTER TABLE `debts`
  ADD CONSTRAINT `debts_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `debts_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fees`
--
ALTER TABLE `fees`
  ADD CONSTRAINT `fees_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fees_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `learning_resources`
--
ALTER TABLE `learning_resources`
  ADD CONSTRAINT `learning_resources_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `learning_resources_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mock_entries`
--
ALTER TABLE `mock_entries`
  ADD CONSTRAINT `mock_entries_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `mock_entries_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `progress_aggregates`
--
ALTER TABLE `progress_aggregates`
  ADD CONSTRAINT `progress_aggregates_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `progress_aggregates_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `promotion_logs`
--
ALTER TABLE `promotion_logs`
  ADD CONSTRAINT `promotion_logs_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `promotion_logs_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `terms`
--
ALTER TABLE `terms`
  ADD CONSTRAINT `terms_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
