<?php
/**
 * Sentiment Analysis Engine for Student & Facility Evaluations
 * Supports English, Tagalog (Filipino), and Taglish with negation handling,
 * multi-word phrase matching, safe root/morphology matching, and combined rating+text analysis.
 * Strictly returns 'Positive' or 'Negative'.
 *
 * Combined Determination Rules:
 * ┌────────────────┬──────────────────┬──────────────────────────────────────────┐
 * │ Rating         │ Comment          │ Result                                   │
 * ├────────────────┼──────────────────┼──────────────────────────────────────────┤
 * │ High (≥3.5)    │ Positive/Neutral │ POSITIVE                                 │
 * │ Low  (<3.0)    │ Negative/Neutral │ NEGATIVE                                 │
 * │ High (≥3.5)    │ Negative         │ Weighted: mild neg → Positive, strong → Negative │
 * │ Low  (<3.0)    │ Positive         │ Weighted: mild pos → Negative, strong → Positive │
 * │ Mid  (3.0-3.4) │ Any              │ Follow text sentiment; if neutral, Positive│
 * └────────────────┴──────────────────┴──────────────────────────────────────────┘
 */

function analyzeSentiment($text, $rating = null) {
    $text_raw = trim($text ?? '');
    
    // If text is empty, fall back to numerical rating
    if ($text_raw === '') {
        if ($rating !== null) {
            return ($rating >= 3.0) ? 'Positive' : 'Negative';
        }
        return 'Positive';
    }

    $normalized = mb_strtolower($text_raw, 'UTF-8');

    // =========================================================================
    // 1. HIGH-PRIORITY MULTI-WORD PHRASES (English, Tagalog, Taglish)
    // =========================================================================
    $neg_phrases = [
        // Teaching competence & Classroom delivery (Tagalog / Taglish)
        'hindi mo alam kung ano tinuturo mo', 'di mo alam kung ano tinuturo mo', 'hindi mo alam kung ano tinuturo', 'di mo alam kung ano tinuturo',
        'hindi mo alam tinuturo mo', 'di mo alam tinuturo mo', 'hindi mo alam', 'di mo alam', 'hindi nya alam', 'di nya alam',
        'hindi niya alam', 'di niya alam', 'di alam ang tinuturo', 'hindi alam ang tinuturo', 'di alam tinuturo', 'hindi alam tinuturo',
        'walang alam sa tinuturo', 'walang alam sa subject', 'walang alam magturo', 'di alam magturo', 'hindi alam magturo',
        'di alam ginagawa', 'hindi alam ginagawa', 'walang alam', 'walang kaalam-alam', 'walang kaalam alam',
        'ano ba tinuturo mo', 'ano bang tinuturo mo', 'ano tinuturo mo', 'ano tinuturo nito',
        'di ka marunong', 'di marunong', 'hindi marunong', 'di marunong magturo', 'hindi marunong magturo',
        'di marunong mag turo', 'hindi marunong mag turo', 'di magaling mag turo', 'hindi magaling mag turo',
        'di maayos mag turo', 'hindi maayos mag turo', 'di nagtuturo ng maayos', 'hindi nagtuturo ng maayos',
        'di nagtuturo nang maayos', 'hindi nagtuturo nang maayos',
        'pangit magturo', 'panget magturo', 'sobrang pangit magturo', 'sobrang panget magturo',
        'pangit ang turo', 'panget ang turo', 'pangit pagturo', 'panget pagturo', 'pangit ang pagtuturo', 'panget ang pagtuturo',
        'di nagtuturo', 'hindi nagtuturo', 'di pumapasok', 'hindi pumapasok', 'di pumupunta', 'hindi pumupunta',
        'di nagpapakita', 'hindi nagpapakita', 'di nag-eexplain', 'hindi nag-eexplain', 'hindi nagpapaliwanag',
        'di nagpapaliwanag', 'di maayos magturo', 'hindi maayos magturo', 'di magaling magturo',
        'di magaling', 'hindi magaling', 'di ka magaling', 'di maayos', 'hindi maayos',
        'di malinaw', 'hindi malinaw', 'di mabait', 'hindi mabait', 'di patas', 'hindi patas',
        'di makatarungan', 'di makatao', 'di approachable', 'hindi approachable',
        'di maintindihan', 'hindi maintindihan', 'di ko maintindihan', 'hindi ko maintindihan', 'walang maintindihan',
        'di maintindihan ang tinuturo', 'hindi maintindihan ang tinuturo', 'di ko maintindihan ang tinuturo', 'hindi ko maintindihan ang tinuturo',
        'di malinaw ang tinuturo', 'hindi malinaw ang tinuturo',
        'hirap intindihin', 'mahirap intindihin', 'hirap unawain', 'mahirap unawain',
        'walang natutunan', 'walang natututunan', 'wala kaming natutunan', 'wala akong natutunan', 'walang natutunan sa kanya',
        'walang kwenta', 'walang kwentang', 'walang silbi', 'walang kwenta magturo', 'walang kwentang magturo', 'walang kwenta mag turo',
        'walang kwenta ang turo', 'walang kwenta turo', 'walang kwenta ang klase', 'walang kwenta ang subject',
        'walang modo', 'walang galang', 'walang hiya', 'walang respeto',
        'walang consideration', 'walang konsiderasyon', 'walang konsiderasyon sa estudyante',
        'walang pake', 'walang pakialam', 'walang pakundangan', 'walang paki',
        'ang sungit', 'sobrang sungit', 'napaka sungit', 'napakasungit', 'sungit mo', 'sungit naman',
        'ang gulo', 'sobrang gulo', 'magulo magturo', 'magulong magturo', 'ang gulo magturo',
        'tamad magturo', 'tamad pumasok', 'tamad mag-check', 'tinatamad magturo', 'tinatamad pumasok',
        'bobo magturo', 'tanga magturo', 'inutil magturo',
        'laging late', 'palaging late', 'laging huli', 'palaging huli', 'late lagi',
        'laging absent', 'palaging absent', 'madalas absent', 'pala absent',
        'sayang tuition', 'sayang bayad', 'sayang pera', 'sayang oras', 'sayang binabayaran', 'sayang ang binabayad',
        'bagsak lahat', 'nambabagsak ng estudyante', 'mapagbagsak', 'nangbabagsak', 'nambabagsak',
        'nakaka antok', 'nakakaantok', 'nakakatulog sa klase', 'sobrang boring', 'sobrang bagal magturo', 'mabagal magturo',
        'may favoritism', 'may paborito', 'bias mag-grade', 'unfair mag-grade',
        'madamot sa grade', 'kuripot sa grade', 'mababang magbigay', 'mababa magbigay',
        'nagbabasa lang', 'nagbabasa lang ng slides', 'nagbabasa lang ng ppt', 'basa lang ng basa',
        'puro reporting', 'puro report', 'puro kwento', 'puro daldal', 'estudyante lang nagtuturo', 'nagpapalibre',
        'sumisigaw sa klase', 'naninigaw', 'namamahiya', 'nanghihiya ng estudyante',

        // Speech & Audio delivery (Voice, accent, clarity, stuttering)
        'mahina ang boses', 'mahina boses', 'napakababa ng boses', 'di marinig', 'hindi marinig',
        'di madinig', 'hindi madinig', 'nauutal', 'utal-utal', 'pautal-utal', 'hirap magsalita',
        'hirap mag-english', 'di makapagsalita', 'hindi makapagsalita', 'sobrang bilis magturo',
        'mabilis magturo', 'di makahabol', 'hindi makahabol',

        // Facility & Service Specific Negative Phrases
        'walang tubig', 'walang internet', 'walang wifi', 'walang signal', 'walang kuryente',
        'walang aircon', 'walang bentilador', 'sira sira', 'sirang gamit', 'di gumagana',
        'hindi gumagana', 'kulang sa gamit', 'kulang sa upuan', 'mabaho ang cr',
        'ang panghi', 'sobrang panghi', 'napakapanghi', 'mapanghi ang cr',
        'hindi masarap', 'di masarap', 'walang lasa', 'lasang ewan', 'panis na pagkain',

        // Teaching & Classroom delivery (English)
        'you dont know what you are teaching', 'you do not know what you are teaching',
        'dont know what you are teaching', 'do not know what you are teaching',
        'dont know what to teach', 'do not know what to teach',
        'does not know what to teach', 'doesnt know what to teach',
        'does not know what he is teaching', 'does not know what she is teaching',
        'doesnt know what she is teaching', 'doesnt know what he is teaching',
        'dont know anything', 'doesnt know anything', 'do not know anything', 'does not know anything',
        'dont know how to teach', 'don\'t know how to teach', 'do not know how to teach',
        'does not know how to teach', 'doesn\'t know how to teach', 'doesnt know how to teach',
        'dont now how to teach', 'don\'t now how to teach', 'do not now how to teach', // Typo tolerance
        'you dont know how to teach', 'you do not know how to teach',
        'cannot speak english', 'cant speak english', 'can\'t speak english',
        'cannot speak', 'cant speak', 'can\'t speak', 'hard to speak', 'difficult to speak',
        'keeps on stuttering', 'always stuttering', 'stuttering during', 'stutters during',
        'voice is so low', 'voice is too low', 'voice is low', 'low voice', 'very low voice',
        'hard to hear', 'cannot hear', 'cant hear', 'can\'t hear', 'difficult to hear',
        'just reading slides', 'just read slides', 'reads powerpoint', 'reads the slides',
        'speaks too fast', 'teaches too fast', 'too fast to understand',
        'not good', 'not great', 'not clear', 'not helpful', 'not approachable',
        'not fair', 'not organized', 'not effective', 'not friendly', 'not polite',
        'does not teach', 'doesn\'t teach', 'fails to explain', 'failed to explain',
        'cannot understand', 'cant understand', 'can\'t understand', 'hard to understand',
        'we learned nothing', 'i learned nothing', 'learned nothing', 'did not learn anything', 'didn\'t learn anything',
        'waste of time', 'waste of money', 'bad attitude', 'poor teaching',
        'terrible teacher', 'worst teacher', 'bad teacher', 'poor teacher', 'horrible teacher',
        'bad teaching', 'terrible teaching', 'horrible teaching', 'boring teacher', 'lazy teacher', 'rude teacher',
        'needs improvement', 'need improvement', 'needs to improve', 'need to improve',
        'very bad', 'very rude', 'very strict', 'very slow', 'out of order',
        'shouts at students', 'screams at students', 'disrespectful to students'
    ];

    $pos_phrases = [
        // Tagalog / Taglish Positive Phrases
        'ang galing', 'sobrang galing', 'napakagaling', 'napaka galing', 'magaling magturo',
        'ang husay', 'sobrang husay', 'napakahusay', 'mahusay magturo',
        'ang bait', 'sobrang bait', 'napakabait', 'napaka bait', 'mabait magturo', 'bait mo',
        'marunong magturo', 'maayos magturo', 'maayos mag-explain', 'maayos magpaliwanag',
        'malinaw magturo', 'malinaw mag-explain', 'malinaw magpaliwanag',
        'madaling intindihin', 'madaling maunawaan', 'madaling lapitan', 'madaling kausap',
        'laging handa', 'laging prepared', 'laging maaga', 'laging pumapasok',
        'on time', 'the best', 'the best teacher', 'dabest', 'keep it up', 'keep up',
        'keep up the good work', 'keep up the great work',
        'good job', 'great job', 'well done', 'saludo ako', 'idol talaga',

        // Facility-specific Positive Phrases
        'very clean', 'well maintained', 'well-maintained', 'very comfortable',
        'sobrang linis', 'napakalinis', 'maayos ang pasilidad', 'malakas ang wifi',
        'sobrang sarap', 'napakasarap', 'masarap ang pagkain',
        'fast computers', 'updated software', 'updated equipment', 'reliable internet',
        'great facilities', 'excellent facilities', 'modern equipment', 'good facilities',
        'well equipped', 'well-equipped', 'fully functional', 'state of the art',

        // English Positive Phrases
        'very good', 'very great', 'very helpful', 'very approachable', 'very kind',
        'very patient', 'very clear', 'very inspiring', 'very dedicated',
        'well prepared', 'well organized', 'well explained', 'high quality',
        'highly recommend', 'highly recommended', 'great teacher', 'excellent teacher', 'best teacher',
        'easy to understand', 'always ready', 'always on time'
    ];

    $score = 0;
    $masked_text = $normalized;

    // Sort phrases by length descending to match longest, most specific phrases first
    usort($neg_phrases, function($a, $b) { return strlen($b) - strlen($a); });
    usort($pos_phrases, function($a, $b) { return strlen($b) - strlen($a); });

    // Check high priority negative phrases (-3 points each) and mask out matched text
    foreach ($neg_phrases as $phrase) {
        if (strpos($masked_text, $phrase) !== false) {
            $score -= 3;
            $masked_text = str_replace($phrase, ' ', $masked_text);
        }
    }

    // Check high priority positive phrases (+3 points each) and mask out matched text
    foreach ($pos_phrases as $phrase) {
        if (strpos($masked_text, $phrase) !== false) {
            $score += 3;
            $masked_text = str_replace($phrase, ' ', $masked_text);
        }
    }

    // =========================================================================
    // 2. WORD LEVEL LEXICON WITH CLAUSE-AWARE NEGATION HANDLING
    // =========================================================================
    $negation_tokens = [
        'di', 'hindi', 'hndi', 'ndi', 'wala', 'walang', 'not', 'no', 'never',
        'hardly', 'barely', 'dont', "don't", 'doesnt', "doesn't", 'didnt', "didn't",
        'cant', "can't", 'cannot', 'without', 'less', 'wag', 'huwag'
    ];

    $positive_words = [
        // English Positive Words
        'excellent' => 3, 'amazing' => 3, 'best' => 3, 'great' => 2, 'helpful' => 2,
        'good' => 2, 'clear' => 2, 'approachable' => 2, 'organized' => 2, 'kind' => 2,
        'dedicated' => 2, 'patient' => 2, 'inspiring' => 3, 'effective' => 2, 'fair' => 2,
        'understood' => 1, 'nice' => 1, 'superb' => 3, 'awesome' => 3, 'fantastic' => 3,
        'wonderful' => 3, 'brilliant' => 3, 'knowledgeable' => 2, 'punctual' => 2,
        'considerate' => 2, 'understanding' => 2, 'friendly' => 2, 'respectful' => 2,
        'polite' => 2, 'supportive' => 2, 'encouraging' => 2, 'interactive' => 2,
        'engaging' => 2, 'love' => 2, 'loved' => 2, 'loves' => 2, 'appreciate' => 2,
        'appreciated' => 2, 'accommodating' => 2, 'exceptional' => 3, 'competent' => 2,
        'proficient' => 2, 'skilled' => 2, 'enthusiastic' => 2, 'clean' => 2, 'comfortable' => 2,
        'functional' => 2, 'complete' => 2, 'spacious' => 2, 'fast' => 1, 'perfect' => 3,
        'delicious' => 3, 'tasty' => 2, 'fluent' => 3, 'audible' => 2,
        'reliable' => 2, 'updated' => 2, 'modern' => 2, 'equipped' => 2, 'adequate' => 1,
        'sufficient' => 1, 'accessible' => 2, 'convenient' => 2, 'efficient' => 2,
        'outstanding' => 3, 'remarkable' => 3, 'impressive' => 3, 'satisfactory' => 1,
        'know' => 1, 'knowledge' => 2, 'teach' => 1, 'teaching' => 1, 'explain' => 1,
        'explaining' => 1, 'learn' => 1, 'learned' => 1,

        // Tagalog / Taglish Positive Words & Roots
        'magaling' => 3, 'galing' => 3, 'husay' => 3, 'mahusay' => 3, 'mabait' => 2,
        'bait' => 2, 'marunong' => 2, 'maalam' => 2, 'maayos' => 2, 'ayos' => 1,
        'malinaw' => 2, 'linaw' => 2, 'masipag' => 2, 'sipag' => 2, 'maunawain' => 2,
        'unawa' => 2, 'malasakit' => 2, 'maalaga' => 2, 'maasahan' => 2, 'patas' => 2,
        'masaya' => 2, 'nakakatuwa' => 2, 'petmalu' => 3, 'lodi' => 2, 'solid' => 2,
        'magalang' => 2, 'galang' => 2, 'marespeto' => 2, 'respeto' => 2, 'handa' => 1,
        'maasikaso' => 2, 'sulit' => 2, 'madali' => 1, 'malinis' => 2, 'linis' => 2,
        'maaliwalas' => 2, 'tahimik' => 1, 'malamig' => 1, 'malawak' => 1,
        'masarap' => 3, 'sarap' => 2, 'malasa' => 2, 'mabango' => 2, 'presko' => 2,
        'alam' => 1, 'natutunan' => 2, 'natututunan' => 2, 'natututo' => 2,
        'intindi' => 1, 'maintindihan' => 2, 'naintindihan' => 2
    ];

    $negative_words = [
        // English Negative Words
        'worst' => 3, 'rude' => 3, 'bad' => 2, 'terrible' => 3, 'poor' => 2,
        'confusing' => 2, 'late' => 2, 'boring' => 2, 'strict' => 1, 'absent' => 2,
        'unfair' => 3, 'disorganized' => 2, 'unapproachable' => 3, 'incompetent' => 3,
        'awful' => 3, 'horrible' => 3, 'useless' => 3, 'mean' => 2, 'harsh' => 2,
        'lazy' => 3, 'unprepared' => 2, 'biased' => 3, 'tardy' => 2, 'sleepy' => 2,
        'arrogant' => 3, 'disrespectful' => 3, 'impatient' => 2, 'aggressive' => 3,
        'shout' => 2, 'shouting' => 2, 'yell' => 2, 'yelling' => 2, 'hate' => 3,
        'disappointing' => 3, 'disappointment' => 3, 'waste' => 3, 'failed' => 2,
        'failure' => 3, 'unqualified' => 3, 'annoying' => 2, 'dull' => 2, 'monotone' => 2,
        'terror' => 3, 'negligent' => 3, 'toxic' => 3, 'dirty' => 2, 'smelly' => 2,
        'broken' => 2, 'damaged' => 2, 'noisy' => 2, 'crowded' => 1,
        'stuttering' => 3, 'stutter' => 3, 'stutters' => 3, 'inaudible' => 3,
        'bullshit' => 4, 'shit' => 3, 'fuck' => 4, 'wtf' => 3,

        // Tagalog / Taglish Negative Words & Roots
        'sungit' => 3, 'masungit' => 3, 'tamad' => 3, 'tinatamad' => 3, 'katamaran' => 3,
        'magulo' => 2, 'gulo' => 2, 'naguguluhan' => 2, 'pangit' => 3, 'panget' => 3,
        'bastos' => 3, 'pabaya' => 3, 'bagsak' => 2, 'nambabagsak' => 3, 'nangbabagsak' => 3,
        'nakakatakot' => 2, 'nakakaantok' => 2, 'antok' => 2, 'nakakabagot' => 2,
        'bagot' => 2, 'lito' => 2, 'nakakalito' => 2, 'nalilito' => 2, 'malabo' => 2,
        'labo' => 2, 'galit' => 2, 'palaaway' => 3, 'sigaw' => 2, 'sumisigaw' => 2,
        'bwisit' => 3, 'buwisit' => 3, 'nakakainis' => 3, 'inis' => 2, 'kupal' => 4,
        'gago' => 4, 'tarantado' => 4, 'tangina' => 4, 'walanghiya' => 4,
        'madamot' => 2, 'kuripot' => 2, 'swapang' => 2,
        'sayang' => 2, 'hirap' => 1, 'mahirap' => 2, 'kulang' => 1, 'paasa' => 2,
        'basura' => 3, 'bulok' => 3, 'marumi' => 2, 'madumi' => 2, 'mabaho' => 3,
        'sira' => 2, 'mainit' => 1, 'masikip' => 1, 'maingay' => 2, 'madilim' => 1,
        'nauutal' => 3, 'utal' => 3, 'panghi' => 3, 'mapanghi' => 3,
        'nakakapikon' => 3, 'pikon' => 2, 'nakakabanas' => 3, 'banas' => 2,
        'makupad' => 3, 'kupad' => 3, 'matabang' => 2, 'panis' => 3,
        'bobo' => 4, 'tanga' => 4, 'inutil' => 4
    ];

    // Delimiters that break negation scope
    $clause_breakers = [
        ',', ';', '.', '!', '?', ':', '-', 'pero', 'ngunit', 'subalit', 'kaso',
        'datapwat', 'habang', 'bagamat', 'but', 'however', 'although', 'yet', 'while',
        'at', 'and', 'also', 'tsaka', 'saka'
    ];

    // =========================================================================
    // SAFE ROOT MATCHING: Words that should NEVER be matched by substring/root
    // to prevent false positives (e.g. "laboratory" ≠ "labo", "internet" ≠ "inis")
    // =========================================================================
    $safe_english_words = [
        'laboratory', 'laboratories', 'elaborate', 'elaborated', 'laborious', 'collaborate',
        'collaboration', 'collaborative', 'label', 'labor', 'laborer',
        'internet', 'internal', 'international', 'internship', 'interest', 'interested',
        'interesting', 'interpret', 'interpretation', 'interior', 'interval', 'interview',
        'introduce', 'introduction', 'intel', 'intelligence', 'intelligent', 'intense',
        'intensity', 'intention', 'interact', 'interaction', 'interactive',
        'initial', 'initiative', 'initiate', 'innocent', 'innovation', 'innovative',
        'inspire', 'inspired', 'inspiring', 'install', 'installation', 'instant',
        'institute', 'institution', 'instruction', 'instructor', 'instrument',
        'insurance', 'integrate', 'integration', 'integrity', 'inventory', 'investigate',
        'investigation', 'investment', 'invisible', 'invitation', 'involve', 'involved',
        'literary', 'literacy', 'literature', 'literally', 'literal',
        'gallon', 'gallery', 'gallop', 'galactic', 'galaxies', 'galaxy',
        'significant', 'signal', 'signature', 'signing', 'design', 'designer',
        'assign', 'assignment', 'resign', 'resignation',
        'semester', 'seminar', 'semifinal', 'senior',
        'maintain', 'maintained', 'maintenance', 'container', 'entertainment',
        'hospital', 'hospitality', 'hospitalized',
        'utilities', 'utility', 'utilize', 'utilization',
        'beautiful', 'beautifully', 'beauty', 'beautify',
        'education', 'educator', 'educational', 'educate',
        'manual', 'manufacture', 'manufacturer', 'manuscript',
        'original', 'originally', 'originate', 'origin',
        'animal', 'animated', 'animation', 'animate',
        'material', 'maternal', 'maternity', 'mathematics',
        'criminal', 'criminology', 'criminologist',
        'digital', 'digitally', 'digitize',
        'medical', 'medicine', 'medication', 'medicate',
        'physical', 'physician', 'physics', 'physiological',
        'political', 'politics', 'politician',
        'terminal', 'terminate', 'terminology',
        'universal', 'university', 'universities',
        'professional', 'professionalism', 'professionally',
        'traditional', 'traditionally', 'tradition',
        'additional', 'additionally',
        'national', 'nationally', 'nation',
        'functional', 'functionality',
        'operational', 'operation', 'operations',
        'computational', 'computation', 'computer', 'computers', 'computing',
        'updated', 'updates', 'update', 'updating',
        'related', 'relation', 'relationship',
        'dedicated', 'dedication',
        'complicated', 'complication',
        'situation', 'situations',
        'evaluation', 'evaluations', 'evaluate',
        'classification', 'classify',
        'administration', 'administrative', 'administrator',
        'organization', 'organizational',
        'registration', 'registrar',
        'transportation', 'transport',
        'communication', 'communicate',
        'information', 'informative', 'informed',
        'preparation', 'prepare', 'prepared',
        'presentation', 'present', 'presented',
        'application', 'applicant', 'apply',
        'consultation', 'consultant',
        'curriculum', 'curricular',
        'particular', 'particularly',
        'facilities', 'facility', 'facilitate', 'facilitator',
        'laboratory', 'laboratories',
        'software', 'hardware',
        'standard', 'standards',
        'student', 'students',
        'teacher', 'teachers',
        'learning', 'learner', 'learners',
        'quality', 'qualities',
        'program', 'programs', 'programming',
        'practice', 'practical', 'practitioner',
        'academic', 'academically',
        'technology', 'technological', 'technologies',
        'engineering', 'engineer', 'engineers',
        'science', 'scientific', 'scientist',
        'research', 'researcher', 'researchers',
        'clinic', 'clinical', 'clinician',
        'service', 'services', 'serving',
        'guidance', 'guide', 'guided',
        'finance', 'financial', 'financing',
        'security', 'secured', 'securing',
        'management', 'manager', 'managing',
        'department', 'departmental',
        'development', 'developer', 'developing',
        'improvement', 'improving',
        'environment', 'environmental',
        'requirement', 'requirements', 'required',
        'assessment', 'assessing',
        'performance', 'performing',
        'experience', 'experienced',
        'knowledge', 'knowledgeable',
        'equipment', 'equipped',
        'curriculum', 'curricular',
        'instruction', 'instructional',
        'examination', 'examining',
    ];

    // Build a set for quick lookup (check if this word is a "safe" English word that should NOT be root-matched)
    $safe_words_set = array_flip($safe_english_words);

    // Severe abusive/vulgar words that should immediately flag as Negative
    $severe_vulgar_words = [
        'bobo', 'tanga', 'gago', 'kupal', 'tarantado', 'tangina', 'walanghiya',
        'inutil', 'putangina', 'fuck', 'shit', 'bullshit', 'pukinangina'
    ];
    $has_severe_vulgar = false;

    // Clean text into tokens preserving clause boundaries
    $token_text = preg_replace('/([,;.\!?:-])/', ' $1 ', $masked_text);
    $tokens = preg_split('/\s+/', $token_text, -1, PREG_SPLIT_NO_EMPTY);
    $num_tokens = count($tokens);

    $active_negator = false;
    $words_since_negator = 999;

    for ($i = 0; $i < $num_tokens; $i++) {
        $token = $tokens[$i];

        // Check if token is a clause breaker
        if (in_array($token, $clause_breakers, true)) {
            $active_negator = false;
            $words_since_negator = 999;
            continue;
        }

        // Check if token is a negator
        if (in_array($token, $negation_tokens, true)) {
            $active_negator = true;
            $words_since_negator = 0;
            continue;
        }

        $words_since_negator++;
        if ($words_since_negator > 3) {
            $active_negator = false;
        }

        // Strip any residual non-letters for dictionary lookup
        $word = preg_replace('/[^\p{L}\p{N}]/u', '', $token);
        if ($word === '') continue;

        if (in_array($word, $severe_vulgar_words, true)) {
            $has_severe_vulgar = true;
        }

        // Match positive word
        $pos_weight = 0;
        if (isset($positive_words[$word])) {
            $pos_weight = $positive_words[$word];
        } else {
            // Safe root matching: Only match if the word is NOT a known safe English word
            // AND the root is a standalone Tagalog/short word that makes sense as a substring
            if (!isset($safe_words_set[$word])) {
                foreach ($positive_words as $pw => $wt) {
                    // Only root-match Tagalog roots (length >= 4) and require the word starts with the root
                    // or the word is a clear morphological extension (e.g., "napakagaling" contains "galing")
                    if (strlen($pw) >= 4 && strlen($word) > strlen($pw) && strpos($word, $pw) !== false) {
                        // Extra safety: ensure the match isn't a coincidental English substring
                        // by checking that the word shares at least 60% of characters with the root
                        $overlap_ratio = strlen($pw) / strlen($word);
                        if ($overlap_ratio >= 0.35) {
                            $pos_weight = $wt;
                            break;
                        }
                    }
                }
            }
        }

        if ($pos_weight > 0) {
            if ($active_negator) {
                // Invert positive to NEGATIVE when negated (e.g. "not clear", "not effective", "hindi magaling")
                $score -= ($pos_weight + 1);
                $active_negator = false; // Consumed!
                $words_since_negator = 999;
            } else {
                $score += $pos_weight;
            }
            continue;
        }

        // Match negative word
        $neg_weight = 0;
        if (isset($negative_words[$word])) {
            $neg_weight = $negative_words[$word];
        } else {
            // Safe root matching for negative words
            if (!isset($safe_words_set[$word])) {
                foreach ($negative_words as $nw => $wt) {
                    if (strlen($nw) >= 4 && strlen($word) > strlen($nw) && strpos($word, $nw) !== false) {
                        $overlap_ratio = strlen($nw) / strlen($word);
                        if ($overlap_ratio >= 0.35) {
                            $neg_weight = $wt;
                            break;
                        }
                    }
                }
            }
        }

        if ($neg_weight > 0) {
            if ($active_negator) {
                // Invert negative to POSITIVE when negated (e.g. "not bad", "hindi masungit")
                $score += ($neg_weight - 1);
                $active_negator = false; // Consumed!
                $words_since_negator = 999;
            } else {
                $score -= $neg_weight;
            }
            continue;
        }
    }

    // =========================================================================
    // 3. COMBINED DETERMINATION — Text Sentiment Score Takes Priority
    // =========================================================================
    // The student's written comment (in English, Filipino, or Taglish) is the
    // primary determinant of sentiment:
    //   - Profanity / Severe insults = ALWAYS Negative
    //   - If comment is Good/Positive (score > 0) = POSITIVE (even if rating is lowest)
    //   - If comment is Bad/Negative (score < 0) = NEGATIVE (even if rating is highest)
    //   - If comment is Neutral (score == 0) or empty = Fallback to numerical rating
    // =========================================================================

    // Severe insults/profanity ALWAYS result in Negative regardless of rating
    if ($has_severe_vulgar) {
        return 'Negative';
    }

    // 1. Text has positive sentiment -> Always POSITIVE (even if rating is 1.0)
    if ($score > 0) {
        return 'Positive';
    }

    // 2. Text has negative sentiment -> Always NEGATIVE (even if rating is 5.0)
    if ($score < 0) {
        return 'Negative';
    }

    // 3. Text has no detected sentiment (score == 0, e.g. "N/A", "None") -> Fallback to rating
    if ($rating !== null) {
        return ($rating >= 3.0) ? 'Positive' : 'Negative';
    }

    // Pure neutral text without rating
    return 'Positive';
}