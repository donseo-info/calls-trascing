<?php
require_once dirname(__DIR__) . '/rb.php';
require_once dirname(__DIR__) . '/config.php';

R::setup('sqlite:' . DB_PATH);
R::freeze(true);

// ── AJAX-обработчики ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

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
        $raw   = trim($_POST['phone'] ?? '');
        $phone = preg_replace('/\D/', '', $raw);
        if (strlen($phone) >= 10) {
            $exists = R::findOne('phonepool', 'phone = ?', [$phone]);
            if (!$exists) {
                $p             = R::dispense('phonepool');
                $p->phone      = $phone;
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

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    R::close(); exit;
}

// ── Дашборд: статистика ───────────────────────────────────────────
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');

$callsToday   = (int)R::getCell('SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ?', [$today]);
$matchedToday = (int)R::getCell('SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND session_id IS NOT NULL', [$today]);
$goalsSent    = (int)R::getCell('SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND goal_sent = 1', [$today]);
$totalPhones  = (int)R::getCell('SELECT COUNT(*) FROM phonepool WHERE is_active = 1');
$busyPhones   = (int)R::getCell(
    'SELECT COUNT(DISTINCT phonepool_id) FROM sessions WHERE phonepool_id IS NOT NULL AND expires_at > ?',
    [$now]
);
$freePhones     = $totalPhones - $busyPhones;
$requestsToday  = (int)R::getCell('SELECT COUNT(*) FROM sessions WHERE DATE(created_at) = ?', [$today]);
$convRate       = $requestsToday > 0 ? round($callsToday / $requestsToday * 100) : 0;

// Звонки за 7 дней для мини-графика
$weekStats = R::getAll(
    "SELECT DATE(created_at) as day, COUNT(*) as cnt
     FROM calls
     WHERE DATE(created_at) >= DATE('now', '-6 days')
     GROUP BY day ORDER BY day ASC"
);

// ── Вкладка «Звонки»: фильтры + пагинация ────────────────────────
$tab       = $_GET['tab']       ?? 'dashboard';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;
$offset    = ($page - 1) * $perPage;
$search    = trim($_GET['search']    ?? '');
$dirFilter = trim($_GET['direction'] ?? '');
$dateFilter= trim($_GET['date']      ?? '');

$where  = [];
$params = [];

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
            c.goal_sent, c.created_at, c.session_id,
            s.client_id, s.utm_source, s.phone as session_phone, s.landing_page
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
     ORDER BY pp.id ASC",
    [$now]
);

// Последние 6 звонков для дашборда
$lastCalls = R::getAll(
    'SELECT caller, called, direction, call_time, goal_sent FROM calls ORDER BY created_at DESC LIMIT 6'
);

// ── Вкладка «Запросы» ─────────────────────────────────────────────
$sessionsAll   = [];
$totalSessions = 0;
$totalReqPages = 1;
if ($tab === 'requests') {
    $totalSessions = (int)R::getCell('SELECT COUNT(*) FROM sessions');
    $totalReqPages = max(1, (int)ceil($totalSessions / $perPage));
    $sessionsAll = R::getAll(
        "SELECT s.id, s.created_at, s.client_id, s.phone,
                s.utm_source, s.utm_medium, s.landing_page, s.ip,
                (SELECT COUNT(*) FROM calls c WHERE c.session_id = s.id) as call_count
         FROM sessions s
         ORDER BY s.created_at DESC
         LIMIT ? OFFSET ?",
        [$perPage, $offset]
    );
}

// ── Вкладка «Метрика» ─────────────────────────────────────────────
$goalsFailedToday    = (int)R::getCell(
    'SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND goal_sent = 0 AND session_id IS NOT NULL', [$today]
);
$goalsNoSessionToday = (int)R::getCell(
    'SELECT COUNT(*) FROM calls WHERE DATE(created_at) = ? AND goal_sent = 0 AND session_id IS NULL', [$today]
);
$failedCalls = R::getAll(
    "SELECT c.id, c.caller, c.call_time, c.created_at, s.client_id, s.utm_source
     FROM calls c
     LEFT JOIN sessions s ON s.id = c.session_id
     WHERE c.goal_sent = 0 AND c.session_id IS NOT NULL
     ORDER BY c.created_at DESC
     LIMIT 50"
);

R::close();

// Читаем лог — последние строки с METRIKA
$metrikaLog = [];
$logFile = dirname(__DIR__) . '/calls.txt';
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
</style>
</head>
<body>

<!-- Шапка -->
<header class="ct-header">
  <div class="ct-logo">
    <div class="dot"><i class="bi bi-telephone-fill"></i></div>
    Call Tracking
  </div>
  <div class="ct-now" id="ct-clock"></div>
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
                <?php if ($lc['goal_sent']): ?>
                  <i class="bi bi-check-circle-fill goal-yes" title="Цель отправлена"></i>
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
              <?php if ($c['goal_sent']): ?>
                <i class="bi bi-check-circle-fill goal-yes" title="Цель отправлена в Метрику"></i>
              <?php else: ?>
                <i class="bi bi-dash-circle goal-no" title="Цель не отправлена"></i>
              <?php endif ?>
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
              <tr><th>#</th><th>Время</th><th>Звонящий</th><th>ClientID</th><th>UTM</th></tr>
            </thead>
            <tbody>
              <?php foreach ($failedCalls as $fc):
                $dt = $fc['call_time'] ? date('d.m H:i', strtotime($fc['call_time'])) : date('d.m H:i', strtotime($fc['created_at']));
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

<?php endif ?>
</div><!-- /container -->

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
</script>
</body>
</html>
