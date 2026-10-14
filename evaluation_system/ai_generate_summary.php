<?php
/**
 * AI Generative Sentiment Summary Engine
 * 
 * Analyzes all evaluation comments for a given period and generates
 * a real, data-driven summary by extracting themes and patterns
 * from actual student/evaluator feedback.
 * 
 * Returns JSON with positive/negative summaries, theme frequencies,
 * and sentiment distribution data.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db_connect.php';
global $conn;
include_once 'process_sentiment.php';

// Only authenticated admins, system admins, and deans can access
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'system admin', 'dean'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!headers_sent()) {
    header('Content-Type: application/json');
}

$period_id = (int)($_POST['period_id'] ?? $_GET['period_id'] ?? 0);
$target_eval = trim($_POST['target_evaluation'] ?? $_GET['target_evaluation'] ?? 'Overall Institution');
$faculty_id = (int)($_POST['faculty_id'] ?? $_GET['faculty_id'] ?? 0);
$service_name = trim($_POST['service'] ?? $_GET['service'] ?? '');
$dept_id = (int)($_POST['dept_id'] ?? $_GET['dept_id'] ?? 0);
$course_id = (int)($_POST['course_id'] ?? $_GET['course_id'] ?? 0);

if ($period_id <= 0) {
    // Get active period
    $ap = $conn->query("SELECT id FROM academic_periods WHERE is_active = 1 LIMIT 1")->fetch_assoc();
    $period_id = (int)($ap['id'] ?? 0);
}

if ($period_id <= 0) {
    echo json_encode(['error' => 'No valid academic period found.']);
    exit();
}

// Dean restriction: lock to their department
if ($_SESSION['role'] === 'dean' && !empty($_SESSION['dept_id'])) {
    $dept_id = (int)$_SESSION['dept_id'];
}

// ─── Theme Detection Dictionaries ───────────────────────────────────────────
// These map keywords/phrases found in comments to human-readable theme labels

$theme_mappings = [
    // ── POSITIVE THEMES ──
    'positive' => [
        'Teaching Quality' => [
            'magaling magturo', 'magaling', 'galing', 'mahusay', 'marunong magturo', 'marunong',
            'great teacher', 'excellent teacher', 'best teacher', 'good teacher',
            'good teaching', 'great teaching', 'excellent teaching',
            'well explained', 'clear explanation', 'easy to understand',
            'malinaw', 'malinaw magturo', 'malinaw mag-explain',
            'madaling intindihin', 'madaling maunawaan', 'naintindihan',
            'knowledgeable', 'competent', 'proficient', 'skilled',
            'effective', 'interactive', 'engaging',
        ],
        'Professionalism & Attitude' => [
            'mabait', 'bait', 'approachable', 'friendly', 'kind', 'patient',
            'respectful', 'polite', 'considerate', 'understanding',
            'madaling lapitan', 'madaling kausap',
            'magalang', 'marespeto', 'supportive', 'encouraging',
            'accommodating', 'dedicated', 'inspiring',
        ],
        'Preparedness & Punctuality' => [
            'prepared', 'well prepared', 'well organized', 'organized',
            'on time', 'always on time', 'always ready', 'laging handa',
            'laging prepared', 'laging maaga', 'laging pumapasok', 'punctual',
            'masipag', 'maasahan',
        ],
        'Subject Mastery' => [
            'knowledgeable', 'expertise', 'expert', 'mastery',
            'maalam', 'alam', 'knows the subject', 'deep knowledge',
            'well versed', 'brilliant',
        ],
        'Student Support' => [
            'helpful', 'very helpful', 'supportive', 'encouraging',
            'maalaga', 'maasikaso', 'malasakit',
            'helps students', 'always available', 'guides students',
        ],
        'Overall Excellence' => [
            'excellent', 'outstanding', 'amazing', 'best', 'perfect',
            'superb', 'awesome', 'fantastic', 'wonderful',
            'the best', 'dabest', 'petmalu', 'lodi', 'solid',
            'keep it up', 'keep up the good work', 'good job', 'great job',
            'love', 'loved', 'highly recommend',
        ],
        'Clean Facilities' => [
            'clean', 'malinis', 'linis', 'very clean', 'sobrang linis',
            'well maintained', 'comfortable', 'spacious', 'maaliwalas',
        ],
        'Good Food & Services' => [
            'masarap', 'sarap', 'delicious', 'tasty', 'sobrang sarap',
            'good food', 'great food', 'quality food',
            'fast service', 'good service', 'excellent service',
        ],
        'Strong WiFi & IT' => [
            'fast wifi', 'strong wifi', 'malakas ang wifi', 'fast internet',
            'good connection', 'functional', 'working computers',
        ],
    ],

    // ── NEGATIVE THEMES ──
    'negative' => [
        'Poor Teaching Quality' => [
            'pangit magturo', 'panget magturo', 'hindi marunong magturo', 'di marunong magturo',
            'walang alam', 'hindi alam', 'di alam', 'di nagtuturo', 'hindi nagtuturo',
            'bad teaching', 'poor teaching', 'terrible teaching',
            'bad teacher', 'worst teacher', 'terrible teacher', 'horrible teacher',
            'confusing', 'unclear', 'hard to understand', 'hindi maintindihan',
            'di maintindihan', 'walang natutunan', 'learned nothing',
            'nagbabasa lang', 'just reading slides', 'puro reporting',
            'boring', 'boring teacher', 'dull', 'monotone',
            'nakakaantok', 'nakakabagot',
        ],
        'Attendance & Punctuality Issues' => [
            'late', 'laging late', 'palaging late', 'always late', 'tardy',
            'absent', 'laging absent', 'palaging absent', 'madalas absent',
            'di pumapasok', 'hindi pumapasok', 'di nagpapakita',
            'tamad pumasok', 'lazy',
        ],
        'Rude & Unfriendly Behavior' => [
            'sungit', 'masungit', 'bastos', 'rude', 'mean', 'harsh',
            'arrogant', 'disrespectful', 'impatient', 'aggressive',
            'walang modo', 'walang galang', 'walang respeto',
            'shout', 'shouting', 'yell', 'yelling', 'sumisigaw',
            'namamahiya', 'nanghihiya', 'naninigaw',
            'not approachable', 'unapproachable', 'hindi approachable',
        ],
        'Unfair Grading & Bias' => [
            'unfair', 'biased', 'bias', 'favoritism', 'may paborito',
            'hindi patas', 'di patas', 'walang consideration',
            'madamot sa grade', 'kuripot sa grade',
            'nambabagsak', 'nangbabagsak', 'mapagbagsak',
            'unfair mag-grade', 'bias mag-grade',
        ],
        'Communication Issues' => [
            'mahina ang boses', 'low voice', 'voice is so low', 'inaudible',
            'nauutal', 'utal', 'stuttering', 'stutter',
            'hindi marinig', 'di marinig', 'hard to hear', 'cannot hear',
            'speaks too fast', 'teaches too fast', 'sobrang bilis magturo',
            'cannot speak english', 'cant speak english',
        ],
        'Lack of Support' => [
            'walang pake', 'walang pakialam', 'pabaya', 'negligent',
            'not helpful', 'unhelpful', 'walang konsiderasyon',
            'toxic', 'terror',
        ],
        'Dirty / Poorly Maintained Facilities' => [
            'dirty', 'marumi', 'madumi', 'mabaho', 'smelly', 'panghi',
            'broken', 'sira', 'damaged', 'bulok', 'basura',
            'not clean', 'hindi malinis',
        ],
        'Poor Food Quality' => [
            'hindi masarap', 'di masarap', 'walang lasa', 'matabang',
            'panis', 'bad food', 'terrible food', 'tasteless',
        ],
        'Weak WiFi & IT Issues' => [
            'walang wifi', 'walang internet', 'walang signal', 'slow wifi',
            'no wifi', 'no internet', 'broken computer',
            'hindi gumagana', 'di gumagana', 'sira sira',
        ],
        'Overcrowding & Comfort Issues' => [
            'masikip', 'crowded', 'noisy', 'maingay', 'mainit', 'hot',
            'walang aircon', 'walang bentilador', 'madilim', 'dark',
            'kulang sa upuan', 'kulang sa gamit',
        ],
    ],
];

// ─── Fetch Comments ──────────────────────────────────────────────────────────

$where_clauses = ["e.academic_period_id = ?", "e.feedback IS NOT NULL", "e.feedback != ''"];
$bind_types = "i";
$bind_params = [&$period_id];

if ($faculty_id > 0) {
    $where_clauses[] = "e.faculty_id = ?";
    $bind_types .= "i";
    $bind_params[] = &$faculty_id;
} elseif (!empty($service_name) && $service_name !== 'all') {
    $where_clauses[] = "e.faculty_id IS NULL";
    $where_clauses[] = "e.target_name = ?";
    $bind_types .= "s";
    $bind_params[] = &$service_name;
} elseif ($target_eval === 'Facilities & Campus Services') {
    $where_clauses[] = "e.faculty_id IS NULL";
} else {
    // Faculty evaluations (may filter by dept/course)
    if ($course_id > 0) {
        $where_clauses[] = "u.course_id = ?";
        $bind_types .= "i";
        $bind_params[] = &$course_id;
    } elseif ($dept_id > 0) {
        $where_clauses[] = "u.dept_id = ?";
        $bind_types .= "i";
        $bind_params[] = &$dept_id;
    }
}

$sql = "SELECT e.feedback, e.rating, e.target_name, e.faculty_id
        FROM evaluations e
        LEFT JOIN users u ON e.faculty_id = u.id
        WHERE " . implode(" AND ", $where_clauses) . "
        ORDER BY e.created_at DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['error' => 'Database query failed: ' . $conn->error]);
    exit();
}
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$result = $stmt->get_result();

$positive_comments = [];
$negative_comments = [];
$all_feedbacks = [];

while ($row = $result->fetch_assoc()) {
    $sentiment = analyzeSentiment($row['feedback'], $row['rating']);
    $entry = [
        'feedback' => $row['feedback'],
        'rating' => $row['rating'],
        'sentiment' => $sentiment,
    ];
    $all_feedbacks[] = $entry;
    if ($sentiment === 'Positive') {
        $positive_comments[] = $row['feedback'];
    } else {
        $negative_comments[] = $row['feedback'];
    }
}

$total_comments = count($all_feedbacks);
$positive_count = count($positive_comments);
$negative_count = count($negative_comments);

if ($total_comments === 0) {
    echo json_encode([
        'success' => true,
        'total_comments' => 0,
        'positive_count' => 0,
        'negative_count' => 0,
        'overall_sentiment' => 'No Data',
        'positive_summary' => 'No evaluation comments found for the selected period and filters.',
        'negative_summary' => 'No evaluation comments found for the selected period and filters.',
        'top_positive_themes' => [],
        'top_negative_themes' => [],
        'sentiment_ratio' => 0,
    ]);
    exit();
}

// ─── Theme Extraction ────────────────────────────────────────────────────────

if (!function_exists('extractThemes')) {
    function extractThemes($comments, $theme_dict) {
        $theme_counts = [];
        $theme_samples = [];

        foreach ($theme_dict as $theme_label => $keywords) {
            $count = 0;
            $samples = [];
            foreach ($comments as $comment) {
                $normalized = mb_strtolower($comment, 'UTF-8');
                foreach ($keywords as $keyword) {
                    if (mb_strpos($normalized, $keyword) !== false) {
                        $count++;
                        if (count($samples) < 2) {
                            // Truncate long comments for display
                            $samples[] = mb_strlen($comment) > 120 
                                ? mb_substr($comment, 0, 117) . '...' 
                                : $comment;
                        }
                        break; // Count each comment once per theme
                    }
                }
            }
            if ($count > 0) {
                $theme_counts[$theme_label] = $count;
                $theme_samples[$theme_label] = $samples;
            }
        }

        // Sort by frequency descending
        arsort($theme_counts);

        $result = [];
        foreach ($theme_counts as $label => $count) {
            $result[] = [
                'theme' => $label,
                'count' => $count,
                'samples' => $theme_samples[$label] ?? [],
            ];
        }

        return $result;
    }
}

$top_positive_themes = extractThemes($positive_comments, $theme_mappings['positive']);
$top_negative_themes = extractThemes($negative_comments, $theme_mappings['negative']);

// ─── Natural Language Summary Generation ─────────────────────────────────────

if (!function_exists('generatePositiveSummary')) {
    function generatePositiveSummary($themes, $total_positive, $total_comments) {
        if ($total_positive === 0) {
            return "No positive comments were detected in the evaluation feedback for the selected period.";
        }

        $pct = round(($total_positive / $total_comments) * 100);
        $summary = "Out of {$total_comments} total evaluation comments, {$total_positive} ({$pct}%) were classified as positive. ";

        if (empty($themes)) {
            $summary .= "Evaluators expressed general satisfaction, though no specific themes were prominently highlighted.";
            return $summary;
        }

        $top_themes = array_slice($themes, 0, 5);
        $theme_parts = [];

        foreach ($top_themes as $t) {
            $theme_parts[] = "<strong>{$t['theme']}</strong> (mentioned {$t['count']} time" . ($t['count'] > 1 ? 's' : '') . ")";
        }

        $summary .= "The most frequently praised areas include: " . implode(', ', $theme_parts) . ". ";

        // Add sample context from the top theme
        if (!empty($themes[0]['samples'])) {
            $summary .= "Representative feedback includes: <em>\"" . htmlspecialchars($themes[0]['samples'][0]) . "\"</em>";
            if (count($themes[0]['samples']) > 1) {
                $summary .= " and <em>\"" . htmlspecialchars($themes[0]['samples'][1]) . "\"</em>";
            }
            $summary .= ".";
        }

        return $summary;
    }
}

if (!function_exists('generateNegativeSummary')) {
    function generateNegativeSummary($themes, $total_negative, $total_comments) {
        if ($total_negative === 0) {
            return "No negative or needs-improvement comments were detected. All feedback for this period was positive.";
        }

        $pct = round(($total_negative / $total_comments) * 100);
        $summary = "Out of {$total_comments} total comments, {$total_negative} ({$pct}%) were flagged as needing attention. ";

        if (empty($themes)) {
            $summary .= "Some evaluators expressed dissatisfaction, but no specific recurring issues were prominently identified.";
            return $summary;
        }

        $top_themes = array_slice($themes, 0, 5);
        $theme_parts = [];

        foreach ($top_themes as $t) {
            $theme_parts[] = "<strong>{$t['theme']}</strong> (reported {$t['count']} time" . ($t['count'] > 1 ? 's' : '') . ")";
        }

        $summary .= "The primary areas of concern are: " . implode(', ', $theme_parts) . ". ";

        // Add recommendation context
        if (!empty($themes[0])) {
            $top_issue = $themes[0]['theme'];
            $recommendations = [
                'Poor Teaching Quality' => 'Consider providing faculty development workshops and peer mentoring programs to strengthen instructional delivery.',
                'Attendance & Punctuality Issues' => 'Enforce attendance policies and implement faculty check-in systems to improve class availability.',
                'Rude & Unfriendly Behavior' => 'Recommend sensitivity training and student-faculty communication improvement sessions.',
                'Unfair Grading & Bias' => 'Review grading rubrics for transparency and consider implementing standardized assessment frameworks.',
                'Communication Issues' => 'Provide public speaking and classroom communication workshops for affected faculty members.',
                'Lack of Support' => 'Encourage faculty to hold regular consultation hours and provide student mentorship programs.',
                'Dirty / Poorly Maintained Facilities' => 'Schedule more frequent maintenance and cleaning rotations for the reported areas.',
                'Poor Food Quality' => 'Coordinate with canteen management to improve food preparation standards and menu variety.',
                'Weak WiFi & IT Issues' => 'Upgrade network infrastructure and allocate budget for equipment replacement and maintenance.',
                'Overcrowding & Comfort Issues' => 'Assess classroom capacity limits and consider scheduling adjustments to reduce overcrowding.',
            ];

            if (isset($recommendations[$top_issue])) {
                $summary .= "<br><br><strong>Recommendation:</strong> " . $recommendations[$top_issue];
            }
        }

        // Add sample context
        if (!empty($themes[0]['samples'])) {
            $summary .= "<br><br><strong>Sample feedback:</strong> <em>\"" . htmlspecialchars($themes[0]['samples'][0]) . "\"</em>";
        }

        return $summary;
    }
}

$positive_summary = generatePositiveSummary($top_positive_themes, $positive_count, $total_comments);
$negative_summary = generateNegativeSummary($top_negative_themes, $negative_count, $total_comments);

// ─── Overall Sentiment Determination ─────────────────────────────────────────

$sentiment_ratio = $total_comments > 0 ? round(($positive_count / $total_comments) * 100) : 0;
if ($sentiment_ratio >= 75) {
    $overall_sentiment = 'Mostly Positive';
} elseif ($sentiment_ratio >= 50) {
    $overall_sentiment = 'Leaning Positive';
} elseif ($sentiment_ratio >= 25) {
    $overall_sentiment = 'Leaning Negative';
} else {
    $overall_sentiment = 'Mostly Negative';
}

if ($positive_count === $negative_count && $total_comments > 0) {
    $overall_sentiment = 'Mixed';
}

// ─── Average Rating ──────────────────────────────────────────────────────────

$avg_rating = 0;
if (!empty($all_feedbacks)) {
    $ratings = array_filter(array_column($all_feedbacks, 'rating'), function($r) { return $r !== null; });
    if (!empty($ratings)) {
        $avg_rating = round(array_sum($ratings) / count($ratings), 2);
    }
}

// ─── JSON Response ───────────────────────────────────────────────────────────

echo json_encode([
    'success' => true,
    'total_comments' => $total_comments,
    'positive_count' => $positive_count,
    'negative_count' => $negative_count,
    'overall_sentiment' => $overall_sentiment,
    'sentiment_ratio' => $sentiment_ratio,
    'average_rating' => $avg_rating,
    'positive_summary' => $positive_summary,
    'negative_summary' => $negative_summary,
    'top_positive_themes' => array_slice($top_positive_themes, 0, 6),
    'top_negative_themes' => array_slice($top_negative_themes, 0, 6),
], JSON_UNESCAPED_UNICODE);
