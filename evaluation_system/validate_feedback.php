<?php
/**
 * Feedback Comment Validation Engine
 * 
 * Ensures student and visitor feedback is constructive, meaningful, and written in 
 * real English or Filipino / Tagalog.
 * 
 * Strictly rejects:
 * - Keyboard mashing (e.g. "asdfghjkl", "qwertyuiop", "easbhdjdvbafakhfals")
 * - Unpronounceable consonant clusters (e.g. "heisvsjskamsbsjywuqiq")
 * - Symbol / punctuation / digit spam (e.g. "6282626181;$(#:#) $! \"($!")
 * - Pure emoji or emoji-dominated spam (e.g. "😂😂😂🔥👍")
 * - Repetitive characters or laughter loops (e.g. "aaaaaa", "hahahahahaha")
 * - Meaningless strings with no recognizable English or Filipino words/roots.
 */

if (!function_exists('validateFeedbackComment')) {
    function validateFeedbackComment($text, $minLength = 10) {
        $text = trim($text ?? '');
        $minLength = max((int)$minLength, 10); // Standard minimum 10 characters

        // 1. Minimum character length
        if (mb_strlen($text, 'UTF-8') < $minLength) {
            return [
                'valid' => false,
                'reason' => 'too_short',
                'title' => 'Feedback Too Short',
                'short_message' => 'Minimum ' . $minLength . ' characters required',
                'message' => "Your feedback must contain at least {$minLength} characters. Please provide detailed constructive remarks."
            ];
        }

        // 2. Count alphabetic letters vs non-letters (emojis, numbers, symbols)
        preg_match_all('/\p{L}/u', $text, $letterMatches);
        $letters = $letterMatches[0] ?? [];
        $letterCount = count($letters);

        if ($letterCount < 8) {
            return [
                'valid' => false,
                'reason' => 'not_enough_letters',
                'title' => 'Insufficient Letters',
                'short_message' => 'Symbols/emojis only is not allowed',
                'message' => 'Feedback must contain at least 8 alphabetic letters. Emojis, numbers, and symbols alone are not accepted.'
            ];
        }

        // Check ratio of symbols, digits, and emojis to total non-whitespace characters
        $nonSpaceText = preg_replace('/\s+/u', '', $text);
        $totalNonSpace = mb_strlen($nonSpaceText, 'UTF-8');
        $symbolOrDigitCount = $totalNonSpace - $letterCount;

        // If symbols/digits/emojis outnumber letters or make up > 50%
        if ($symbolOrDigitCount > $letterCount || ($totalNonSpace > 0 && ($symbolOrDigitCount / $totalNonSpace) > 0.45)) {
            return [
                'valid' => false,
                'reason' => 'too_many_symbols',
                'title' => 'Excessive Symbols or Emojis',
                'short_message' => 'Too many symbols or emojis',
                'message' => 'Your feedback contains excessive symbols, punctuation, numbers, or emojis. Please write in complete words.'
            ];
        }

        // 3. Repeated identical characters (e.g. "aaaaa", "11111", ".....", "?????")
        if (preg_match('/(.)\1{3,}/u', $text)) {
            return [
                'valid' => false,
                'reason' => 'repetitive_chars',
                'title' => 'Repetitive Characters',
                'short_message' => 'Repetitive characters detected',
                'message' => 'Feedback cannot contain repetitive characters or punctuation. Please write meaningful sentences.'
            ];
        }

        // 4. Repeated short patterns (e.g. "hahaha", "hehehe", "asdfasdf", "abcabc")
        if (preg_match('/(.{2,4})\1{2,}/ui', $text)) {
            return [
                'valid' => false,
                'reason' => 'repetitive_pattern',
                'title' => 'Repetitive Pattern Detected',
                'short_message' => 'Repetitive pattern detected',
                'message' => 'Feedback contains repetitive text patterns or laughter spam (e.g. "hahaha"). Please provide constructive feedback.'
            ];
        }

        // 5. Common keyboard mash sequences
        $mashPatterns = [
            'qwerty', 'wertyu', 'ertyui', 'rtyuio', 'tyuiop',
            'asdfgh', 'sdfghj', 'dfghjk', 'fghjkl', 'ghjkl;',
            'zxcvbn', 'xcvbnm',
            'qazwsx', 'wsxedc', 'edcrfv', 'rfvtgb', 'tgbzhn',
            '123456', '234567', '345678', '456789', '567890',
            'lkjhgf', 'kjhgfd', 'jhgfdsa', 'poiuyt', 'mnbvcx',
            'asdfasdf', 'zxcvzxcv'
        ];
        $lowerText = mb_strtolower($text, 'UTF-8');
        foreach ($mashPatterns as $mash) {
            if (strpos($lowerText, $mash) !== false) {
                return [
                    'valid' => false,
                    'reason' => 'keyboard_mash',
                    'title' => 'Keyboard Mash Detected',
                    'short_message' => 'Keyboard mash detected',
                    'message' => 'Your feedback contains keyboard-mash sequences (e.g. "' . $mash . '"). Please enter genuine thoughts and feedback.'
                ];
            }
        }

        // 6. Inspect word-level structures
        preg_match_all('/\b[\p{L}\'\-]+\b/u', $lowerText, $wordMatches);
        $words = $wordMatches[0] ?? [];

        if (empty($words)) {
            return [
                'valid' => false,
                'reason' => 'no_words',
                'title' => 'No Words Found',
                'short_message' => 'No readable words found',
                'message' => 'Please provide constructive feedback containing readable words.'
            ];
        }

        // Single long word without spaces (e.g. "easbhdjdvbafakhfals")
        if (count($words) === 1 && mb_strlen($words[0], 'UTF-8') > 14) {
            return [
                'valid' => false,
                'reason' => 'single_long_gibberish',
                'title' => 'Random Character String',
                'short_message' => 'Random word detected',
                'message' => 'Comments cannot be a single random sequence of characters (e.g. "' . htmlspecialchars($words[0]) . '"). Please write in proper words with spaces.'
            ];
        }

        $vowelsPattern = '/[aeiouy]/i';
        $consonantsPattern = '/[bcdfghjklmnpqrstvwxz]/i';

        $gibberishWords = 0;
        foreach ($words as $word) {
            $wordLen = mb_strlen($word, 'UTF-8');
            if ($wordLen <= 2) continue; // Particles: po, sa, ng, to, is, in, at, etc.

            // 6a. 5 or more consecutive consonants (rare/impossible in natural Tagalog/English)
            if (preg_match('/[bcdfghjklmnpqrstvwxz]{5,}/i', $word)) {
                if (!in_array($word, ['strengths', 'angsts', 'catchphrase'], true)) {
                    return [
                        'valid' => false,
                        'reason' => 'gibberish_words',
                        'title' => 'Random Words Detected',
                        'short_message' => 'Random character mash detected',
                        'message' => 'Your feedback contains random or unreadable words (e.g. "' . htmlspecialchars($word) . '"). Please write in proper constructive words.'
                    ];
                }
            }

            // 6b. 4 or more consecutive vowels (e.g. "aeiou")
            if (preg_match('/[aeiou]{4,}/i', $word)) {
                return [
                    'valid' => false,
                    'reason' => 'gibberish_words',
                    'title' => 'Random Vowel Clusters',
                    'short_message' => 'Random vowel clusters detected',
                    'message' => 'Your feedback contains random vowel clusters (e.g. "' . htmlspecialchars($word) . '"). Please write proper constructive words.'
                ];
            }

            // 6c. Zero vowels in words of 4+ characters (e.g. "bcdfgh", "sdfg")
            if ($wordLen >= 4 && !preg_match($vowelsPattern, $word)) {
                return [
                    'valid' => false,
                    'reason' => 'gibberish_words',
                    'title' => 'Unpronounceable Words',
                    'short_message' => 'Unreadable words detected',
                    'message' => 'Your feedback contains unpronounceable words without vowels (e.g. "' . htmlspecialchars($word) . '"). Please write in proper words.'
                ];
            }

            // 6d. Vowel ratio in words of 6+ characters (should be between 18% and 85%)
            preg_match_all($vowelsPattern, $word, $vwMatches);
            $vowelCount = count($vwMatches[0] ?? []);
            $vowelRatio = $vowelCount / $wordLen;
            if ($wordLen >= 6 && ($vowelRatio < 0.18 || $vowelRatio > 0.85)) {
                $gibberishWords++;
                continue;
            }

            // 6e. Unnaturally long single word (over 20 characters)
            if ($wordLen > 20) {
                return [
                    'valid' => false,
                    'reason' => 'word_too_long',
                    'title' => 'Unusually Long Word',
                    'short_message' => 'Word exceeds 20 characters',
                    'message' => 'Words longer than 20 characters are not allowed. Please use spaces between words.'
                ];
            }
        }

        if ($gibberishWords > 0 && ($gibberishWords / count($words)) >= 0.35) {
            return [
                'valid' => false,
                'reason' => 'gibberish_words',
                'title' => 'Unreadable Words Detected',
                'short_message' => 'Unreadable words detected',
                'message' => 'Your feedback contains unreadable words. Please write meaningful and constructive feedback.'
            ];
        }

        // 7. Dictionary / Recognized Word and Root Check
        static $dict = null;
        if ($dict === null) {
            $commonWords = [
                // Filipino pronouns, markers, conjunctions, particles
                'ako', 'ikaw', 'siya', 'kami', 'kayo', 'sila', 'tayo', 'ito', 'iyan', 'iyon', 'dito', 'doon', 'diyan',
                'ang', 'mga', 'sa', 'ng', 'nang', 'ni', 'kay', 'kina', 'para', 'dahil', 'kasi', 'pero', 'ngunit',
                'subalit', 'kung', 'kapag', 'habang', 'bago', 'pagkatapos', 'po', 'opo', 'hindi', 'di', 'oo', 'wala',
                'walang', 'meron', 'may', 'mayroon', 'sana', 'naman', 'lang', 'din', 'rin', 'daw', 'raw', 'pala', 'ba',
                'na', 'pa', 'man', 'natin', 'namin', 'inyo', 'kanila', 'atin', 'amin', 'akin', 'iyo', 'kanya',
                'lahat', 'bawat', 'isa', 'dalawa', 'tatlo', 'marami', 'kaunti', 'sobra', 'masyado', 'napaka',
                
                // Filipino evaluation words & roots
                'mabait', 'bait', 'magaling', 'galing', 'mahusay', 'husay', 'maayos', 'ayos', 'malinaw', 'linaw',
                'masipag', 'sipag', 'marunong', 'maasikaso', 'matulungin', 'masayahin', 'pasensyoso', 'pasensyosa',
                'matalino', 'patas', 'turo', 'magturo', 'tinuturo', 'nagtuturo', 'pagtuturo', 'klase', 'aralin',
                'leksyon', 'pagsusulit', 'gawain', 'grado', 'marka', 'exam', 'estudyante', 'guro', 'sir', 'maam',
                'mam', 'salamat', 'pasasalamat', 'tulong', 'tulungan', 'intindi', 'maintindihan', 'naintindihan',
                'nauunawaan', 'unawa', 'nakatulong', 'maganda', 'ganda', 'masarap', 'malinis', 'linis', 'maaliwalas',
                'tahimik', 'malamig', 'mabaho', 'masungit', 'sungit', 'tamad', 'late', 'absent', 'gulo', 'magulo',
                'pangit', 'panget', 'bastos', 'pabaya', 'bagsak', 'nambabagsak', 'nakakalito', 'lito', 'malabo',
                'labo', 'galit', 'bwisit', 'inis', 'kulang', 'paasa', 'basura', 'sira', 'maingay', 'ingay', 'makupad',
                'mabagal', 'bagal', 'mabilis', 'bilis', 'pasilidad', 'kwarto', 'kuwarto', 'gamit', 'upuan', 'mesa',
                'bentilador', 'aircon', 'ilaw', 'pinto', 'bintana', 'kompyuter', 'laboratory', 'canteen', 'library',
                'banyo', 'cr', 'pagkain', 'tubig', 'serbisyo', 'sulit', 'subok', 'araw', 'oras', 'panahon',
                'pogi', 'ser', 'kuya', 'ate', 'bata', 'matanda', 'boses', 'tinig', 'marinig', 'madinig',

                // English pronouns, conjunctions, core vocabulary
                'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have', 'i', 'it', 'for', 'not', 'on', 'with',
                'he', 'as', 'you', 'do', 'at', 'this', 'but', 'his', 'by', 'from', 'they', 'we', 'say', 'her',
                'she', 'or', 'an', 'will', 'my', 'one', 'all', 'would', 'there', 'their', 'what', 'so', 'up',
                'out', 'if', 'about', 'who', 'get', 'which', 'go', 'me', 'when', 'make', 'can', 'like', 'time',
                'no', 'just', 'him', 'know', 'take', 'people', 'into', 'year', 'your', 'good', 'some', 'could',
                'them', 'see', 'other', 'than', 'then', 'now', 'look', 'only', 'come', 'its', 'over', 'think',
                'also', 'back', 'after', 'use', 'two', 'how', 'our', 'work', 'first', 'well', 'way', 'even',
                'new', 'want', 'because', 'any', 'these', 'give', 'day', 'most', 'us', 'very', 'much', 'too',
                
                // English evaluation words
                'great', 'excellent', 'best', 'bad', 'worst', 'teacher', 'professor', 'instructor', 'teach',
                'teaches', 'taught', 'teaching', 'student', 'students', 'class', 'classes', 'lesson', 'lessons',
                'lecture', 'lectures', 'subject', 'subjects', 'course', 'courses', 'grade', 'grades', 'exam',
                'exams', 'quiz', 'quizzes', 'project', 'projects', 'homework', 'activity', 'discussion',
                'presentation', 'slide', 'slides', 'helpful', 'kind', 'nice', 'rude', 'strict', 'patient',
                'clear', 'clearly', 'clarity', 'fast', 'slow', 'explain', 'explains', 'explained', 'explanation',
                'understand', 'understood', 'understanding', 'fair', 'unfair', 'respectful', 'polite', 'supportive',
                'organized', 'disorganized', 'late', 'present', 'listen', 'listens', 'listening', 'voice',
                'speak', 'speaks', 'speaking', 'learn', 'learns', 'learned', 'learning', 'room', 'facility',
                'campus', 'chair', 'table', 'clean', 'dirty', 'comfortable', 'food', 'service', 'staff',
                'library', 'lab', 'office', 'impatient', 'boring', 'energetic', 'awesome', 'amazing', 'superb',
                'recommend', 'recommended', 'needs', 'improvement', 'improve', 'keep', 'done', 'job', 'manner'
            ];
            $dict = array_flip($commonWords);
        }

        $recognizedCount = 0;
        foreach ($words as $w) {
            if (isset($dict[$w])) {
                $recognizedCount++;
                continue;
            }
            // Substring / root check for derived words
            if (mb_strlen($w, 'UTF-8') >= 4) {
                foreach (['turo', 'aral', 'galing', 'bait', 'linaw', 'ayos', 'husay', 'intindi', 'unawa', 'sipag', 'ganda', 'linis', 'sarap', 'sungit', 'tamad', 'gulo', 'pabaya', 'bagsak', 'teach', 'learn', 'explain', 'help', 'clean', 'grade', 'class', 'listen', 'speak', 'work', 'improv'] as $root) {
                    if (strpos($w, $root) !== false) {
                        $recognizedCount++;
                        break;
                    }
                }
            }
        }

        if ($recognizedCount === 0) {
            return [
                'valid' => false,
                'reason' => 'no_recognized_words',
                'title' => 'Meaningful Feedback Required',
                'short_message' => 'No recognizable words found',
                'message' => 'Please provide constructive comments with recognizable words. Random words or character smashing are not accepted.'
            ];
        }

        return [
            'valid' => true,
            'reason' => 'ok',
            'title' => 'Valid Feedback',
            'short_message' => 'Meaningful feedback verified',
            'message' => 'Your feedback is valid and constructive.'
        ];
    }
}
