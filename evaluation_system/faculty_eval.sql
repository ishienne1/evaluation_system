-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 14, 2026 at 08:48 AM
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
-- Database: `faculty_eval`
--
CREATE DATABASE IF NOT EXISTS `faculty_eval` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `faculty_eval`;


-- --------------------------------------------------------

--
-- Table structure for table `academic_periods`
--

CREATE TABLE `academic_periods` (
  `id` int(11) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `academic_periods`
--

INSERT INTO `academic_periods` (`id`, `school_year`, `semester`, `is_active`, `created_at`) VALUES
(1, '2026', '1st Semester', 1, '2026-07-26 16:55:26'),
(2, '2026', '2nd Semester', 0, '2026-07-26 16:55:26'),
(3, '2027', '1st Semester', 0, '2026-07-26 16:55:26'),
(4, '2027', '2nd Semester', 0, '2026-07-26 16:55:26');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `event_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `period_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `faculty_id`, `subject_id`, `period_id`) VALUES
(21, 88, 1, 1),
(22, 89, 19, 1),
(24, 90, 21, 1),
(25, 91, 22, 1),
(26, 92, 5, 1),
(27, 93, 23, 1),
(28, 94, 24, 1),
(29, 95, 25, 1),
(30, 96, 26, 1),
(31, 97, 27, 1),
(32, 98, 28, 1),
(33, 99, 29, 1),
(34, 100, 30, 1),
(35, 101, 31, 1),
(36, 102, 32, 1),
(37, 103, 33, 1),
(38, 104, 34, 1),
(39, 105, 35, 1),
(40, 106, 36, 1),
(41, 107, 37, 1),
(42, 108, 38, 1),
(43, 109, 39, 1),
(44, 110, 40, 1),
(45, 111, 41, 1),
(46, 112, 42, 1),
(47, 113, 43, 1),
(48, 114, 3, 1),
(49, 115, 44, 1),
(50, 116, 45, 1),
(51, 117, 46, 1),
(52, 118, 47, 1),
(53, 119, 48, 1),
(54, 120, 49, 1),
(55, 121, 50, 1),
(56, 122, 51, 1),
(57, 123, 52, 1),
(58, 125, 20, 1),
(59, 126, 53, 1),
(60, 127, 54, 1),
(61, 128, 55, 1),
(62, 129, 56, 1),
(63, 130, 57, 1),
(64, 131, 58, 1),
(65, 132, 59, 1),
(66, 133, 60, 1),
(67, 134, 61, 1),
(68, 135, 62, 1),
(69, 136, 63, 1),
(70, 137, 64, 1),
(71, 138, 65, 1),
(72, 139, 66, 1),
(73, 140, 67, 1),
(74, 141, 68, 1),
(75, 142, 69, 1);

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `dept_id` int(11) NOT NULL,
  `course_name` varchar(150) NOT NULL,
  `course_alias` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `dept_id`, `course_name`, `course_alias`) VALUES
(1, 1, 'Bachelor of Science in Social Work', 'BSSW'),
(2, 1, 'Bachelor of Secondary Education', 'BSED'),
(3, 1, 'Bachelor of Science in Psychology', 'BS PSYCH'),
(4, 1, 'Bachelor of Physical Education', 'BPED'),
(5, 2, 'Bachelor of Science in Customs Administration', 'BSCA'),
(6, 2, 'Bachelor of Science in Business Administration', 'BSBA'),
(7, 2, 'Bachelor of Science in Accountancy', 'BSAC'),
(8, 2, 'Bachelor of Science in Hospitality Management', 'BSHM'),
(9, 2, 'Bachelor of Science in Tourism Management', 'BSTM'),
(10, 3, 'Bachelor of Science in Information Technology', 'BSIT'),
(11, 3, 'Bachelor of Science in Computer Engineering', 'BSCpE'),
(12, 4, 'Bachelor of Science in Criminology', 'BSCRIM'),
(13, 5, 'Bachelor of Science in Medical Laboratory Science', 'BSMLS'),
(14, 7, 'Bachelor of Science in Nursing', 'BSN'),
(15, 7, '2-Year Midwifery', 'Midwifery'),
(16, 8, 'Bachelor of Science in Pharmacy', 'BSPHARM'),
(17, 9, 'Bachelor of Science in Physical Therapy', 'BSPT'),
(18, 10, 'Bachelor of Science in Radiologic Technology', 'BSRT');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `dept_name` varchar(150) NOT NULL,
  `dept_alias` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `dept_name`, `dept_alias`) VALUES
(1, 'College of Arts, Sciences and Teacher Education', 'CASTE'),
(2, 'College of Business, Economics and Management', 'CBEM'),
(3, 'College of Information Technology and Engineering', 'CITE'),
(4, 'College of Criminal Justice Education', 'COCJE'),
(5, 'College of Medical Laboratory Science', 'CMLS'),
(6, 'College of Medicine', 'COM'),
(7, 'College of Nursing', 'CON'),
(8, 'College of Pharmacy', 'COP'),
(9, 'College of Physical Therapy', 'COPT'),
(10, 'College of Radiologic Technology', 'CORT');

-- --------------------------------------------------------

--
-- Table structure for table `email_otp`
--

CREATE TABLE `email_otp` (
  `id` int(11) NOT NULL,
  `email` varchar(191) NOT NULL,
  `otp_code` varchar(6) NOT NULL,
  `purpose` enum('register','reset') NOT NULL DEFAULT 'register',
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_otp`
--

INSERT INTO `email_otp` (`id`, `email`, `otp_code`, `purpose`, `expires_at`, `is_used`, `created_at`) VALUES
(1, 'brixxharoldanicetocostales01@gmail.com', '675460', 'register', '2026-08-04 10:36:39', 1, '2026-08-04 08:26:39'),
(2, 'brixxharoldcostales01@gmail.com', '527483', 'register', '2026-08-04 10:38:10', 1, '2026-08-04 08:28:10'),
(3, 'tulauan.sheennalei00@gmail.com', '190808', 'register', '2026-08-04 10:39:00', 1, '2026-08-04 08:29:00'),
(4, 'brixxharoldanicetocostales01@gmail.com', '177319', 'register', '2026-08-04 11:39:20', 1, '2026-08-04 09:29:20'),
(5, 'tulauan.sheennalei00@gmail.com', '155013', 'register', '2026-08-04 11:41:33', 1, '2026-08-04 09:31:33'),
(6, 'geleentapaoan@gmail.com', '456172', 'register', '2026-08-04 15:19:53', 1, '2026-08-04 13:09:53'),
(7, 'geleentapaoan@gmail.com', '965790', 'register', '2026-08-04 15:21:29', 1, '2026-08-04 13:11:29'),
(8, 'geleentapaoan@gmail.com', '324546', 'register', '2026-08-04 15:23:04', 1, '2026-08-04 13:13:04'),
(9, 'brixxharoldanicetocostales01@gmail.com', '509655', 'register', '2026-08-04 15:23:48', 1, '2026-08-04 13:13:48'),
(10, 'geleentapaoan@gmail.com', '657547', 'register', '2026-08-04 15:36:16', 1, '2026-08-04 13:26:16'),
(11, 'trzvshiiaa823@gmail.com', '509179', 'register', '2026-08-04 15:42:53', 1, '2026-08-04 13:32:53'),
(12, 'brixxharoldanicetocostales01@gmail.com', '406452', 'reset', '2026-08-04 18:29:22', 1, '2026-08-04 16:19:22'),
(13, 'brixxharoldanicetocostales01@gmail.com', '907436', 'register', '2026-08-05 04:33:04', 1, '2026-08-05 02:23:04'),
(14, 'trzvshiiaa823@gmail.com', '799234', 'register', '2026-08-05 04:35:21', 1, '2026-08-05 02:25:21'),
(15, 'trzvshiiaa823@gmail.com', '214247', 'register', '2026-08-05 04:44:48', 1, '2026-08-05 02:34:48'),
(16, 'trzvshiiaa823@gmail.com', '461738', 'register', '2026-08-05 04:47:50', 1, '2026-08-05 02:37:50'),
(17, 'aidendwayne.19@gmail.com', '173069', 'register', '2026-08-05 04:49:11', 1, '2026-08-05 02:39:11'),
(18, 'aidendwayne.19@gmail.com', '642425', 'register', '2026-08-05 05:00:10', 0, '2026-08-05 02:50:10'),
(19, 'aeronmartin44@gmail.com', '402768', 'register', '2026-08-05 05:00:51', 0, '2026-08-05 02:50:51'),
(20, 'brixxharoldanicetocostales01@gmail.com', '609141', 'reset', '2026-08-27 06:22:03', 1, '2026-08-27 04:12:03'),
(21, 'brixxharoldanicetocostales01@gmail.com', '905419', 'register', '2026-08-27 14:45:42', 1, '2026-08-27 12:35:42'),
(22, 'brixxharoldcostales01@gmail.com', '456966', 'register', '2026-08-27 14:49:32', 1, '2026-08-27 12:39:32'),
(23, 'brixxharoldcostales01@gmail.com', '460201', 'register', '2026-08-27 14:59:37', 1, '2026-08-27 12:49:37'),
(24, 'geleentapaoan@gmail.com', '744911', 'register', '2026-08-27 15:14:44', 1, '2026-08-27 13:04:44'),
(25, 'brixxharoldanicetocostales01@gmail.com', '845634', 'register', '2026-08-27 15:22:55', 1, '2026-08-27 13:12:55'),
(26, 'brixxharoldcostales01@gmail.com', '553557', 'register', '2026-08-27 15:47:08', 1, '2026-08-27 13:37:08'),
(27, 'geleentapaoan@gmail.com', '375347', 'register', '2026-08-27 18:15:45', 1, '2026-08-27 16:05:45'),
(28, 'brixxharoldcostales01@gmail.com', '741986', 'register', '2026-08-27 18:32:09', 1, '2026-08-27 16:22:09'),
(29, 'trishaherrera01@gmail.com', '625189', 'register', '2026-08-27 18:35:07', 1, '2026-08-27 16:25:07'),
(30, 'trishaherrera01@gmail.com', '387552', 'register', '2026-08-27 18:36:03', 0, '2026-08-27 16:26:03'),
(31, 'trzvshiiaa823@gmail.com', '500393', 'register', '2026-08-27 18:36:16', 1, '2026-08-27 16:26:16'),
(32, 'brixxharoldanicetocostales01@gmail.com', '487306', 'register', '2026-08-28 05:20:44', 1, '2026-08-28 03:10:44'),
(33, 'brixxharoldanicetocostales01@gmail.com', '455237', 'register', '2026-08-28 05:20:54', 1, '2026-08-28 03:10:54'),
(34, 'brixxharoldanicetocostales01@gmail.com', '910618', 'register', '2026-08-28 05:44:06', 1, '2026-08-28 03:34:06'),
(35, 'tulauan.sheennalei00@gmail.com', '822160', 'register', '2026-08-28 06:10:09', 1, '2026-08-28 04:00:09'),
(36, 'tulauan.sheennalei00@gmail.com', '743434', 'register', '2026-08-28 06:12:30', 1, '2026-08-28 04:02:30'),
(37, 'tulauan.sheennalei00@gmail.com', '790339', 'register', '2026-08-28 06:13:14', 1, '2026-08-28 04:03:14'),
(38, 'brixxharoldcostales01@gmail.com', '817688', 'register', '2026-08-28 06:18:10', 1, '2026-08-28 04:08:10'),
(39, 'averykylebiggaong@gmail.com', '586764', 'register', '2026-08-28 06:33:36', 0, '2026-08-28 04:23:36'),
(40, 'averybiggaong01@gmail.com', '856650', 'register', '2026-08-28 06:35:07', 1, '2026-08-28 04:25:07'),
(41, 'averybiggaong01@gmail.com', '171625', 'register', '2026-08-28 06:35:47', 1, '2026-08-28 04:25:47'),
(42, 'averybiggaong01@gmail.com', '970895', 'register', '2026-08-28 06:37:18', 0, '2026-08-28 04:27:18'),
(43, 'brixxharoldanicetocostales01@gmail.com', '345503', 'register', '2026-09-13 14:21:51', 1, '2026-09-13 12:11:51'),
(44, 'brixxharoldanicetocostales01@gmail.com', '875738', 'register', '2026-09-13 14:22:49', 1, '2026-09-13 12:12:49'),
(45, 'brixxharoldanicetocostales01@gmail.com', '159468', 'register', '2026-09-13 14:27:57', 1, '2026-09-13 12:17:57'),
(46, 'brixxharoldanicetocostales01@gmail.com', '222677', 'register', '2026-09-13 20:55:52', 1, '2026-09-13 18:45:52'),
(47, 'geleentapaoan@gmail.com', '530994', 'register', '2026-09-14 03:44:21', 1, '2026-09-14 01:34:21'),
(48, 'geleentapaoan@gmail.com', '239265', 'register', '2026-09-14 03:46:31', 1, '2026-09-14 01:36:32'),
(49, 'brixxharoldanicetocostales01@gmail.com', '637577', 'reset', '2026-09-14 05:00:08', 1, '2026-09-14 02:50:08');

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`id`, `student_id`, `class_id`) VALUES
(125, 124, 21),
(126, 124, 22),
(127, 124, 58),
(128, 143, 52),
(129, 143, 53),
(130, 143, 73);

-- --------------------------------------------------------

--
-- Table structure for table `evaluations`
--

CREATE TABLE `evaluations` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `academic_period_id` int(11) DEFAULT NULL,
  `target_name` varchar(100) DEFAULT 'Faculty',
  `feedback` text DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--  
-- Dumping data for table `evaluations`
--

INSERT INTO `evaluations` (`id`, `student_id`, `faculty_id`, `subject_id`, `academic_period_id`, `target_name`, `feedback`, `rating`, `created_at`) VALUES
(7, 124, 88, 1, 1, '0', 'You are great at teaching, and you are very kind.', 1.50, '2026-09-13 19:26:04'),
(8, 124, 89, 19, 1, '0', 'medium rare.', 3.00, '2026-09-13 19:27:16'),
(9, 124, 125, 20, 1, '0', 'You do not know how to teach, and you are very strict.', 4.50, '2026-09-13 19:48:42'),
(10, 88, NULL, NULL, 1, 'Science Lab', 'Great lab facilities and updated equipment for research!', 4.67, '2026-09-14 02:31:12'),
(11, 61, NULL, NULL, 1, 'Canteen Services', 'Clean canteen area and courteous staff. Meal variety can be expanded.', 4.33, '2026-09-14 02:31:12'),
(12, 88, NULL, NULL, 1, 'Computer Lab', 'The computer laboratory equipment and internet connectivity are excellent and well-maintained.', 5.00, '2026-09-14 02:33:19'),
(13, 61, NULL, NULL, 1, 'School Library', 'The library resources and environment are outstanding.', 5.00, '2026-09-14 02:35:27');

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_categories`
--

CREATE TABLE `evaluation_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `evaluation_categories`
--

INSERT INTO `evaluation_categories` (`id`, `category_name`) VALUES
(15, 'Campus Common Areas'),
(2, 'Canteen Services'),
(13, 'Classrooms'),
(14, 'Comfort Rooms'),
(4, 'Computer Lab'),
(7, 'Finance Office'),
(1, 'General School'),
(10, 'Guidance Office'),
(12, 'IT & WiFi Services'),
(6, 'Registrar Office'),
(11, 'School Clinic'),
(5, 'School Gym'),
(8, 'School Library'),
(3, 'Science Lab'),
(9, 'Security Unit');

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_questions`
--

CREATE TABLE `evaluation_questions` (
  `id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `category` varchar(100) DEFAULT 'General'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `evaluation_questions`
--

INSERT INTO `evaluation_questions` (`id`, `question_text`, `category`) VALUES
(1, 'Overall quality and standard of academic programs offered by the institution', 'School'),
(3, 'Availability of gym schedules and fairness in usage allocation for students', 'Gym'),
(4, 'Accessibility and availability of guidance counselors during school hours', 'Guidance'),
(5, 'Availability of essential medicines and first-aid supplies in the school clinic', 'Clinic'),
(6, 'Responsiveness and helpfulness of IT support staff for technical issues', 'IT-Services'),
(7, 'Responsiveness of the institution to student concerns and feedback', 'School'),
(8, 'Demonstrates deep knowledge, mastery, and understanding of the subject matter.', 'Teaching'),
(9, 'Explains complex concepts clearly, audibly, and at a well-paced speed.', 'Teaching'),
(10, 'Organizes lectures logically and follows the approved syllabus outline.', 'Teaching'),
(11, 'Uses relevant real-world examples, visual aids, and interactive discussions.', 'Teaching'),
(12, 'Encourages critical thinking, student questions, and active participation in class.', 'Teaching'),
(13, 'Starts and ends classes on time, demonstrating professional punctuality.', 'Professionalism'),
(14, 'Maintains a respectful, orderly, and conducive learning environment.', 'Professionalism'),
(15, 'Treats all students with fairness, equity, and professional integrity.', 'Professionalism'),
(16, 'Exhibits professional conduct, proper grooming, and ethical behavior at all times.', 'Professionalism'),
(17, 'Administers quizzes, exams, and tasks that directly align with course learning outcomes.', 'Assessment'),
(18, 'Grades assignments objectively and transparently according to clear criteria.', 'Assessment'),
(19, 'Returns tests and graded requirements promptly with constructive feedback.', 'Assessment'),
(20, 'Shows approachability and is accessible during designated academic consultation hours.', 'Support'),
(21, 'Provides helpful, encouraging, and actionable guidance to support student success.', 'Support'),
(22, 'Overall institutional standard, excellence, and reputation of the campus.', 'School'),
(23, 'Responsiveness and transparency of school administration to student concerns.', 'School'),
(24, 'Promotion of student safety, academic integrity, and a positive learning culture.', 'School'),
(25, 'Cleanliness, hygiene, and sanitation of the dining hall and food preparation counters.', 'Canteen'),
(26, 'Nutritional value, quality, affordability, and variety of meals and beverages.', 'Canteen'),
(27, 'Courtesy, promptness, and efficiency of canteen staff during break periods.', 'Canteen'),
(28, 'Availability and adequacy of scientific reagents, apparatus, and computer equipment.', 'Laboratory'),
(29, 'Modernity, software reliability, and proper safety equipment inside the laboratories.', 'Laboratory'),
(30, 'Cleanliness, ventilation, and helpful supervision provided by laboratory technicians.', 'Laboratory'),
(31, 'Maintenance, cleanliness, and safety of sports equipment and athletic courts.', 'Gym'),
(32, 'Fair scheduling and accessibility of gymnasium facilities for student activities.', 'Gym'),
(33, 'Promptness and processing efficiency of student records, grades, and certifications.', 'Registrar'),
(34, 'Helpfulness, courtesy, and professionalism of Registrar personnel when assisting students.', 'Registrar'),
(35, 'Transparency and accuracy of tuition billing statements and payment records.', 'Finance'),
(36, 'Wait time, queue efficiency, and cashier service speed during assessment and payment.', 'Finance'),
(37, 'Availability of updated books, reference materials, journals, and online academic databases.', 'Library'),
(38, 'Quiet, well-ventilated, and conducive study environment with functional workstations.', 'Library'),
(39, 'Approachability, assistance, and guidance offered by librarians and staff.', 'Library'),
(40, 'Vigilance, visibility, and alertness of campus security guards at all entry and exit points.', 'Security'),
(41, 'Fairness, politeness, and effectiveness in implementing campus safety guidelines.', 'Security'),
(42, 'Accessibility and readiness of guidance counselors to support student mental wellness.', 'Guidance'),
(43, 'Confidentiality, empathy, and constructive advice provided during counseling sessions.', 'Guidance'),
(44, 'Immediate availability of licensed medical staff, first-aid supplies, and basic medicines.', 'Clinic'),
(45, 'Quality of emergency medical care, warmth, and attention given to sick or injured students.', 'Clinic'),
(46, 'Speed, reliability, and coverage of Campus WiFi and internet infrastructure.', 'IT-Services'),
(47, 'Prompt technical support for student portal access, passwords, and online learning tools.', 'IT-Services'),
(48, 'Adequate lighting, good acoustics, and comfortable room ventilation in all classrooms.', 'Classrooms'),
(49, 'Good working condition of instructional equipment like smart TVs, projectors, and whiteboards.', 'Classrooms'),
(50, 'Regular cleaning, sanitation, and sufficient water supply in student restrooms (CRs).', 'Facilities'),
(51, 'Upkeep, cleanliness, and maintenance of corridors, stairways, and campus common spaces.', 'Facilities');

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_responses`
--

CREATE TABLE `evaluation_responses` (
  `id` int(11) NOT NULL,
  `evaluation_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `evaluation_responses`
--

INSERT INTO `evaluation_responses` (`id`, `evaluation_id`, `question_id`, `rating`) VALUES
(45, 7, 8, 1),
(46, 7, 9, 2),
(47, 7, 10, 1),
(48, 7, 11, 2),
(49, 7, 12, 1),
(50, 7, 13, 2),
(51, 7, 14, 1),
(52, 7, 15, 2),
(53, 7, 16, 1),
(54, 7, 17, 2),
(55, 7, 18, 1),
(56, 7, 19, 2),
(57, 7, 20, 1),
(58, 7, 21, 2),
(59, 8, 8, 3),
(60, 8, 9, 3),
(61, 8, 10, 3),
(62, 8, 11, 3),
(63, 8, 12, 3),
(64, 8, 13, 3),
(65, 8, 14, 3),
(66, 8, 15, 3),
(67, 8, 16, 3),
(68, 8, 17, 3),
(69, 8, 18, 3),
(70, 8, 19, 3),
(71, 8, 20, 3),
(72, 8, 21, 3),
(73, 9, 8, 5),
(74, 9, 9, 4),
(75, 9, 10, 5),
(76, 9, 11, 4),
(77, 9, 12, 5),
(78, 9, 13, 4),
(79, 9, 14, 5),
(80, 9, 15, 4),
(81, 9, 16, 5),
(82, 9, 17, 4),
(83, 9, 18, 5),
(84, 9, 19, 4),
(85, 9, 20, 5),
(86, 9, 21, 4),
(87, 10, 28, 5),
(88, 10, 29, 5),
(89, 10, 30, 5),
(90, 12, 28, 5),
(91, 12, 29, 5),
(92, 12, 30, 5),
(93, 13, 37, 5),
(94, 13, 38, 5),
(95, 13, 39, 5);

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `subject_code` varchar(50) NOT NULL,
  `subject_description` varchar(255) NOT NULL,
  `dept_id` int(11) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `subject_code`, `subject_description`, `dept_id`, `course_id`, `faculty_id`, `year_level`, `section`) VALUES
(1, 'IT101', 'Introduction to Computing', 3, 10, 88, '1st Year', 'A'),
(2, 'PROG1', 'Programming 1', 3, NULL, NULL, '1st Year', 'A'),
(3, 'NUR101', 'Anatomy and Physiology for Nursing', 7, 14, 114, '1st Year', 'A'),
(4, 'ANAT1', 'Anatomy and Physiology', 7, NULL, NULL, '1st Year', 'A'),
(5, 'ACC101', 'Financial Accounting and Reporting', 2, 7, 92, '1st Year', 'A'),
(6, 'MGT101', 'Human Resource Management', 2, NULL, NULL, '1st Year', 'A'),
(7, 'CRIM1', 'Introduction to Criminology', 4, NULL, NULL, '1st Year', 'A'),
(8, 'CRIM2', 'Philippine Criminal Justice System', 4, NULL, NULL, '1st Year', 'A'),
(19, 'IT102', 'Data Structures and Algorithms', 3, 10, 89, NULL, NULL),
(20, 'IT103', 'Web Systems and Technologies', 3, 10, 125, NULL, NULL),
(21, 'CPE101', 'Digital Logic and Design', 3, 11, 90, NULL, NULL),
(22, 'CPE102', 'Microprocessors and Embedded Systems', 3, 11, 91, NULL, NULL),
(23, 'ACC102', 'Cost Accounting and Control', 2, 7, 93, NULL, NULL),
(24, 'BA101', 'Principles of Marketing', 2, 6, 94, NULL, NULL),
(25, 'BA102', 'Business Finance and Management', 2, 6, 95, NULL, NULL),
(26, 'CA101', 'Customs Laws and Customs Administration', 2, 5, 96, NULL, NULL),
(27, 'CA102', 'Tariff and International Trade Agreements', 2, 5, 97, NULL, NULL),
(28, 'HM101', 'Culinary Arts and Kitchen Operations', 2, 8, 98, NULL, NULL),
(29, 'HM102', 'Front Office and Rooms Division Operations', 2, 8, 99, NULL, NULL),
(30, 'TM101', 'Tourism Planning and Development', 2, 9, 100, NULL, NULL),
(31, 'TM102', 'Tour Operations and Airline Ticketing', 2, 9, 101, NULL, NULL),
(32, 'CRIM101', 'Introduction to Criminology', 4, 12, 102, NULL, NULL),
(33, 'CRIM102', 'Criminal Jurisprudence and Procedure', 4, 12, 103, NULL, NULL),
(34, 'SW101', 'Social Welfare Policies and Programs', 1, 1, 104, NULL, NULL),
(35, 'SW102', 'Social Work Methods and Casework', 1, 1, 105, NULL, NULL),
(36, 'ED101', 'Facilitating Learner-Centered Teaching', 1, 2, 106, NULL, NULL),
(37, 'ED102', 'Assessment in Learning', 1, 2, 107, NULL, NULL),
(38, 'PSY101', 'Theories of Personality', 1, 3, 108, NULL, NULL),
(39, 'PSY102', 'Abnormal Psychology', 1, 3, 109, NULL, NULL),
(40, 'PED101', 'Movement Education and Motor Learning', 1, 4, 110, NULL, NULL),
(41, 'PED102', 'Individual, Dual, and Team Sports', 1, 4, 111, NULL, NULL),
(42, 'MLS101', 'Clinical Hematology', 5, 13, 112, NULL, NULL),
(43, 'MLS102', 'Immunology, Serology and Blood Banking', 5, 13, 113, NULL, NULL),
(44, 'NUR102', 'Care of Mother, Child and Adolescent', 7, 14, 115, NULL, NULL),
(45, 'MID101', 'Fundamentals of Midwifery Care', 7, 15, 116, NULL, NULL),
(46, 'MID102', 'Obstetric and Neonatal Care', 7, 15, 117, NULL, NULL),
(47, 'PHAR101', 'Pharmaceutical Chemistry and Biochemistry', 8, 16, 118, NULL, NULL),
(48, 'PHAR102', 'Pharmacology and Pharmacokinetics', 8, 16, 119, NULL, NULL),
(49, 'PT101', 'Gross Human Anatomy and Kinesiology', 9, 17, 120, NULL, NULL),
(50, 'PT102', 'Biomechanics and Therapeutic Exercises', 9, 17, 121, NULL, NULL),
(51, 'RAD101', 'Radiographic Positioning and Anatomy', 10, 18, 122, NULL, NULL),
(52, 'RAD102', 'Radiation Physics, Radiobiology and Protection', 10, 18, 123, NULL, NULL),
(53, 'CPE103', 'Computer Architecture and Organization', 3, 11, 126, NULL, NULL),
(54, 'ACC103', 'Taxation and Auditing Principles', 2, 7, 127, NULL, NULL),
(55, 'BA103', 'Human Resource Management', 2, 6, 128, NULL, NULL),
(56, 'CA103', 'Customs Valuation and Classification', 2, 5, 129, NULL, NULL),
(57, 'HM103', 'Food and Beverage Service Management', 2, 8, 130, NULL, NULL),
(58, 'TM103', 'Heritage Tourism and Destination Marketing', 2, 9, 131, NULL, NULL),
(59, 'CRIM103', 'Forensic Science and Crime Detection', 4, 12, 132, NULL, NULL),
(60, 'SW103', 'Community Organizing and Social Action', 1, 1, 133, NULL, NULL),
(61, 'ED103', 'Curriculum Development and Pedagogy', 1, 2, 134, NULL, NULL),
(62, 'PSY103', 'Psychological Assessment and Testing', 1, 3, 135, NULL, NULL),
(63, 'PED103', 'Sports Coaching and Officiating', 1, 4, 136, NULL, NULL),
(64, 'MLS103', 'Clinical Chemistry and Urinalysis', 5, 13, 137, NULL, NULL),
(65, 'NUR103', 'Pharmacology for Nurses and Clinical Practice', 7, 14, 138, NULL, NULL),
(66, 'MID103', 'Clinical Practicum in Maternal Care', 7, 15, 139, NULL, NULL),
(67, 'PHAR103', 'Pharmacy Practice and Dispensing', 8, 16, 140, NULL, NULL),
(68, 'PT103', 'Neurological and Orthopedic Physical Therapy', 9, 17, 141, NULL, NULL),
(69, 'RAD103', 'Imaging Equipment and Patient Care', 10, 18, 142, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `surveys`
--

CREATE TABLE `surveys` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `target_audience` enum('incoming','continuing','alumni') NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `survey_responses`
--

CREATE TABLE `survey_responses` (
  `id` int(11) NOT NULL,
  `survey_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `respondent_type` enum('anonymous','registered_user') NOT NULL,
  `responses` text NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `level` varchar(20) NOT NULL DEFAULT 'info',
  `message` varchar(255) NOT NULL,
  `context` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_logs`
--

INSERT INTO `system_logs` (`id`, `level`, `message`, `context`, `user_id`, `timestamp`) VALUES
(1, 'info', 'New user registered: admin User', 'User ID: 4, Role: admin, Status: active', 4, '2026-07-26 16:57:50'),
(2, 'info', 'New user registered: Brixx Harold Costales', 'User ID: 8, Role: student, Status: pending', NULL, '2026-07-28 15:52:43'),
(3, 'info', 'User registration approved: Brixx Harold Costales', 'Approved User ID: 8, Role: student', 4, '2026-07-28 15:53:41'),
(4, 'info', 'New user registered: admin User', 'User ID: 9, Role: system admin, Status: active', NULL, '2026-07-28 16:01:38'),
(5, 'info', 'New user registered: Amelia Faith', 'User ID: 13, Role: dean, Status: pending', NULL, '2026-07-29 02:24:27'),
(6, 'info', 'User registration approved: Amelia Faith', 'Approved User ID: 13, Role: dean', 4, '2026-07-29 02:24:48'),
(7, 'info', 'New user registered: Trisha Herrerra', 'User ID: 14, Role: faculty, Status: pending', NULL, '2026-07-29 02:27:20'),
(8, 'info', 'User registration approved: Trisha Herrerra', 'Approved User ID: 14, Role: faculty', 4, '2026-07-29 02:30:12'),
(9, 'info', 'New user registered: Avery Kyle Biggaong', 'User ID: 16, Role: student, Status: pending', NULL, '2026-07-30 02:43:48'),
(10, 'info', 'User registration approved: Avery Kyle Biggaong', 'Approved User ID: 16, Role: student', 4, '2026-07-30 03:36:21'),
(11, 'warning', 'Admin reset password for a user.', 'Admin ID: 4, User ID: 15', 4, '2026-07-30 11:46:47'),
(12, 'info', 'New user registered: Sheenna Lei Tulauan', 'User ID: 17, Role: student, Status: pending', NULL, '2026-08-04 09:32:13'),
(13, 'info', 'User registration approved: Sheenna Lei Tulauan', 'Approved User ID: 17, Role: student', 4, '2026-08-04 09:32:38'),
(14, 'info', 'New user registered: Geleen Tapaoan', 'User ID: 18, Role: student, Status: pending', NULL, '2026-08-04 13:27:51'),
(15, 'info', 'User registration approved: Geleen Tapaoan', 'Approved User ID: 18, Role: student', 4, '2026-08-04 13:28:14'),
(16, 'info', 'New user registered: Trisha Herrerra', 'User ID: 22, Role: faculty, Status: pending', NULL, '2026-08-04 13:33:56'),
(17, 'info', 'User registration approved: Trisha Herrerra', 'Approved User ID: 22, Role: faculty', 4, '2026-08-04 13:34:19'),
(18, 'info', 'User registration approved: Brixx Harold Aniceto Costales', 'Approved User ID: 23, Role: student', 4, '2026-08-05 02:23:57'),
(19, 'info', 'User registration approved: Trisha Herrerra', 'Approved User ID: 24, Role: student', 4, '2026-08-05 02:27:02'),
(20, 'info', 'User registration approved: Trisha Herrerra', 'Approved User ID: 83, Role: visitor', 4, '2026-08-27 16:28:12'),
(21, 'info', 'User registration approved: Brixx Harold Costales', 'Approved User ID: 82, Role: student', 4, '2026-08-27 16:28:14'),
(22, 'info', 'User registration approved: Geleen Tapaoan', 'Approved User ID: 81, Role: student', 4, '2026-08-27 16:28:18'),
(23, 'info', 'User registration approved: Brixx Harold Costales', 'Approved User ID: 86, Role: student', 4, '2026-08-28 04:22:57'),
(24, 'info', 'User registration approved: Sheenna Lei Tulauan', 'Approved User ID: 85, Role: student', 4, '2026-08-28 13:16:24'),
(25, 'info', 'User registration approved: Brixx Harold Costales', 'Approved User ID: 87, Role: student', 4, '2026-09-13 12:18:42'),
(26, 'info', 'User registration approved: Brixx Harold Costales', 'Approved User ID: 124, Role: student', 4, '2026-09-13 18:46:55');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('evaluation_status', 'open', 'Controls if the evaluation form is accessible to students'),
('faculty_results_visible', 'visible', 'Controls whether faculty can view their own evaluation results'),
('max_rating', '5', 'The maximum scale for evaluation ratings (e.g. 5 or 10)'),
('min_feedback_length', '10', 'Minimum characters required for the general feedback field'),
('system_name', 'Faculty Evaluation System', 'The branding name of the application'),
('theme_accent', '#b71c1c', NULL),
('theme_background', '#f4f6f9', NULL),
('theme_dark_accent', '#b71c1c', NULL),
('theme_dark_background', '#0b1121', NULL),
('theme_dark_card', '#161b2c', NULL),
('theme_dark_primary', '#1a237e', NULL),
('theme_dark_text', '#f8fafc', NULL),
('theme_primary', '#283593', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','system admin','dean','student','faculty','visitor') NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `school` enum('mcnp','isap') DEFAULT NULL,
  `dept_id` int(11) DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive','pending') NOT NULL DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `theme_color` varchar(20) DEFAULT 'blue'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `first_name`, `middle_name`, `last_name`, `full_name`, `email`, `school`, `dept_id`, `reset_token`, `reset_token_expiry`, `course_id`, `status`, `last_login`, `avatar`, `created_at`, `theme_color`) VALUES
(4, 'admin_user', '$2y$10$f7fMeZmGWYbeIzykcywgAePdanho1Ldl.laNh4B.oFKarbOL5h0ra', 'admin', 'admin', '', 'User', 'admin User', 'admin@mcnpisap.edu.ph', NULL, NULL, NULL, NULL, NULL, 'active', '2026-09-14 14:43:51', 'uploads/avatars/avatar_6a71ed4a0374a8.43585752.jpg', '2026-07-26 16:57:50', 'blue'),
(61, 'dean_caste', '$2y$10$6tjOfRxwuAsJUxZ.03tBaOymOYRWFV06PG2wWPhX67g83xSEEle4e', 'dean', NULL, NULL, NULL, 'Dean CASTE', 'dean.caste@example.com', 'isap', 1, NULL, NULL, NULL, 'active', NULL, NULL, '2026-08-27 14:31:31', 'blue'),
(63, 'dean_cbem', '$2y$10$6tjOfRxwuAsJUxZ.03tBaOymOYRWFV06PG2wWPhX67g83xSEEle4e', 'dean', NULL, NULL, NULL, 'Dean CBEM', 'dean.cbem@example.com', 'isap', 2, NULL, NULL, NULL, 'active', NULL, NULL, '2026-08-27 14:31:31', 'blue'),
(65, 'dean_cite', '$2y$10$6tjOfRxwuAsJUxZ.03tBaOymOYRWFV06PG2wWPhX67g83xSEEle4e', 'dean', NULL, NULL, NULL, 'Dean CITE', 'dean.cite@example.com', 'isap', 3, NULL, NULL, NULL, 'active', NULL, NULL, '2026-08-27 14:31:31', 'blue'),
(67, 'dean_cocje', '$2y$10$6tjOfRxwuAsJUxZ.03tBaOymOYRWFV06PG2wWPhX67g83xSEEle4e', 'dean', NULL, NULL, NULL, 'Dean COCJE', 'dean.cocje@example.com', 'isap', 4, NULL, NULL, NULL, 'active', NULL, NULL, '2026-08-27 14:31:31', 'blue'),
(69, 'dean_cmls', '$2y$10$6tjOfRxwuAsJUxZ.03tBaOymOYRWFV06PG2wWPhX67g83xSEEle4e', 'dean', NULL, NULL, NULL, 'Dean CMLS', 'dean.cmls@example.com', 'mcnp', 5, NULL, NULL, NULL, 'active', NULL, NULL, '2026-08-27 14:31:31', 'blue'),
(71, 'dean_com', '$2y$10$6tjOfRxwuAsJUxZ.03tBaOymOYRWFV06PG2wWPhX67g83xSEEle4e', 'dean', NULL, NULL, NULL, 'Dean COM', 'dean.com@example.com', 'mcnp', 6, NULL, NULL, NULL, 'active', NULL, NULL, '2026-08-27 14:31:31', 'blue'),
(73, 'dean_con', '$2y$10$6tjOfRxwuAsJUxZ.03tBaOymOYRWFV06PG2wWPhX67g83xSEEle4e', 'dean', NULL, NULL, NULL, 'Dean CON', 'dean.con@example.com', 'mcnp', 7, NULL, NULL, NULL, 'active', NULL, NULL, '2026-08-27 14:31:31', 'blue'),
(75, 'dean_cop', '$2y$10$6tjOfRxwuAsJUxZ.03tBaOymOYRWFV06PG2wWPhX67g83xSEEle4e', 'dean', NULL, NULL, NULL, 'Dean COP', 'dean.cop@example.com', 'mcnp', 8, NULL, NULL, NULL, 'active', NULL, NULL, '2026-08-27 14:31:31', 'blue'),
(77, 'dean_copt', '$2y$10$6tjOfRxwuAsJUxZ.03tBaOymOYRWFV06PG2wWPhX67g83xSEEle4e', 'dean', NULL, NULL, NULL, 'Dean COPT', 'dean.copt@example.com', 'mcnp', 9, NULL, NULL, NULL, 'active', NULL, NULL, '2026-08-27 14:31:31', 'blue'),
(79, 'dean_cort', '$2y$10$6tjOfRxwuAsJUxZ.03tBaOymOYRWFV06PG2wWPhX67g83xSEEle4e', 'dean', NULL, NULL, NULL, 'Dean CORT', 'dean.cort@example.com', 'mcnp', 10, NULL, NULL, NULL, 'active', NULL, NULL, '2026-08-27 14:31:31', 'blue'),
(83, 'Trisha', '$2y$10$M6pKI3k2dj8E8ifIEEsFBea7rmn/l4uvFtzEfVhyLFEtJtdxOlNeu', 'visitor', 'Trisha', '', 'Herrerra', 'Trisha Herrerra', 'trzvshiiaa823@gmail.com', 'isap', NULL, NULL, NULL, NULL, 'active', '2026-08-28 12:09:40', NULL, '2026-08-27 16:27:02', 'blue'),
(84, 'sysadmin_ict', '$2y$10$rEpLjMGCxJHb1RniWJWCJelMap71DpbYpQAEOWANthDZeX.8rQsUu', 'system admin', NULL, NULL, NULL, 'admin_ict', 'adminict@mcnpisap.edu.ph', NULL, NULL, NULL, NULL, NULL, 'active', '2026-08-28 12:38:46', NULL, '2026-08-27 17:14:08', 'blue'),
(88, 'EMP-BSIT-01', '$2y$10$F5XayyH9cwD6SFH1DjacZ.GIPhKI9BKIaaDNub1/wWSSNwdKHhY.u', 'faculty', 'Mark Anthony', NULL, 'Santos', 'Mark Anthony Santos', 'mark.santos@isap.edu.ph', 'isap', 3, NULL, NULL, 10, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(89, 'EMP-BSIT-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Jessica', NULL, 'Reyes', 'Jessica Reyes', 'jessica.reyes@isap.edu.ph', 'isap', 3, NULL, NULL, 10, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(90, 'EMP-BSCPE-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Paul Vincent', NULL, 'Bautista', 'Paul Vincent Bautista', 'paul.bautista@isap.edu.ph', 'isap', 3, NULL, NULL, 11, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(91, 'EMP-BSCPE-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Catherine', NULL, 'Alcantara', 'Catherine Alcantara', 'catherine.alcantara@isap.edu.ph', 'isap', 3, NULL, NULL, 11, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(92, 'EMP-BSAC-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Eduardo', NULL, 'Lim', 'Eduardo Lim', 'eduardo.lim@isap.edu.ph', 'isap', 2, NULL, NULL, 7, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(93, 'EMP-BSAC-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Maricel', NULL, 'Tan', 'Maricel Tan', 'maricel.tan@isap.edu.ph', 'isap', 2, NULL, NULL, 7, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(94, 'EMP-BSBA-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Roberto', NULL, 'Navarro', 'Roberto Navarro', 'roberto.navarro@isap.edu.ph', 'isap', 2, NULL, NULL, 6, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(95, 'EMP-BSBA-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Elena', NULL, 'Gutierrez', 'Elena Gutierrez', 'elena.gutierrez@isap.edu.ph', 'isap', 2, NULL, NULL, 6, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(96, 'EMP-BSCA-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Danilo', NULL, 'Perez', 'Danilo Perez', 'danilo.perez@isap.edu.ph', 'isap', 2, NULL, NULL, 5, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(97, 'EMP-BSCA-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Grace', NULL, 'Tolentino', 'Grace Tolentino', 'grace.tolentino@isap.edu.ph', 'isap', 2, NULL, NULL, 5, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(98, 'EMP-BSHM-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Marco', NULL, 'Rivera', 'Marco Rivera', 'marco.rivera@isap.edu.ph', 'isap', 2, NULL, NULL, 8, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(99, 'EMP-BSHM-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Melissa', NULL, 'Castro', 'Melissa Castro', 'melissa.castro@isap.edu.ph', 'isap', 2, NULL, NULL, 8, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(100, 'EMP-BSTM-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Christine', NULL, 'Mercado', 'Christine Mercado', 'christine.mercado@isap.edu.ph', 'isap', 2, NULL, NULL, 9, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(101, 'EMP-BSTM-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Gabriel', NULL, 'Aquino', 'Gabriel Aquino', 'gabriel.aquino@isap.edu.ph', 'isap', 2, NULL, NULL, 9, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(102, 'EMP-BSCRIM-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Jaime', NULL, 'Dela Rosa', 'Jaime Dela Rosa', 'jaime.delarosa@isap.edu.ph', 'isap', 4, NULL, NULL, 12, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(103, 'EMP-BSCRIM-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Victor', NULL, 'Magno', 'Victor Magno', 'victor.magno@isap.edu.ph', 'isap', 4, NULL, NULL, 12, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(104, 'EMP-BSSW-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Patricia', NULL, 'Villanueva', 'Patricia Villanueva', 'patricia.villanueva@isap.edu.ph', 'isap', 1, NULL, NULL, 1, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(105, 'EMP-BSSW-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Arthur', NULL, 'Soriano', 'Arthur Soriano', 'arthur.soriano@isap.edu.ph', 'isap', 1, NULL, NULL, 1, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(106, 'EMP-BSED-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Manuel', NULL, 'Enriquez', 'Manuel Enriquez', 'manuel.enriquez@isap.edu.ph', 'isap', 1, NULL, NULL, 2, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(107, 'EMP-BSED-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Rosemarie', NULL, 'Bernardo', 'Rosemarie Bernardo', 'rosemarie.bernardo@isap.edu.ph', 'isap', 1, NULL, NULL, 2, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(108, 'EMP-BSPSY-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Andrea', NULL, 'Salazar', 'Andrea Salazar', 'andrea.salazar@isap.edu.ph', 'isap', 1, NULL, NULL, 3, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(109, 'EMP-BSPSY-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Jonathan', NULL, 'Pascual', 'Jonathan Pascual', 'jonathan.pascual@isap.edu.ph', 'isap', 1, NULL, NULL, 3, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(110, 'EMP-BPED-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Ronald', NULL, 'Garcia', 'Ronald Garcia', 'ronald.garcia@isap.edu.ph', 'isap', 1, NULL, NULL, 4, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(111, 'EMP-BPED-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Diana', NULL, 'Mendoza', 'Diana Mendoza', 'diana.mendoza@isap.edu.ph', 'isap', 1, NULL, NULL, 4, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(112, 'EMP-BSMLS-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Fernando', NULL, 'Alonzo', 'Fernando Alonzo', 'fernando.alonzo@mcnp.edu.ph', 'mcnp', 5, NULL, NULL, 13, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(113, 'EMP-BSMLS-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Joanna', NULL, 'David', 'Joanna David', 'joanna.david@mcnp.edu.ph', 'mcnp', 5, NULL, NULL, 13, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(114, 'EMP-BSN-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Mary Grace', NULL, 'Ramos', 'Mary Grace Ramos', 'grace.ramos@mcnp.edu.ph', 'mcnp', 7, NULL, NULL, 14, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(115, 'EMP-BSN-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Michael Angelo', NULL, 'Cruz', 'Michael Angelo Cruz', 'michael.cruz@mcnp.edu.ph', 'mcnp', 7, NULL, NULL, 14, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(116, 'EMP-MID-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Corazon', NULL, 'Espiritu', 'Corazon Espiritu', 'corazon.espiritu@mcnp.edu.ph', 'mcnp', 7, NULL, NULL, 15, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(117, 'EMP-MID-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Lourdes', NULL, 'Beltran', 'Lourdes Beltran', 'lourdes.beltran@mcnp.edu.ph', 'mcnp', 7, NULL, NULL, 15, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(118, 'EMP-BSPH-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Ramon', NULL, 'Castillo', 'Ramon Castillo', 'ramon.castillo@mcnp.edu.ph', 'mcnp', 8, NULL, NULL, 16, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(119, 'EMP-BSPH-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Angela', NULL, 'Valdez', 'Angela Valdez', 'angela.valdez@mcnp.edu.ph', 'mcnp', 8, NULL, NULL, 16, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(120, 'EMP-BSPT-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Vincent', NULL, 'Ferrer', 'Vincent Ferrer', 'vincent.ferrer@mcnp.edu.ph', 'mcnp', 9, NULL, NULL, 17, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(121, 'EMP-BSPT-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Theresa', NULL, 'Ocampo', 'Theresa Ocampo', 'theresa.ocampo@mcnp.edu.ph', 'mcnp', 9, NULL, NULL, 17, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(122, 'EMP-BSRT-01', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Gregorio', NULL, 'Santiago', 'Gregorio Santiago', 'gregorio.santiago@mcnp.edu.ph', 'mcnp', 10, NULL, NULL, 18, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(123, 'EMP-BSRT-02', '$2y$10$KotmRBZxMNqq3KSW51fQ.uKene9FCOCHSyRvsax2osdK/0Ekf42u2', 'faculty', 'Lorena', NULL, 'Delos Santos', 'Lorena Delos Santos', 'lorena.delossantos@mcnp.edu.ph', 'mcnp', 10, NULL, NULL, 18, 'active', NULL, NULL, '2026-09-13 17:54:04', 'blue'),
(124, '2023-10408', '$2y$10$kSrkxl3hGS1pf.Lm2/SLDOZ8es3Uv3Zu2Nqtw359WYPVM6cYpUJ8m', 'student', 'Brixx Harold', '', 'Costales', 'Brixx Harold Costales', 'brixxharoldanicetocostales01@gmail.com', 'isap', 3, NULL, NULL, 10, 'active', '2026-09-14 14:27:14', NULL, '2026-09-13 18:46:34', 'blue'),
(125, 'EMP-BSIT-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'John Dominic', NULL, 'Cruz', 'John Dominic Cruz', 'john.cruz@isap.edu.ph', 'isap', 3, NULL, NULL, 10, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(126, 'EMP-BSCPE-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Allan Christopher', NULL, 'Reyes', 'Allan Christopher Reyes', 'allan.reyes@isap.edu.ph', 'isap', 3, NULL, NULL, 11, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(127, 'EMP-BSAC-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Antonio', NULL, 'Villanueva', 'Antonio Villanueva', 'antonio.villanueva@isap.edu.ph', 'isap', 2, NULL, NULL, 7, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(128, 'EMP-BSBA-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Marissa', NULL, 'Salvador', 'Marissa Salvador', 'marissa.salvador@isap.edu.ph', 'isap', 2, NULL, NULL, 6, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(129, 'EMP-BSCA-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Leandro', NULL, 'De Guzman', 'Leandro De Guzman', 'leandro.deguzman@isap.edu.ph', 'isap', 2, NULL, NULL, 5, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(130, 'EMP-BSHM-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Christine Joy', NULL, 'David', 'Christine Joy David', 'christine.david@isap.edu.ph', 'isap', 2, NULL, NULL, 8, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(131, 'EMP-BSTM-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Bernadette', NULL, 'Ramos', 'Bernadette Ramos', 'bernadette.ramos@isap.edu.ph', 'isap', 2, NULL, NULL, 9, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(132, 'EMP-BSCRIM-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Ricardo', NULL, 'Galvez', 'Ricardo Galvez', 'ricardo.galvez@isap.edu.ph', 'isap', 4, NULL, NULL, 12, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(133, 'EMP-BSSW-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Ma. Theresa', NULL, 'Santos', 'Ma. Theresa Santos', 'theresa.santos@isap.edu.ph', 'isap', 1, NULL, NULL, 1, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(134, 'EMP-BSED-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Clarissa', NULL, 'Dizon', 'Clarissa Dizon', 'clarissa.dizon@isap.edu.ph', 'isap', 1, NULL, NULL, 2, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(135, 'EMP-BSPSY-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Christian Ray', NULL, 'Ramos', 'Christian Ray Ramos', 'christian.ramos@isap.edu.ph', 'isap', 1, NULL, NULL, 3, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(136, 'EMP-BPED-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Noel', NULL, 'Macaraeg', 'Noel Macaraeg', 'noel.macaraeg@isap.edu.ph', 'isap', 1, NULL, NULL, 4, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(137, 'EMP-BSMLS-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Katrina Bianca', NULL, 'Flores', 'Katrina Bianca Flores', 'katrina.flores@mcnp.edu.ph', 'mcnp', 5, NULL, NULL, 13, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(138, 'EMP-BSN-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Kristine Joy', NULL, 'Morales', 'Kristine Joy Morales', 'kristine.morales@mcnp.edu.ph', 'mcnp', 7, NULL, NULL, 14, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(139, 'EMP-MID-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Rowena', NULL, 'Cabanilla', 'Rowena Cabanilla', 'rowena.cabanilla@mcnp.edu.ph', 'mcnp', 7, NULL, NULL, 15, 'active', NULL, NULL, '2026-09-13 19:04:12', 'blue'),
(140, 'EMP-BSPH-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Jennifer Joy', NULL, 'Santos', 'Jennifer Joy Santos', 'jennifer.santos@mcnp.edu.ph', 'mcnp', 8, NULL, NULL, 16, 'active', NULL, NULL, '2026-09-13 19:04:15', 'blue'),
(141, 'EMP-BSPT-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Patrick Neil', NULL, 'Soriano', 'Patrick Neil Soriano', 'patrick.soriano@mcnp.edu.ph', 'mcnp', 9, NULL, NULL, 17, 'active', NULL, NULL, '2026-09-13 19:04:15', 'blue'),
(142, 'EMP-BSRT-03', '$2y$10$S.jf3eEH5jPL/gGbZXGv0u6AWSImXfYMDGYTlT3rry5KQzHKgsUzG', 'faculty', 'Jerome', NULL, 'Mallillin', 'Jerome Mallillin', 'jerome.mallillin@mcnp.edu.ph', 'mcnp', 10, NULL, NULL, 18, 'active', NULL, NULL, '2026-09-13 19:04:15', 'blue'),
(143, '2023-11516', '$2y$10$XtIMp9mJD61Ia5SEf88iBukdkni/GLaJlbv61FDHfTvzop3OKnLii', 'student', 'Geleen', '', 'Tapaoan', 'Geleen Tapaoan', 'geleentapaoan@gmail.com', 'mcnp', 8, NULL, NULL, 16, 'pending', NULL, NULL, '2026-09-14 01:37:12', 'blue');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_periods`
--
ALTER TABLE `academic_periods`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `faculty_id` (`faculty_id`,`subject_id`,`period_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `period_id` (`period_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `course_alias` (`course_alias`),
  ADD KEY `dept_id` (`dept_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dept_alias` (`dept_alias`);

--
-- Indexes for table `email_otp`
--
ALTER TABLE `email_otp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email_purpose` (`email`,`purpose`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`,`class_id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `faculty_id` (`faculty_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `academic_period_id` (`academic_period_id`);

--
-- Indexes for table `evaluation_categories`
--
ALTER TABLE `evaluation_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `evaluation_questions`
--
ALTER TABLE `evaluation_questions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `evaluation_responses`
--
ALTER TABLE `evaluation_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evaluation_id` (`evaluation_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subject_code` (`subject_code`),
  ADD KEY `dept_id` (`dept_id`);

--
-- Indexes for table `surveys`
--
ALTER TABLE `surveys`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `survey_responses`
--
ALTER TABLE `survey_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `survey_id` (`survey_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `dept_id` (`dept_id`),
  ADD KEY `course_id` (`course_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_periods`
--
ALTER TABLE `academic_periods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `email_otp`
--
ALTER TABLE `email_otp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=131;

--
-- AUTO_INCREMENT for table `evaluations`
--
ALTER TABLE `evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `evaluation_categories`
--
ALTER TABLE `evaluation_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `evaluation_questions`
--
ALTER TABLE `evaluation_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `evaluation_responses`
--
ALTER TABLE `evaluation_responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT for table `surveys`
--
ALTER TABLE `surveys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `survey_responses`
--
ALTER TABLE `survey_responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=144;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `classes_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `classes_ibfk_3` FOREIGN KEY (`period_id`) REFERENCES `academic_periods` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD CONSTRAINT `evaluations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluations_ibfk_2` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluations_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluations_ibfk_4` FOREIGN KEY (`academic_period_id`) REFERENCES `academic_periods` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `evaluation_responses`
--
ALTER TABLE `evaluation_responses`
  ADD CONSTRAINT `evaluation_responses_ibfk_1` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluation_responses_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `evaluation_questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subjects`
--
ALTER TABLE `subjects`
  ADD CONSTRAINT `subjects_ibfk_1` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `surveys`
--
ALTER TABLE `surveys`
  ADD CONSTRAINT `surveys_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `survey_responses`
--
ALTER TABLE `survey_responses`
  ADD CONSTRAINT `survey_responses_ibfk_1` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `survey_responses_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
