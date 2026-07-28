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
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$openRouterKey = $_SERVER['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: '';
$model = $_SERVER['OPENROUTER_MODEL'] ?? getenv('OPENROUTER_MODEL') ?: 'google/gemini-2.5-flash-lite';
$maxMessageLength = 1200;
$maxMessages = 12;
$historyWindow = 10;
$rateLimit = (int) ($_SERVER['CHAT_RATE_LIMIT'] ?? getenv('CHAT_RATE_LIMIT') ?: 20);
$rateWindowSeconds = (int) ($_SERVER['CHAT_RATE_WINDOW'] ?? getenv('CHAT_RATE_WINDOW') ?: 3600);

$apiUrl = 'https://openrouter.ai/api/v1/chat/completions';
$siteTitle = 'Lomagundi & Forestal Pharmacies';

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
    echo json_encode($payload, JSON_THROW_ON_ERROR);
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
    $input = json_decode($rawInput, true, 3, JSON_THROW_ON_ERROR);
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
// Forward to OpenRouter
// ---------------------------------------------------------------------------

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
    respond(false, 'Could not reach the assistant service. Please try again shortly.', 502);
}

try {
    $apiResponse = json_decode($responseBody, true, 3, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    respond(false, 'Unexpected response from the assistant service.', 502);
}

if ($statusCode < 200 || $statusCode >= 300) {
    $message = 'Assistant service returned an error.';
    if (is_array($apiResponse) && isset($apiResponse['error']['message']) && is_string($apiResponse['error']['message'])) {
        // Surface the provider message, but never include the raw API key or stack traces.
        $message = $apiResponse['error']['message'];
    }
    respond(false, $message, 502);
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

respond(true, 'OK', 200, ['reply' => $reply]);
