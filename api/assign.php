<?php
/**
 * api/assign.php — выдача подменного номера посетителю
 *
 * GET-параметры:
 *   client_id    — ClientID Яндекс.Метрики
 *   utm_source, utm_medium, utm_campaign, utm_term, utm_content
 *   landing_page — URL страницы посетителя
 *   referrer     — откуда пришёл
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/rb.php';
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/sites.php';

R::setup('sqlite:' . DB_PATH);
R::freeze(true);

// --- Определяем сайт по ключу ---
$siteKey = isset($_GET['site']) ? trim($_GET['site']) : null;
$site    = site_by_key($siteKey);

if (!$site) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'unknown site key']);
    R::close();
    exit;
}

$siteId        = (int)$site['id'];
$cookieName    = 'ct_session_' . $siteId;
$ttlMinutes    = (int)($site['session_ttl_minutes'] ?: 10);
$fallbackPhone = $site['fallback_phone'] ?: '';
$now           = date('Y-m-d H:i:s');

// --- Входные данные ---
$clientId   = isset($_GET['client_id'])    ? trim($_GET['client_id'])    : null;
$utmSource  = isset($_GET['utm_source'])   ? trim($_GET['utm_source'])   : null;
$utmMedium  = isset($_GET['utm_medium'])   ? trim($_GET['utm_medium'])   : null;
$utmCampaign= isset($_GET['utm_campaign']) ? trim($_GET['utm_campaign']) : null;
$utmTerm    = isset($_GET['utm_term'])     ? trim($_GET['utm_term'])     : null;
$utmContent = isset($_GET['utm_content'])  ? trim($_GET['utm_content'])  : null;
$landingPage= isset($_GET['landing_page']) ? trim($_GET['landing_page']) : null;
$referrer   = isset($_GET['referrer'])     ? trim($_GET['referrer'])     : null;
$ip         = $_SERVER['REMOTE_ADDR'] ?? null;

// --- 1. Проверяем существующую сессию ---
$session = null;

// По cookie
$sessionCookie = $_COOKIE[$cookieName] ?? null;
if ($sessionCookie) {
    $session = R::findOne('sessions',
        'session_cookie = ? AND site_id = ? AND expires_at > ?',
        [$sessionCookie, $siteId, $now]
    );
}

// По client_id если cookie не нашло
if (!$session && $clientId) {
    $session = R::findOne('sessions',
        'client_id = ? AND site_id = ? AND expires_at > ?',
        [$clientId, $siteId, $now]
    );
}

// Нашли активную сессию — продлеваем TTL и возвращаем тот же номер
if ($session) {
    $session->expires_at = date('Y-m-d H:i:s', strtotime("+{$ttlMinutes} minutes"));
    R::store($session);

    setcookie($cookieName, $session->session_cookie, time() + $ttlMinutes * 60, '/');

    echo json_encode([
        'status' => 'existing',
        'phone'  => $session->phone,
    ]);
    R::close();
    exit;
}

// --- 2. Освобождаем просроченные сессии этого сайта (попутная очистка) ---
R::exec("UPDATE sessions SET phonepool_id = NULL, phone = NULL
         WHERE site_id = ? AND expires_at IS NOT NULL AND expires_at < ?", [$siteId, $now]);

// --- 3. Ищем свободный номер по round-robin (только пул этого сайта) ---
// Берём активный номер, который дольше всего не использовался
$phone = R::getRow(
    "SELECT pp.id, pp.phone
     FROM phonepool pp
     WHERE pp.site_id = ?
       AND pp.is_active = 1
       AND pp.id NOT IN (
           SELECT phonepool_id FROM sessions
           WHERE phonepool_id IS NOT NULL AND expires_at > ?
       )
     ORDER BY (
         SELECT MAX(s.revealed_at) FROM sessions s WHERE s.phonepool_id = pp.id
     ) ASC NULLS FIRST
     LIMIT 1",
    [$siteId, $now]
);

if (!$phone) {
    // Пул исчерпан — возвращаем fallback номер сайта
    echo json_encode([
        'status'   => 'fallback',
        'phone'    => $fallbackPhone,
    ]);
    R::close();
    exit;
}

// --- 4. Создаём новую сессию ---
$newCookie = bin2hex(random_bytes(16));
$expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlMinutes} minutes"));

$s = R::dispense('sessions');
$s->session_cookie  = $newCookie;
$s->site_id         = $siteId;
$s->client_id       = $clientId;
$s->ip              = $ip;
$s->phonepool_id    = (int)$phone['id'];
$s->phone           = $phone['phone'];
$s->utm_source      = $utmSource;
$s->utm_medium      = $utmMedium;
$s->utm_campaign    = $utmCampaign;
$s->utm_term        = $utmTerm;
$s->utm_content     = $utmContent;
$s->landing_page    = $landingPage;
$s->referrer        = $referrer;
$s->revealed_at     = $now;
$s->expires_at      = $expiresAt;
$s->created_at      = $now;
R::store($s);

setcookie($cookieName, $newCookie, time() + $ttlMinutes * 60, '/');

echo json_encode([
    'status' => 'assigned',
    'phone'  => $phone['phone'],
]);

R::close();
