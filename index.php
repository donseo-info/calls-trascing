<?php

require_once __DIR__ . '/rb.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/MetrikaSender.php';
require_once __DIR__ . '/src/sites.php';

define('LOG_FILE', __DIR__ . '/logs/calls.txt');

// ── 1. Парсим raw query string ────────────────────────────────────
// Исправляем баг Novofon: пропущен & между communication_number и employee_full_name
$rawQuery = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';

$knownParams = [
    'notification_name', 'virtual_phone_number', 'notification_time', 'external_id',
    'contact_phone_number', 'communication_number', 'employee_full_name', 'employee_id',
    'call_source', 'call_session_id', 'direction', 'scenario_name',
    'talk_time_duration', 'total_time_duration', 'wait_time_duration',
    'contact_id', 'contact_full_name',
];

foreach ($knownParams as $p) {
    $rawQuery = preg_replace('/(?<!&|^)(' . preg_quote($p, '/') . '=)/', '&$1', $rawQuery);
}

parse_str($rawQuery, $get);

$data = [];
foreach ($knownParams as $param) {
    $data[$param] = isset($get[$param]) && $get[$param] !== '' ? $get[$param] : null;
}

// ── 2. Пустой вебхук (пинг/healthcheck без данных) — выходим без лога ─
// Novofon шлёт реальные звонки с virtual_phone_number. Пустые пинги
// (деплой-хук, мониторинг) не логируем, чтобы не засорять calls.txt.
$virtualPhone = $data['virtual_phone_number'] ?? null;
if (!$virtualPhone) {
    http_response_code(200);
    echo 'OK (no phone)';
    exit;
}

// ── 3. Определяем caller / called по direction ────────────────────
// Novofon в уведомлении «Завершение звонка» НЕ присылает direction.
// Для нашего сценария это входящий звонок на подменный номер:
// контакт (клиент) звонит → caller, наш virtual_phone_number → called.
$direction = $data['direction'] ?? null;

if ($direction === 'out') {
    $callerNumber = $data['virtual_phone_number'];
    $calledNumber = $data['contact_phone_number'];
} else {
    // 'in' или null (Novofon не прислал) → трактуем как входящий
    $callerNumber = $data['contact_phone_number'] ?? null;
    $calledNumber = $data['virtual_phone_number'] ?? null;
    if ($direction === null) $direction = 'in';
}

// Время звонка: убираем дробные секунды (.944) — strtotime их не любит
$callTime = $data['notification_time'] ?? date('Y-m-d H:i:s');
$callTime = preg_replace('/\.\d+$/', '', $callTime);

// ── 4. Логируем сырые данные ──────────────────────────────────────
$ts       = '[' . date('Y-m-d H:i:s') . ']';
$summary  = ['call_time' => $callTime, 'caller' => $callerNumber, 'called' => $calledNumber, 'direction' => $direction];
$logEntry = $ts . ' SUMMARY: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$logEntry .= $ts . ' RAW:     ' . json_encode($data,    JSON_UNESCAPED_UNICODE) . PHP_EOL;
file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);

// ── 5. Матчинг: ищем сессию по номеру virtual_phone_number ───────
// virtual_phone_number — это подменный номер, который был показан посетителю
// Нормализуем номер: убираем всё кроме цифр
$virtualPhoneClean = preg_replace('/\D/', '', $virtualPhone);

R::setup('sqlite:' . DB_PATH);
R::freeze(true);

// Миграция: добавляем sent_client_id / site_id если ещё нет
try { R::exec('ALTER TABLE calls ADD COLUMN sent_client_id TEXT'); } catch (Exception $e) {}
try { R::exec("ALTER TABLE sites ADD COLUMN timezone TEXT DEFAULT 'Europe/Moscow'"); } catch (Exception $e) {}
try { R::exec('ALTER TABLE calls ADD COLUMN site_id INTEGER'); } catch (Exception $e) {}

$now = date('Y-m-d H:i:s');

// ── Определяем сайт по подменному номеру (авторитетно) ───────────
// Номер уникален в пуле и принадлежит одному сайту. Это и есть
// механизм сопоставления вебхуков с разных аккаунтов Novofon:
// virtual_phone_number → phonepool.site_id → настройки сайта.
$siteId = R::getCell(
    "SELECT site_id FROM phonepool WHERE phone LIKE ? ORDER BY id DESC LIMIT 1",
    ['%' . $virtualPhoneClean . '%']
);
$siteId = $siteId ? (int)$siteId : null;

// Ищем сессию в рамках найденного сайта (если сайт известен).
// Самую свежую активную → ту что ближе к моменту звонка.
$sessionWhere  = $siteId ? 'site_id = ? AND phone LIKE ?' : 'phone LIKE ?';
$activeParams  = $siteId ? [$siteId, '%' . $virtualPhoneClean . '%', $now] : ['%' . $virtualPhoneClean . '%', $now];
$session = R::getRow(
    "SELECT * FROM sessions WHERE {$sessionWhere} AND expires_at > ? ORDER BY revealed_at DESC LIMIT 1",
    $activeParams
);

// Если активная не найдена — последняя истёкшая (звонок мог прийти чуть позже TTL)
if (!$session) {
    $expiredParams = $siteId ? [$siteId, '%' . $virtualPhoneClean . '%'] : ['%' . $virtualPhoneClean . '%'];
    $session = R::getRow(
        "SELECT * FROM sessions WHERE {$sessionWhere} ORDER BY revealed_at DESC LIMIT 1",
        $expiredParams
    );
}

$sessionId = $session ? (int)$session['id'] : null;
$clientId  = $session ? $session['client_id'] : null;

// Если сайт не нашли по пулу — fallback на site_id сессии
if (!$siteId && $session && !empty($session['site_id'])) {
    $siteId = (int)$session['site_id'];
}
$site = $siteId ? site_by_id($siteId) : null;

// Извлекаем yclid из landing_page если нет client_id
$yclid = null;
if (!$clientId && !empty($session['landing_page'])) {
    parse_str(parse_url($session['landing_page'], PHP_URL_QUERY) ?? '', $lpParams);
    $yclid = !empty($lpParams['yclid']) ? $lpParams['yclid'] : null;
}

// ── 5. Сохраняем звонок в БД ──────────────────────────────────────
$call = R::dispense('calls');
$call->session_id      = $sessionId;
$call->site_id         = $siteId;
$call->caller          = $callerNumber;
$call->called          = $calledNumber;
$call->direction       = $direction;
$call->call_time       = $callTime;
$call->talk_duration   = isset($data['talk_time_duration'])  ? (int)$data['talk_time_duration']  : null;
$call->total_duration  = isset($data['total_time_duration']) ? (int)$data['total_time_duration'] : null;
$call->wait_duration   = isset($data['wait_time_duration'])  ? (int)$data['wait_time_duration']  : null;
$call->call_session_id = $data['call_session_id']  ?? null;
$call->employee_name   = $data['employee_full_name'] ?? null;
$call->scenario_name   = $data['scenario_name']     ?? null;
$call->goal_sent       = 0;
$call->raw_data        = json_encode($data, JSON_UNESCAPED_UNICODE);
$call->created_at      = $now;
$callId = R::store($call);

// Логируем результат матчинга
$matchLog = $ts . ' MATCH:   site_id=' . ($siteId ?? 'null')
          . ' session_id=' . ($sessionId ?? 'null')
          . ' client_id=' . ($clientId ?? 'null')
          . ' yclid=' . ($yclid ?? 'null')
          . ' call_id=' . $callId . PHP_EOL;
file_put_contents(LOG_FILE, $matchLog, FILE_APPEND | LOCK_EX);

// ── 6. Отправляем офлайн конверсию в Метрику (конфиг сайта) ──────
// Настройки Метрики берём из сайта; нужен идентификатор + токен + счётчик
$mToken   = $site['metrika_access_token'] ?? null;
$mCounter = $site['metrika_counter_id']   ?? null;
$mGoal    = $site['metrika_goal_id']      ?? 'send_lead';

$hasIdentifier = !empty($clientId) || !empty($yclid) || !empty($callerNumber);

if ($hasIdentifier && $mToken && $mCounter) {

    // Проверяем дубль по client_id в рамках этого сайта
    $isDuplicate = false;

    if ($clientId) {
        $isDuplicate = (int)R::getCell(
            "SELECT COUNT(*) FROM calls c
             JOIN sessions s ON s.id = c.session_id
             WHERE s.client_id = ? AND s.site_id = ? AND c.goal_sent = 1 AND c.id != ?",
            [$clientId, $siteId, $callId]
        ) > 0;
    }

    if ($isDuplicate) {
        R::exec('UPDATE calls SET goal_sent = 2 WHERE id = ?', [$callId]);
        $metrikaLog = $ts . ' METRIKA: duplicate client_id=' . $clientId . ' goal not sent' . PHP_EOL;
        file_put_contents(LOG_FILE, $metrikaLog, FILE_APPEND | LOCK_EX);
    } else {
        $metrika   = new MetrikaSender($mToken);
        // Novofon шлёт notification_time в таймзоне аккаунта (Питер UTC+3,
        // ЕКБ UTC+5 и т.д.) — задаётся на каждый сайт. Парсим явно в TZ сайта,
        // иначе при иной TZ сервера strtotime даёт неверный (часто будущий)
        // инстант → Метрика отвечает 400.
        $siteTz = !empty($site['timezone']) ? $site['timezone'] : 'Europe/Moscow';
        try {
            $dt = new DateTime($callTime, new DateTimeZone($siteTz));
            $timestamp = $dt->getTimestamp();
        } catch (Exception $e) {
            $timestamp = strtotime($callTime) ?: time();
        }
        // Подстраховка от рассинхрона часов: будущее время Метрика отвергает
        if ($timestamp > time()) $timestamp = time();

        $result = $metrika->send(
            $mCounter,
            $mGoal,
            $timestamp,
            $clientId     ?: null,
            $yclid        ?: null,
            $callerNumber ?: null
        );

        if (!empty($result['success'])) {
            R::exec('UPDATE calls SET goal_sent = 1, sent_client_id = ? WHERE id = ?', [$clientId ?: null, $callId]);
        }

        // CSV содержит переносы строк — схлопываем в один пробел,
        // чтобы вся запись (включая response) осталась одной строкой лога
        $csvInline = str_replace(["\r\n", "\n", "\r"], ' | ', trim($result['csv'] ?? ''));
        $metrikaLog = $ts . ' METRIKA: success=' . ($result['success'] ? 'true' : 'false')
                    . ' http=' . ($result['http_code'] ?? '?')
                    . ' error=' . ($result['error'] ?? 'none')
                    . ' csv=[' . $csvInline . ']'
                    . ' response=' . ($result['raw_response'] ?? 'none') . PHP_EOL;
        file_put_contents(LOG_FILE, $metrikaLog, FILE_APPEND | LOCK_EX);
    }
} else {
    $metrikaLog = $ts . ' METRIKA: skipped (no identifier/site/token)' . PHP_EOL;
    file_put_contents(LOG_FILE, $metrikaLog, FILE_APPEND | LOCK_EX);
}

// ── 7. Освобождаем номер сразу после звонка ──────────────────────
// Звонок зафиксирован — номер можно вернуть в пул немедленно,
// не дожидаясь истечения TTL. phonepool_id = NULL — слот свободен.
// phone оставляем для истории и матчинга запоздавших вебхуков.
if ($sessionId && !empty($session['phonepool_id'])) {
    R::exec(
        'UPDATE sessions SET phonepool_id = NULL, expires_at = ? WHERE id = ?',
        [$now, $sessionId]
    );
    $freeLog = $ts . ' FREED:   session_id=' . $sessionId
             . ' phone=' . ($session['phone'] ?? '?')
             . ' returned to pool after call' . PHP_EOL;
    file_put_contents(LOG_FILE, $freeLog, FILE_APPEND | LOCK_EX);
}

R::close();

http_response_code(200);
echo 'OK';
