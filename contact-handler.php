<?php
/**
 * Contact form handler for Lomagundi & Forestal Pharmacies.
 *
 * Hardened rewrite of the original submit_form.php. Fixes applied:
 *  - Email header injection: the visitor's email address is no longer
 *    interpolated into the "From" header (the classic PHP mail() header
 *    injection vulnerability). We send From a fixed, local address and put
 *    the visitor's address in Reply-To instead, so replying still reaches
 *    them.
 *  - Strips CR/LF and other control characters from every field before use,
 *    which blocks attempts to smuggle extra headers (Bcc, Cc, etc.) via any
 *    form field.
 *  - Validates all fields server-side (required + max length + real email
 *    format) since client-side JS validation can always be bypassed.
 *  - Honours a hidden honeypot field ("website") to quietly no-op simple
 *    spam bots without giving them a signal to change tactics.
 *  - Returns JSON with proper HTTP status codes so the front-end can tell
 *    success from failure.
 */

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contact.html');
    exit;
}

// ---- Configuration --------------------------------------------------------
// Mailbox that should receive enquiries. Update per your real hosting setup.
$recipientEmail = 'help@lomagundi-pharmacy.com';
// A local address your own mail server/domain is authorised to send "From".
// Never put visitor-supplied data here — that is what causes header
// injection and spoofing/blacklisting problems.
$fromAddress = 'no-reply@lomagundi-pharmacy.com';
$maxMessageLength = 500;

function respond(bool $ok, string $message, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $ok, 'message' => $message]);
    exit;
}

// Remove CR/LF and other control characters so no field can be used to
// inject extra mail headers or forge additional body content.
function cleanField(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', $value);
    return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));
}

// ---- Honeypot ---------------------------------------------------------
// Hidden from real visitors via CSS; only bots that fill in every field
// trip this. Respond as if it succeeded so the bot has no reason to adapt.
if (!empty($_POST['website'] ?? '')) {
    respond(true, 'Thank you for your submission!');
}

// ---- Collect + validate -------------------------------------------------
$name    = cleanField((string) ($_POST['bb-name'] ?? ''));
$phone   = cleanField((string) ($_POST['bb-phone'] ?? ''));
$email   = cleanField((string) ($_POST['user-email'] ?? ''));
$branch  = cleanField((string) ($_POST['bb-branch'] ?? ''));
$message = cleanField((string) ($_POST['bb-message'] ?? ''));

$errors = [];
if ($name === '' || mb_strlen($name) > 80) {
    $errors[] = 'name';
}
if ($phone === '' || mb_strlen($phone) > 30) {
    $errors[] = 'phone';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}
if ($branch === '') {
    $errors[] = 'branch';
}
if ($message === '' || mb_strlen($message) > $maxMessageLength) {
    $errors[] = 'message';
}

if (!empty($errors)) {
    respond(false, 'Please check the following field(s) and try again: ' . implode(', ', $errors), 400);
}

// ---- Compose + send -----------------------------------------------------
$subject = 'New website enquiry - ' . $branch;

$body  = "You have a new enquiry from the pharmacy website.\n\n";
$body .= "Name:    {$name}\n";
$body .= "Phone:   {$phone}\n";
$body .= "Email:   {$email}\n";
$body .= "Branch:  {$branch}\n";
$body .= "Message:\n{$message}\n";

$headers   = [];
$headers[] = 'From: Lomagundi & Forestal Pharmacies Website <' . $fromAddress . '>';
$headers[] = 'Reply-To: ' . $email; // Replies go straight to the visitor.
$headers[] = 'X-Mailer: PHP/' . phpversion();
$headers[] = 'Content-Type: text/plain; charset=utf-8';

$sent = @mail($recipientEmail, $subject, $body, implode("\r\n", $headers));

if ($sent) {
    respond(true, 'Thank you for your submission! We will be in touch shortly.');
}

respond(false, 'Sorry, we could not send your message right now. Please call or WhatsApp us directly.', 500);
