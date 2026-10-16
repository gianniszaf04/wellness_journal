<?php
declare(strict_types=1);
namespace App\Services;

// Μηνυματα υποστηριξης και γραμμες βοηθειας (Ελλαδα). Οι λεξεις-κλειδια δουλευουν και οταν το AI ειναι κατω.
class CrisisSupport
{
    public const LEVEL_CRISIS = 'crisis';
    public const LEVEL_LOW_MOOD = 'low_mood';

    // χαμηλο οριο: εδω μετραει να μη χαθει καποιος που κινδυνευει
    private const DEFAULT_RISK_THRESHOLD = 0.3;

    private const HELPLINES = [
        '1018' => [
            'name' => 'Γραμμή Παρέμβασης για την Αυτοκτονία (Κλίμακα)',
            'description' => 'Για σκέψεις αυτοκτονίας ή αυτοτραυματισμού.',
            'available' => '24 ώρες, 7 ημέρες',
        ],
        '10306' => [
            'name' => 'Γραμμή Ψυχοκοινωνικής Υποστήριξης',
            'description' => 'Συζήτηση με ειδικούς ψυχικής υγείας για ό,τι σε βαραίνει.',
            'available' => '24 ώρες, 7 ημέρες',
        ],
        '112' => [
            'name' => 'Αριθμός Έκτακτης Ανάγκης',
            'description' => 'Αν κινδυνεύεις άμεσα.',
            'available' => '24 ώρες, 7 ημέρες',
        ],
        '116111' => [
            'name' => 'Γραμμή για Παιδιά και Εφήβους (Το Χαμόγελο του Παιδιού)',
            'description' => 'Για άτομα κάτω των 18.',
            'available' => '24 ώρες, 7 ημέρες',
        ],
    ];

    // πανω σε κειμενο χωρις τονους, πεζα, με σ αντι για ς (βλ. normalize)
    private const PATTERNS = [
        '/αυτοκτον/u',
        '/αυτοτραυματ/u',
        '/(θελω|θα ηθελα|ευχομαι|μακαρι) να (πεθανω|πεθαινα|ημουν νεκρ|μην ξυπνησω|μην ξυπναγα|εξαφανιστω για παντα)/u',
        '/δε(ν)? (θελω|αντεχω|μπορω) (αλλο |πια )?να ζω(?! χωρισ)/u',
        '/δε(ν)? θελω να ξυπνησω/u',
        '/ζωη (μου )?δε(ν)? (εχει (κανενα |πια )?νοημα|αξιζει)/u',
        '/(τελειωσω|τερματισω|βαλω τελοσ στη|βαλω τελοσ στην) (τη |την )?ζωη μου/u',
        '/βαλω τελοσ σε ολα/u',
        '/να τα τελειωσω μια και καλη/u',
        '/(κοψω|κοβω) (τισ )?φλεβ/u',
        '/(κανω κακο|βλαψω|πληγωσω|τραυματισω|κοβω|κοψω) (στον |τον )?εαυτο μου/u',
        '/καλυτερα (να μην υπηρχα|να μην ειχα γεννηθει|χωρισ εμενα)/u',
        '/να φυγω απο (τη |αυτη τη )?ζωη/u',
        // greeklish
        '/a[uvfy]toktoni/u',
        '/thel[ow] na pe(th|8)an[ow]/u',
        '/de(n)? thel[ow] na z[ow]/u',
    ];

    private const ACCENTS = [
        'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
        'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ',
    ];

    public static function normalize(string $text): string
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function mentionsSelfHarm(string $text): bool
    {
        $normalized = self::normalize($text);
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function riskThreshold(): float
    {
        $value = getenv('CRISIS_RISK_THRESHOLD');
        if ($value === false || !is_numeric(trim($value))) {
            return self::DEFAULT_RISK_THRESHOLD;
        }
        $threshold = (float) $value;
        return ($threshold > 0 && $threshold <= 1) ? $threshold : self::DEFAULT_RISK_THRESHOLD;
    }

    public static function isCrisis(string $text, ?float $riskProbability): bool
    {
        return self::mentionsSelfHarm($text)
            || ($riskProbability !== null && $riskProbability >= self::riskThreshold());
    }

    // μονο κριση, π.χ. για τη ζωντανη αναλυση
    public static function forText(string $text, ?float $riskProbability): ?array
    {
        return self::isCrisis($text, $riskProbability) ? self::crisis() : null;
    }

    // κριση, αλλιως ηπιο μηνυμα για πολυ χαμηλη διαθεση
    public static function forEntry(string $text, ?float $riskProbability, ?float $sentimentScore, float $lowMoodThreshold): ?array
    {
        if (self::isCrisis($text, $riskProbability)) {
            return self::crisis();
        }
        if ($sentimentScore !== null && $sentimentScore < $lowMoodThreshold) {
            return self::lowMood();
        }
        return null;
    }

    public static function crisis(): array
    {
        return [
            'level' => self::LEVEL_CRISIS,
            'title' => 'Αυτό που γράφεις ακούγεται πολύ βαρύ',
            'message' => 'Δεν χρειάζεται να το σηκώνεις μόνος ή μόνη σου. Μίλησε τώρα με κάποιον που είναι εκεί για να ακούσει, '
                . 'σε μία από τις γραμμές παρακάτω, ή πες το σε κάποιον που εμπιστεύεσαι. Αν κινδυνεύεις άμεσα, κάλεσε το 112.',
            'helplines' => self::helplines(['1018', '10306', '112', '116111']),
        ];
    }

    public static function lowMood(): array
    {
        return [
            'level' => self::LEVEL_LOW_MOOD,
            'title' => 'Φαίνεται πως η μέρα σου ήταν δύσκολη',
            'message' => 'Αν αυτό το βάρος κρατάει καιρό, μπορείς να μιλήσεις με έναν ειδικό, όποια ώρα κι αν είναι.',
            'helplines' => self::helplines(['10306']),
        ];
    }

    private static function helplines(array $numbers): array
    {
        return array_map(
            static fn (string $number): array => ['number' => $number] + self::HELPLINES[$number],
            $numbers
        );
    }
}
