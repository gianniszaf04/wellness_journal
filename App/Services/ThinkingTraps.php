<?php
declare(strict_types=1);
namespace App\Services;

class ThinkingTraps
{
    public const CONFIDENT_MARGIN = 0.39;
    public const ASK_FLOOR = 0.01;
    public const QUOTE_LENGTH = 80;

    public const FIRST_QUESTION = 'Τι περνούσε από το μυαλό μου λίγο πριν αρχίσω να νιώθω έτσι;';
    public const FAIR_QUESTION = 'Πώς θα μπορούσα να το πω στον εαυτό μου πιο δίκαια;';
    public const FRIEND_QUESTION = 'Τι θα έλεγα σε έναν φίλο που περνάει το ίδιο;';

    public const MOOD_QUESTIONS = [
        'meaning' => 'Τι σημαίνει αυτό για μένα; Για τη ζωή μου, για το μέλλον μου;',
        'fear' => 'Τι φοβάμαι ότι μπορεί να συμβεί;',
        'others' => 'Τι σημαίνει αυτό για το πώς με βλέπουν οι άλλοι;',
    ];

    public const EMOTIONS = [
        'Λύπη' => ['key' => 'meaning', 'in' => 'στη λύπη σου', 'with' => 'λύπη', 'the' => 'η λύπη'],
        'Άγχος' => ['key' => 'fear', 'in' => 'στο άγχος σου', 'with' => 'άγχος', 'the' => 'το άγχος'],
        'Φόβος' => ['key' => 'fear', 'in' => 'στον φόβο σου', 'with' => 'φόβο', 'the' => 'ο φόβος'],
        'Θυμός' => ['key' => 'others', 'in' => 'στον θυμό σου', 'with' => 'θυμό', 'the' => 'ο θυμός'],
        'Ντροπή' => ['key' => 'others', 'in' => 'στην ντροπή σου', 'with' => 'ντροπή', 'the' => 'η ντροπή'],
        'Ενοχή' => ['key' => 'meaning', 'in' => 'στις ενοχές σου', 'with' => 'ενοχές', 'the' => 'η ενοχή'],
        'Κούραση' => ['key' => 'meaning', 'in' => 'στην κούρασή σου', 'with' => 'κούραση', 'the' => 'η κούραση'],
    ];

    private const TRAPS = [
        'Καταστροφολογία' => [
            'label' => 'Το χειρότερο σενάριο',
            'explanation' => 'Φαντάζεσαι το χειρότερο που μπορεί να συμβεί, ή κάνεις ένα πρόβλημα πολύ μεγαλύτερο απ\' ό,τι είναι.',
            'question' => 'Ποιο είναι το πιο πιθανό να συμβεί, όχι το χειρότερο; Κι αν γίνει το χειρότερο, πώς θα το αντιμετώπιζες;',
            'ask' => 'Σκέφτεσαι αυτό που είναι πιο πιθανό να γίνει, ή το χειρότερο που θα μπορούσε να γίνει;',
            'neutral' => 'Το πιο πιθανό',
            'trap' => 'Το χειρότερο',
        ],
        'Όλα ή τίποτα' => [
            'label' => 'Άσπρο ή μαύρο',
            'explanation' => 'Βλέπεις τα πράγματα σε δύο άκρα, χωρίς τίποτα ενδιάμεσα.',
            'question' => 'Πού θα το έβαζες σε μια κλίμακα από το 0 έως το 10, αντί για «όλα» ή «τίποτα»;',
            'ask' => 'Στη σκέψη σου υπάρχει κάτι ενδιάμεσο, ή μόνο «όλα» ή «τίποτα»;',
            'neutral' => 'Υπάρχει κάτι ενδιάμεσο',
            'trap' => 'Μόνο τα δύο άκρα',
        ],
        'Υπεργενίκευση' => [
            'label' => '«Πάντα» και «ποτέ»',
            'explanation' => 'Ένα γεγονός γίνεται κανόνας για όλα.',
            'question' => 'Ισχύει πραγματικά πάντα; Θυμάσαι μια φορά που έγινε διαφορετικά;',
            'ask' => 'Μιλάς για αυτή τη μία φορά, ή για το πώς πάνε πάντα τα πράγματα;',
            'neutral' => 'Για αυτή τη φορά',
            'trap' => 'Για το πώς πάνε πάντα',
        ],
        'Ανάγνωση σκέψης' => [
            'label' => 'Διαβάζω το μυαλό των άλλων',
            'explanation' => 'Θεωρείς δεδομένο τι σκέφτονται οι άλλοι, χωρίς να σου το έχουν πει.',
            'question' => 'Τι στοιχεία έχεις γι\' αυτό; Ποιοι άλλοι λόγοι θα μπορούσαν να εξηγούν αυτό που έγινε;',
            'ask' => 'Αυτό που σκέφτονται οι άλλοι, σου το είπαν ή το υποθέτεις;',
            'neutral' => 'Μου το είπαν',
            'trap' => 'Το υποθέτω',
        ],
        'Προφητεία' => [
            'label' => 'Ξέρω τι θα γίνει',
            'explanation' => 'Θεωρείς σίγουρο ότι το μέλλον θα πάει άσχημα.',
            'question' => 'Πόσες φορές βγήκε αληθινή μια τέτοια πρόβλεψη στο παρελθόν; Τι άλλο θα μπορούσε να συμβεί;',
            'ask' => 'Πόσο σίγουρο σου φαίνεται ότι θα γίνει έτσι;',
            'neutral' => 'Μπορεί να γίνει, μπορεί και όχι',
            'trap' => 'Το θεωρώ σίγουρο',
        ],
        'Συναισθηματική συλλογιστική' => [
            'label' => 'Το νιώθω, άρα ισχύει',
            'explanation' => 'Θεωρείς ότι κάτι είναι αλήθεια επειδή έτσι το νιώθεις.',
            'question' => 'Αν ξεχωρίσεις το συναίσθημα από τα γεγονότα, τι λένε τα γεγονότα;',
            'ask' => 'Το πιστεύεις επειδή έχεις στοιχεία, ή επειδή έτσι το νιώθεις;',
            'neutral' => 'Έχω στοιχεία',
            'trap' => 'Έτσι το νιώθω',
        ],
        'Πρέπει' => [
            'label' => 'Τα «πρέπει»',
            'explanation' => 'Βάζεις αυστηρούς κανόνες στον εαυτό σου ή στους άλλους, και νιώθεις άσχημα όταν δεν τηρούνται.',
            'question' => 'Τι θα άλλαζε αν το έλεγες «θα ήθελα» αντί για «πρέπει»; Θα έβαζες τον ίδιο κανόνα σε έναν φίλο;',
            'ask' => 'Η σκέψη σου λέει τι έγινε, ή πώς θα έπρεπε να είχε γίνει;',
            'neutral' => 'Τι έγινε',
            'trap' => 'Πώς θα έπρεπε',
        ],
        'Ταμπέλες' => [
            'label' => 'Ταμπέλες',
            'explanation' => 'Δίνεις μια ολική ετικέτα σε έναν άνθρωπο, σε εσένα ή σε άλλον, αντί να περιγράψεις τι έκανε.',
            'question' => 'Τι έκανες συγκεκριμένα, αντί για το τι είσαι; Μια πράξη ορίζει ολόκληρο τον άνθρωπο;',
            'ask' => 'Μιλάς για κάτι που έκανε κάποιος (εσύ ή άλλος), ή για το τι είναι σαν άνθρωπος;',
            'neutral' => 'Για κάτι που έκανε',
            'trap' => 'Για το τι είναι',
        ],
        'Προσωποποίηση' => [
            'label' => 'Φταίω εγώ',
            'explanation' => 'Παίρνεις την ευθύνη για κάτι που δεν ελέγχεις.',
            'question' => 'Τι άλλο έπαιξε ρόλο σε αυτό που έγινε; Ποιο κομμάτι ήταν πραγματικά στο χέρι σου;',
            'ask' => 'Έπαιξαν ρόλο και άλλοι ή άλλα πράγματα, ή νιώθεις ότι φταις μόνο εσύ;',
            'neutral' => 'Έπαιξαν ρόλο κι άλλα',
            'trap' => 'Φταίω μόνο εγώ',
        ],
        'Νοητικό φίλτρο' => [
            'label' => 'Βλέπω μόνο το αρνητικό',
            'explanation' => 'Κρατάς μόνο το αρνητικό κομμάτι και αγνοείς τα θετικά.',
            'question' => 'Τι θετικό έγινε επίσης που ίσως προσπέρασες;',
            'ask' => 'Όταν σκέφτεσαι αυτή την κατάσταση, βλέπεις και ό,τι πήγε καλά, ή μόνο ό,τι πήγε στραβά;',
            'neutral' => 'Βλέπω και τα δύο',
            'trap' => 'Κυρίως ό,τι πήγε στραβά',
        ],
    ];

    public static function types(): array
    {
        return array_keys(self::TRAPS);
    }

    public static function exists(string $type): bool
    {
        return isset(self::TRAPS[$type]);
    }

    public static function describe(string $type): array
    {
        $trap = self::TRAPS[$type];
        return [
            'type' => $type,
            'label' => $trap['label'],
            'quoted' => self::quoted($trap['label']),
            'explanation' => $trap['explanation'],
            'question' => $trap['question'],
        ];
    }

    public static function marginQuestion(string $type, int $trapPosition): array
    {
        $trap = self::TRAPS[$type];
        $options = [['answer' => 'neutral', 'label' => $trap['neutral']], ['answer' => 'trap', 'label' => $trap['trap']]];
        return self::describe($type) + [
            'ask' => $trap['ask'],
            'options' => $trapPosition === 1 ? array_reverse($options) : $options,
        ];
    }

    public static function quoted(string $text): string
    {
        return '«' . strtr($text, ['«' => '“', '»' => '”']) . '»';
    }

    public static function ranking(mixed $probabilities): array
    {
        $ranking = [];
        foreach (AiClient::scored(array_keys(is_array($probabilities) ? $probabilities : []), $probabilities) as $type => $probability) {
            if (self::exists($type) && $probability !== null) {
                $ranking[] = ['type' => $type, 'probability' => $probability];
            }
        }
        usort($ranking, static fn (array $a, array $b): int => $b['probability'] <=> $a['probability']);
        return $ranking;
    }

    public static function isConfident(?bool $detected, array $ranking): bool
    {
        return $detected === true && count($ranking) >= 2
            && round($ranking[0]['probability'] - $ranking[1]['probability'], 4) >= self::CONFIDENT_MARGIN;
    }

    public static function isQuiet(?bool $detected, array $ranking): bool
    {
        return $detected === false && $ranking !== [] && $ranking[0]['probability'] < self::ASK_FLOOR;
    }

    public static function strongest(array $emotions): ?array
    {
        $strongest = null;
        foreach ($emotions as $emotion) {
            if ($strongest === null || $emotion['before'] > $strongest['before']) {
                $strongest = $emotion;
            }
        }
        return $strongest;
    }

    public static function moodKey(array $emotions): string
    {
        return self::EMOTIONS[self::strongest($emotions)['emotion'] ?? '']['key'] ?? 'meaning';
    }

    public static function modelText(string $thought, ?string $thoughtMood): string
    {
        $thought = trim($thought);
        $thoughtMood = trim((string) $thoughtMood);
        if ($thought === '' || $thoughtMood === '') {
            return $thought !== '' ? $thought : $thoughtMood;
        }
        $end = (string) preg_replace('/[\s»"\')\]]+$/u', '', $thought);
        if (preg_match('/[.!?;\x{037E}…]$/u', $end) !== 1) {
            $thought = preg_replace('/[\s,·:–—-]+$/u', '', $thought) . '.';
        }
        return $thought . ' ' . $thoughtMood;
    }

    public static function quote(string $thought): string
    {
        $text = (string) preg_replace(['/^\s*(ότι|πως)\s+/iu', '/[\s.]+$/u'], '', $thought);
        if (mb_strlen($text) <= self::QUOTE_LENGTH) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::QUOTE_LENGTH);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, " ,;·") . '…';
    }

    public static function feelLine(string $thought, ?string $thoughtMood, array $emotions): string
    {
        $quote = self::quote($thoughtMood !== null && trim($thoughtMood) !== '' ? $thoughtMood : $thought);
        $in = self::EMOTIONS[self::strongest($emotions)['emotion'] ?? '']['in'] ?? 'σε αυτό που νιώθεις';
        return 'Όταν λες στον εαυτό σου «' . strtr($quote, ['«' => '“', '»' => '”']) . '», τι κάνει αυτό ' . $in . ';';
    }
}
