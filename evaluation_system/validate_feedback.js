/**
 * Client-Side Feedback Comment Validator
 * Evaluates feedback comments in real time for English and Filipino / Tagalog.
 * 
 * Rejects:
 * - Keyboard mashing (e.g. "asdfghjkl", "qwertyuiop", "easbhdjdvbafakhfals")
 * - Unpronounceable consonant clusters (e.g. "heisvsjskamsbsjywuqiq")
 * - Symbol & digit spam (e.g. "6282626181;$(#:#) $! \"($!")
 * - Pure emoji or emoji-dominated spam (e.g. "😂😂😂🔥👍")
 * - Repetitive characters or loops (e.g. "aaaaa", "hahahaha")
 * - Random words with no recognized English or Filipino vocabulary/roots.
 */

const FEEDBACK_VALIDATOR_DICT = new Set([
    // Filipino pronouns, markers, conjunctions, particles
    'ako', 'ikaw', 'siya', 'kami', 'kayo', 'sila', 'tayo', 'ito', 'iyan', 'iyon', 'dito', 'doon', 'diyan',
    'ang', 'mga', 'sa', 'ng', 'nang', 'ni', 'kay', 'kina', 'para', 'dahil', 'kasi', 'pero', 'ngunit',
    'subalit', 'kung', 'kapag', 'habang', 'bago', 'pagkatapos', 'po', 'opo', 'hindi', 'di', 'oo', 'wala',
    'walang', 'meron', 'may', 'mayroon', 'sana', 'naman', 'lang', 'din', 'rin', 'daw', 'raw', 'pala', 'ba',
    'na', 'pa', 'man', 'natin', 'namin', 'inyo', 'kanila', 'atin', 'amin', 'akin', 'iyo', 'kanya',
    'lahat', 'bawat', 'isa', 'dalawa', 'tatlo', 'marami', 'kaunti', 'sobra', 'masyado', 'napaka',
    
    // Filipino evaluation terms & roots
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

    // English pronouns, conjunctions, common words
    'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have', 'i', 'it', 'for', 'not', 'on', 'with',
    'he', 'as', 'you', 'do', 'at', 'this', 'but', 'his', 'by', 'from', 'they', 'we', 'say', 'her',
    'she', 'or', 'an', 'will', 'my', 'one', 'all', 'would', 'there', 'their', 'what', 'so', 'up',
    'out', 'if', 'about', 'who', 'get', 'which', 'go', 'me', 'when', 'make', 'can', 'like', 'time',
    'no', 'just', 'him', 'know', 'take', 'people', 'into', 'year', 'your', 'good', 'some', 'could',
    'them', 'see', 'other', 'than', 'then', 'now', 'look', 'only', 'come', 'its', 'over', 'think',
    'also', 'back', 'after', 'use', 'two', 'how', 'our', 'work', 'first', 'well', 'way', 'even',
    'new', 'want', 'because', 'any', 'these', 'give', 'day', 'most', 'us', 'very', 'much', 'too',
    
    // English evaluation terms
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
]);

const FEEDBACK_VALIDATOR_ROOTS = [
    'turo', 'aral', 'galing', 'bait', 'linaw', 'ayos', 'husay', 'intindi', 'unawa', 'sipag',
    'ganda', 'linis', 'sarap', 'sungit', 'tamad', 'gulo', 'pabaya', 'bagsak', 'teach', 'learn',
    'explain', 'help', 'clean', 'grade', 'class', 'listen', 'speak', 'work', 'improv'
];

const FEEDBACK_MASH_PATTERNS = [
    'qwerty', 'wertyu', 'ertyui', 'rtyuio', 'tyuiop',
    'asdfgh', 'sdfghj', 'dfghjk', 'fghjkl', 'ghjkl',
    'zxcvbn', 'xcvbnm',
    'qazwsx', 'wsxedc', 'edcrfv', 'rfvtgb', 'tgbzhn',
    '123456', '234567', '345678', '456789', '567890',
    'lkjhgf', 'kjhgfd', 'jhgfdsa', 'poiuyt', 'mnbvcx',
    'asdfasdf', 'zxcvzxcv'
];

function validateFeedbackCommentJS(text, minLen = 10) {
    text = (text || '').trim();
    minLen = Math.max(parseInt(minLen, 10) || 10, 10);

    // 1. Length check
    if (text.length < minLen) {
        const diff = minLen - text.length;
        return {
            valid: false,
            reason: 'too_short',
            title: 'Feedback Too Short',
            shortMessage: `${diff} more character${diff === 1 ? '' : 's'} needed`,
            message: `Your feedback must be at least ${minLen} characters. Currently: ${text.length} characters.`
        };
    }

    // 2. Letters count vs symbols/digits/emojis
    const lettersMatch = text.match(/\p{L}/gu) || [];
    const letterCount = lettersMatch.length;

    if (letterCount < 8) {
        return {
            valid: false,
            reason: 'not_enough_letters',
            title: 'Meaningful Feedback Required',
            shortMessage: '⚠ Symbols or emojis only is not allowed',
            message: 'Feedback must contain at least 8 alphabetic letters. Emojis, numbers, and symbols alone cannot be submitted.'
        };
    }

    const nonSpaceText = text.replace(/\s+/g, '');
    const symbolOrDigitCount = nonSpaceText.length - letterCount;

    if (symbolOrDigitCount > letterCount || (nonSpaceText.length > 0 && (symbolOrDigitCount / nonSpaceText.length) > 0.45)) {
        return {
            valid: false,
            reason: 'too_many_symbols',
            title: 'Excessive Symbols or Emojis',
            shortMessage: '⚠ Excessive symbols or emojis detected',
            message: 'Your feedback contains too many symbols, punctuation, or emojis. Please write in complete sentences.'
        };
    }

    // 3. Repeated identical characters (e.g. "aaaaa", "11111", ".....")
    if (/(.)\1{3,}/u.test(text)) {
        return {
            valid: false,
            reason: 'repetitive_chars',
            title: 'Repetitive Characters',
            shortMessage: '⚠ Repetitive characters detected',
            message: 'Feedback cannot contain repetitive characters or punctuation (e.g. "aaaaa"). Please write constructive comments.'
        };
    }

    // 4. Repeated short patterns (e.g. "hahaha", "asdfasdf")
    if (/(.{2,4})\1{2,}/i.test(text)) {
        return {
            valid: false,
            reason: 'repetitive_pattern',
            title: 'Repetitive Text / Laughter Spam',
            shortMessage: '⚠ Repetitive pattern detected',
            message: 'Feedback contains repetitive text patterns or laughter spam (e.g. "hahaha"). Please provide constructive feedback.'
        };
    }

    // 5. Keyboard mash patterns
    const lowerText = text.toLowerCase();
    for (const mash of FEEDBACK_MASH_PATTERNS) {
        if (lowerText.includes(mash)) {
            return {
                valid: false,
                reason: 'keyboard_mash',
                title: 'Keyboard Mash Detected',
                shortMessage: '⚠ Keyboard-mash detected',
                message: `Your feedback contains keyboard-mash sequences (e.g. "${mash}"). Please enter constructive feedback.`
            };
        }
    }

    // 6. Word-level analysis
    const words = lowerText.match(/\b[\p{L}'\-]+\b/gu) || [];
    if (words.length === 0) {
        return {
            valid: false,
            reason: 'no_words',
            title: 'No Readable Words',
            shortMessage: '⚠ No readable words found',
            message: 'Please provide constructive feedback containing readable words.'
        };
    }

    // Single long word without spaces
    if (words.length === 1 && words[0].length > 14) {
        return {
            valid: false,
            reason: 'single_long_gibberish',
            title: 'Random Character String',
            shortMessage: '⚠ Random word detected',
            message: `Comments cannot be a single random sequence of characters (e.g. "${words[0]}"). Please write proper words with spaces.`
        };
    }

    const vowelsRegex = /[aeiouy]/i;
    const consonants5Regex = /[bcdfghjklmnpqrstvwxz]{5,}/i;
    const vowels4Regex = /[aeiou]{4,}/i;

    let gibberishWords = 0;
    for (const word of words) {
        if (word.length <= 2) continue; // Skip particles

        // 6a. 5+ consecutive consonants
        if (consonants5Regex.test(word)) {
            if (!['strengths', 'angsts', 'catchphrase'].includes(word)) {
                return {
                    valid: false,
                    reason: 'gibberish_words',
                    title: 'Random Words Detected',
                    shortMessage: '⚠ Random characters detected',
                    message: `Your comments contain random or unreadable words (e.g. "${word}"). Random characters and keyboard smashing are not accepted.`
                };
            }
        }

        // 6b. 4+ consecutive vowels
        if (vowels4Regex.test(word)) {
            return {
                valid: false,
                reason: 'gibberish_words',
                title: 'Random Vowels Detected',
                shortMessage: '⚠ Random vowel cluster detected',
                message: `Your comments contain random vowel clusters (e.g. "${word}"). Please provide constructive feedback.`
            };
        }

        // 6c. Zero vowels in 4+ letters
        if (word.length >= 4 && !vowelsRegex.test(word)) {
            return {
                valid: false,
                reason: 'gibberish_words',
                title: 'Unpronounceable Words',
                shortMessage: '⚠ Unreadable words detected',
                message: `Your comments contain unpronounceable words without vowels (e.g. "${word}"). Please write in proper words.`
            };
        }

        // 6d. Vowel ratio in 6+ letter words
        if (word.length >= 6) {
            const vMatches = word.match(/[aeiouy]/gi) || [];
            const ratio = vMatches.length / word.length;
            if (ratio < 0.18 || ratio > 0.85) {
                gibberishWords++;
                continue;
            }
        }

        // 6e. Word length > 20
        if (word.length > 20) {
            return {
                valid: false,
                reason: 'word_too_long',
                title: 'Word Exceeds 20 Characters',
                shortMessage: '⚠ Word exceeds 20 characters',
                message: 'Words longer than 20 characters are not allowed. Please separate words with spaces.'
            };
        }
    }

    if (gibberishWords > 0 && (gibberishWords / words.length) >= 0.35) {
        return {
            valid: false,
            reason: 'gibberish_words',
            title: 'Unreadable Feedback',
            shortMessage: '⚠ Unreadable words detected',
            message: 'Your feedback contains unreadable words. Please write meaningful and constructive comments.'
        };
    }

    // 7. Dictionary / recognizable word check
    let recognizedCount = 0;
    for (const w of words) {
        if (FEEDBACK_VALIDATOR_DICT.has(w)) {
            recognizedCount++;
            continue;
        }
        if (w.length >= 4) {
            for (const root of FEEDBACK_VALIDATOR_ROOTS) {
                if (w.includes(root)) {
                    recognizedCount++;
                    break;
                }
            }
        }
    }

    if (recognizedCount === 0) {
        return {
            valid: false,
            reason: 'no_recognized_words',
            title: 'Meaningful Feedback Required',
            shortMessage: '⚠ No recognizable words found',
            message: 'Please provide constructive comments with recognizable words. Random words or character smashing are not accepted.'
        };
    }

    return {
        valid: true,
        reason: 'ok',
        title: 'Valid Feedback',
        shortMessage: '✓ Meaningful feedback verified',
        message: 'Your feedback is valid and constructive.'
    };
}
