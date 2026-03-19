<?php

require_once __DIR__ . '/rb.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/MetrikaSender.php';

define('LOG_FILE', __DIR__ . '/calls.txt');

// ── 1. Парсим raw query string ────────────────────────────────────
// Исправляем баг Novofon: пропущен & между communication_number и employee_full_name
$rawQuery = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';

$knownParams = [
    'notification_name', 'virtual_phone_number', 'notification_time', 'external_id',
    'contact_phone_number', 'communication_number', 'employee_full_name', 'employee_id',
    'call_source', 'call_session_id', 'direction', 'scenario_name',
    'talk_time_duration', 'total_time_duration', 'wait_time_duration',
];

foreach ($knownParams as $p) {
    $rawQuery = preg_replace('/(?<!&|^)(' . preg_quote($p, '/') . '=)/', '&$1', $rawQuery);
}

parse_str($rawQuery, $get);

$data = [];
foreach ($knownParams as $param) {
    $data[$param] = isset($get[$param]) && $get[$param] !== '' ? $get[$param] : null;
}

// ── 2. Определяем caller / called по direction ────────────────────
$direction = $data['direction'] ?? null;

if ($direction === 'out') {
    $callerNumber = $data['virtual_phone_number'];
    $calledNumber = $data['contact_phone_number'];
} elseif ($direction === 'in') {
    $callerNumber = $data['contact_phone_number'];
    $calledNumber = $data['virtual_phone_number'];
} else {
    $callerNumber = $data['contact_phone_number'] ?? null;
    $calledNumber = $data['virtual_phone_number'] ?? null;
}

$callTime = $data['notification_time'] ?? date('Y-m-d H:i:s');

// ── 3. Логируем сырые данные ──────────────────────────────────────
$ts       = '[' . date('Y-m-d H:i:s') . ']';
$summary  = ['call_time' => $callTime, 'caller' => $callerNumber, 'called' => $calledNumber, 'direction' => $direction];
$logEntry = $ts . ' SUMMARY: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$logEntry .= $ts . ' RAW:     ' . json_encode($data,    JSON_UNESCAPED_UNICODE) . PHP_EOL;
file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);

// ── 4. Матчинг: ищем сессию по номеру virtual_phone_number ───────
// virtual_phone_number — это подменный номер, который был показан посетителю
$virtualPhone = $data['virtual_phone_number'] ?? null;

if (!$virtualPhone) {
    http_response_code(200);
    echo 'OK (no phone)';
    exit;
}

// Нормализуем номер: убираем всё кроме цифр
$virtualPhoneClean = preg_replace('/\D/', '', $virtualPhone);

R::setup('sqlite:' . DB_PATH);
R::freeze(true);

// Миграция: добавляем sent_client_id если ещё нет
try { R::exec('ALTER TABLE calls ADD COLUMN sent_client_id TEXT'); } catch (Exception $e) {}

$now = date('Y-m-d H:i:s');

// Ищем активную сессию с этим подменным номером
// Берём самую свежую — та что ближе всего к моменту звонка
$session = R::getRow(
    "SELECT * FROM sessions
     WHERE phone LIKE ?
       AND expires_at > ?
     ORDER BY revealed_at DESC
     LIMIT 1",
    ['%' . $virtualPhoneClean . '%', $now]
);

// Если активная сессия не найдена — ищем последнюю истёкшую (звонок мог прийти чуть позже TTL)
if (!$session) {
    $session = R::getRow(
        "SELECT * FROM sessions
         WHERE phone LIKE ?
         ORDER BY revealed_at DESC
         LIMIT 1",
        ['%' . $virtualPhoneClean . '%']
    );
}

$sessionId = $session ? (int)$session['id'] : null;
$clientId  = $session ? $session['client_id'] : null;

// ── 5. Сохраняем звонок в БД ──────────────────────────────────────
$call = R::dispense('calls');
$call->session_id      = $sessionId;
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
$matchLog = $ts . ' MATCH:   session_id=' . ($sessionId ?? 'null')
          . ' client_id=' . ($clientId ?? 'null')
          . ' call_id=' . $callId . PHP_EOL;
file_put_contents(LOG_FILE, $matchLog, FILE_APPEND | LOCK_EX);

// ── 6. Отправляем офлайн конверсию в Метрику ─────────────────────
// Отправляем только если есть хотя бы один идентификатор
$hasIdentifier = !empty($clientId) || !empty($callerNumber);

if ($hasIdentifier && METRIKA_ACCESS_TOKEN && METRIKA_COUNTER_ID) {

    // Проверяем дубль по client_id
    $isDuplicate = false;

    if ($clientId) {
        $isDuplicate = (int)R::getCell(
            "SELECT COUNT(*) FROM calls c
             JOIN sessions s ON s.id = c.session_id
             WHERE s.client_id = ? AND c.goal_sent = 1 AND c.id != ?",
            [$clientId, $callId]
        ) > 0;
    }

    if ($isDuplicate) {
        R::exec('UPDATE calls SET goal_sent = 2 WHERE id = ?', [$callId]);
        $metrikaLog = $ts . ' METRIKA: duplicate client_id=' . $clientId . ' goal not sent' . PHP_EOL;
        file_put_contents(LOG_FILE, $metrikaLog, FILE_APPEND | LOCK_EX);
    } else {
        $metrika   = new MetrikaSender(METRIKA_ACCESS_TOKEN);
        $timestamp = strtotime($callTime) ?: time();

        $result = $metrika->send(
            METRIKA_COUNTER_ID,
            METRIKA_GOAL_ID,
            $timestamp,
            $clientId    ?: null,
            $callerNumber ?: null
        );

        if (!empty($result['success'])) {
            R::exec('UPDATE calls SET goal_sent = 1, sent_client_id = ? WHERE id = ?', [$clientId ?: null, $callId]);
        }

        $metrikaLog = $ts . ' METRIKA: success=' . ($result['success'] ? 'true' : 'false')
                    . ' http=' . ($result['http_code'] ?? '?')
                    . ' error=' . ($result['error'] ?? 'none')
                    . ' csv=[' . trim($result['csv'] ?? '') . ']'
                    . ' response=' . ($result['raw_response'] ?? 'none') . PHP_EOL;
        file_put_contents(LOG_FILE, $metrikaLog, FILE_APPEND | LOCK_EX);
    }
} else {
    $metrikaLog = $ts . ' METRIKA: skipped (no identifier or token not set)' . PHP_EOL;
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
