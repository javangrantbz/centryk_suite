<?php
/**
 * Generate a company logo with OpenAI's image API and hand it back to the
 * browser as a data URI. It does NOT save anything or touch companies.logo —
 * the caller previews the result and only the normal profile save
 * (api/companies/update-profile.php or api/onboarding/save_profile.php) with a
 * `logo` file field commits it. Review-then-apply.
 *
 * Requires OPENAI_API_KEY in .env; the button that calls this is hidden when
 * the key is absent.
 *
 * POST JSON: { company_id (required), prompt (optional, <=300 chars) }
 * → { success, image: "data:image/webp;base64,...", remaining }
 */
require_once __DIR__ . '/../../../app/core/Env.php';
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Env::load(__DIR__ . '/../../../.env');
Auth::start();

$caller = Auth::user();
if (!$caller) {
    Response::error('Unauthorized.', 401);
}

$apiKey = trim((string)($_ENV['OPENAI_API_KEY'] ?? ''));
if ($apiKey === '') {
    Response::error("AI logo generation isn't configured.", 503);
}
if (!function_exists('curl_init')) {
    Response::error('AI logo generation is unavailable on this server.', 503);
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId = (int)($body['company_id'] ?? 0);
if (!$companyId) {
    Response::error('company_id is required.', 422);
}

$pdo = DB::pdo();

// Caller must administer this company (or be a site admin).
if (empty($caller['is_admin'])) {
    $chk = $pdo->prepare('SELECT 1 FROM company_members
                          WHERE company_id = :cid AND user_id = :uid
                            AND role = "admin" AND status = "active" LIMIT 1');
    $chk->execute(['cid' => $companyId, 'uid' => (int)$caller['id']]);
    if (!$chk->fetch()) {
        Response::error('You are not an admin of this company.', 403);
    }
}

$co = $pdo->prepare('SELECT name, business_type FROM companies WHERE id = :id LIMIT 1');
$co->execute(['id' => $companyId]);
$company = $co->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    Response::error('Company not found.', 404);
}

// Rate limit: a company gets a fixed number of generations per rolling 24h.
const AI_LOGO_DAILY_CAP = 12;
$used = $pdo->prepare('SELECT COUNT(*) FROM ai_logo_events
                       WHERE company_id = :cid AND created_at > (NOW() - INTERVAL 1 DAY)');
$used->execute(['cid' => $companyId]);
$usedCount = (int)$used->fetchColumn();
if ($usedCount >= AI_LOGO_DAILY_CAP) {
    Response::error('You have reached the daily limit for AI logo generation. Try again tomorrow.', 429);
}

// ── Build the prompt ────────────────────────────────────────────────────────
$typeLabels = [
    'school'      => 'school or education provider',
    'gym'         => 'gym or fitness studio',
    'clinic'      => 'health clinic',
    'salon'       => 'salon or spa',
    'retail'      => 'retail shop',
    'restaurant'  => 'restaurant',
    'ice_cream'   => 'ice cream and dessert shop',
    'meat_shop'   => 'butcher and meat shop',
    'cafeteria'   => 'cafeteria and food service',
    'auto_sales'  => 'car dealership',
    'auto_rental' => 'car rental company',
    'services'    => 'services business',
    'property'    => 'property rental company',
    'grocery'     => 'grocery store',
];
$name     = trim((string)$company['name']) ?: 'this business';
$typeText = $typeLabels[(string)($company['business_type'] ?? '')] ?? '';

$prompt = 'A modern, minimalist logo icon for "' . $name . '"'
        . ($typeText !== '' ? ', a ' . $typeText : '') . '. '
        . 'Flat vector style, simple bold geometric shapes, a cohesive palette of two or three colors, '
        . 'clean and professional, centered with generous padding, on a plain transparent background. '
        . 'Icon only — no text, no letters, no words, no typography.';

$extra = trim((string)($body['prompt'] ?? ''));
if ($extra !== '') {
    $extra = mb_substr($extra, 0, 300);
    $prompt .= ' Incorporate this idea: ' . $extra;
}

// ── Call OpenAI ─────────────────────────────────────────────────────────────
@set_time_limit(120);
$payload = json_encode([
    'model'              => 'gpt-image-1',
    'prompt'             => $prompt,
    'n'                  => 1,
    'size'               => '1024x1024',
    'quality'            => 'medium',
    'background'         => 'transparent',
    'output_format'      => 'webp',
    'output_compression' => 90,
]);

$ch = curl_init('https://api.openai.com/v1/images/generations');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 100,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
]);
$raw  = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cErr = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    Response::error('Could not reach the image service (' . ($cErr ?: 'timeout') . '). Please try again.', 502);
}

$json = json_decode($raw, true);

if ($http !== 200) {
    $msg = $json['error']['message'] ?? 'The image service returned an error.';
    $code = $json['error']['code'] ?? '';
    if ($code === 'moderation_blocked' || stripos($msg, 'safety') !== false) {
        Response::error('That description was rejected by the image service. Try wording it differently.', 422);
    }
    Response::error('Logo generation failed: ' . $msg, 502);
}

$b64 = $json['data'][0]['b64_json'] ?? '';
if ($b64 === '') {
    Response::error('The image service did not return an image. Please try again.', 502);
}

// Sanity-check the payload size (data URI still has to pass the 2MB logo
// upload cap when the browser submits it back as a file).
if (strlen($b64) > 2_600_000) {
    Response::error('The generated image was too large. Please try again.', 502);
}

$pdo->prepare('INSERT INTO ai_logo_events (company_id, user_id) VALUES (:c, :u)')
    ->execute(['c' => $companyId, 'u' => (int)$caller['id']]);

Response::ok([
    'image'     => 'data:image/webp;base64,' . $b64,
    'remaining' => max(0, AI_LOGO_DAILY_CAP - $usedCount - 1),
]);
