<?php
session_start();
require_once dirname(__DIR__) . '/rb.php';
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/MetrikaSender.php';
require_once dirname(__DIR__) . '/src/sites.php';

R::setup('sqlite:' . DB_PATH);
R::freeze(true);

// ── Миграции (на случай если migrate_multisite.php ещё не запускали) ──
try { R::exec('ALTER TABLE calls ADD COLUMN sent_client_id TEXT'); } catch (Exception $e) {}
R::exec("CREATE TABLE IF NOT EXISTS sites (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    name                  TEXT NOT NULL,
    domain                TEXT,
    site_key              TEXT NOT NULL UNIQUE,
    metrika_counter_id    TEXT,
    metrika_access_token  TEXT,
    metrika_goal_id       TEXT,
    fallback_phone        TEXT,
    session_ttl_minutes   INTEGER NOT NULL DEFAULT 10,
    is_active             INTEGER NOT NULL DEFAULT 1,
    created_at            TEXT NOT NULL DEFAULT (datetime('now'))
)");
foreach (['phonepool', 'sessions', 'calls'] as $t) {
    try { R::exec("ALTER TABLE {$t} ADD COLUMN site_id INTEGER"); } catch (Exception $e) {}
}

// ── Авторизация ───────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ?'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (($_POST['password'] ?? '') === ADMIN_PASSWORD) {
        $_SESSION['ct_auth'] = true;
        header('Location: ?'); exit;
    }
    $loginError = 'Неверный пароль';
}

if (empty($_SESSION['ct_auth'])) {
    R::close();
    ?>
    <!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход · Call Tracking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body style="background:#f3f7fb;font-family:'Segoe UI',system-ui,sans-serif;">
      <div class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
        <form method="post" class="p-4 bg-white rounded shadow-sm" style="width:320px;border:1px solid #dbeafe;">
          <h5 class="mb-3" style="color:#1d4ed8;font-weight:700;">Call Tracking</h5>
          <?php if (!empty($loginError)): ?>
            <div class="alert alert-danger py-2" style="font-size:13px;"><?= esc($loginError) ?></div>
          <?php endif ?>
          <input type="hidden" name="action" value="login">
          <input type="password" name="password" class="form-control mb-3" placeholder="Пароль" autofocus required>
          <button class="btn btn-primary w-100">Войти</button>
        </form>
      </div>
    </body></html>
    <?php
    exit;
}

// ── Текущий сайт ──────────────────────────────────────────────────
$sites = sites_all();
if (isset($_GET['site_id'])) {
    $_SESSION['ct_site'] = (int)$_GET['site_id'];
}
$currentSiteId = (int)($_SESSION['ct_site'] ?? ($sites[0]['id'] ?? 0));
// Валидируем что сайт существует
$siteIds = array_map(fn($s) => (int)$s['id'], $sites);
if ($sites && !in_array($currentSiteId, $siteIds, true)) {
    $currentSiteId = (int)$sites[0]['id'];
    $_SESSION['ct_site'] = $currentSiteId;
}
$currentSite = $currentSiteId ? site_by_id($currentSiteId) : null;

// ── AJAX-обработчики ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'login') {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'toggle_phone') {
        $id    = (int)($_POST['id'] ?? 0);
        $phone = R::load('phonepool', $id);
        if ($phone->id) {
            $phone->is_active = $phone->is_active ? 0 : 1;
            R::store($phone);
            echo json_encode(['success' => true, 'is_active' => (int)$phone->is_active]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Not found']);
        }
        R::close(); exit;
    }

    if ($action === 'add_phone') {
        $raw    = trim($_POST['phone'] ?? '');
        $siteId = (int)($_POST['site_id'] ?? $currentSiteId);
        $phone  = preg_replace('/\D/', '', $raw);
        if (!$siteId) {
            echo json_encode(['success' => false, 'error' => 'Сначала создайте сайт']);
        } elseif (strlen($phone) >= 10) {
            $exists = R::findOne('phonepool', 'phone = ?', [$phone]);
            if (!$exists) {
                $p             = R::dispense('phonepool');
                $p->phone      = $phone;
                $p->site_id    = $siteId;
                $p->is_active  = 1;
                $p->created_at = date('Y-m-d H:i:s');
                R::store($p);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Номер уже есть в пуле']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Неверный формат номера']);
        }
        R::close(); exit;
    }

    if ($action === 'delete_phone') {
        $id    = (int)($_POST['id'] ?? 0);
        $phone = R::load('phonepool', $id);
        if ($phone->id) {
            R::trash($phone);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        R::close(); exit;
    }

    if ($action === 'save_site') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        if ($name === '') {
            echo json_encode(['success' => false, 'error' => 'Укажите название']);
            R::close(); exit;
        }
        $site = $id ? R::load('sites', $id) : R::dispense('sites');
        if (!$id || $site->id) {
            $site->name                 = $name;
            $site->domain               = trim($_POST['domain'] ?? '');
            $site->metrika_counter_id   = trim($_POST['metrika_counter_id'] ?? '');
            $site->metrika_access_token = trim($_POST['metrika_access_token'] ?? '');
            $site->metrika_goal_id      = trim($_POST['metrika_goal_id'] ?? '') ?: 'send_lead';
            $site->fallback_phone       = trim($_POST['fallback_phone'] ?? '');
            $site->session_ttl_minutes  = (int)($_POST['session_ttl_minutes'] ?? 10) ?: 10;
            $site->is_active            = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            if (!$id) {
                $site->site_key   = bin2hex(random_bytes(8));
                $site->created_at = date('Y-m-d H:i:s');
            }
            $newId = R::store($site);
            echo json_encode(['success' => true, 'id' => (int)$newId, 'site_key' => $site->site_key]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Сайт не найден']);
        }
        R::close(); exit;
    }

    if ($action === 'delete_site') {
        $id   = (int)($_POST['id'] ?? 0);
        $site = R::load('sites', $id);
        if ($site->id) {
            // Чистим данные сайта
            R::exec('DELETE FROM calls WHERE site_id = ?', [$id]);
            R::exec('DELETE FROM sessions WHERE site_id = ?', [$id]);
            R::exec('DELETE FROM phonepool WHERE site_id = ?', [$id]);
            R::trash($site);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        R::close(); exit;
    }

    if ($action === 'resend_metrika') {
        $callId    = (int)($_POST['call_id']  ?? 0);
        $clientId  = trim($_POST['client_id'] ?? '') ?: null;
        $yclid     = trim($_POST['yclid']     ?? '') ?: null;
        $phone     = trim($_POST['phone']     ?? '') ?: null;
        $datetime  = trim($_POST['datetime']  ?? '');
        $timestamp = $datetime ? strtotime($datetime) : time();

        if (!$callId) {
            echo json_encode(['success' => false, 'error' => 'no call_id']);
            R::close(); exit;
        }
        if (!$clientId && !$yclid && !$phone) {
            echo json_encode(['success' => false, 'error' => 'Нужен хотя бы один идентификатор']);
            R::close(); exit;
        }

        // Настройки Метрики берём из сайта звонка
        $callSiteId = (int)R::getCell('SELECT site_id FROM calls WHERE id = ?', [$callId]);
        $callSite   = $callSiteId ? site_by_id($callSiteId) : $currentSite;
        $token   = $callSite['metrika_access_token'] ?? '';
        $counter = $callSite['metrika_counter_id']   ?? '';
        $goal    = trim($_POST['goal'] ?? '') ?: ($callSite['metrika_goal_id'] ?? 'send_lead');

        if (!$token || !$counter) {
            echo json_encode(['success' => false, 'error' => 'У сайта не задан токен/счётчик Метрики']);
            R::close(); exit;
        }

        $metrika = new MetrikaSender($token);
        $result  = $metrika->send($counter, $goal, $timestamp, $clientId, $yclid, $phone);

        if (!empty($result['success'])) {
            R::exec('UPDATE calls SET goal_sent = 1, sent_client_id = ? WHERE id = ?', [$clientId, $callId]);
        }

        echo json_encode([
            'success'   => !empty($result['success']),
            'http_code' => $result['http_code']    ?? null,
            'error'     => $result['error']         ?? null,
            'csv'       => $result['csv']            ?? null,
            'response'  => $result['raw_response']  ?? null,
        ]);
        R::close(); exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    R::close(); exit;
}

// ── Дашборд: статистика (scope по текущему сайту) ─────────────────
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');
$sid   = $currentSiteId;

$callsToday   = (int)R::getCell('SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND site_id = ?', [$today, $sid]);
$matchedToday = (int)R::getCell('SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND site_id = ? AND session_id IS NOT NULL', [$today, $sid]);
$goalsSent      = (int)R::getCell('SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND site_id = ? AND goal_sent = 1', [$today, $sid]);
$goalsDuplicate = (int)R::getCell('SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND site_id = ? AND goal_sent = 2', [$today, $sid]);
$totalPhones  = (int)R::getCell('SELECT COUNT(*) FROM phonepool WHERE is_active = 1 AND site_id = ?', [$sid]);
$busyPhones   = (int)R::getCell(
    'SELECT COUNT(DISTINCT phonepool_id) FROM sessions WHERE site_id = ? AND phonepool_id IS NOT NULL AND expires_at > ?',
    [$sid, $now]
);
$freePhones     = $totalPhones - $busyPhones;
$requestsToday  = (int)R::getCell('SELECT COUNT(*) FROM sessions WHERE DATE(created_at) = ? AND site_id = ?', [$today, $sid]);
$convRate       = $requestsToday > 0 ? round($callsToday / $requestsToday * 100) : 0;

// Звонки за 7 дней для мини-графика
$weekStats = R::getAll(
    "SELECT DATE(created_at) as day, COUNT(*) as cnt
     FROM calls
     WHERE site_id = ? AND DATE(created_at) >= DATE('now', '-6 days')
     GROUP BY day ORDER BY day ASC",
    [$sid]
);

// ── Вкладка «Звонки»: фильтры + пагинация ────────────────────────
$tab       = $_GET['tab']       ?? 'dashboard';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;
$offset    = ($page - 1) * $perPage;
$search    = trim($_GET['search']    ?? '');
$dirFilter = trim($_GET['direction'] ?? '');
$dateFilter= trim($_GET['date']      ?? '');

$where  = ['c.site_id = ?'];
$params = [$sid];

if ($search !== '') {
    $where[]  = '(c.caller LIKE ? OR c.called LIKE ? OR s.client_id LIKE ? OR s.utm_source LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($dirFilter && in_array($dirFilter, ['in', 'out'], true)) {
    $where[]  = 'c.direction = ?';
    $params[] = $dirFilter;
}
if ($dateFilter) {
    $where[]  = 'DATE(c.created_at) = ?';
    $params[] = $dateFilter;
}

$whereSql   = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$totalRows  = (int)R::getCell(
    "SELECT COUNT(*) FROM calls c LEFT JOIN sessions s ON s.id = c.session_id $whereSql",
    $params
);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$calls = R::getAll(
    "SELECT c.id, c.caller, c.called, c.direction, c.call_time, c.talk_duration,
            c.goal_sent, c.created_at, c.session_id, c.sent_client_id,
            s.client_id, s.utm_source, s.utm_medium, s.utm_campaign, s.utm_term, s.utm_content,
            s.phone as session_phone, s.landing_page
     FROM calls c
     LEFT JOIN sessions s ON s.id = c.session_id
     $whereSql
     ORDER BY c.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// ── Пул номеров ───────────────────────────────────────────────────
$phones = R::getAll(
    "SELECT pp.*,
            (SELECT MAX(s.revealed_at) FROM sessions s WHERE s.phonepool_id = pp.id) as last_used,
            CASE WHEN EXISTS(
                SELECT 1 FROM sessions s
                WHERE s.phonepool_id = pp.id AND s.expires_at > ?
            ) THEN 1 ELSE 0 END as is_busy
     FROM phonepool pp
     WHERE pp.site_id = ?
     ORDER BY pp.id ASC",
    [$now, $sid]
);

// Последние 6 звонков для дашборда
$lastCalls = R::getAll(
    'SELECT caller, called, direction, call_time, goal_sent FROM calls WHERE site_id = ? ORDER BY created_at DESC LIMIT 6',
    [$sid]
);

// ── Вкладка «Запросы» ─────────────────────────────────────────────
$sessionsAll   = [];
$totalSessions = 0;
$totalReqPages = 1;
if ($tab === 'requests') {
    $totalSessions = (int)R::getCell('SELECT COUNT(*) FROM sessions WHERE site_id = ?', [$sid]);
    $totalReqPages = max(1, (int)ceil($totalSessions / $perPage));
    $sessionsAll = R::getAll(
        "SELECT s.id, s.created_at, s.client_id, s.phone,
                s.utm_source, s.utm_medium, s.landing_page, s.ip,
                (SELECT COUNT(*) FROM calls c WHERE c.session_id = s.id) as call_count
         FROM sessions s
         WHERE s.site_id = ?
         ORDER BY s.created_at DESC
         LIMIT ? OFFSET ?",
        [$sid, $perPage, $offset]
    );
}

// ── Вкладка «Метрика» ─────────────────────────────────────────────
$goalsFailedToday    = (int)R::getCell(
    'SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND site_id = ? AND goal_sent = 0 AND session_id IS NOT NULL', [$today, $sid]
);
$goalsNoSessionToday = (int)R::getCell(
    'SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND site_id = ? AND goal_sent = 0 AND session_id IS NULL', [$today, $sid]
);
$goalsDuplicateToday = (int)R::getCell(
    'SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND site_id = ? AND goal_sent = 2', [$today, $sid]
);
$failedCalls = R::getAll(
    "SELECT c.id, c.caller, c.call_time, c.created_at,
            s.client_id, s.utm_source, s.utm_medium, s.utm_campaign, s.utm_term, s.utm_content,
            s.landing_page
     FROM calls c
     LEFT JOIN sessions s ON s.id = c.session_id
     WHERE c.site_id = ? AND c.goal_sent = 0 AND c.session_id IS NOT NULL
     ORDER BY c.created_at DESC
     LIMIT 50",
    [$sid]
);

R::close();

// Последние 10 отправленных CSV файлов
$lastCsvFiles = [];
$csvDir = dirname(__DIR__) . '/logs/csv_files';
if (is_dir($csvDir)) {
    $csvGlob = glob($csvDir . '/conv_*.csv');
    if ($csvGlob) {
        usort($csvGlob, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($csvGlob, 0, 10) as $f) {
            $lastCsvFiles[] = [
                'name'    => basename($f),
                'time'    => filemtime($f),
                'content' => trim(file_get_contents($f)),
            ];
        }
    }
}

// Читаем лог — последние строки с METRIKA
$metrikaLog = [];
$logFile = dirname(__DIR__) . '/logs/calls.txt';
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_reverse($lines) as $line) {
        if (str_contains($line, 'METRIKA:')) {
            $metrikaLog[] = $line;
            if (count($metrikaLog) >= 100) break;
        }
    }
}

// ── Хелперы ───────────────────────────────────────────────────────
function fmtPhone($raw) {
    $d = preg_replace('/\D/', '', $raw ?? '');
    if (strlen($d) === 11 && $d[0] === '7') {
        return '+7 (' . substr($d,1,3) . ') ' . substr($d,4,3) . '-' . substr($d,7,2) . '-' . substr($d,9,2);
    }
    return $raw ?? '—';
}
function fmtDur($sec) {
    if ($sec === null || $sec === '') return '—';
    $s = (int)$sec;
    return $s >= 60 ? floor($s/60) . 'м ' . ($s%60) . 'с' : $s . 'с';
}
function activeTab($name, $current) {
    return $name === $current ? 'active' : '';
}
function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function buildUrl($extra = []) {
    $params = array_merge($_GET, $extra);
    unset($params['page']);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Call Tracking · Админка</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
  html, body { height: 100%; }
  body {
    background: #f3f7fb;
    color: #0f172a;
    font-size: 13px;
    font-family: 'Segoe UI', system-ui, sans-serif;
  }

  /* ── Шапка ── */
  .ct-header {
    background: #fff;
    border-bottom: 1px solid #dbeafe;
    padding: 0 24px;
    height: 52px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 1px 4px rgba(15,23,42,.05);
  }
  .ct-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: 14px;
    color: #1d4ed8;
    letter-spacing: -.3px;
  }
  .ct-logo .dot {
    width: 28px; height: 28px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 14px;
  }
  .ct-now { color: #94a3b8; font-size: 11px; }

  /* ── Навигация ── */
  .ct-nav {
    background: #fff;
    border-bottom: 1px solid #dbeafe;
    padding: 0 24px;
  }
  .ct-nav .nav-link {
    color: #64748b;
    font-size: 13px;
    font-weight: 500;
    padding: 10px 14px;
    border-bottom: 2px solid transparent;
    border-radius: 0;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: color .15s, border-color .15s;
  }
  .ct-nav .nav-link:hover { color: #1d4ed8; }
  .ct-nav .nav-link.active {
    color: #1d4ed8;
    border-bottom-color: #1d4ed8;
    background: transparent;
  }

  /* ── Карточки дашборда ── */
  .stat-card {
    background: #fff;
    border: 1px solid #dbeafe;
    border-radius: 12px;
    padding: 20px 22px;
    box-shadow: 0 4px 12px rgba(15,23,42,.06);
    position: relative;
    overflow: hidden;
    transition: box-shadow .2s;
  }
  .stat-card:hover { box-shadow: 0 6px 20px rgba(15,23,42,.1); }
  .stat-card .stat-icon {
    position: absolute; top: 16px; right: 18px;
    width: 36px; height: 36px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
  }
  .stat-card .stat-val {
    font-size: 32px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1;
    margin-bottom: 4px;
  }
  .stat-card .stat-label {
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 500;
  }
  .stat-card .stat-sub {
    font-size: 11px; color: #94a3b8; margin-top: 6px;
  }
  .ic-blue   { background: #eff6ff; color: #3b82f6; }
  .ic-indigo { background: #eef2ff; color: #6366f1; }
  .ic-green  { background: #f0fdf4; color: #22c55e; }
  .ic-amber  { background: #fffbeb; color: #f59e0b; }

  /* ── Мини-бар-чарт ── */
  .week-chart { display: flex; align-items: flex-end; gap: 4px; height: 48px; }
  .week-bar {
    flex: 1;
    background: #dbeafe;
    border-radius: 3px 3px 0 0;
    min-height: 4px;
    transition: background .2s;
    cursor: default;
  }
  .week-bar:hover { background: #3b82f6; }
  .week-label { font-size: 9px; color: #94a3b8; text-align: center; margin-top: 3px; }

  /* ── Карточки-обёртки ── */
  .ct-card {
    background: #fff;
    border: 1px solid #dbeafe;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(15,23,42,.06);
    overflow: hidden;
  }
  .ct-card-header {
    background: #e0f2fe;
    border-bottom: 1px solid #bfdbfe;
    padding: 10px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }
  .ct-card-header .ct-title {
    font-weight: 600;
    font-size: 13px;
    color: #1e3a8a;
    display: flex; align-items: center; gap: 7px;
  }

  /* ── Фильтры ── */
  .filter-bar .form-control,
  .filter-bar .form-select {
    font-size: 12px;
    border-color: #bfdbfe;
    background: #fff;
    color: #0f172a;
    height: 32px;
    padding: 0 10px;
  }
  .filter-bar .form-control:focus,
  .filter-bar .form-select:focus {
    border-color: #38bdf8;
    box-shadow: 0 0 0 2px rgba(56,189,248,.2);
  }
  .filter-bar .btn-sm {
    height: 32px;
    font-size: 12px;
    padding: 0 12px;
  }

  /* ── Таблица ── */
  .ct-table { font-size: 12px; margin: 0; }
  .ct-table thead th {
    background: #eff6ff;
    color: #64748b;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .05em;
    border-bottom: 1px solid #dbeafe;
    padding: 8px 12px;
    white-space: nowrap;
  }
  .ct-table tbody td {
    padding: 9px 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    color: #334155;
  }
  .ct-table tbody tr:last-child td { border-bottom: none; }
  .ct-table tbody tr:hover td { background: #f8fbff; }

  /* ── Бейджи ── */
  .badge-in  { background: #dbeafe; color: #1d4ed8; font-weight: 600; font-size: 10px; }
  .badge-out { background: #dcfce7; color: #15803d; font-weight: 600; font-size: 10px; }
  .badge-free{ background: #dcfce7; color: #15803d; font-weight: 600; font-size: 10px; }
  .badge-busy{ background: #fee2e2; color: #b91c1c; font-weight: 600; font-size: 10px; }
  .badge-on  { background: #dbeafe; color: #1d4ed8; font-weight: 600; font-size: 10px; }
  .badge-off { background: #f1f5f9; color: #94a3b8; font-weight: 600; font-size: 10px; }

  .goal-yes { color: #22c55e; font-size: 14px; }
  .goal-no  { color: #cbd5e1; font-size: 14px; }
  .goal-dup { color: #f59e0b; font-size: 14px; }

  .cid-tag {
    font-family: monospace;
    font-size: 11px;
    background: #eff6ff;
    color: #1d4ed8;
    padding: 1px 6px;
    border-radius: 4px;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: inline-block;
  }
  .utm-tag {
    font-size: 11px;
    background: #f0fdf4;
    color: #15803d;
    padding: 1px 6px;
    border-radius: 4px;
    display: inline-block;
  }

  /* ── Пагинация ── */
  .ct-pagination .page-link {
    font-size: 12px;
    padding: 4px 10px;
    color: #1d4ed8;
    border-color: #dbeafe;
    background: #fff;
  }
  .ct-pagination .page-item.active .page-link {
    background: #1d4ed8;
    border-color: #1d4ed8;
  }
  .ct-pagination .page-item.disabled .page-link { color: #cbd5e1; }

  /* ── Переключатели номеров ── */
  .form-check-input:checked { background-color: #1d4ed8; border-color: #1d4ed8; }
  .add-phone-form .form-control {
    font-size: 13px;
    border-color: #bfdbfe;
  }
  .add-phone-form .form-control:focus {
    border-color: #38bdf8;
    box-shadow: 0 0 0 2px rgba(56,189,248,.2);
  }

  .phone-num {
    font-family: monospace;
    font-size: 12px;
    font-weight: 600;
    color: #1e3a8a;
    letter-spacing: .03em;
  }

  .empty-state {
    text-align: center;
    padding: 48px 24px;
    color: #94a3b8;
  }
  .empty-state i { font-size: 32px; display: block; margin-bottom: 8px; opacity: .4; }

  .toast-ct {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    min-width: 220px;
  }

  .btn-resend {
    background: none;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    color: #3b82f6;
    padding: 2px 6px;
    font-size: 12px;
    cursor: pointer;
    line-height: 1.4;
    transition: background .15s, color .15s;
  }
  .btn-resend:hover { background: #eff6ff; color: #1d4ed8; border-color: #93c5fd; }
</style>
</head>
<body>

<!-- Шапка -->
<header class="ct-header">
  <div class="ct-logo">
    <div class="dot"><i class="bi bi-telephone-fill"></i></div>
    Call Tracking
  </div>
  <div class="d-flex align-items-center gap-3">
    <?php if ($sites): ?>
    <form method="get" class="d-flex align-items-center gap-1" id="site-switch-form">
      <input type="hidden" name="tab" value="<?= esc($_GET['tab'] ?? 'dashboard') ?>">
      <i class="bi bi-globe2" style="color:#94a3b8;"></i>
      <select name="site_id" class="form-select form-select-sm" style="font-size:12px;border-color:#bfdbfe;width:auto;"
              onchange="document.getElementById('site-switch-form').submit()">
        <?php foreach ($sites as $st): ?>
          <option value="<?= (int)$st['id'] ?>" <?= (int)$st['id'] === $currentSiteId ? 'selected' : '' ?>>
            <?= esc($st['name']) ?><?= $st['is_active'] ? '' : ' (выкл)' ?>
          </option>
        <?php endforeach ?>
      </select>
    </form>
    <?php endif ?>
    <div class="ct-now" id="ct-clock"></div>
    <a href="?logout=1" class="btn btn-sm btn-outline-secondary" style="font-size:11px;padding:2px 8px;" title="Выйти">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</header>

<!-- Навигация -->
<nav class="ct-nav">
  <ul class="nav" id="mainTabs">
    <li class="nav-item">
      <a class="nav-link <?= activeTab('dashboard', $tab) ?>" href="?tab=dashboard">
        <i class="bi bi-speedometer2"></i> Дашборд
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= activeTab('calls', $tab) ?>" href="?tab=calls">
        <i class="bi bi-telephone-inbound"></i> Звонки
        <?php if ($callsToday > 0): ?>
          <span class="badge rounded-pill ms-1" style="background:#dbeafe;color:#1d4ed8;font-size:10px;"><?= $callsToday ?></span>
        <?php endif ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= activeTab('pool', $tab) ?>" href="?tab=pool">
        <i class="bi bi-sim-fill"></i> Пул номеров
        <span class="badge rounded-pill ms-1" style="background:<?= $freePhones > 0 ? '#dcfce7;color:#15803d' : '#fee2e2;color:#b91c1c' ?>;font-size:10px;"><?= $freePhones ?>/<?= $totalPhones ?></span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= activeTab('requests', $tab) ?>" href="?tab=requests">
        <i class="bi bi-cursor-fill"></i> Запросы
        <?php if ($requestsToday > 0): ?>
          <span class="badge rounded-pill ms-1" style="background:#eef2ff;color:#6366f1;font-size:10px;"><?= $requestsToday ?></span>
        <?php endif ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= activeTab('metrika', $tab) ?>" href="?tab=metrika">
        <i class="bi bi-graph-up-arrow"></i> Метрика
        <?php if ($goalsFailedToday > 0): ?>
          <span class="badge rounded-pill ms-1" style="background:#fee2e2;color:#b91c1c;font-size:10px;"><?= $goalsFailedToday ?></span>
        <?php endif ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= activeTab('sites', $tab) ?>" href="?tab=sites">
        <i class="bi bi-globe2"></i> Сайты
        <span class="badge rounded-pill ms-1" style="background:#eef2ff;color:#6366f1;font-size:10px;"><?= count($sites) ?></span>
      </a>
    </li>
  </ul>
</nav>

<div class="container-fluid px-4 py-4" style="max-width:1400px;">

<!-- ══ Дашборд ══════════════════════════════════════════════════ -->
<?php if ($tab === 'dashboard'): ?>

  <!-- Стат-карточки -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-xl">
      <div class="stat-card">
        <div class="stat-icon ic-indigo"><i class="bi bi-cursor-fill"></i></div>
        <div class="stat-val"><?= $requestsToday ?></div>
        <div class="stat-label">Запросов номера</div>
        <div class="stat-sub">кликов «Показать» сегодня</div>
      </div>
    </div>
    <div class="col-6 col-xl">
      <div class="stat-card">
        <div class="stat-icon ic-blue"><i class="bi bi-telephone"></i></div>
        <div class="stat-val"><?= $callsToday ?></div>
        <div class="stat-label">Звонков сегодня</div>
        <div class="stat-sub">за <?= date('d.m.Y') ?></div>
      </div>
    </div>
    <div class="col-6 col-xl">
      <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#16a34a"><i class="bi bi-percent"></i></div>
        <div class="stat-val" style="<?= $convRate >= 20 ? 'color:#16a34a' : ($convRate > 0 ? '' : 'color:#94a3b8') ?>"><?= $convRate ?>%</div>
        <div class="stat-label">Конверсия</div>
        <div class="stat-sub">запросов → звонков</div>
      </div>
    </div>
    <div class="col-6 col-xl">
      <div class="stat-card">
        <div class="stat-icon ic-green"><i class="bi bi-graph-up-arrow"></i></div>
        <div class="stat-val"><?= $goalsSent ?></div>
        <div class="stat-label">Целей в Метрику</div>
        <div class="stat-sub">
          <?= $callsToday > 0 ? round($goalsSent/$callsToday*100) . '% от звонков' : '—' ?>
        </div>
      </div>
    </div>
    <div class="col-6 col-xl">
      <div class="stat-card">
        <div class="stat-icon ic-amber"><i class="bi bi-sim"></i></div>
        <div class="stat-val"><?= $freePhones ?><span style="font-size:16px;color:#94a3b8;font-weight:400;">/<?= $totalPhones ?></span></div>
        <div class="stat-label">Свободно в пуле</div>
        <div class="stat-sub"><?= $busyPhones ?> занято сейчас</div>
      </div>
    </div>
  </div>

  <!-- Активность за неделю -->
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="ct-card">
        <div class="ct-card-header">
          <div class="ct-title"><i class="bi bi-bar-chart-line"></i> Звонки за 7 дней</div>
        </div>
        <div class="p-4">
          <?php
          // Строим массив на 7 дней с нулями
          $days = [];
          for ($i = 6; $i >= 0; $i--) {
              $d = date('Y-m-d', strtotime("-$i days"));
              $days[$d] = 0;
          }
          foreach ($weekStats as $ws) { $days[$ws['day']] = (int)$ws['cnt']; }
          $maxVal = max(array_values($days)) ?: 1;
          ?>
          <div class="week-chart">
            <?php foreach ($days as $d => $cnt): ?>
              <div style="flex:1;display:flex;flex-direction:column;align-items:center;">
                <div class="week-bar" style="height:<?= round(($cnt/$maxVal)*44) ?>px"
                     title="<?= $d ?>: <?= $cnt ?> зв."></div>
                <div class="week-label"><?= date('d.m', strtotime($d)) ?></div>
              </div>
            <?php endforeach ?>
          </div>
          <div class="d-flex justify-content-between mt-3" style="font-size:11px;color:#94a3b8;">
            <span>Всего за неделю: <strong style="color:#1d4ed8"><?= array_sum($days) ?></strong></span>
            <span>Пик: <strong style="color:#1d4ed8"><?= max(array_values($days)) ?></strong></span>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="ct-card">
        <div class="ct-card-header">
          <div class="ct-title"><i class="bi bi-clock-history"></i> Последние звонки</div>
          <a href="?tab=calls" class="btn btn-sm" style="font-size:11px;color:#1d4ed8;padding:2px 8px;">
            Все <i class="bi bi-arrow-right"></i>
          </a>
        </div>
        <table class="ct-table table table-borderless">
          <thead>
            <tr>
              <th>Время</th><th>Звонящий</th><th>Направл.</th><th>Метрика</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($lastCalls):
              foreach ($lastCalls as $lc):
                $dt = $lc['call_time'] ? date('d.m H:i', strtotime($lc['call_time'])) : '—';
            ?>
            <tr>
              <td style="color:#64748b"><?= esc($dt) ?></td>
              <td class="phone-num"><?= esc(fmtPhone($lc['caller'])) ?></td>
              <td>
                <?php if ($lc['direction'] === 'in'): ?>
                  <span class="badge badge-in">↓ входящий</span>
                <?php elseif ($lc['direction'] === 'out'): ?>
                  <span class="badge badge-out">↑ исходящий</span>
                <?php else: ?><span class="badge badge-off">—</span><?php endif ?>
              </td>
              <td>
                <?php if ($lc['goal_sent'] == 1): ?>
                  <i class="bi bi-check-circle-fill goal-yes" title="Цель отправлена"></i>
                <?php elseif ($lc['goal_sent'] == 2): ?>
                  <i class="bi bi-arrow-repeat goal-dup" title="Дубль — не отправлено"></i>
                <?php else: ?>
                  <i class="bi bi-dash-circle goal-no"></i>
                <?php endif ?>
              </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="4" class="text-center" style="color:#94a3b8;padding:24px">Звонков ещё нет</td></tr>
            <?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>


<!-- ══ Звонки ════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'calls'): ?>

  <div class="ct-card">
    <div class="ct-card-header">
      <div class="ct-title"><i class="bi bi-telephone-inbound"></i> Журнал звонков</div>
      <div class="text-muted" style="font-size:11px;">Найдено: <?= $totalRows ?></div>
    </div>

    <!-- Фильтры -->
    <div class="p-3 border-bottom" style="border-color:#dbeafe!important;background:#f8fbff;">
      <form method="get" action="" class="filter-bar">
        <input type="hidden" name="tab" value="calls">
        <div class="row g-2 align-items-center">
          <div class="col-auto" style="flex:1;min-width:180px;">
            <input type="text" name="search" class="form-control"
                   placeholder="Поиск по номеру, client_id, utm..."
                   value="<?= esc($search) ?>">
          </div>
          <div class="col-auto">
            <select name="direction" class="form-select" style="width:150px;">
              <option value="">Все звонки</option>
              <option value="in"  <?= $dirFilter==='in'  ? 'selected' : '' ?>>↓ Входящие</option>
              <option value="out" <?= $dirFilter==='out' ? 'selected' : '' ?>>↑ Исходящие</option>
            </select>
          </div>
          <div class="col-auto">
            <input type="date" name="date" class="form-control" style="width:140px;"
                   value="<?= esc($dateFilter) ?>">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">
              <i class="bi bi-search"></i> Найти
            </button>
            <?php if ($search || $dirFilter || $dateFilter): ?>
            <a href="?tab=calls" class="btn btn-outline-secondary btn-sm ms-1">
              <i class="bi bi-x"></i> Сброс
            </a>
            <?php endif ?>
          </div>
        </div>
      </form>
    </div>

    <!-- Таблица -->
    <div class="table-responsive">
      <table class="ct-table table table-borderless">
        <thead>
          <tr>
            <th>#</th>
            <th>Время</th>
            <th>Звонящий</th>
            <th>Принял</th>
            <th>Направление</th>
            <th>Сессия / ClientID</th>
            <th>UTM source</th>
            <th>Страница</th>
            <th>Длительность</th>
            <th>Метрика</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($calls): foreach ($calls as $c):
            $callDt = $c['call_time'] ? date('d.m.Y H:i:s', strtotime($c['call_time'])) : esc($c['created_at']);
            $callDtInput = $c['call_time'] ? date('Y-m-d\TH:i', strtotime($c['call_time'])) : date('Y-m-d\TH:i');
            // Извлекаем yclid из landing_page
            $cYclid = '';
            if (!empty($c['landing_page'])) {
                parse_str(parse_url($c['landing_page'], PHP_URL_QUERY) ?? '', $lpq);
                $cYclid = $lpq['yclid'] ?? '';
            }
          ?>
          <tr>
            <td style="color:#cbd5e1;font-size:11px;"><?= (int)$c['id'] ?></td>
            <td style="color:#64748b;white-space:nowrap;"><?= esc($callDt) ?></td>
            <td class="phone-num"><?= esc(fmtPhone($c['caller'])) ?></td>
            <td class="phone-num"><?= esc(fmtPhone($c['called'])) ?></td>
            <td>
              <?php if ($c['direction'] === 'in'): ?>
                <span class="badge badge-in">↓ входящий</span>
              <?php elseif ($c['direction'] === 'out'): ?>
                <span class="badge badge-out">↑ исходящий</span>
              <?php else: ?>
                <span class="badge badge-off">—</span>
              <?php endif ?>
            </td>
            <td>
              <?php if ($c['client_id']): ?>
                <span class="cid-tag" title="<?= esc($c['client_id']) ?>">
                  <?= esc(substr($c['client_id'], 0, 14)) . (strlen($c['client_id']) > 14 ? '…' : '') ?>
                </span>
              <?php elseif ($c['session_id']): ?>
                <span style="font-size:11px;color:#94a3b8;">сессия #<?= (int)$c['session_id'] ?></span>
              <?php else: ?>
                <span style="color:#cbd5e1;">—</span>
              <?php endif ?>
            </td>
            <td>
              <?php if ($c['utm_source']): ?>
                <span class="utm-tag"><?= esc($c['utm_source']) ?></span>
              <?php else: ?>
                <span style="color:#cbd5e1;font-size:11px;">—</span>
              <?php endif ?>
            </td>
            <td style="max-width:160px;overflow:hidden;">
              <?php if (!empty($c['landing_page'])): ?>
                <?php
                  $path = parse_url($c['landing_page'], PHP_URL_PATH) ?: '/';
                  $qs   = parse_url($c['landing_page'], PHP_URL_QUERY);
                  $label = $path . ($qs ? '?' . $qs : '');
                  if (strlen($label) > 30) $label = substr($label, 0, 28) . '…';
                ?>
                <a href="<?= esc($c['landing_page']) ?>" target="_blank" rel="noopener"
                   title="<?= esc($c['landing_page']) ?>"
                   style="font-size:11px;color:#1d4ed8;text-decoration:none;white-space:nowrap;">
                  <?= esc($label) ?>
                </a>
              <?php else: ?>
                <span style="color:#cbd5e1;font-size:11px;">—</span>
              <?php endif ?>
            </td>
            <td style="color:#475569;"><?= esc(fmtDur($c['talk_duration'])) ?></td>
            <td>
              <div class="d-flex align-items-center gap-1">
                <?php if ($c['goal_sent'] == 1): ?>
                  <i class="bi bi-check-circle-fill goal-yes" title="Цель отправлена в Метрику"></i>
                <?php elseif ($c['goal_sent'] == 2): ?>
                  <i class="bi bi-arrow-repeat goal-dup" title="Дубль — клиент уже звонил, цель не отправлена"></i>
                <?php else: ?>
                  <i class="bi bi-dash-circle goal-no" title="Цель не отправлена"></i>
                <?php endif ?>
                <button class="btn-resend"
                  title="Переотправить в Метрику"
                  data-call-id="<?= (int)$c['id'] ?>"
                  data-client-id="<?= esc($c['client_id'] ?? '') ?>"
                  data-yclid="<?= esc($cYclid) ?>"
                  data-phone="<?= esc(preg_replace('/\D/', '', $c['caller'] ?? '')) ?>"
                  data-goal="<?= esc(METRIKA_GOAL_ID) ?>"
                  data-datetime="<?= esc($callDtInput) ?>"
                  data-landing="<?= esc($c['landing_page'] ?? '') ?>"
                  data-utm-source="<?= esc($c['utm_source'] ?? '') ?>"
                  data-utm-medium="<?= esc($c['utm_medium'] ?? '') ?>"
                  data-utm-campaign="<?= esc($c['utm_campaign'] ?? '') ?>"
                  data-utm-term="<?= esc($c['utm_term'] ?? '') ?>"
                  data-utm-content="<?= esc($c['utm_content'] ?? '') ?>">
                  <i class="bi bi-send"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr>
            <td colspan="10">
              <div class="empty-state">
                <i class="bi bi-telephone-x"></i>
                Звонков не найдено
              </div>
            </td>
          </tr>
          <?php endif ?>
        </tbody>
      </table>
    </div>

    <!-- Пагинация -->
    <?php if ($totalPages > 1): ?>
    <div class="px-3 py-2 border-top d-flex align-items-center justify-content-between"
         style="border-color:#dbeafe!important;background:#f8fbff;">
      <div style="font-size:11px;color:#94a3b8;">
        Стр. <?= $page ?> из <?= $totalPages ?> · <?= $totalRows ?> записей
      </div>
      <nav>
        <ul class="pagination mb-0 ct-pagination">
          <li class="page-item <?= $page<=1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= buildUrl(['page' => $page-1, 'tab'=>'calls']) ?>">‹</a>
          </li>
          <?php
          $range = range(max(1,$page-2), min($totalPages,$page+2));
          foreach ($range as $p):
          ?>
          <li class="page-item <?= $p==$page ? 'active' : '' ?>">
            <a class="page-link" href="<?= buildUrl(['page'=>$p, 'tab'=>'calls']) ?>"><?= $p ?></a>
          </li>
          <?php endforeach ?>
          <li class="page-item <?= $page>=$totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= buildUrl(['page' => $page+1, 'tab'=>'calls']) ?>">›</a>
          </li>
        </ul>
      </nav>
    </div>
    <?php endif ?>
  </div>


<!-- ══ Пул номеров ═══════════════════════════════════════════════ -->
<?php elseif ($tab === 'pool'): ?>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="ct-card">
        <div class="ct-card-header">
          <div class="ct-title"><i class="bi bi-sim-fill"></i> Номера в пуле</div>
          <div style="font-size:11px;color:#1d4ed8;">
            <?= $freePhones ?> свободно · <?= $busyPhones ?> занято · <?= $totalPhones ?> всего
          </div>
        </div>
        <table class="ct-table table table-borderless">
          <thead>
            <tr>
              <th>Номер</th>
              <th>Статус</th>
              <th>Последнее использование</th>
              <th>Активен</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($phones): foreach ($phones as $ph): ?>
            <tr id="phone-row-<?= (int)$ph['id'] ?>">
              <td class="phone-num"><?= esc(fmtPhone($ph['phone'])) ?></td>
              <td>
                <?php if ($ph['is_busy']): ?>
                  <span class="badge badge-busy">● Занят</span>
                <?php else: ?>
                  <span class="badge badge-free">○ Свободен</span>
                <?php endif ?>
              </td>
              <td style="color:#64748b;">
                <?= $ph['last_used'] ? esc(date('d.m.Y H:i', strtotime($ph['last_used']))) : '—' ?>
              </td>
              <td>
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input phone-toggle" type="checkbox"
                         data-id="<?= (int)$ph['id'] ?>"
                         <?= $ph['is_active'] ? 'checked' : '' ?>
                         style="cursor:pointer;">
                </div>
              </td>
              <td>
                <button class="btn btn-sm phone-delete"
                        data-id="<?= (int)$ph['id'] ?>"
                        style="color:#ef4444;background:none;border:none;padding:2px 6px;font-size:14px;"
                        title="Удалить">
                  <i class="bi bi-trash3"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; else: ?>
            <tr>
              <td colspan="5">
                <div class="empty-state">
                  <i class="bi bi-sim"></i>
                  Номеров нет — добавьте первый
                </div>
              </td>
            </tr>
            <?php endif ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Форма добавления -->
    <div class="col-lg-4">
      <div class="ct-card">
        <div class="ct-card-header">
          <div class="ct-title"><i class="bi bi-plus-circle"></i> Добавить номер</div>
        </div>
        <div class="p-4">
          <form id="add-phone-form" class="add-phone-form" autocomplete="off">
            <div class="mb-3">
              <label class="form-label" style="font-size:12px;font-weight:600;color:#475569;">
                Номер телефона
              </label>
              <input type="text" id="new-phone" class="form-control"
                     placeholder="+7 (900) 000-00-00"
                     style="font-family:monospace;">
              <div class="form-text" style="font-size:11px;">
                Формат: 79001234567 или +7 (900) 123-45-67
              </div>
            </div>
            <button type="submit" class="btn btn-primary w-100" style="font-size:13px;">
              <i class="bi bi-plus-lg me-1"></i> Добавить в пул
            </button>
          </form>
          <div id="add-phone-msg" class="mt-2" style="font-size:12px;display:none;"></div>
        </div>
      </div>

      <!-- Подсказка про seed.php -->
      <div class="ct-card mt-3" style="background:#f8fbff;">
        <div class="p-3" style="font-size:11px;color:#64748b;line-height:1.7;">
          <div class="mb-1" style="font-weight:600;color:#1d4ed8;">
            <i class="bi bi-info-circle me-1"></i> Массовое добавление
          </div>
          Отредактируй <code>seed.php</code> и запусти:<br>
          <code style="background:#eff6ff;padding:3px 7px;border-radius:4px;display:inline-block;margin-top:4px;">
            php seed.php
          </code>
        </div>
      </div>
    </div>
  </div>

<!-- ══ Метрика ═════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'metrika'): ?>

  <!-- Карточки статуса -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon ic-green"><i class="bi bi-check-circle"></i></div>
        <div class="stat-val"><?= $goalsSent ?></div>
        <div class="stat-label">Целей отправлено</div>
        <div class="stat-sub">сегодня</div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:#fee2e2;color:#ef4444"><i class="bi bi-x-circle"></i></div>
        <div class="stat-val" style="<?= $goalsFailedToday > 0 ? 'color:#ef4444' : '' ?>"><?= $goalsFailedToday ?></div>
        <div class="stat-label">Ошибок отправки</div>
        <div class="stat-sub">сессия есть, цель не ушла</div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="bi bi-arrow-repeat"></i></div>
        <div class="stat-val" style="color:#d97706"><?= $goalsDuplicateToday ?></div>
        <div class="stat-label">Дублей пропущено</div>
        <div class="stat-sub">клиент уже звонил</div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon ic-amber"><i class="bi bi-question-circle"></i></div>
        <div class="stat-val"><?= $goalsNoSessionToday ?></div>
        <div class="stat-label">Без сессии</div>
        <div class="stat-sub">звонок не привязан</div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-icon ic-indigo"><i class="bi bi-percent"></i></div>
        <div class="stat-val"><?= $callsToday > 0 ? round($goalsSent / $callsToday * 100) : 0 ?>%</div>
        <div class="stat-label">Конверсия</div>
        <div class="stat-sub">звонков → целей сегодня</div>
      </div>
    </div>
  </div>

  <div class="row g-3">

    <!-- Таблица: не отправленные цели -->
    <div class="col-lg-6">
      <div class="ct-card">
        <div class="ct-card-header">
          <div class="ct-title"><i class="bi bi-x-circle" style="color:#ef4444"></i> Не отправленные цели</div>
          <div style="font-size:11px;color:#64748b;">сессия есть — цель не ушла</div>
        </div>
        <?php if ($failedCalls): ?>
        <div class="table-responsive">
          <table class="ct-table table table-borderless">
            <thead>
              <tr><th>#</th><th>Время</th><th>Звонящий</th><th>ClientID</th><th>UTM</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($failedCalls as $fc):
                $dt = $fc['call_time'] ? date('d.m H:i', strtotime($fc['call_time'])) : date('d.m H:i', strtotime($fc['created_at']));
                $fcDtInput = $fc['call_time'] ? date('Y-m-d\TH:i', strtotime($fc['call_time'])) : date('Y-m-d\TH:i');
                $fcYclid = '';
                if (!empty($fc['landing_page'])) {
                    parse_str(parse_url($fc['landing_page'], PHP_URL_QUERY) ?? '', $fcLpq);
                    $fcYclid = $fcLpq['yclid'] ?? '';
                }
              ?>
              <tr>
                <td style="color:#cbd5e1;font-size:11px;"><?= (int)$fc['id'] ?></td>
                <td style="color:#64748b;white-space:nowrap;"><?= esc($dt) ?></td>
                <td class="phone-num"><?= esc(fmtPhone($fc['caller'])) ?></td>
                <td>
                  <?php if ($fc['client_id']): ?>
                    <span class="cid-tag" title="<?= esc($fc['client_id']) ?>">
                      <?= esc(substr($fc['client_id'], 0, 14)) ?>…
                    </span>
                  <?php else: ?>
                    <span style="color:#cbd5e1">—</span>
                  <?php endif ?>
                </td>
                <td>
                  <?php if ($fc['utm_source']): ?>
                    <span class="utm-tag"><?= esc($fc['utm_source']) ?></span>
                  <?php else: ?>
                    <span style="color:#cbd5e1;font-size:11px;">—</span>
                  <?php endif ?>
                </td>
                <td>
                  <button class="btn-resend"
                    title="Переотправить в Метрику"
                    data-call-id="<?= (int)$fc['id'] ?>"
                    data-client-id="<?= esc($fc['client_id'] ?? '') ?>"
                    data-yclid="<?= esc($fcYclid) ?>"
                    data-phone="<?= esc(preg_replace('/\D/', '', $fc['caller'] ?? '')) ?>"
                    data-goal="<?= esc(METRIKA_GOAL_ID) ?>"
                    data-datetime="<?= esc($fcDtInput) ?>"
                    data-landing="<?= esc($fc['landing_page'] ?? '') ?>"
                    data-utm-source="<?= esc($fc['utm_source'] ?? '') ?>"
                    data-utm-medium="<?= esc($fc['utm_medium'] ?? '') ?>"
                    data-utm-campaign="<?= esc($fc['utm_campaign'] ?? '') ?>"
                    data-utm-term="<?= esc($fc['utm_term'] ?? '') ?>"
                    data-utm-content="<?= esc($fc['utm_content'] ?? '') ?>">
                    <i class="bi bi-send"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-check-circle" style="color:#22c55e"></i> Все цели отправлены успешно</div>
        <?php endif ?>
      </div>
    </div>

    <!-- Лог METRIKA из calls.txt -->
    <div class="col-lg-6">
      <div class="ct-card">
        <div class="ct-card-header">
          <div class="ct-title"><i class="bi bi-terminal"></i> Лог отправки (calls.txt)</div>
          <div style="font-size:11px;color:#64748b;">последние 100 строк</div>
        </div>
        <div style="padding:12px;max-height:420px;overflow-y:auto;background:#0f172a;border-radius:0 0 12px 12px;">
          <?php if ($metrikaLog): ?>
            <?php foreach ($metrikaLog as $line):
              $isError = str_contains($line, 'success=false') || str_contains($line, 'error=') && !str_contains($line, 'error=none');
              $color = $isError ? '#fca5a5' : '#86efac';
            ?>
            <div style="font-family:monospace;font-size:11px;line-height:1.8;color:<?= $color ?>;word-break:break-all;">
              <?= esc($line) ?>
            </div>
            <?php endforeach ?>
          <?php else: ?>
            <div style="font-family:monospace;font-size:11px;color:#475569;padding:16px;">Лог пуст или файл не найден</div>
          <?php endif ?>
        </div>
      </div>
    </div>

  </div>

  <!-- Последние отправленные CSV -->
  <div class="ct-card mt-3">
    <div class="ct-card-header">
      <div class="ct-title"><i class="bi bi-file-earmark-text"></i> Последние отправленные конверсии</div>
      <div style="font-size:11px;color:#64748b;">последние 10 файлов из logs/csv_files/</div>
    </div>
    <?php if ($lastCsvFiles): ?>
    <div class="table-responsive">
      <table class="ct-table table table-borderless">
        <thead>
          <tr><th>Время</th><th>Файл</th><th>Содержимое CSV</th></tr>
        </thead>
        <tbody>
          <?php foreach ($lastCsvFiles as $cf): ?>
          <tr>
            <td style="white-space:nowrap;color:#64748b;"><?= date('d.m.Y H:i:s', $cf['time']) ?></td>
            <td style="font-family:monospace;font-size:11px;color:#94a3b8;"><?= esc($cf['name']) ?></td>
            <td>
              <pre style="margin:0;font-size:11px;background:#f8fbff;padding:6px 10px;border-radius:6px;border:1px solid #dbeafe;color:#1e3a8a;white-space:pre-wrap;word-break:break-all;"><?= esc($cf['content']) ?></pre>
            </td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><i class="bi bi-file-earmark-x"></i> Файлов ещё нет</div>
    <?php endif ?>
  </div>

<!-- ══ Запросы номера ══════════════════════════════════════════════ -->
<?php elseif ($tab === 'requests'): ?>

  <div class="ct-card">
    <div class="ct-card-header">
      <div class="ct-title"><i class="bi bi-cursor-fill"></i> Запросы номера</div>
      <div class="text-muted" style="font-size:11px;">Всего: <?= $totalSessions ?> · каждая запись = клик «Показать»</div>
    </div>
    <div class="table-responsive">
      <table class="ct-table table table-borderless">
        <thead>
          <tr>
            <th>#</th>
            <th>Время</th>
            <th>Телефон показан</th>
            <th>ClientID</th>
            <th>UTM source</th>
            <th>Страница</th>
            <th>IP</th>
            <th>Звонок</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($sessionsAll): foreach ($sessionsAll as $s):
            $dt = date('d.m.Y H:i:s', strtotime($s['created_at']));
            $hasCall = (int)$s['call_count'] > 0;
          ?>
          <tr>
            <td style="color:#cbd5e1;font-size:11px;"><?= (int)$s['id'] ?></td>
            <td style="color:#64748b;white-space:nowrap;"><?= esc($dt) ?></td>
            <td class="phone-num"><?= $s['phone'] ? esc(fmtPhone($s['phone'])) : '<span style="color:#cbd5e1">—</span>' ?></td>
            <td>
              <?php if ($s['client_id']): ?>
                <span class="cid-tag" title="<?= esc($s['client_id']) ?>">
                  <?= esc(substr($s['client_id'], 0, 14)) . (strlen($s['client_id']) > 14 ? '…' : '') ?>
                </span>
              <?php else: ?>
                <span style="color:#cbd5e1">—</span>
              <?php endif ?>
            </td>
            <td>
              <?php if ($s['utm_source']): ?>
                <span class="utm-tag"><?= esc($s['utm_source']) ?></span>
              <?php else: ?>
                <span style="color:#cbd5e1;font-size:11px;">—</span>
              <?php endif ?>
            </td>
            <td style="max-width:160px;overflow:hidden;">
              <?php if (!empty($s['landing_page'])): ?>
                <?php
                  $path  = parse_url($s['landing_page'], PHP_URL_PATH) ?: '/';
                  $label = strlen($path) > 30 ? substr($path, 0, 28) . '…' : $path;
                ?>
                <a href="<?= esc($s['landing_page']) ?>" target="_blank" rel="noopener"
                   title="<?= esc($s['landing_page']) ?>"
                   style="font-size:11px;color:#1d4ed8;text-decoration:none;white-space:nowrap;">
                  <?= esc($label) ?>
                </a>
              <?php else: ?>
                <span style="color:#cbd5e1;font-size:11px;">—</span>
              <?php endif ?>
            </td>
            <td style="font-size:11px;color:#94a3b8;font-family:monospace;"><?= esc($s['ip'] ?? '—') ?></td>
            <td>
              <?php if ($hasCall): ?>
                <i class="bi bi-telephone-fill goal-yes" title="Был звонок"></i>
              <?php else: ?>
                <i class="bi bi-dash-circle goal-no" title="Звонка не было"></i>
              <?php endif ?>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr>
            <td colspan="8">
              <div class="empty-state">
                <i class="bi bi-cursor"></i>
                Запросов ещё не было
              </div>
            </td>
          </tr>
          <?php endif ?>
        </tbody>
      </table>
    </div>
    <!-- Пагинация -->
    <?php if ($totalReqPages > 1): ?>
    <div class="px-3 py-2 border-top d-flex align-items-center justify-content-between"
         style="border-color:#dbeafe!important;background:#f8fbff;">
      <div style="font-size:11px;color:#94a3b8;">
        Стр. <?= $page ?> из <?= $totalReqPages ?> · <?= $totalSessions ?> записей
      </div>
      <nav>
        <ul class="pagination mb-0 ct-pagination">
          <li class="page-item <?= $page<=1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= buildUrl(['page' => $page-1, 'tab'=>'requests']) ?>">‹</a>
          </li>
          <?php foreach (range(max(1,$page-2), min($totalReqPages,$page+2)) as $p): ?>
          <li class="page-item <?= $p==$page ? 'active' : '' ?>">
            <a class="page-link" href="<?= buildUrl(['page'=>$p, 'tab'=>'requests']) ?>"><?= $p ?></a>
          </li>
          <?php endforeach ?>
          <li class="page-item <?= $page>=$totalReqPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= buildUrl(['page' => $page+1, 'tab'=>'requests']) ?>">›</a>
          </li>
        </ul>
      </nav>
    </div>
    <?php endif ?>
  </div>

<!-- ══ Сайты ═══════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'sites'):
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? '';
  $base   = $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
?>

  <div class="ct-card">
    <div class="ct-card-header">
      <div class="ct-title"><i class="bi bi-globe2"></i> Сайты</div>
      <button class="btn btn-primary btn-sm" id="btn-add-site" style="font-size:12px;">
        <i class="bi bi-plus-lg me-1"></i>Добавить сайт
      </button>
    </div>
    <div class="table-responsive">
      <table class="ct-table table table-borderless">
        <thead>
          <tr>
            <th>#</th><th>Название</th><th>Домен</th><th>Ключ</th>
            <th>Счётчик</th><th>TTL</th><th>Статус</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($sites): foreach ($sites as $st): ?>
          <tr>
            <td style="color:#cbd5e1;font-size:11px;"><?= (int)$st['id'] ?></td>
            <td style="font-weight:600;color:#1e3a8a;"><?= esc($st['name']) ?></td>
            <td style="font-size:11px;color:#64748b;"><?= esc($st['domain'] ?: '—') ?></td>
            <td><span class="cid-tag" title="нажми чтобы скопировать" style="cursor:pointer;"
                      onclick="navigator.clipboard.writeText('<?= esc($st['site_key']) ?>');showToast('Ключ скопирован',true)"><?= esc($st['site_key']) ?></span></td>
            <td style="font-size:11px;font-family:monospace;color:#475569;"><?= esc($st['metrika_counter_id'] ?: '—') ?></td>
            <td style="font-size:11px;color:#64748b;"><?= (int)$st['session_ttl_minutes'] ?>м</td>
            <td>
              <?php if ($st['is_active']): ?><span class="badge badge-on">● вкл</span>
              <?php else: ?><span class="badge badge-off">○ выкл</span><?php endif ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <button class="btn-resend btn-site-snippet"
                  title="Код виджета"
                  data-base="<?= esc($base) ?>"
                  data-key="<?= esc($st['site_key']) ?>"
                  data-counter="<?= esc($st['metrika_counter_id']) ?>"><i class="bi bi-code-slash"></i></button>
                <button class="btn-resend btn-edit-site"
                  title="Редактировать"
                  data-site='<?= esc(json_encode($st, JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-pencil"></i></button>
                <button class="btn-resend btn-delete-site"
                  title="Удалить сайт со всеми данными"
                  data-id="<?= (int)$st['id'] ?>" data-name="<?= esc($st['name']) ?>"
                  style="color:#ef4444;border-color:#fecaca;"><i class="bi bi-trash3"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="8"><div class="empty-state"><i class="bi bi-globe2"></i> Сайтов нет — добавьте первый</div></td></tr>
          <?php endif ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif ?>
</div><!-- /container -->

<!-- Модал: переотправка в Метрику -->
<div class="modal fade" id="resendModal" tabindex="-1" aria-labelledby="resendModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:1px solid #dbeafe;border-radius:12px;">
      <div class="modal-header" style="background:#e0f2fe;border-bottom:1px solid #bfdbfe;">
        <h5 class="modal-title" id="resendModalLabel" style="font-size:14px;font-weight:700;color:#1e3a8a;">
          <i class="bi bi-send me-2"></i>Переотправить в Яндекс.Метрику
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="background:#f8fbff;">
        <div class="mb-3 p-2 rounded" style="background:#eff6ff;font-size:11px;color:#1d4ed8;border:1px solid #bfdbfe;">
          <strong>Приоритет:</strong> ClientId → Yclid → Phone. Пустые поля игнорируются. Хотя бы один идентификатор обязателен.
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">ClientID <span style="color:#94a3b8;font-weight:400;">(_ym_uid)</span></label>
            <input type="text" id="rm-client-id" class="form-control form-control-sm" placeholder="оставьте пустым чтобы не использовать" style="font-family:monospace;font-size:12px;">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">Yclid <span style="color:#94a3b8;font-weight:400;">(из URL)</span></label>
            <input type="text" id="rm-yclid" class="form-control form-control-sm" placeholder="оставьте пустым чтобы не использовать" style="font-family:monospace;font-size:12px;">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">Phone <span style="color:#94a3b8;font-weight:400;">(звонящий)</span></label>
            <input type="text" id="rm-phone" class="form-control form-control-sm" placeholder="79001234567" style="font-family:monospace;font-size:12px;">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">Цель <span style="color:#94a3b8;font-weight:400;">(goal name)</span></label>
            <input type="text" id="rm-goal" class="form-control form-control-sm" style="font-family:monospace;font-size:12px;">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">Дата и время звонка</label>
            <input type="datetime-local" id="rm-datetime" class="form-control form-control-sm" style="font-size:12px;">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">Landing Page</label>
            <input type="text" id="rm-landing" class="form-control form-control-sm" readonly style="font-size:11px;color:#64748b;background:#f1f5f9;">
          </div>
        </div>

        <!-- UTM -->
        <div class="mt-3 p-2 rounded" style="background:#fff;border:1px solid #dbeafe;">
          <div style="font-size:11px;font-weight:600;color:#475569;margin-bottom:8px;">UTM-метки (только для справки)</div>
          <div class="row g-2">
            <div class="col">
              <label style="font-size:10px;color:#94a3b8;">source</label>
              <input type="text" id="rm-utm-source" class="form-control form-control-sm" readonly style="font-size:11px;background:#f8fbff;">
            </div>
            <div class="col">
              <label style="font-size:10px;color:#94a3b8;">medium</label>
              <input type="text" id="rm-utm-medium" class="form-control form-control-sm" readonly style="font-size:11px;background:#f8fbff;">
            </div>
            <div class="col">
              <label style="font-size:10px;color:#94a3b8;">campaign</label>
              <input type="text" id="rm-utm-campaign" class="form-control form-control-sm" readonly style="font-size:11px;background:#f8fbff;">
            </div>
            <div class="col">
              <label style="font-size:10px;color:#94a3b8;">term</label>
              <input type="text" id="rm-utm-term" class="form-control form-control-sm" readonly style="font-size:11px;background:#f8fbff;">
            </div>
            <div class="col">
              <label style="font-size:10px;color:#94a3b8;">content</label>
              <input type="text" id="rm-utm-content" class="form-control form-control-sm" readonly style="font-size:11px;background:#f8fbff;">
            </div>
          </div>
        </div>

        <!-- Результат -->
        <div id="rm-result" class="mt-3" style="display:none;"></div>
      </div>
      <div class="modal-footer" style="background:#f8fbff;border-top:1px solid #dbeafe;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Отмена</button>
        <button type="button" id="rm-submit" class="btn btn-primary btn-sm">
          <i class="bi bi-send me-1"></i>Отправить в Метрику
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Модал: сайт (создание/редактирование) -->
<div class="modal fade" id="siteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:1px solid #dbeafe;border-radius:12px;">
      <div class="modal-header" style="background:#e0f2fe;border-bottom:1px solid #bfdbfe;">
        <h5 class="modal-title" id="siteModalTitle" style="font-size:14px;font-weight:700;color:#1e3a8a;">
          <i class="bi bi-globe2 me-2"></i>Сайт
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="background:#f8fbff;">
        <input type="hidden" id="sm-id">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">Название *</label>
            <input type="text" id="sm-name" class="form-control form-control-sm" placeholder="Мой сайт">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">Домен</label>
            <input type="text" id="sm-domain" class="form-control form-control-sm" placeholder="example.ru">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">ID счётчика Метрики</label>
            <input type="text" id="sm-counter" class="form-control form-control-sm" style="font-family:monospace;" placeholder="108141615">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">Название цели</label>
            <input type="text" id="sm-goal" class="form-control form-control-sm" style="font-family:monospace;" placeholder="send_lead">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold d-flex align-items-center gap-2" style="font-size:12px;">
              OAuth токен Метрики
              <a href="#" id="sm-token-help-toggle" style="font-size:11px;font-weight:400;text-decoration:none;">
                <i class="bi bi-question-circle"></i> как получить?
              </a>
            </label>
            <input type="text" id="sm-token" class="form-control form-control-sm" style="font-family:monospace;font-size:11px;" placeholder="y0__...">
            <div id="sm-token-help" class="mt-2 p-3 rounded" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;font-size:11px;color:#1e3a8a;line-height:1.7;">
              <strong>Получение OAuth-токена Яндекс.Метрики:</strong>
              <ol class="mb-1 ps-3 mt-1">
                <li>Создай приложение — <a href="https://oauth.yandex.ru/client/new" target="_blank" rel="noopener">oauth.yandex.ru/client/new</a></li>
                <li>Платформа «Веб-сервисы», Redirect URI: <code>https://oauth.yandex.ru/verification_code</code></li>
                <li>Доступы → <strong>Яндекс.Метрика</strong>: отметь «Получение статистики, чтение параметров» и «Запись параметров»</li>
                <li>Сохрани → скопируй <strong>ClientID</strong> приложения</li>
                <li>Открой в браузере (подставь ClientID):<br>
                  <code style="word-break:break-all;">https://oauth.yandex.ru/authorize?response_type=token&amp;client_id=ВАШ_CLIENT_ID</code></li>
                <li>Подтверди доступ → в адресной строке появится <code>access_token=y0__...</code> — это токен</li>
              </ol>
              Аккаунт Яндекса должен иметь доступ к нужному счётчику. Токен живёт ~1 год.
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" style="font-size:12px;">Fallback-номер</label>
            <input type="text" id="sm-fallback" class="form-control form-control-sm" style="font-family:monospace;" placeholder="+79001234567">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold" style="font-size:12px;">TTL (мин)</label>
            <input type="number" id="sm-ttl" class="form-control form-control-sm" value="10" min="1">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold" style="font-size:12px;">Статус</label>
            <select id="sm-active" class="form-select form-select-sm">
              <option value="1">Включён</option>
              <option value="0">Выключен</option>
            </select>
          </div>
        </div>
        <div id="sm-result" class="mt-3" style="display:none;"></div>
      </div>
      <div class="modal-footer" style="background:#f8fbff;border-top:1px solid #dbeafe;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Отмена</button>
        <button type="button" id="sm-submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Сохранить</button>
      </div>
    </div>
  </div>
</div>

<!-- Модал: код виджета -->
<div class="modal fade" id="snippetModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:1px solid #dbeafe;border-radius:12px;">
      <div class="modal-header" style="background:#e0f2fe;border-bottom:1px solid #bfdbfe;">
        <h5 class="modal-title" style="font-size:14px;font-weight:700;color:#1e3a8a;"><i class="bi bi-code-slash me-2"></i>Код для установки</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="background:#f8fbff;">
        <label class="form-label fw-semibold" style="font-size:12px;">1. Виджет на страницы сайта (перед &lt;/body&gt;)</label>
        <pre id="snippet-widget" style="font-size:11px;background:#0f172a;color:#86efac;padding:12px;border-radius:8px;white-space:pre-wrap;word-break:break-all;"></pre>
        <div class="mb-2" style="font-size:11px;color:#64748b;">Номер выводится в элементе с атрибутом <code>data-ct-phone</code>:</div>
        <pre id="snippet-html" style="font-size:11px;background:#0f172a;color:#93c5fd;padding:12px;border-radius:8px;white-space:pre-wrap;word-break:break-all;"></pre>
        <label class="form-label fw-semibold mt-2" style="font-size:12px;">2. Вебхук Novofon</label>
        <div class="p-2 mb-2 rounded" style="background:#fffbeb;border:1px solid #fde68a;font-size:11px;color:#92400e;line-height:1.6;">
          <i class="bi bi-info-circle me-1"></i>
          Личный кабинет Novofon → <strong>Настройки → Уведомления о звонках</strong> →
          добавить уведомление, метод <strong>GET</strong>, вставить URL ниже.
          Тип события — «Завершение звонка».
        </div>
        <pre id="snippet-webhook" style="font-size:11px;background:#0f172a;color:#fbbf24;padding:12px;border-radius:8px;white-space:pre-wrap;word-break:break-all;"></pre>
        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="snippet-webhook-copy" style="font-size:11px;">
          <i class="bi bi-clipboard me-1"></i>Скопировать URL
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-ct">
  <div id="ct-toast" class="toast align-items-center border-0" role="alert" aria-live="assertive">
    <div class="d-flex">
      <div class="toast-body" id="ct-toast-msg" style="font-size:13px;"></div>
      <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Часы
(function() {
  function tick() {
    var d = new Date();
    var pad = n => String(n).padStart(2,'0');
    document.getElementById('ct-clock').textContent =
      d.getFullYear() + '.' + pad(d.getMonth()+1) + '.' + pad(d.getDate()) +
      ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }
  tick(); setInterval(tick, 1000);
})();

// Toast helper
var toastEl = document.getElementById('ct-toast');
var toastInst = toastEl ? new bootstrap.Toast(toastEl, {delay: 2800}) : null;
function showToast(msg, ok) {
  if (!toastInst) return;
  toastEl.classList.remove('text-bg-success','text-bg-danger','text-bg-secondary');
  toastEl.classList.add(ok === true ? 'text-bg-success' : ok === false ? 'text-bg-danger' : 'text-bg-secondary');
  document.getElementById('ct-toast-msg').textContent = msg;
  toastInst.show();
}

// Переключатель активности номера
document.querySelectorAll('.phone-toggle').forEach(function(toggle) {
  toggle.addEventListener('change', function() {
    var id = this.dataset.id;
    var checked = this.checked;
    var fd = new FormData();
    fd.append('action', 'toggle_phone');
    fd.append('id', id);
    fetch(window.location.pathname + '?tab=pool', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(function(data) {
        if (data.success) {
          showToast(checked ? 'Номер активирован' : 'Номер деактивирован', true);
        } else {
          toggle.checked = !checked;
          showToast('Ошибка: ' + (data.error || '?'), false);
        }
      })
      .catch(function() { toggle.checked = !checked; showToast('Ошибка сети', false); });
  });
});

// Удаление номера
document.querySelectorAll('.phone-delete').forEach(function(btn) {
  btn.addEventListener('click', function() {
    if (!confirm('Удалить номер из пула?')) return;
    var id = this.dataset.id;
    var fd = new FormData();
    fd.append('action', 'delete_phone');
    fd.append('id', id);
    fetch(window.location.pathname + '?tab=pool', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(function(data) {
        if (data.success) {
          var row = document.getElementById('phone-row-' + id);
          if (row) row.remove();
          showToast('Номер удалён', true);
        } else {
          showToast('Ошибка удаления', false);
        }
      });
  });
});

// Переотправка в Метрику
var resendModal = new bootstrap.Modal(document.getElementById('resendModal'));
var rmCallId = null;

document.addEventListener('click', function(e) {
  var btn = e.target.closest('.btn-resend');
  if (!btn) return;
  if (!btn.dataset.callId) return; // не resend-кнопка (напр. кнопки сайта)
  rmCallId = btn.dataset.callId;
  document.getElementById('rm-client-id').value   = btn.dataset.clientId   || '';
  document.getElementById('rm-yclid').value        = btn.dataset.yclid       || '';
  document.getElementById('rm-phone').value        = btn.dataset.phone       || '';
  document.getElementById('rm-goal').value         = btn.dataset.goal        || '';
  document.getElementById('rm-datetime').value     = btn.dataset.datetime    || '';
  document.getElementById('rm-landing').value      = btn.dataset.landing     || '';
  document.getElementById('rm-utm-source').value   = btn.dataset.utmSource   || '';
  document.getElementById('rm-utm-medium').value   = btn.dataset.utmMedium   || '';
  document.getElementById('rm-utm-campaign').value = btn.dataset.utmCampaign || '';
  document.getElementById('rm-utm-term').value     = btn.dataset.utmTerm     || '';
  document.getElementById('rm-utm-content').value  = btn.dataset.utmContent  || '';
  document.getElementById('rm-result').style.display = 'none';
  resendModal.show();
});

document.getElementById('rm-submit').addEventListener('click', function() {
  if (!rmCallId) return;
  var btn = this;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Отправка…';

  var fd = new FormData();
  fd.append('action',    'resend_metrika');
  fd.append('call_id',   rmCallId);
  fd.append('client_id', document.getElementById('rm-client-id').value.trim());
  fd.append('yclid',     document.getElementById('rm-yclid').value.trim());
  fd.append('phone',     document.getElementById('rm-phone').value.trim());
  fd.append('goal',      document.getElementById('rm-goal').value.trim());
  fd.append('datetime',  document.getElementById('rm-datetime').value);

  fetch(window.location.pathname, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send me-1"></i>Отправить в Метрику';
      var res = document.getElementById('rm-result');
      res.style.display = 'block';
      if (data.success) {
        res.innerHTML = '<div class="alert alert-success py-2 mb-0" style="font-size:12px;">' +
          '<i class="bi bi-check-circle-fill me-1"></i>Успешно! HTTP ' + data.http_code +
          '<br><code style="font-size:10px;">' + (data.csv || '').replace(/\n/g,'<br>') + '</code></div>';
        showToast('Цель отправлена', true);
      } else {
        res.innerHTML = '<div class="alert alert-danger py-2 mb-0" style="font-size:12px;">' +
          '<i class="bi bi-x-circle-fill me-1"></i>Ошибка: ' + (data.error || 'неизвестная') +
          (data.http_code ? ' (HTTP ' + data.http_code + ')' : '') +
          (data.response ? '<br><code style="font-size:10px;">' + data.response + '</code>' : '') +
          '</div>';
        showToast('Ошибка отправки', false);
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send me-1"></i>Отправить в Метрику';
      showToast('Ошибка сети', false);
    });
});

// Добавление номера
var addForm = document.getElementById('add-phone-form');
if (addForm) {
  addForm.addEventListener('submit', function(e) {
    e.preventDefault();
    var phone = document.getElementById('new-phone').value.trim();
    if (!phone) return;
    var fd = new FormData();
    fd.append('action', 'add_phone');
    fd.append('phone', phone);
    fd.append('site_id', <?= (int)$currentSiteId ?>);
    var msg = document.getElementById('add-phone-msg');
    fetch(window.location.pathname + '?tab=pool', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(function(data) {
        if (data.success) {
          document.getElementById('new-phone').value = '';
          showToast('Номер добавлен — обновите страницу', true);
          msg.style.display = 'block';
          msg.innerHTML = '<span style="color:#15803d"><i class="bi bi-check-circle"></i> Номер добавлен</span>';
          setTimeout(function() { msg.style.display = 'none'; }, 3000);
        } else {
          showToast(data.error || 'Ошибка', false);
          msg.style.display = 'block';
          msg.innerHTML = '<span style="color:#b91c1c"><i class="bi bi-exclamation-circle"></i> ' + (data.error || 'Ошибка') + '</span>';
        }
      })
      .catch(function() { showToast('Ошибка сети', false); });
  });
}

// ── Сайты: CRUD ───────────────────────────────────────────────────
var siteModalEl = document.getElementById('siteModal');
var siteModal   = siteModalEl ? new bootstrap.Modal(siteModalEl) : null;
var snippetModalEl = document.getElementById('snippetModal');
var snippetModal   = snippetModalEl ? new bootstrap.Modal(snippetModalEl) : null;

function openSiteModal(site) {
  document.getElementById('siteModalTitle').innerHTML = site
    ? '<i class="bi bi-pencil me-2"></i>Редактировать сайт'
    : '<i class="bi bi-plus-lg me-2"></i>Новый сайт';
  document.getElementById('sm-id').value       = site ? site.id : '';
  document.getElementById('sm-name').value     = site ? (site.name || '') : '';
  document.getElementById('sm-domain').value   = site ? (site.domain || '') : '';
  document.getElementById('sm-counter').value  = site ? (site.metrika_counter_id || '') : '';
  document.getElementById('sm-goal').value     = site ? (site.metrika_goal_id || 'send_lead') : 'send_lead';
  document.getElementById('sm-token').value    = site ? (site.metrika_access_token || '') : '';
  document.getElementById('sm-fallback').value = site ? (site.fallback_phone || '') : '';
  document.getElementById('sm-ttl').value      = site ? (site.session_ttl_minutes || 10) : 10;
  document.getElementById('sm-active').value   = site ? (site.is_active ? '1' : '0') : '1';
  document.getElementById('sm-result').style.display = 'none';
  siteModal.show();
}

var addSiteBtn = document.getElementById('btn-add-site');
if (addSiteBtn) addSiteBtn.addEventListener('click', function() { openSiteModal(null); });

var tokenHelpToggle = document.getElementById('sm-token-help-toggle');
if (tokenHelpToggle) tokenHelpToggle.addEventListener('click', function(e) {
  e.preventDefault();
  var h = document.getElementById('sm-token-help');
  h.style.display = h.style.display === 'none' ? 'block' : 'none';
});

document.addEventListener('click', function(e) {
  var edit = e.target.closest('.btn-edit-site');
  if (edit) { openSiteModal(JSON.parse(edit.dataset.site)); return; }

  var snip = e.target.closest('.btn-site-snippet');
  if (snip) {
    var base = snip.dataset.base, key = snip.dataset.key, counter = snip.dataset.counter || '';
    var counterAttr = counter ? ' data-counter="' + counter + '"' : '';
    document.getElementById('snippet-widget').textContent =
      '<script src="' + base + '/ct.js" data-site="' + key + '"' + counterAttr + '><\/script>';
    document.getElementById('snippet-html').textContent =
      '<span data-ct-phone="+7 (988) 400-70-97"></span>';
    var webhookParams = [
      'notification_name={{notification_name}}',
      'virtual_phone_number={{virtual_phone_number}}',
      'notification_time={{notification_time}}',
      'scenario_name={{scenario_name}}',
      'contact_phone_number={{contact_phone_number}}',
      'communication_number={{communication_number}}',
      'contact_id={{contact_id}}',
      'contact_full_name={{contact_full_name}}',
      'call_session_id={{call_session_id}}'
    ].join('&');
    document.getElementById('snippet-webhook').textContent = base + '/?' + webhookParams;
    snippetModal.show();
    return;
  }

  var whCopy = e.target.closest('#snippet-webhook-copy');
  if (whCopy) {
    navigator.clipboard.writeText(document.getElementById('snippet-webhook').textContent);
    showToast('URL вебхука скопирован', true);
    return;
    return;
  }

  var del = e.target.closest('.btn-delete-site');
  if (del) {
    if (!confirm('Удалить сайт «' + del.dataset.name + '» вместе со всеми номерами, сессиями и звонками?')) return;
    var fd = new FormData();
    fd.append('action', 'delete_site');
    fd.append('id', del.dataset.id);
    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(function(data) {
        if (data.success) { showToast('Сайт удалён', true); setTimeout(() => location.reload(), 600); }
        else showToast('Ошибка удаления', false);
      });
    return;
  }
});

var smSubmit = document.getElementById('sm-submit');
if (smSubmit) smSubmit.addEventListener('click', function() {
  var name = document.getElementById('sm-name').value.trim();
  if (!name) { showToast('Укажите название', false); return; }
  var btn = this;
  btn.disabled = true;
  var fd = new FormData();
  fd.append('action', 'save_site');
  fd.append('id',                   document.getElementById('sm-id').value);
  fd.append('name',                 name);
  fd.append('domain',               document.getElementById('sm-domain').value.trim());
  fd.append('metrika_counter_id',   document.getElementById('sm-counter').value.trim());
  fd.append('metrika_goal_id',      document.getElementById('sm-goal').value.trim());
  fd.append('metrika_access_token', document.getElementById('sm-token').value.trim());
  fd.append('fallback_phone',       document.getElementById('sm-fallback').value.trim());
  fd.append('session_ttl_minutes',  document.getElementById('sm-ttl').value);
  fd.append('is_active',            document.getElementById('sm-active').value);
  fetch(window.location.pathname, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(function(data) {
      btn.disabled = false;
      if (data.success) { showToast('Сохранено', true); setTimeout(() => location.reload(), 600); }
      else {
        var res = document.getElementById('sm-result');
        res.style.display = 'block';
        res.innerHTML = '<div class="alert alert-danger py-2 mb-0" style="font-size:12px;">' + (data.error || 'Ошибка') + '</div>';
      }
    })
    .catch(function() { btn.disabled = false; showToast('Ошибка сети', false); });
});
</script>
</body>
</html>
