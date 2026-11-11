<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Core\Request;
use App\Models\ThoughtRecord;
use App\Services\AiClient;
use App\Services\CrisisSupport;
use App\Services\ThinkingTraps;

class ThoughtRecordController
{
    private const SESSION_KEY = 'thought_records';
    private const MAX_PENDING = 5;
    private const PENDING_TTL = 7200;
    private const HIGH_INTENSITY = 80;

    private const MODE_CONFIDENT = 'confident';
    private const MODE_ASK = 'ask';
    private const MODE_OFFER = 'offer';
    private const MODE_AI_DOWN = 'ai_down';
    private const MODE_NONE = 'none';

    public function suggest(): void
    {
        $request = new Request();
        $event = $this->text($request->input('event'), ThoughtRecord::MAX_EVENT, 'Γράψε τι έγινε.', true);
        $emotions = $this->emotionsBefore($request->input('emotions'));
        $thought = $this->text($request->input('thought'), ThoughtRecord::MAX_THOUGHT, 'Γράψε τι περνούσε από το μυαλό σου.', true);
        if (ThoughtRecord::wordCount($thought) < ThoughtRecord::MIN_THOUGHT_WORDS) {
            json_response(['success' => false, 'error' => 'Μπορείς να τη γράψεις λίγο πιο ολόκληρη;'], 422);
        }
        $thoughtMood = $this->text($request->input('thought_mood'), ThoughtRecord::MAX_THOUGHT, 'Μη έγκυρη απάντηση.', false);

        $userId = (int) current_user_id();
        release_session();

        $thoughtText = ThinkingTraps::modelText($thought, $thoughtMood);
        $thoughtData = $this->analyze($thoughtText);
        $eventData = $this->analyze($event);
        $model = $this->model($thoughtData);
        $page = [
            'user_id' => $userId,
            'event' => $event,
            'thought' => $thought,
            'thought_mood' => $thoughtMood !== '' ? $thoughtMood : null,
            'thought_mood_key' => ThinkingTraps::moodKey($emotions),
            'emotions' => $emotions,
        ] + $model;

        if (CrisisSupport::isCrisis($event, $this->risk($eventData)) || CrisisSupport::isCrisis($thoughtText, $this->risk($thoughtData))) {
            $id = (new ThoughtRecord())->create($userId, $page + ['observation_source' => ThoughtRecord::SOURCE_CRISIS, 'crisis' => true]);
            if ($id === false) {
                json_response(['success' => false, 'error' => 'Η αποθήκευση απέτυχε.'], 500);
            }
            json_response(['success' => true, 'data' => ['id' => $id, 'crisis' => true], 'support' => CrisisSupport::crisis()], 201);
        }

        $ranking = $model['model_ranking'] ?? [];
        $data = [
            'crisis' => false,
            'feel' => ThinkingTraps::feelLine($thought, $page['thought_mood'], $emotions),
            'friend' => ThinkingTraps::FRIEND_QUESTION,
        ];

        if ($model['model_detected'] === null || $ranking === []) {
            $pending = ['mode' => self::MODE_AI_DOWN];
        } elseif (ThinkingTraps::isConfident($model['model_detected'], $ranking)) {
            $pending = ['mode' => self::MODE_CONFIDENT, 'observed' => $ranking[0]['type']];
            $data['note'] = ThinkingTraps::describe($ranking[0]['type']);
        } elseif (ThinkingTraps::isQuiet($model['model_detected'], $ranking)) {
            $pending = ['mode' => self::MODE_NONE];
        } else {
            $questions = array_map(
                static fn (array $item): array => ['type' => $item['type'], 'trap_position' => random_int(1, 2)],
                array_slice($ranking, 0, ThoughtRecord::MAX_QUESTIONS)
            );
            $pending = ['mode' => $model['model_detected'] === false ? self::MODE_OFFER : self::MODE_ASK, 'questions' => $questions];
            $data['questions'] = array_map(
                static fn (array $q): array => ThinkingTraps::marginQuestion($q['type'], $q['trap_position']),
                $questions
            );
        }

        $data['mode'] = $pending['mode'];
        $data['token'] = $this->remember($page + $pending);
        json_response(['success' => true, 'data' => $data]);
    }

    public function store(): void
    {
        $request = new Request();
        $token = $request->input('token');
        $pending = is_string($token) ? $this->pending($token) : null;
        release_session();
        if ($pending === null) {
            $this->expired();
        }

        $emotions = $this->emotionsAfter($pending['emotions'], $request->input('after'));
        [$source, $observed, $answers] = $this->observation($pending, $request->input('answers'));
        $response = $this->response($source, $request->input('response'));
        $reframe = $this->text($request->input('reframe'), ThoughtRecord::MAX_REFRAME, 'Μη έγκυρη απάντηση.', false);
        $balanced = $this->text($request->input('balanced_thought'), ThoughtRecord::MAX_BALANCED, 'Μη έγκυρη απάντηση.', false);

        if ($response === ThoughtRecord::RESPONSE_NOT_NOW && in_array($source, [ThoughtRecord::SOURCE_NONE, ThoughtRecord::SOURCE_DECLINED], true)) {
            $reframe = '';
        }
        if ($observed === null || $response !== ThoughtRecord::RESPONSE_YES) {
            $balanced = '';
        }

        $crisis = false;
        foreach (array_filter([$reframe, $balanced]) as $text) {
            $crisis = $crisis || CrisisSupport::isCrisis($text, $this->risk($this->analyze($text)));
        }

        if (!$this->forget($token)) {
            $this->expired();
        }
        $id = (new ThoughtRecord())->create((int) $pending['user_id'], [
            'event' => $pending['event'],
            'thought' => $pending['thought'],
            'thought_mood' => $pending['thought_mood'],
            'thought_mood_key' => $pending['thought_mood_key'],
            'emotions' => $emotions,
            'model_probability' => $pending['model_probability'],
            'model_detected' => $pending['model_detected'],
            'model_ranking' => $pending['model_ranking'],
            'model_version' => $pending['model_version'],
            'answers' => $answers,
            'observed_type' => $observed,
            'observation_source' => $source,
            'response' => $response,
            'reframe' => $reframe !== '' ? $reframe : null,
            'balanced_thought' => $balanced !== '' ? $balanced : null,
            'crisis' => $crisis,
        ]);
        if ($id === false) {
            json_response(['success' => false, 'error' => 'Η αποθήκευση απέτυχε.'], 500);
        }

        $intense = array_filter($emotions, static fn (array $e): bool => $e['before'] > self::HIGH_INTENSITY && $e['after'] > self::HIGH_INTENSITY);
        $strongest = ThinkingTraps::strongest($emotions);
        json_response([
            'success' => true,
            'data' => [
                'id' => $id,
                'crisis' => $crisis,
                'emotions' => $emotions,
                'closing' => $strongest + ['fell' => $strongest['after'] < $strongest['before']],
            ],
            'support' => $crisis ? CrisisSupport::crisis() : ($intense !== [] ? CrisisSupport::lowMood() : null),
        ], 201);
    }

    private function observation(array $pending, mixed $answers): array
    {
        $answers ??= [];
        if (!is_array($answers) || !array_is_list($answers)) {
            $this->invalid('Μη έγκυρες απαντήσεις.');
        }
        if ($pending['mode'] === self::MODE_OFFER && $answers === []) {
            return [ThoughtRecord::SOURCE_DECLINED, null, []];
        }
        if (!in_array($pending['mode'], [self::MODE_ASK, self::MODE_OFFER], true)) {
            if ($answers !== []) {
                $this->invalid('Μη έγκυρες απαντήσεις.');
            }
            return match ($pending['mode']) {
                self::MODE_CONFIDENT => [ThoughtRecord::SOURCE_CONFIDENT, $pending['observed'], []],
                self::MODE_NONE => [ThoughtRecord::SOURCE_NONE, null, []],
                default => [ThoughtRecord::SOURCE_AI_DOWN, null, []],
            };
        }

        $valid = [ThoughtRecord::ANSWER_NEUTRAL, ThoughtRecord::ANSWER_TRAP, ThoughtRecord::ANSWER_UNKNOWN];
        $saved = [];
        foreach ($answers as $i => $answer) {
            $question = $pending['questions'][$i] ?? null;
            $stopped = $saved !== [] && $saved[count($saved) - 1]['answer'] === ThoughtRecord::ANSWER_TRAP;
            if ($question === null || $stopped || !in_array($answer, $valid, true)) {
                $this->invalid('Μη έγκυρες απαντήσεις.');
            }
            $saved[] = ['type' => $question['type'], 'answer' => $answer, 'trap_position' => $question['trap_position']];
        }

        $last = $saved[count($saved) - 1] ?? null;
        if ($last !== null && $last['answer'] === ThoughtRecord::ANSWER_TRAP) {
            return [ThoughtRecord::SOURCE_ASKED, $last['type'], $saved];
        }
        if (count($saved) !== count($pending['questions'])) {
            $this->invalid('Μη έγκυρες απαντήσεις.');
        }
        return [ThoughtRecord::SOURCE_NONE, null, $saved];
    }

    private function response(string $source, mixed $response): ?string
    {
        $allowed = match ($source) {
            ThoughtRecord::SOURCE_AI_DOWN => [null],
            ThoughtRecord::SOURCE_DECLINED => [ThoughtRecord::RESPONSE_NOT_NOW],
            ThoughtRecord::SOURCE_NONE => [ThoughtRecord::RESPONSE_YES, ThoughtRecord::RESPONSE_NOT_NOW],
            default => [ThoughtRecord::RESPONSE_YES, ThoughtRecord::RESPONSE_NOT_NOW, ThoughtRecord::RESPONSE_REJECTED],
        };
        if (!in_array($response, $allowed, true)) {
            $this->invalid('Μη έγκυρη απάντηση στη σημείωση.');
        }
        return $response;
    }

    private function text(mixed $value, int $max, string $error, bool $required): string
    {
        if ($value !== null && !is_string($value)) {
            $this->invalid($error);
        }
        $text = trim((string) $value);
        if ($required && $text === '') {
            $this->invalid($error);
        }
        if (mb_strlen($text) > $max) {
            $this->invalid('Το κείμενο ξεπερνά τους ' . number_format($max, 0, ',', '.') . ' χαρακτήρες.');
        }
        return $text;
    }

    private function emotionsBefore(mixed $value): array
    {
        $error = 'Διάλεξε από 1 έως ' . ThoughtRecord::MAX_EMOTIONS . ' συναισθήματα, με ένταση από 0 έως 100.';
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > ThoughtRecord::MAX_EMOTIONS) {
            $this->invalid($error);
        }

        $emotions = [];
        foreach ($value as $item) {
            $name = is_array($item) ? ($item['emotion'] ?? null) : null;
            $before = is_array($item) ? ($item['before'] ?? null) : null;
            if (!is_string($name) || !isset(ThinkingTraps::EMOTIONS[$name]) || !self::isIntensity($before) || isset($emotions[$name])) {
                $this->invalid($error);
            }
            $emotions[$name] = ['emotion' => $name, 'before' => $before];
        }
        return array_values($emotions);
    }

    private function emotionsAfter(array $emotions, mixed $after): array
    {
        foreach ($emotions as &$emotion) {
            $value = is_array($after) ? ($after[$emotion['emotion']] ?? null) : null;
            if (!self::isIntensity($value)) {
                $this->invalid('Δώσε ένταση από 0 έως 100 για κάθε συναίσθημα.');
            }
            $emotion['after'] = $value;
        }
        unset($emotion);
        return $emotions;
    }

    private static function isIntensity(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 100;
    }

    private function analyze(string $text): ?array
    {
        try {
            return (new AiClient())->analyze($text);
        } catch (\Exception $e) {
            error_log('Thought record AI error: ' . $e->getMessage());
            return null;
        }
    }

    private function risk(?array $aiData): ?float
    {
        return $aiData === null ? null : AiClient::probability($aiData, 'risk');
    }

    private function model(?array $aiData): array
    {
        if ($aiData === null) {
            return ['model_probability' => null, 'model_detected' => null, 'model_ranking' => null, 'model_version' => null];
        }
        $ranking = ThinkingTraps::ranking($aiData['analysis']['distortion']['type_probabilities'] ?? null);
        $version = $aiData['model_version'] ?? null;
        return [
            'model_probability' => AiClient::probability($aiData, 'distortion'),
            'model_detected' => AiClient::detected($aiData, 'distortion'),
            'model_ranking' => $ranking !== [] ? $ranking : null,
            'model_version' => is_string($version) && $version !== '' ? mb_substr($version, 0, 100) : null,
        ];
    }

    private function remember(array $pending): string
    {
        $token = bin2hex(random_bytes(16));
        $this->withSession(function () use ($token, $pending): void {
            $list = array_filter(
                is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : [],
                static fn (mixed $item): bool => is_array($item) && ($item['at'] ?? 0) > time() - self::PENDING_TTL
            );
            $list[$token] = $pending + ['at' => time()];
            $_SESSION[self::SESSION_KEY] = array_slice($list, -self::MAX_PENDING, null, true);
        });
        return $token;
    }

    private function pending(string $token): ?array
    {
        $item = $_SESSION[self::SESSION_KEY][$token] ?? null;
        return is_array($item) && ($item['at'] ?? 0) > time() - self::PENDING_TTL
            && (int) ($item['user_id'] ?? 0) === (int) current_user_id() ? $item : null;
    }

    private function forget(string $token): bool
    {
        $found = false;
        $this->withSession(function () use ($token, &$found): void {
            $found = $this->pending($token) !== null;
            unset($_SESSION[self::SESSION_KEY][$token]);
        });
        return $found;
    }

    private function withSession(callable $change): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $change();
        release_session();
    }

    private function invalid(string $error): void
    {
        json_response(['success' => false, 'error' => $error], 422);
    }

    private function expired(): void
    {
        json_response(['success' => false, 'error' => 'Η σελίδα έληξε. Ξεκίνα μια νέα σελίδα.'], 409);
    }
}
