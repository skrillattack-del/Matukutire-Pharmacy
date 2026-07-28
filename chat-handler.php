<?php
/**
 * Server-side proxy for the pharmacy assistant.
 *
 * Reads a single OpenRouter API key from the OPENROUTER_API_KEY environment
 * variable and forwards chat requests to OpenRouter. The key never reaches the
 * browser, so one developer can set it once in Railway and every visitor can use
 * the widget with no setup.
 *
 * Includes basic per-IP rate limiting and input validation so the public
 * endpoint cannot be trivially abused.
 *
 * `GET chat-handler.php?health=1` reports whether the key reached the container,
 * without revealing it — use that first when the widget says it is unconfigured.
 *
 * `GET chat-handler.php?models=1` returns the free models OpenRouter is currently
 * offering, which is what fills the picker in the chat widget. The list is pulled
 * live and cached, because OpenRouter retires free models regularly — hard-coding
 * one is how the assistant breaks a month later.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/**
 * Read a setting from the Apache request environment first (populated by the
 * PassEnv directives that docker/apache-port.sh writes) and fall back to the
 * process environment. Returns the trimmed value and where it came from, so the
 * health check can say which layer is actually delivering the key.
 *
 * @return array{0: string, 1: string} [value, source]
 */
function envSetting(string $name): array
{
    $fromServer = $_SERVER[$name] ?? null;
    if (is_string($fromServer) && trim($fromServer) !== '') {
        return [trim($fromServer), 'server'];
    }

    $fromProcess = getenv($name);
    if (is_string($fromProcess) && trim($fromProcess) !== '') {
        return [trim($fromProcess), 'env'];
    }

    return ['', 'none'];
}

[$openRouterKey, $keySource] = envSetting('OPENROUTER_API_KEY');
[$modelSetting] = envSetting('OPENROUTER_MODEL');
[$paidModelSetting] = envSetting('OPENROUTER_PAID_MODEL');
[$rateLimitSetting] = envSetting('CHAT_RATE_LIMIT');
[$rateWindowSetting] = envSetting('CHAT_RATE_WINDOW');

$maxMessageLength = 1200;
$maxMessages = 12;
$historyWindow = 10;
$rateLimit = $rateLimitSetting !== '' ? (int) $rateLimitSetting : 20;
$rateWindowSeconds = $rateWindowSetting !== '' ? (int) $rateWindowSetting : 3600;

$apiUrl = 'https://openrouter.ai/api/v1/chat/completions';
$modelsUrl = 'https://openrouter.ai/api/v1/models';
$siteTitle = 'Lomagundi & Forestal Pharmacies';

// How long the fetched free-model list stays cached on disk. Six hours keeps the
// public endpoint from turning into an OpenRouter traffic amplifier while still
// noticing roster changes the same day.
$modelCacheSeconds = 6 * 3600;

// How many free models to offer in the picker. Enough choice to route around one
// model being rate-limited, short enough to stay a usable dropdown.
$modelChoiceLimit = 3;

// The one paid model offered alongside the free ones, for when the free tier is
// rate-limited or simply answering badly. DeepSeek V4 Flash costs about $0.14 per
// million input tokens and $0.28 per million output — a few cents a month at this
// site's traffic. Set OPENROUTER_PAID_MODEL to swap it, or to an empty value to
// offer nothing but free models.
$paidModel = $paidModelSetting !== '' ? $paidModelSetting : 'deepseek/deepseek-v4-flash';
if (strtolower($paidModelSetting) === 'none') {
    $paidModel = '';
}

// Preferred families, in order. Any free model from these is floated to the top of
// the picker; everything else free still appears below them.
$preferredFamilies = ['deepseek', 'qwen', 'meta-llama', 'mistral', 'google', 'z-ai', 'moonshotai'];

// Free models that are not general-purpose chat would only confuse a pharmacy
// visitor. Some announce themselves in the id; others (coding agents especially)
// only say so in the catalogue description, so both are checked. The description
// phrases are deliberately multi-word — a bare "code" would throw out perfectly
// good general models that merely mention code among their abilities.
$excludedIdPatterns = ['guard', 'safety', 'moderation', 'embed', 'rerank', 'coder', 'whisper', 'tts'];
$excludedDescriptions = [
    'coding agent',
    'code completion',
    'coding model',
    'guardrail',
    'content moderation',
    'content safety',
    'text embedding',
];

// Used only when OpenRouter cannot be reached and no cache exists yet.
$fallbackModel = $paidModel !== '' ? $paidModel : 'deepseek/deepseek-v4-flash';

// ---------------------------------------------------------------------------
// Response helpers
// ---------------------------------------------------------------------------

function respond(bool $ok, string $message, int $status = 200, ?array $extra = null): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['success' => $ok, 'message' => $message];
    if ($extra !== null) {
        $payload = array_merge($payload, $extra);
    }
    // Substitute rather than throw on malformed UTF-8 from the model, so a bad byte
    // in a reply cannot turn into a blank 500 for the visitor.
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function clientIp(): string
{
    // Railway forwards the real client IP in X-Forwarded-For; fall back to REMOTE_ADDR.
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $first = explode(',', $forwarded)[0];
        $first = trim($first);
        if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
            return $first;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function rateLimitPath(string $ip): string
{
    $hash = hash('sha256', $ip);
    return sys_get_temp_dir() . '/chat_ratelimit_' . $hash . '.json';
}

function isRateLimited(string $ip, int $limit, int $window): bool
{
    if ($limit <= 0) {
        return false;
    }
    $path = rateLimitPath($ip);
    $now = time();
    $record = [];
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $record = $decoded;
            }
        }
    }

    $cutoff = $now - $window;
    $timestamps = [];
    foreach ($record as $ts) {
        if (is_int($ts) && $ts > $cutoff) {
            $timestamps[] = $ts;
        }
    }

    if (count($timestamps) >= $limit) {
        return true;
    }

    $timestamps[] = $now;
    @file_put_contents($path, json_encode($timestamps, JSON_THROW_ON_ERROR), LOCK_EX);
    return false;
}

// ---------------------------------------------------------------------------
// Free model discovery
// ---------------------------------------------------------------------------

/**
 * Fetch OpenRouter's catalogue and build the picker: the models that cost nothing
 * to run, newest-usable first, followed by the single paid model kept on hand for
 * when the free tier is throttled. OpenRouter adds and retires free models
 * continually, so this is derived live rather than from a hard-coded list.
 *
 * @return list<array{id: string, name: string, context: int, paid: bool}>
 */
function fetchModelOptions(
    string $url,
    string $key,
    array $preferred,
    array $excludedIds,
    array $excludedDescriptions,
    int $limit,
    string $paidModel
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return [];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
        error_log('chat-handler: could not list OpenRouter models (HTTP ' . $status . ') ' . $error);
        return [];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
        error_log('chat-handler: unexpected model list payload from OpenRouter.');
        return [];
    }

    $free = [];
    $paid = null;

    foreach ($decoded['data'] as $entry) {
        if (!is_array($entry) || !isset($entry['id']) || !is_string($entry['id'])) {
            continue;
        }

        $id = $entry['id'];

        // Pick up the display name for the configured paid model on the way past, so
        // the dropdown can label it properly instead of showing a raw slug.
        if ($paidModel !== '' && $id === $paidModel) {
            $paid = [
                'id' => $id,
                'name' => isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : $id,
                'context' => (int) ($entry['context_length'] ?? 0),
                'paid' => true,
            ];
        }

        // OpenRouter marks genuinely zero-cost variants with a ":free" suffix. Trusting
        // the pricing fields alone is not enough: the Auto Router and the media models
        // (Lyria and friends) also report "0" for prompt and completion while charging
        // per request or per second elsewhere.
        if (!str_ends_with($id, ':free')) {
            continue;
        }

        $pricing = $entry['pricing'] ?? [];
        if (!is_array($pricing)) {
            continue;
        }

        // Belt and braces: every priced dimension the catalogue reports must be zero.
        $charged = false;
        foreach ($pricing as $amount) {
            if (is_numeric($amount) && (float) $amount > 0.0) {
                $charged = true;
                break;
            }
        }
        if ($charged) {
            continue;
        }

        // Must be able to take text in and give text back — no image or audio models.
        $architecture = $entry['architecture'] ?? [];
        if (is_array($architecture)) {
            $inputs = $architecture['input_modalities'] ?? ['text'];
            $outputs = $architecture['output_modalities'] ?? ['text'];
            if (is_array($inputs) && !in_array('text', $inputs, true)) {
                continue;
            }
            if (is_array($outputs) && !in_array('text', $outputs, true)) {
                continue;
            }
        }

        $name = isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : $id;

        $label = strtolower($id . ' ' . $name);
        foreach ($excludedIds as $needle) {
            if (str_contains($label, $needle)) {
                continue 2;
            }
        }

        $description = isset($entry['description']) && is_string($entry['description'])
            ? strtolower($entry['description'])
            : '';
        foreach ($excludedDescriptions as $phrase) {
            if (str_contains($description, $phrase)) {
                continue 2;
            }
        }

        $free[] = [
            'id' => $id,
            'name' => $name,
            'context' => (int) ($entry['context_length'] ?? 0),
            'paid' => false,
        ];
    }

    // Float the well-known families to the top; keep everything else as a backstop
    // for the day those families have nothing free on offer.
    usort($free, static function (array $a, array $b) use ($preferred): int {
        $rank = static function (string $id) use ($preferred): int {
            foreach ($preferred as $index => $family) {
                if (str_starts_with($id, $family . '/')) {
                    return $index;
                }
            }
            return count($preferred);
        };

        $byRank = $rank($a['id']) <=> $rank($b['id']);
        if ($byRank !== 0) {
            return $byRank;
        }
        return $b['context'] <=> $a['context'];
    });

    $options = array_slice($free, 0, $limit);

    // The paid model goes last, so the free options are what a visitor reaches first
    // and choosing to spend money is always a deliberate step down the list.
    if ($paid !== null) {
        $options[] = $paid;
    } elseif ($paidModel !== '') {
        // Configured but absent from the catalogue — usually a retired or mistyped id.
        error_log('chat-handler: paid model "' . $paidModel . '" is not in the OpenRouter catalogue.');
    }

    return $options;
}

/**
 * The picker list wrapped in a disk cache, so a busy page cannot turn every visitor
 * into an outbound request to OpenRouter.
 *
 * @return list<array{id: string, name: string, context: int, paid: bool}>
 */
function cachedModelOptions(
    string $url,
    string $key,
    array $preferred,
    array $excludedIds,
    array $excludedDescriptions,
    int $limit,
    string $paidModel,
    int $ttl
): array {
    // Keying the cache on the paid model keeps a changed OPENROUTER_PAID_MODEL from
    // being masked by a list built under the previous value.
    $path = sys_get_temp_dir() . '/chat_models_' . substr(hash('sha256', $paidModel), 0, 16) . '.json';

    if (is_file($path) && (time() - (int) @filemtime($path)) < $ttl) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }
    }

    $models = fetchModelOptions($url, $key, $preferred, $excludedIds, $excludedDescriptions, $limit, $paidModel);
    if ($models !== []) {
        @file_put_contents($path, json_encode($models), LOCK_EX);
        return $models;
    }

    // Upstream is unhappy — serve the stale cache rather than an empty picker.
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }
    }

    return [];
}

function cleanText(?string $value): string
{
    if ($value === null) {
        return '';
    }
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '');
}

// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

// Deployment check: `GET chat-handler.php?health=1` reports whether the container
// can actually see the API key, and by which route. Deliberately returns no key
// material — only a length and a prefix flag, enough to spot a truncated or
// quote-wrapped value pasted into the hosting dashboard.
if (isset($_GET['health'])) {
    respond(true, 'OK', 200, [
        'configured' => $openRouterKey !== '',
        'keySource' => $keySource,
        'keyLength' => strlen($openRouterKey),
        'keyPrefixOk' => str_starts_with($openRouterKey, 'sk-or-'),
        'pinnedModel' => $modelSetting,
        'paidModel' => $paidModel,
        'curl' => function_exists('curl_init'),
        'mbstring' => function_exists('mb_strlen'),
    ]);
}

// Powers the model picker in the widget. Cached upstream-side, so this stays cheap
// even though it is public.
if (isset($_GET['models'])) {
    if ($openRouterKey === '') {
        respond(false, 'The pharmacy assistant is not configured right now.', 503, ['models' => []]);
    }

    $available = cachedModelOptions(
        $modelsUrl,
        $openRouterKey,
        $preferredFamilies,
        $excludedIdPatterns,
        $excludedDescriptions,
        $modelChoiceLimit,
        $paidModel,
        $modelCacheSeconds
    );

    respond(true, 'OK', 200, [
        'models' => $available,
        // A pinned OPENROUTER_MODEL wins over anything the visitor picks.
        'pinned' => $modelSetting !== '' ? $modelSetting : null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Only POST requests are accepted.', 405);
}

if ($openRouterKey === '') {
    respond(false, 'The pharmacy assistant is not configured right now. Please call or WhatsApp us.', 503);
}

$ip = clientIp();
if (isRateLimited($ip, $rateLimit, $rateWindowSeconds)) {
    respond(false, 'Too many messages right now. Please wait a few minutes and try again.', 429);
}

$rawInput = file_get_contents('php://input');
if ($rawInput === false || $rawInput === '') {
    respond(false, 'No message data received.', 400);
}

try {
    $input = json_decode($rawInput, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    respond(false, 'Invalid JSON body.', 400);
}

if (!is_array($input) || !isset($input['messages']) || !is_array($input['messages'])) {
    respond(false, 'Request must contain a "messages" array.', 400);
}

$messages = $input['messages'];
if (count($messages) < 1 || count($messages) > $maxMessages) {
    respond(false, 'Message history is out of range.', 400);
}

$validated = [];
foreach ($messages as $msg) {
    if (!is_array($msg) || !isset($msg['role'], $msg['content'])) {
        respond(false, 'Malformed message object.', 400);
    }
    if (!in_array($msg['role'], ['user', 'assistant'], true)) {
        respond(false, 'Invalid message role.', 400);
    }
    $text = cleanText((string) $msg['content']);
    if ($text === '' || mb_strlen($text) > $maxMessageLength) {
        respond(false, 'Message content is empty or too long.', 400);
    }
    $validated[] = ['role' => $msg['role'], 'content' => $text];
}

$validated = array_slice($validated, -$historyWindow);

// ---------------------------------------------------------------------------
// Resolve the model
//
// A pinned OPENROUTER_MODEL always wins. Otherwise the visitor may choose from the
// picker — and the choice is checked against that same list rather than trusted,
// because the request is unauthenticated and the key behind it is ours to protect.
// With nothing chosen the first free model is used, so reaching the paid option is
// always a deliberate act.
// ---------------------------------------------------------------------------

if ($modelSetting !== '') {
    $model = $modelSetting;
} else {
    $available = cachedModelOptions(
        $modelsUrl,
        $openRouterKey,
        $preferredFamilies,
        $excludedIdPatterns,
        $excludedDescriptions,
        $modelChoiceLimit,
        $paidModel,
        $modelCacheSeconds
    );
    $allowedIds = array_column($available, 'id');

    $requested = isset($input['model']) && is_string($input['model']) ? trim($input['model']) : '';

    if ($requested !== '' && in_array($requested, $allowedIds, true)) {
        $model = $requested;
    } elseif ($allowedIds !== []) {
        $model = $allowedIds[0];
    } else {
        // OpenRouter's catalogue is unreachable and nothing is cached. Use the paid
        // model rather than leaving the assistant dead; pin OPENROUTER_MODEL to a free
        // model if you would rather it never spend anything.
        $model = $fallbackModel;
        error_log('chat-handler: no model list available, falling back to ' . $fallbackModel);
    }
}

$systemPrompt = <<<'PROMPT'
You are the Matukutire Pharmacies website assistant for Lomagundi Pharmacy in Chinhoyi and Forestal Machipisa Pharmacy in Harare, Zimbabwe. Help with branch information, hours, services and general product questions. Never diagnose, prescribe, claim live stock or replace a pharmacist or doctor. For symptoms, interactions, dosage, pregnancy, emergencies or uncertain medicine advice, direct the user to a qualified pharmacist or medical professional. Keep answers concise and friendly.
PROMPT;

$payload = [
    'model' => $model,
    'messages' => array_merge(
        [['role' => 'system', 'content' => $systemPrompt]],
        $validated
    ),
    'temperature' => 0.3,
    'max_tokens' => 500,
];

$ch = curl_init($apiUrl);
if ($ch === false) {
    respond(false, 'Could not initialize outbound connection.', 500);
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $openRouterKey,
        'Content-Type: application/json',
        'HTTP-Referer: https://' . ($_SERVER['HTTP_HOST'] ?? 'lomagundi-pharmacy.com'),
        'X-OpenRouter-Title: ' . $siteTitle,
    ],
]);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($responseBody === false || $curlError !== '') {
    error_log('chat-handler: curl failure contacting OpenRouter: ' . $curlError);
    respond(false, 'Could not reach the assistant service. Please try again shortly.', 502);
}

try {
    // A completion response nests four levels deep ({} -> choices[] -> {} -> message{}),
    // and error bodies can go deeper still, so keep the default decode depth.
    $apiResponse = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    error_log('chat-handler: could not decode OpenRouter response (HTTP ' . $statusCode . '): ' . $e->getMessage());
    respond(false, 'Unexpected response from the assistant service.', 502);
}

if ($statusCode < 200 || $statusCode >= 300) {
    $providerMessage = '';
    if (is_array($apiResponse) && isset($apiResponse['error']['message']) && is_string($apiResponse['error']['message'])) {
        $providerMessage = $apiResponse['error']['message'];
    }

    // Log the real cause for the operator; the visitor gets something readable.
    error_log(sprintf(
        'chat-handler: OpenRouter returned HTTP %d for model %s: %s',
        $statusCode,
        $model,
        $providerMessage !== '' ? $providerMessage : '(no error message)'
    ));

    if ($statusCode === 429) {
        respond(false, 'The assistant is busy right now — please try again in a minute.', 502);
    }
    if ($statusCode === 401 || $statusCode === 403) {
        respond(false, 'The pharmacy assistant is not configured correctly. Please call or WhatsApp us.', 502);
    }

    respond(false, $providerMessage !== '' ? $providerMessage : 'Assistant service returned an error.', 502);
}

if (
    !is_array($apiResponse) ||
    !isset($apiResponse['choices'][0]['message']['content']) ||
    !is_string($apiResponse['choices'][0]['message']['content'])
) {
    respond(false, 'The assistant returned an empty response.', 502);
}

$reply = trim($apiResponse['choices'][0]['message']['content']);
if ($reply === '') {
    respond(false, 'The assistant returned an empty response.', 502);
}

respond(true, 'OK', 200, ['reply' => $reply, 'model' => $model]);
