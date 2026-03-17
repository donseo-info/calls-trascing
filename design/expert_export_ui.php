<?php

require_once __DIR__ . '/YandexDirectAPI.php';
require_once __DIR__ . '/api_helpers.php';

// ====== НАСТРОЙКИ ======
$config = require __DIR__ . '/config.php';
$token  = $config['yandex_direct']['token'] ?? null;

// ID кампании из GET-параметра (?campaign_id=XXXXX)
$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;

// AJAX: сохранение заметок (POST с campaign_id)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_notes') {
    $cid = (int)($_POST['campaign_id'] ?? 0);
    if ($cid > 0) {
        header('Content-Type: application/json; charset=utf-8');
        $text = trim($_POST['text'] ?? '');
        $notesFile = __DIR__ . "/campaign_{$cid}_notes.json";
        $data = ['entries' => []];
        if (file_exists($notesFile)) {
            $data = json_decode(file_get_contents($notesFile), true) ?: $data;
        }
        if (!isset($data['entries'])) {
            $data['entries'] = [];
        }
        if ($text !== '') {
            $data['entries'][] = [
                'text' => $text,
                'created_at' => date('Y-m-d H:i:s')
            ];
            file_put_contents($notesFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        echo json_encode(['ok' => true, 'entries' => $data['entries']]);
        exit;
    }
}

// Если ID не передан — показываем форму выбора кампании
if (!$campaignId) {
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Панель ставок — Яндекс Директ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card shadow" style="min-width:340px;">
        <div class="card-body p-4">
            <h5 class="mb-1">Панель ставок</h5>
            <p class="text-muted small mb-3">Яндекс Директ — управление ставками</p>
            <form method="get" action="">
                <div class="mb-3">
                    <label for="campaign_id" class="form-label">ID кампании</label>
                    <input type="number" class="form-control" id="campaign_id" name="campaign_id"
                           placeholder="Например, 707520302" min="1" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100">Открыть кампанию →</button>
            </form>
        </div>
    </div>
</body>
</html><?php
    exit;
}

// Нужно ли сохранять результат в JSON-файл
$saveToFile = true;

// Имя файла для сохранения (в папке stat)
$jsonFile = __DIR__ . "/campaign_{$campaignId}_bids_ui.json";

// =======================

function price_fmt($v) {
    return ($v !== null) ? number_format($v, 2, '.', '') . '₽' : '—';
}

// Управление кешем и синхронизацией
$doSync = isset($_GET['sync']) && $_GET['sync'] === '1';
$lastSyncTime = null;
if (file_exists($jsonFile)) {
    $lastSyncTime = date('d.m.Y H:i', filemtime($jsonFile));
}

// Получаем название кампании (один раз, отдельно от кеша ставок)
$campaignName = null;
try {
    $url = 'https://api.direct.yandex.com/json/v5/campaigns';
    $requestBody = [
        'method' => 'get',
        'params' => [
            'SelectionCriteria' => ['Ids' => [(int)$campaignId]],
            'FieldNames' => ['Id', 'Name']
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json; charset=utf-8',
        'Accept-Language: ru'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    if (isset($result['result']['Campaigns'][0]['Name'])) {
        $campaignName = $result['result']['Campaigns'][0]['Name'];
    }
} catch (\Throwable $e) {
    // игнорируем ошибки имени кампании
}

$api = new YandexDirectBids($token, null, false);

$resultRows = [];

if ($doSync || !file_exists($jsonFile)) {
    // 1. Получаем ставки по всем ключам кампании (Bids.get)
    $allFields = ['KeywordId','CampaignId','AdGroupId','Bid','CurrentSearchPrice','AuctionBids'];
    $bidsResponse = $api->getBidsByCampaign($campaignId, $allFields);

    if ($bidsResponse === false || empty($bidsResponse['result']['Bids'])) {
        die("Не удалось получить ставки по кампании {$campaignId}: " . $api->getLastError());
    }

    $formattedBids = $api->formatBids($bidsResponse);

    // Собираем список ID ключей
    $keywordIds = [];
    foreach ($formattedBids as $item) {
        if (!empty($item['keyword_id'])) {
            $keywordIds[] = (int)$item['keyword_id'];
        }
    }
    $keywordIds = array_values(array_unique($keywordIds));

    if (empty($keywordIds)) {
        die("В кампании {$campaignId} не найдено ключевых слов.");
    }

    // 2. Получаем тексты ключевых слов
    $kwApi = new KeywordsAPI($token);
    $keywordTexts = $kwApi->getTexts($keywordIds);

    // 3. Получаем аукцион по трафику (KeywordBids.get)
    $keywordBidsResponse = $api->getKeywordBids($keywordIds);
    $trafficMap = []; // keyword_id => массив auction_bids, отсортированный по traffic_volume DESC
    if ($keywordBidsResponse !== false) {
        $formattedKeywordBids = $api->formatKeywordBids($keywordBidsResponse);
        foreach ($formattedKeywordBids as $kb) {
            $kid = $kb['keyword_id'] ?? null;
            if ($kid && !empty($kb['search']['auction_bids'])) {
                $items = $kb['search']['auction_bids'];
                // API не гарантирует порядок — сортируем по убыванию трафика.
                // Это критично для PHP-матчинга: первое совпадение yourBid >= bid_rubles
                // должно соответствовать наивысшей достижимой позиции, а не произвольной.
                usort($items, fn($a, $b) => ($b['traffic_volume'] ?? 0) <=> ($a['traffic_volume'] ?? 0));
                $trafficMap[$kid] = $items;
            }
        }
    }

    // 4. Собираем итоговый массив
    foreach ($formattedBids as $item) {
        $kid = $item['keyword_id'];
        if (!$kid) {
            continue;
        }

        $keywordText = $keywordTexts[$kid] ?? '';
        $yourBid     = $item['bid_rubles'] ?? null;

        // Базовая цена (текущая списываемая)
        $basePrice = $item['current_search_price_rubles'] ?? null;

        $percent = null;
        $price   = $basePrice;

        // Если есть аукцион по трафику — ищем точку, соответствующую нашей ставке
        if ($yourBid !== null && isset($trafficMap[$kid])) {
            foreach ($trafficMap[$kid] as $ta) {
                if (!isset($ta['bid_rubles'], $ta['traffic_volume'], $ta['price_rubles'])) {
                    continue;
                }
                if ($yourBid >= $ta['bid_rubles']) {
                    $percent = $ta['traffic_volume'];
                    $price   = $ta['price_rubles'];
                    break;
                }
            }
        }

        $resultRows[] = [
            'id'         => $kid,
            'keyword'    => $keywordText,
            'percent'    => $percent,
            'bid'        => $yourBid,
            'price'      => $price,
            'adgroup_id' => $item['adgroup_id'] ?? null,
            'traffic_auction' => $trafficMap[$kid] ?? null, // Сохраняем массив auction_bids для каждого ключа
        ];
    }

    // Сортировка по цене (от меньшей к большей)
    usort($resultRows, function ($a, $b) {
        $pa = $a['price'] ?? PHP_FLOAT_MAX;
        $pb = $b['price'] ?? PHP_FLOAT_MAX;
        if ($pa == $pb) {
            return 0;
        }
        return ($pa < $pb) ? -1 : 1;
    });

    // Сохраняем кеш
    file_put_contents(
        $jsonFile,
        json_encode($resultRows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    $lastSyncTime = date('d.m.Y H:i');
} else {
    // Читаем из кеша
    $json = file_get_contents($jsonFile);
    $resultRows = json_decode($json, true) ?: [];
}

// Группировка по ID группы объявлений
$groups = [];
foreach ($resultRows as $row) {
    $gid = $row['adgroup_id'] ?? 0;
    if (!isset($groups[$gid])) {
        $groups[$gid] = [];
    }
    $groups[$gid][] = $row;
}
ksort($groups);

// Получаем названия групп объявлений
$adGroupApi = new AdGroupsAPI($token);
$adGroupNames = $adGroupApi->getNames(array_keys($groups));

// 5. Сохранение в JSON (кеш уже сохранён выше при синхронизации)
// 6. Загрузка заметок кампании
$campaignNotes = ['entries' => []];
$notesFile = __DIR__ . "/campaign_{$campaignId}_notes.json";
if (file_exists($notesFile)) {
    $campaignNotes = json_decode(file_get_contents($notesFile), true) ?: $campaignNotes;
}
if (!isset($campaignNotes['entries'])) {
    $campaignNotes['entries'] = [];
}

// 7. Вывод минималистичной панели на Bootstrap
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Панель ставок кампании <?php echo htmlspecialchars($campaignId, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        html, body { height: 100%; }
        body { background-color: #f3f7fb; color: #0f172a; font-size: 13px; margin: 0; }
        .page-wrapper { /*min-height: 100vh;*/ display: flex; flex-direction: column; }
        .page-content { flex: 1 1 auto; }
        .card { background-color: #ffffff; border-color: #dbeafe; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06); }
        .card-header { background-color: #e0f2fe; border-bottom-color: #bfdbfe; }
        .table { color: #0f172a; font-size: 12px; }
        .table thead th { border-bottom-color: #dbeafe; color: #64748b; background-color: #eff6ff; }
        .table tbody td { border-top-color: #e5e7eb; background-color: #ffffff; }
        .table tbody tr:hover > td { background-color: #f1f5f9; }
        .row-selected > td { background-color: #dbeafe !important; }
        .group-title { font-size: 13px; font-weight: 600; color: #1d4ed8; }
        .search-input { background-color: #ffffff; border-color: #bfdbfe; color: #0f172a; }
        .search-input:focus { background-color: #ffffff; color: #0f172a; box-shadow: 0 0 0 1px #38bdf8; }
        .badge-adgroup { background-color: #38bdf8; color: #0f172a; font-size: 11px; }
        .text-muted-small { color: #94a3b8; font-size: 11px; }
        .kw-highlight { background-color: #fde68a; color: #0f172a; padding: 0 1px; border-radius: 2px; }
        .input-inline { background: transparent; border: none; padding: 0; height: auto; box-shadow: none; }
        .input-inline:focus { background: #e0f2fe; border: 1px solid #38bdf8; box-shadow: 0 0 0 1px #38bdf8; }
        /* убираем стрелки у number-инпутов */
        input[type=number]::-webkit-outer-spin-button,
        input[type=number]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type=number] {
            -moz-appearance: textfield;
        }
        .collapsed-group .card-body { display: none; }
        #clearSearchBtn {
            margin-top: 22px;
        }
        .notes-history-list { max-height: 280px; overflow-y: auto; }
        .notes-history-item {
            background: #f8fafc;
            border-left: 3px solid #f59e0b;
            padding: 8px 12px;
            margin-bottom: 8px;
            border-radius: 0 4px 4px 0;
            font-size: 12px;
        }
        .notes-history-item .notes-date { color: #94a3b8; font-size: 11px; }
    </style>
</head>
<body>
<div class="page-wrapper">
    <div class="page-content container-fluid py-3" data-campaign-id="<?php echo (int)$campaignId; ?>" data-notes="<?php echo htmlspecialchars(json_encode($campaignNotes['entries'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div class="flex-grow-1">
                            <div class="fw-semibold">
                                <?php echo $campaignName ? htmlspecialchars($campaignName, ENT_QUOTES, 'UTF-8') : 'Кампания'; ?>
                            </div>
                            <div class="text-muted-small">ID: <?php echo htmlspecialchars($campaignId, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if ($lastSyncTime): ?>
                                <div class="text-muted-small">Последняя синхронизация: <?php echo htmlspecialchars($lastSyncTime, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                            <?php if ($saveToFile): ?>
                                <div class="text-muted-small mb-1">JSON: <?php echo htmlspecialchars(basename($jsonFile), ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <form method="get" action="" class="d-flex gap-1 align-items-center">
                                <input type="number" name="campaign_id" class="form-control form-control-sm"
                                       style="max-width:140px;" value="<?php echo (int)$campaignId; ?>"
                                       min="1" required title="ID кампании">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Сменить кампанию">→</button>
                            </form>
                            <a href="?campaign_id=<?php echo (int)$campaignId; ?>&amp;sync=1" class="btn btn-sm btn-primary">Синхронизация</a>
                            <a href="#" id="massEditLink" class="btn btn-sm btn-outline-secondary">Массовое изменение</a>
                            <button type="button" id="massSaveBtn" class="btn btn-sm btn-success" disabled>
                                💾 Сохранить все
                            </button>
                            <a href="#" id="exportTxtLink" class="btn btn-sm btn-outline-primary">Экспорт ключей (TXT)</a>
                            <button type="button" id="notesBtn" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#notesModal" title="Заметки по кампании">
                                📓 Заметки
                            </button>
                            <button type="button" id="toggleAllGroupsBtn" class="btn btn-sm btn-outline-info">Свернуть все</button>
                            <button type="button" id="toggleSearchBtn" class="btn btn-sm btn-outline-success">
                                🔍 Поиск
                            </button>
                        </div>
                    </div>
                    <div id="searchBlock" class="mt-2" style="display: none;">
                        <div class="d-flex flex-column gap-2">
                            <div class="d-flex gap-2 align-items-center">
                                <div class="flex-grow-1">
                                    <label for="keywordFilter" class="form-label mb-1 text-muted-small">Фильтр по ключу:</label>
                                    <input type="text" id="keywordFilter" class="form-control form-control-sm search-input"
                                           placeholder="Введите часть ключа...">
                                </div>
                            </div>
                            <div class="d-flex gap-3 align-items-end flex-wrap">
                                <div>
                                    <label for="priceFilterValue" class="form-label mb-1 text-muted-small">Цена клика (₽):</label>
                                    <div class="d-flex gap-1">
                                        <select id="priceFilterOp" class="form-select form-select-sm search-input" style="max-width: 80px;">
                                            <option value=">">&gt;</option>
                                            <option value=">=">&ge;</option>
                                            <option value="<">&lt;</option>
                                            <option value="<=">&le;</option>
                                            <option value="=">=</option>
                                        </select>
                                        <input type="number" id="priceFilterValue" class="form-control form-control-sm search-input"
                                               placeholder="Например, 100" min="0" step="0.01" style="max-width: 130px;">
                                    </div>
                                </div>
                                <div>
                                    <label for="trafficFilterValue" class="form-label mb-1 text-muted-small">Трафик, %:</label>
                                    <div class="d-flex gap-1">
                                        <select id="trafficFilterOp" class="form-select form-select-sm search-input" style="max-width: 80px;">
                                            <option value=">">&gt;</option>
                                            <option value=">=">&ge;</option>
                                            <option value="<">&lt;</option>
                                            <option value="<=">&le;</option>
                                            <option value="=">=</option>
                                        </select>
                                        <input type="number" id="trafficFilterValue" class="form-control form-control-sm search-input"
                                               placeholder="Например, 50" min="0" max="200" step="1" style="max-width: 130px;">
                                    </div>
                                </div>
                                <div class="ms-auto">
                                    <button type="button" id="clearSearchBtn" class="btn btn-sm btn-outline-secondary">Очистить</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="filterCounter" class="text-muted-small mt-1 px-1"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mx-1">
        <div class="col-12">
            <?php foreach ($groups as $gid => $rows): ?>
                <?php $groupName = $adGroupNames[$gid] ?? null; ?>
                <div class="card mb-3 group-block" data-group-id="<?php echo (int)$gid; ?>">
                    <div class="card-header d-flex justify-content-between align-items-center py-2">
                        <div class="group-title">
                            <?php if ($groupName): ?>
                                <?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?>
                                <span class="text-muted-small ms-2">ID: <?php echo (int)$gid; ?></span>
                            <?php else: ?>
                                Группа ID: <?php echo (int)$gid; ?>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-pill badge-adgroup">
                                ключей: <?php echo count($rows); ?>
                            </span>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary btn-toggle-group">
                                Свернуть
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead>
                                <tr>
                                    <th style="width: 7%; cursor:pointer;" data-sort-col="id">ID</th>
                                    <th style="width: 35%; cursor:pointer;" data-sort-col="keyword">Ключ</th>
                                    <th style="width: 9%; cursor:pointer;" data-sort-col="percent">Трафик, %</th>
                                    <th style="width: 13%; cursor:pointer;" data-sort-col="bid">Ставка</th>
                                    <th style="width: 13%; cursor:pointer;" data-sort-col="price">Цена</th>
                                    <th style="width: 10%;">Аукцион</th>
                                    <th style="width: 13%;">Обновить</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr class="keyword-row"
                                        data-keyword="<?php echo htmlspecialchars(mb_strtolower($row['keyword']), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-traffic-auction="<?php echo htmlspecialchars(json_encode($row['traffic_auction'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                                        <td><?php echo (int)$row['id']; ?></td>
                                        <td class="kw-text"
                                            data-text="<?php echo htmlspecialchars($row['keyword'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($row['keyword'], ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td>
                                            <input type="number"
                                                   class="form-control form-control-sm input-inline input-percent"
                                                   value="<?php echo $row['percent'] !== null ? (int)$row['percent'] : ''; ?>"
                                                   data-original="<?php echo $row['percent'] !== null ? (int)$row['percent'] : ''; ?>"
                                                   min="0" max="200" step="1"
                                                   placeholder="—">
                                        </td>
                                        <td>
                                            <input type="number"
                                                   class="form-control form-control-sm input-inline input-bid"
                                                   value="<?php echo $row['bid'] !== null ? number_format($row['bid'], 2, '.', '') : ''; ?>"
                                                   data-original="<?php echo $row['bid'] !== null ? number_format($row['bid'], 2, '.', '') : ''; ?>"
                                                   min="0" step="0.01"
                                                   placeholder="—">
                                        </td>
                                        <td class="td-price" data-price="<?php echo $row['price'] !== null ? number_format($row['price'], 2, '.', '') : ''; ?>">
                                            <?php echo price_fmt($row['price']); ?>
                                        </td>
                                        <td>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-info btn-auction"
                                                    data-keyword-id="<?php echo (int)$row['id']; ?>"
                                                    data-keyword-text="<?php echo htmlspecialchars($row['keyword'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#auctionModal">
                                                📊
                                            </button>
                                        </td>
                                        <td>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary btn-update"
                                                    data-keyword-id="<?php echo (int)$row['id']; ?>"
                                                    disabled>
                                                Обновить
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Модальное окно массового редактирования -->
    <div class="modal fade" id="massEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Массовое изменение ставок</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted-small mb-2">
                        Изменения будут применены ко всем <strong>видимым</strong> ключам (с учётом фильтра по ключу).
                    </p>
                    <div class="mb-3">
                        <label class="form-label mb-1 text-muted-small">Режим</label><br>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="massMode" id="massModeTraffic" value="traffic" checked>
                            <label class="form-check-label" for="massModeTraffic">% трафика</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="massMode" id="massModeBid" value="bid">
                            <label class="form-check-label" for="massModeBid">Ставка (₽)</label>
                        </div>
                    </div>

                    <!-- Ползунок — только для режима "% трафика" -->
                    <div class="mb-2" id="massSliderContainer">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0 text-muted-small">Объём трафика</label>
                            <strong class="text-primary" id="massSliderPct">75%</strong>
                        </div>
                        <input type="range" class="form-range" id="massTrafficSlider" min="5" max="139" step="1" value="75">
                        <div class="d-flex justify-content-between text-muted mb-1" style="font-size:0.72rem">
                            <span>5%</span><span>50%</span><span>100%</span><span>139%</span>
                        </div>
                        <div id="massSliderPreview" class="px-2 py-1 rounded small" style="background:#eef2ff; display:none">
                            1-й видимый ключ — ставка ≈ <strong id="prevBid">—</strong> &nbsp;·&nbsp; цена ≈ <strong id="prevPrice">—</strong>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="massValue" class="form-label mb-1 text-muted-small" id="massValueLabel">% трафика (точное значение)</label>
                        <input type="number" class="form-control form-control-sm" id="massValue" placeholder="5–139" min="5" max="139" step="1" value="75">
                    </div>
                    <div class="mb-2" id="massMaxBidContainer" style="display: none;">
                        <label for="massMaxBid" class="form-label mb-1 text-muted-small">Макс. ставка (₽)</label>
                        <input type="number" class="form-control form-control-sm" id="massMaxBid" step="0.01" min="0" placeholder="Например, 100.00">
                        <small class="text-muted">Если ставка по объёму трафика больше этой суммы, будет установлена максимальная ставка</small>
                    </div>
                    <div class="mb-2" id="massAdjustmentContainer" style="display: none;">
                        <label for="massAdjustment" class="form-label mb-1 text-muted-small">Корректировка (₽)</label>
                        <input type="number" class="form-control form-control-sm" id="massAdjustment" step="0.01" placeholder="Например, 5.00">
                        <small class="text-muted">Добавляется к ставке по объёму трафика (может быть отрицательной)</small>
                    </div>
                    <div class="mb-2">
                        <label for="massAutotargetingBid" class="form-label mb-1 text-muted-small">Ставка для автотаргетинга (₽)</label>
                        <input type="number" class="form-control form-control-sm" id="massAutotargetingBid" step="0.01" min="0" placeholder="Например, 50.00">
                        <small class="text-muted">Ставка для ключевых слов с "---autotargeting"</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-sm btn-primary" id="massApplyBtn">Применить к видимым</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно заметок (блокнот кампании) -->
    <div class="modal fade" id="notesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">📓 Заметки по кампании</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="notesTextarea" class="form-label text-muted-small">Добавить запись</label>
                        <textarea id="notesTextarea" class="form-control" rows="4" placeholder="Опишите изменения, задачи или важные моменты по проекту..."></textarea>
                        <button type="button" id="notesSaveBtn" class="btn btn-sm btn-primary mt-2">Сохранить запись</button>
                    </div>
                    <hr>
                    <div class="notes-history-section">
                        <label class="form-label text-muted-small mb-2">Хронология изменений</label>
                        <div id="notesHistoryList" class="notes-history-list">
                            <!-- Записи подставляются через JS -->
                        </div>
                        <div id="notesHistoryEmpty" class="text-muted small text-center py-3" style="display:none;">
                            Пока нет записей. Добавьте первую заметку выше.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно аукциона -->
    <div class="modal fade" id="auctionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="auctionModalTitle">Аукцион по ключевому слову</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Трафик, %</th>
                                    <th>Ставка (₽)</th>
                                    <th>Цена клика (₽)</th>
                                </tr>
                            </thead>
                            <tbody id="auctionModalBody">
                                <tr>
                                    <td colspan="3" class="text-center text-muted">Загрузка...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                </div>
            </div>
        </div>
    </div>

</div> <!-- /page-content -->
</div> <!-- /page-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    /**
     * Найти ставку для заданного % трафика в массиве auction_bids.
     * Алгоритм: точное совпадение → ближайший порог выше → максимальная ставка.
     *
     * @param  {Array}       trafficAuction  [{traffic_volume, bid_rubles, ...}]
     * @param  {number}      targetPercent
     * @returns {number|null}  ставка в рублях или null если данных нет
     */
    /** Catmull-Rom сплайн для одного параметра */
    function catmullRomInterp(t, p0, p1, p2, p3) {
        const v0 = (p2 - p0) * 0.5;
        const v1 = (p3 - p1) * 0.5;
        const t2 = t * t, t3 = t * t2;
        return (2*p1 - 2*p2 + v0 + v1)*t3 + (-3*p1 + 3*p2 - 2*v0 - v1)*t2 + v0*t + p1;
    }

    /**
     * Интерполирует ставку и цену для заданного объёма трафика.
     * Возвращает {bid, price} или null если данных нет.
     * Для разрывов ≤50 единиц — линейная интерполяция.
     * Для разрывов >50 — Catmull-Rom сплайн (из expert.php).
     */
    function interpolateForTraffic(trafficAuction, targetTV) {
        if (!Array.isArray(trafficAuction) || trafficAuction.length === 0) return null;
        const sorted = trafficAuction
            .filter(d => d && typeof d.traffic_volume === 'number' && typeof d.bid_rubles === 'number')
            .sort((a, b) => a.traffic_volume - b.traffic_volume);
        if (sorted.length === 0) return null;

        // Точное совпадение
        const exact = sorted.find(d => d.traffic_volume === targetTV);
        if (exact) return { bid: exact.bid_rubles, price: exact.price_rubles ?? exact.bid_rubles };

        // Граничные случаи
        if (targetTV <= sorted[0].traffic_volume)
            return { bid: sorted[0].bid_rubles, price: sorted[0].price_rubles ?? sorted[0].bid_rubles };
        const last = sorted[sorted.length - 1];
        if (targetTV >= last.traffic_volume)
            return { bid: last.bid_rubles, price: last.price_rubles ?? last.bid_rubles };

        // Находим соседей
        const idx = sorted.findIndex(d => d.traffic_volume > targetTV);
        const p1 = sorted[idx - 1], p2 = sorted[idx];
        if (!p1 || !p2) return null;

        const t   = (targetTV - p1.traffic_volume) / (p2.traffic_volume - p1.traffic_volume);
        const gap = p2.traffic_volume - p1.traffic_volume;
        let bid, price;

        if (gap > 50) {
            // Catmull-Rom для больших разрывов
            const p0 = sorted[Math.max(0, idx - 2)];
            const p3 = sorted[Math.min(sorted.length - 1, idx + 1)];
            bid   = catmullRomInterp(t, p0.bid_rubles,                         p1.bid_rubles,                         p2.bid_rubles,                         p3.bid_rubles);
            price = catmullRomInterp(t, p0.price_rubles ?? p0.bid_rubles, p1.price_rubles ?? p1.bid_rubles, p2.price_rubles ?? p2.bid_rubles, p3.price_rubles ?? p3.bid_rubles);
        } else {
            // Линейная для малых разрывов
            bid   = p1.bid_rubles + t * (p2.bid_rubles - p1.bid_rubles);
            price = (p1.price_rubles ?? p1.bid_rubles) + t * ((p2.price_rubles ?? p2.bid_rubles) - (p1.price_rubles ?? p1.bid_rubles));
        }
        return { bid: Math.max(0, bid), price: Math.max(0, price) };
    }

    /** Возвращает интерполированную ставку для заданного % трафика (или null). */
    function findBidForTraffic(trafficAuction, targetPercent) {
        const r = interpolateForTraffic(trafficAuction, targetPercent);
        return r ? r.bid : null;
    }

    (function () {
        const input = document.getElementById('keywordFilter');
        const priceInput = document.getElementById('priceFilterValue');
        const priceOpSelect = document.getElementById('priceFilterOp');
        const trafficInput = document.getElementById('trafficFilterValue');
        const trafficOpSelect = document.getElementById('trafficFilterOp');
        const filterCounter = document.getElementById('filterCounter');
        const searchBlock = document.getElementById('searchBlock');
        const toggleSearchBtn = document.getElementById('toggleSearchBtn');
        const clearSearchBtn = document.getElementById('clearSearchBtn');
        
        // Вспомогательная функция сравнения
        function cmp(a, op, b) {
            switch (op) {
                case '>':  return a > b;
                case '>=': return a >= b;
                case '<':  return a < b;
                case '<=': return a <= b;
                case '=':  return a === b;
                default:   return a > b;
            }
        }

        // Функция фильтрации
        function applyFilter() {
            const q          = input          ? input.value.toLowerCase().trim() : '';
            const priceRaw   = priceInput     ? String(priceInput.value).trim()  : '';
            const priceVal   = priceRaw       ? parseFloat(priceRaw.replace(',', '.'))   : null;
            const priceOp    = priceOpSelect  ? priceOpSelect.value  : '>';
            const traffRaw   = trafficInput   ? String(trafficInput.value).trim() : '';
            const traffVal   = traffRaw       ? parseFloat(traffRaw.replace(',', '.'))   : null;
            const traffOp    = trafficOpSelect ? trafficOpSelect.value : '>';

            const rows   = document.querySelectorAll('.keyword-row');
            const groups = document.querySelectorAll('.group-block');

            rows.forEach(row => {
                const kwAttr   = row.getAttribute('data-keyword') || '';
                const kwCell   = row.querySelector('.kw-text');
                const origText = kwCell ? (kwCell.getAttribute('data-text') || '') : '';

                let match = !q || kwAttr.indexOf(q) !== -1;

                // Фильтр по цене клика
                if (match && priceVal !== null && !Number.isNaN(priceVal)) {
                    const priceCell = row.querySelector('.td-price');
                    const rowPrice  = priceCell ? parseFloat(priceCell.getAttribute('data-price') || '') : NaN;
                    match = !Number.isNaN(rowPrice) && cmp(rowPrice, priceOp, priceVal);
                }

                // Фильтр по трафику, %
                if (match && traffVal !== null && !Number.isNaN(traffVal)) {
                    const pctEl    = row.querySelector('.input-percent');
                    const pctStr   = pctEl ? pctEl.value : '';
                    const rowTraff = pctStr !== '' ? parseFloat(pctStr) : NaN;
                    match = !Number.isNaN(rowTraff) && cmp(rowTraff, traffOp, traffVal);
                }

                row.style.display = match ? '' : 'none';

                if (!kwCell) return;

                // Подсветка вхождения в тексте ключа
                if (q && match) {
                    const idx = origText.toLowerCase().indexOf(q);
                    if (idx !== -1) {
                        const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        kwCell.innerHTML =
                            esc(origText.substring(0, idx)) +
                            '<span class="kw-highlight">' + esc(origText.substring(idx, idx + q.length)) + '</span>' +
                            esc(origText.substring(idx + q.length));
                    } else {
                        kwCell.textContent = origText;
                    }
                } else {
                    kwCell.textContent = origText;
                }
            });

            // Прячем группы без видимых строк
            let totalRows   = 0;
            let totalGroups = 0;
            groups.forEach(group => {
                const vis = group.querySelectorAll('.keyword-row:not([style*="display: none"])').length;
                group.style.display = vis ? '' : 'none';
                if (vis) { totalGroups++; totalRows += vis; }
            });

            // Счётчик результатов
            if (filterCounter) {
                filterCounter.textContent = `Показано: ${totalRows} ключей в ${totalGroups} группах`;
            }
        }
        
        if (input)          { input.addEventListener('input', applyFilter); }
        if (priceInput)     { priceInput.addEventListener('input', applyFilter); }
        if (priceOpSelect)  { priceOpSelect.addEventListener('change', applyFilter); }
        if (trafficInput)   { trafficInput.addEventListener('input', applyFilter); }
        if (trafficOpSelect){ trafficOpSelect.addEventListener('change', applyFilter); }

        // Показываем начальный счётчик (без фильтров)
        applyFilter();

        // Кнопка раскрытия/сворачивания блока поиска
        if (toggleSearchBtn && searchBlock) {
            toggleSearchBtn.addEventListener('click', function () {
                const isVisible = searchBlock.style.display !== 'none';
                searchBlock.style.display = isVisible ? 'none' : 'block';
                if (!isVisible && input) {
                    setTimeout(() => input.focus(), 100);
                }
            });
        }
        
        // Кнопка "Очистить"
        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function () {
                if (input)          { input.value = ''; }
                if (priceInput)     { priceInput.value = ''; }
                if (priceOpSelect)  { priceOpSelect.value = '>'; }
                if (trafficInput)   { trafficInput.value = ''; }
                if (trafficOpSelect){ trafficOpSelect.value = '>'; }
                applyFilter();
                if (input) { input.focus(); }
            });
        }

        // Клик по строке — выделение/снятие выделения (кроме кликов по инпутам/кнопкам)
        document.querySelectorAll('.keyword-row').forEach(row => {
            row.addEventListener('click', function (e) {
                if (e.target.closest('input,button')) return;
                this.classList.toggle('row-selected');
            });
        });

        // Отслеживание изменений и активация кнопки "Обновить"
        function updateRowState(row) {
            const percentInput = row.querySelector('.input-percent');
            const bidInput     = row.querySelector('.input-bid');
            const btn          = row.querySelector('.btn-update');

            if (!btn) return;

            const percentOrig = percentInput ? (percentInput.getAttribute('data-original') || '') : '';
            const bidOrig     = bidInput ? (bidInput.getAttribute('data-original') || '') : '';

            const percentVal = percentInput ? (percentInput.value || '') : '';
            const bidVal     = bidInput ? (bidInput.value || '') : '';

            const dirtyPercent = percentInput && percentVal !== percentOrig;
            const dirtyBid     = bidInput && bidVal !== bidOrig;

            row.dataset.dirtyPercent = dirtyPercent ? '1' : '0';
            row.dataset.dirtyBid     = dirtyBid ? '1' : '0';

            btn.disabled = !(dirtyPercent || dirtyBid);
            
            // Обновляем состояние кнопки "Сохранить все"
            updateMassSaveButton();
        }
        
        // Проверка наличия измененных строк и активация кнопки "Сохранить все"
        function updateMassSaveButton() {
            const massSaveBtn = document.getElementById('massSaveBtn');
            if (!massSaveBtn) return;
            
            // Ищем все строки с активными кнопками "Обновить"
            const changedRows = [];
            document.querySelectorAll('.keyword-row').forEach(row => {
                const btn = row.querySelector('.btn-update');
                if (btn && !btn.disabled) {
                    changedRows.push(row);
                }
            });
            
            massSaveBtn.disabled = changedRows.length === 0;
            if (changedRows.length > 0) {
                massSaveBtn.textContent = `💾 Сохранить все (${changedRows.length})`;
            } else {
                massSaveBtn.textContent = '💾 Сохранить все';
            }
        }

        document.querySelectorAll('.keyword-row').forEach(row => {
            const percentInput = row.querySelector('.input-percent');
            const bidInput     = row.querySelector('.input-bid');

            if (percentInput) {
                percentInput.addEventListener('input', () => updateRowState(row));
            }
            if (bidInput) {
                bidInput.addEventListener('input', () => updateRowState(row));
            }

            updateRowState(row);
        });
        
        // Инициализация состояния кнопки "Сохранить все"
        updateMassSaveButton();
        
        // Функция для обновления одной ставки (вынесена для переиспользования)
        /**
         * Вычисляет bid-значение из состояния строки без отправки запроса.
         * Возвращает {keywordId, value, keywordText} или null если данных нет.
         */
        function collectBidFromRow(row, maxBidLimit = null) {
            const keywordId    = parseInt(row.querySelector('td').textContent) || 0;
            const percentInput = row.querySelector('.input-percent');
            const bidInput     = row.querySelector('.input-bid');
            const keywordText  = row.querySelector('.btn-auction')?.getAttribute('data-keyword-text') || '';

            const dirtyPercent = row.dataset.dirtyPercent === '1';
            const dirtyBid     = row.dataset.dirtyBid     === '1';

            let value = null;

            if (dirtyPercent && percentInput && percentInput.value !== '') {
                const bidValue    = bidInput ? parseFloat(bidInput.value) : null;
                const bidOriginal = bidInput ? parseFloat(bidInput.getAttribute('data-original') || '0') : 0;

                if (bidValue !== null && !isNaN(bidValue) && bidValue > 0 && bidValue !== bidOriginal) {
                    value = bidValue;
                } else {
                    const trafficAuctionJson = row.getAttribute('data-traffic-auction');
                    if (trafficAuctionJson) {
                        try {
                            const trafficAuction = JSON.parse(trafficAuctionJson);
                            const foundBid = findBidForTraffic(trafficAuction, parseFloat(percentInput.value));
                            if (foundBid !== null && foundBid > 0) {
                                value = (maxBidLimit !== null && maxBidLimit > 0 && foundBid > maxBidLimit)
                                    ? maxBidLimit
                                    : foundBid;
                                if (bidInput) bidInput.value = String(value.toFixed(2));
                            }
                        } catch (e) { /* ignore */ }
                    }
                }
            } else if (dirtyBid && bidInput && bidInput.value !== '') {
                value = parseFloat(bidInput.value);
            }

            if (!keywordId || value === null || value <= 0) return null;
            return { keywordId, value, keywordText };
        }

        /** Применяет успешный результат к строке (сбрасывает dirty-флаги). */
        function applyBidSuccess(row, value) {
            const percentInput = row.querySelector('.input-percent');
            const bidInput     = row.querySelector('.input-bid');
            if (row.dataset.dirtyPercent === '1' && percentInput) {
                percentInput.setAttribute('data-original', percentInput.value);
                if (bidInput) bidInput.setAttribute('data-original', String(parseFloat(value).toFixed(2)));
            }
            if (row.dataset.dirtyBid === '1' && bidInput) {
                bidInput.setAttribute('data-original', bidInput.value);
            }
            updateRowState(row);
        }

        /** Одиночное обновление ставки (кнопка «Обновить» в строке). */
        function updateSingleBid(row, onSuccess, onError, maxBidLimit = null) {
            const item = collectBidFromRow(row, maxBidLimit);
            if (!item) {
                if (onError) onError('Некорректные данные');
                return;
            }

            fetch('keyword_bidder.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({ keywordId: String(item.keywordId), mode: 'bid', value: String(item.value) })
            })
                .then(r => r.json())
                .then(data => {
                    if (data && data.ok) {
                        applyBidSuccess(row, item.value);
                        if (onSuccess) onSuccess();
                    } else {
                        if (onError) onError(data && data.error ? data.error : 'Ошибка обновления');
                    }
                })
                .catch(err => {
                    console.error(err);
                    if (onError) onError('Ошибка сети');
                });
        }
        
        // Кнопка массового сохранения
        const massSaveBtn = document.getElementById('massSaveBtn');
        if (massSaveBtn) {
            massSaveBtn.addEventListener('click', function () {
                if (this.disabled) return;
                
                // Собираем все строки с активными кнопками "Обновить"
                const changedRows = [];
                document.querySelectorAll('.keyword-row').forEach(row => {
                    const btn = row.querySelector('.btn-update');
                    if (btn && !btn.disabled) {
                        changedRows.push(row);
                    }
                });
                
                if (changedRows.length === 0) {
                    alert('Нет измененных ставок для сохранения');
                    return;
                }
                
                // Подтверждение
                if (!confirm(`Сохранить ${changedRows.length} измененных ставок?`)) {
                    return;
                }
                
                // Блокируем кнопку
                this.disabled = true;
                const originalText = this.textContent;
                this.textContent = '⏳ Сохранение...';
                
                // Собираем payload из DOM — ставки уже вычислены на клиенте
                const payload  = [];
                const rowMap   = new Map(); // keywordId → row
                changedRows.forEach(row => {
                    const item = collectBidFromRow(row);
                    if (item) {
                        payload.push(item);
                        rowMap.set(item.keywordId, row);
                    }
                });

                if (payload.length === 0) {
                    massSaveBtn.disabled = false;
                    massSaveBtn.textContent = originalText;
                    alert('Нет корректных данных для обновления');
                    return;
                }

                massSaveBtn.textContent = `⏳ Отправка ${payload.length} ставок...`;

                // Один запрос вместо N последовательных
                fetch('bid_batch.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                    body: JSON.stringify(payload)
                })
                    .then(r => r.json())
                    .then(data => {
                        massSaveBtn.disabled = false;
                        if (data && data.ok) {
                            const errIds = new Set((data.errors || []).map(e => e.keywordId));
                            payload.forEach(item => {
                                if (!errIds.has(item.keywordId)) {
                                    const row = rowMap.get(item.keywordId);
                                    if (row) applyBidSuccess(row, item.value);
                                } else {
                                    const e = (data.errors || []).find(e => e.keywordId === item.keywordId);
                                    console.warn(`KeywordId ${item.keywordId}: ${e?.error}`);
                                }
                            });
                            const errCount = (data.errors || []).length;
                            const okCount  = data.updated ?? (payload.length - errCount);
                            massSaveBtn.textContent = errCount === 0
                                ? `✅ Сохранено (${okCount})`
                                : `⚠️ Сохранено: ${okCount}, Ошибок: ${errCount}`;
                        } else {
                            massSaveBtn.textContent = `❌ Ошибка: ${data?.error || 'неизвестная'}`;
                        }
                        setTimeout(() => {
                            massSaveBtn.textContent = originalText;
                            updateMassSaveButton();
                        }, 3000);
                    })
                    .catch(err => {
                        console.error(err);
                        massSaveBtn.disabled = false;
                        massSaveBtn.textContent = '❌ Ошибка сети';
                        setTimeout(() => {
                            massSaveBtn.textContent = originalText;
                            updateMassSaveButton();
                        }, 3000);
                    });
            });
        }

        // Сортировка по колонкам в пределах каждой группы
        document.querySelectorAll('.group-block').forEach(group => {
            const table = group.querySelector('table');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const headers = table.querySelectorAll('th[data-sort-col]');

            headers.forEach(th => {
                th.addEventListener('click', function () {
                    const col = this.getAttribute('data-sort-col');
                    const rows = Array.from(tbody.querySelectorAll('tr.keyword-row'));
                    const currentDir = this.getAttribute('data-sort-dir') || 'asc';
                    const dir = currentDir === 'asc' ? 'desc' : 'asc';
                    this.setAttribute('data-sort-dir', dir);

                    rows.sort((a, b) => {
                        let aVal = 0, bVal = 0;
                        switch (col) {
                            case 'id':
                                aVal = parseInt(a.children[0].textContent) || 0;
                                bVal = parseInt(b.children[0].textContent) || 0;
                                break;
                            case 'keyword':
                                aVal = (a.children[1].textContent || '').toLowerCase();
                                bVal = (b.children[1].textContent || '').toLowerCase();
                                return dir === 'asc' ? aVal.localeCompare(bVal, 'ru') : bVal.localeCompare(aVal, 'ru');
                            case 'percent':
                                aVal = parseFloat(a.querySelector('.input-percent')?.value || '0') || 0;
                                bVal = parseFloat(b.querySelector('.input-percent')?.value || '0') || 0;
                                break;
                            case 'bid':
                                aVal = parseFloat(a.querySelector('.input-bid')?.value || '0') || 0;
                                bVal = parseFloat(b.querySelector('.input-bid')?.value || '0') || 0;
                                break;
                            case 'price':
                                aVal = parseFloat(a.querySelector('.td-price')?.getAttribute('data-price') || '0') || 0;
                                bVal = parseFloat(b.querySelector('.td-price')?.getAttribute('data-price') || '0') || 0;
                                break;
                        }
                        return dir === 'asc' ? aVal - bVal : bVal - aVal;
                    });

                    rows.forEach(r => tbody.appendChild(r));
                });
            });
        });

        // Экспорт видимых ключей в TXT с учётом текущего фильтра/сортировки
        const exportLink = document.getElementById('exportTxtLink');
        if (exportLink) {
            exportLink.addEventListener('click', function (e) {
                e.preventDefault();
                const container = document.querySelector('.container-fluid[data-campaign-id]');
                const campaignId = container ? container.getAttribute('data-campaign-id') : 'campaign';

                const lines = [];
                document.querySelectorAll('.group-block').forEach(group => {
                    if (group.style.display === 'none' || group.classList.contains('collapsed-group')) return;
                    const rows = group.querySelectorAll('.keyword-row');
                    rows.forEach(row => {
                        if (row.style.display === 'none') return;
                        const kwCell = row.querySelector('.kw-text');
                        if (!kwCell) return;
                        const text = (kwCell.getAttribute('data-text') || kwCell.textContent || '').trim();
                        if (text) {
                            lines.push(text);
                        }
                    });
                });

                if (!lines.length) {
                    return;
                }

                const blob = new Blob([lines.join('\n')], {type: 'text/plain;charset=utf-8'});
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `keywords_${campaignId}.txt`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });
        }

        // Массовое изменение ставок/трафика
        const massLink = document.getElementById('massEditLink');
        const massModalEl = document.getElementById('massEditModal');
        const massValueInput        = document.getElementById('massValue');
        const massValueLabel        = document.getElementById('massValueLabel');
        const massMaxBidInput       = document.getElementById('massMaxBid');
        const massMaxBidContainer   = document.getElementById('massMaxBidContainer');
        const massAdjustmentInput   = document.getElementById('massAdjustment');
        const massAdjustmentContainer = document.getElementById('massAdjustmentContainer');
        const massAutotargetingBidInput = document.getElementById('massAutotargetingBid');
        const massApplyBtn          = document.getElementById('massApplyBtn');
        const massSliderContainer   = document.getElementById('massSliderContainer');
        const massTrafficSlider     = document.getElementById('massTrafficSlider');
        const massSliderPct         = document.getElementById('massSliderPct');
        const massSliderPreview     = document.getElementById('massSliderPreview');
        const prevBidEl             = document.getElementById('prevBid');
        const prevPriceEl           = document.getElementById('prevPrice');

        let massModal;
        if (massModalEl) {
            massModal = new bootstrap.Modal(massModalEl);
        }

        /** Возвращает auction-данные первого видимого ключа или null */
        function getFirstVisibleAuction() {
            const rows = document.querySelectorAll('.keyword-row');
            for (const row of rows) {
                if (row.style.display === 'none') continue;
                const group = row.closest('.group-block');
                if (group && (group.style.display === 'none' || group.classList.contains('collapsed-group'))) continue;
                const json = row.getAttribute('data-traffic-auction');
                if (json) {
                    try { return JSON.parse(json); } catch (e) { /* skip */ }
                }
            }
            return null;
        }

        /** Обновляет превью ставки/цены под ползунком */
        function updateSliderPreview(tv) {
            if (!massSliderPreview || !prevBidEl || !prevPriceEl) return;
            const auction = getFirstVisibleAuction();
            if (!auction) { massSliderPreview.style.display = 'none'; return; }
            const result = interpolateForTraffic(auction, tv);
            if (!result) { massSliderPreview.style.display = 'none'; return; }
            prevBidEl.textContent   = result.bid.toFixed(2)   + '₽';
            prevPriceEl.textContent = result.price.toFixed(2) + '₽';
            massSliderPreview.style.display = 'block';
        }

        /** Переключает видимость элементов в зависимости от режима */
        function applyMassMode(mode) {
            const isTraffic = mode === 'traffic';
            if (massSliderContainer)    massSliderContainer.style.display    = isTraffic ? 'block' : 'none';
            if (massMaxBidContainer)    massMaxBidContainer.style.display    = isTraffic ? 'block' : 'none';
            if (massAdjustmentContainer) massAdjustmentContainer.style.display = isTraffic ? 'block' : 'none';
            if (massValueLabel) massValueLabel.textContent = isTraffic ? '% трафика (точное значение)' : 'Ставка (₽)';
            if (massValueInput) {
                massValueInput.placeholder = isTraffic ? '5–139' : 'Например, 100.00';
                massValueInput.step  = isTraffic ? '1'    : '0.01';
                massValueInput.min   = isTraffic ? '5'    : '0';
                massValueInput.max   = isTraffic ? '139'  : '';
                if (isTraffic && massTrafficSlider) {
                    massValueInput.value = massTrafficSlider.value;
                }
            }
        }

        // Ползунок → числовой input + превью
        if (massTrafficSlider) {
            massTrafficSlider.addEventListener('input', function () {
                const tv = parseInt(this.value);
                if (massSliderPct)   massSliderPct.textContent = tv + '%';
                if (massValueInput)  massValueInput.value = tv;
                updateSliderPreview(tv);
            });
        }

        // Числовой input → ползунок
        if (massValueInput) {
            massValueInput.addEventListener('input', function () {
                const mode = document.querySelector('input[name="massMode"]:checked')?.value || 'traffic';
                if (mode !== 'traffic') return;
                const tv = parseInt(this.value) || 75;
                const clamped = Math.min(139, Math.max(5, tv));
                if (massTrafficSlider) massTrafficSlider.value = clamped;
                if (massSliderPct)    massSliderPct.textContent = clamped + '%';
                updateSliderPreview(clamped);
            });
        }

        // Показываем/скрываем поля в зависимости от режима
        document.querySelectorAll('input[name="massMode"]').forEach(radio => {
            radio.addEventListener('change', function () {
                applyMassMode(this.value);
            });
        });

        if (massLink && massModal) {
            massLink.addEventListener('click', function (e) {
                e.preventDefault();
                if (massMaxBidInput)           massMaxBidInput.value = '';
                if (massAutotargetingBidInput) massAutotargetingBidInput.value = '';
                if (massAdjustmentInput)       massAdjustmentInput.value = '';

                const currentMode = document.querySelector('input[name="massMode"]:checked')?.value || 'traffic';
                applyMassMode(currentMode);

                // Инициализируем превью ползунка по текущему значению
                if (currentMode === 'traffic') {
                    const initTv = parseInt(massTrafficSlider?.value || 75);
                    if (massValueInput) massValueInput.value = initTv;
                    if (massSliderPct)  massSliderPct.textContent = initTv + '%';
                    updateSliderPreview(initTv);
                }

                massModal.show();
            });
        }

        if (massApplyBtn && massModal) {
            massApplyBtn.addEventListener('click', function () {
                const rawVal = massValueInput.value.trim();
                const val = parseFloat(rawVal.replace(',', '.'));
                if (!rawVal || isNaN(val) || val <= 0) {
                    return;
                }
                const mode = (document.querySelector('input[name="massMode"]:checked')?.value || 'traffic');
                
                // Получаем максимальную ставку для режима трафика
                let maxBidLimit = null;
                if (mode === 'traffic' && massMaxBidInput) {
                    const maxBidRaw = massMaxBidInput.value.trim();
                    if (maxBidRaw) {
                        const maxBidVal = parseFloat(maxBidRaw.replace(',', '.'));
                        if (!isNaN(maxBidVal) && maxBidVal > 0) {
                            maxBidLimit = maxBidVal;
                        }
                    }
                }
                
                // Получаем корректировку для режима трафика
                let adjustment = 0;
                if (mode === 'traffic' && massAdjustmentInput) {
                    const adjustmentRaw = massAdjustmentInput.value.trim();
                    if (adjustmentRaw) {
                        const adjustmentVal = parseFloat(adjustmentRaw.replace(',', '.'));
                        if (!isNaN(adjustmentVal)) {
                            adjustment = adjustmentVal;
                        }
                    }
                }
                
                // Получаем ставку для автотаргетинга
                let autotargetingBid = null;
                if (massAutotargetingBidInput) {
                    const autotargetingBidRaw = massAutotargetingBidInput.value.trim();
                    if (autotargetingBidRaw) {
                        const autotargetingBidVal = parseFloat(autotargetingBidRaw.replace(',', '.'));
                        if (!isNaN(autotargetingBidVal) && autotargetingBidVal > 0) {
                            autotargetingBid = autotargetingBidVal;
                        }
                    }
                }

                document.querySelectorAll('.group-block').forEach(group => {
                    if (group.style.display === 'none' || group.classList.contains('collapsed-group')) return;
                    const rows = group.querySelectorAll('.keyword-row');
                    rows.forEach(row => {
                        if (row.style.display === 'none') return;
                        
                        // Проверяем, является ли ключ автотаргетингом
                        const kwCell = row.querySelector('.kw-text');
                        const keywordText = kwCell ? (kwCell.getAttribute('data-text') || kwCell.textContent || '').trim() : '';
                        const isAutotargeting = keywordText.toLowerCase().indexOf('---autotargeting') !== -1;
                        
                        // Если это автотаргетинг и указана ставка для него - применяем её
                        if (isAutotargeting && autotargetingBid !== null && autotargetingBid > 0) {
                            const bidInput = row.querySelector('.input-bid');
                            if (bidInput) {
                                bidInput.value = String(autotargetingBid.toFixed(2));
                            }
                            // Для автотаргетинга не устанавливаем процент трафика
                            updateRowState(row);
                            return;
                        }
                        
                        if (mode === 'traffic') {
                            const percentInput = row.querySelector('.input-percent');
                            if (percentInput) {
                                percentInput.value = String(val);
                                
                                // Находим ставку по объему трафика и применяем корректировку/лимит
                                const trafficAuctionJson = row.getAttribute('data-traffic-auction');
                                let foundBid = null;

                                if (trafficAuctionJson) {
                                    try {
                                        foundBid = findBidForTraffic(JSON.parse(trafficAuctionJson), val);
                                    } catch (e) {
                                        foundBid = null;
                                    }
                                }

                                // Базовая ставка: сначала из аукциона, если есть, иначе из текущей/оригинальной ставки
                                let baseBid = null;
                                if (foundBid !== null && foundBid > 0) {
                                    baseBid = foundBid;
                                } else {
                                    const bidInput = row.querySelector('.input-bid');
                                    if (bidInput) {
                                        let current = parseFloat(String(bidInput.value).replace(',', '.')) || 0;
                                        if (!current) {
                                            const origRaw = bidInput.getAttribute('data-original') || '0';
                                            const orig = parseFloat(String(origRaw).replace(',', '.')) || 0;
                                            current = orig;
                                        }
                                        if (current > 0) {
                                            baseBid = current;
                                        }
                                    }
                                }

                                // Если так и не нашли базовую ставку — используем дефолт 13₽,
                                // чтобы такие ключи было легко найти и поправить.
                                if (baseBid === null || baseBid <= 0) {
                                    baseBid = 13;
                                }

                                // Применяем корректировку к базовой ставке
                                let finalBid = baseBid + adjustment;
                                
                                // Применяем ограничение максимальной ставки (если указано)
                                if (maxBidLimit !== null && maxBidLimit > 0 && finalBid > maxBidLimit) {
                                    finalBid = maxBidLimit;
                                }
                                
                                // Не позволяем ставке быть отрицательной или нулевой
                                if (finalBid <= 0) {
                                    finalBid = 0.01; // Минимальная ставка
                                }
                                
                                const bidInput = row.querySelector('.input-bid');
                                if (bidInput) {
                                    bidInput.value = String(finalBid.toFixed(2));
                                }
                            }
                        } else if (mode === 'bid') {
                            // Для автотаргетинга используем специальную ставку, если указана
                            if (isAutotargeting && autotargetingBid !== null && autotargetingBid > 0) {
                                const bidInput = row.querySelector('.input-bid');
                                if (bidInput) {
                                    bidInput.value = String(autotargetingBid.toFixed(2));
                                }
                            } else {
                                const bidInput = row.querySelector('.input-bid');
                                if (bidInput) {
                                    bidInput.value = String(val.toFixed(2));
                                }
                            }
                        }
                        updateRowState(row);
                    });
                });

                massModal.hide();
            });
        }

        // Обновление ставки по кнопке "Обновить"
        document.querySelectorAll('.btn-update').forEach(btn => {
            btn.addEventListener('click', function () {
                const row = this.closest('.keyword-row');
                if (!row) return;
                
                this.disabled = true;
                const originalText = this.textContent;
                this.textContent = '...';
                
                updateSingleBid(
                    row,
                    () => {
                        // Успех
                        this.textContent = 'OK';
                        setTimeout(() => {
                            this.textContent = originalText;
                            updateMassSaveButton();
                        }, 1000);
                    },
                    (error) => {
                        // Ошибка
                        this.disabled = false;
                        this.textContent = 'Ошибка';
                        alert('Ошибка обновления ставки: ' + error);
                        setTimeout(() => {
                            this.textContent = originalText;
                        }, 2000);
                    }
                );
            });
        });

        // Блокнот заметок по кампании
        const pageContent = document.querySelector('.page-content');
        const notesModal = document.getElementById('notesModal');
        const notesTextarea = document.getElementById('notesTextarea');
        const notesSaveBtn = document.getElementById('notesSaveBtn');
        const notesHistoryList = document.getElementById('notesHistoryList');
        const notesHistoryEmpty = document.getElementById('notesHistoryEmpty');

        function renderNotesHistory(entries) {
            if (!notesHistoryList || !notesHistoryEmpty) return;
            const list = Array.isArray(entries) ? entries : [];
            if (list.length === 0) {
                notesHistoryList.innerHTML = '';
                notesHistoryEmpty.style.display = 'block';
                return;
            }
            notesHistoryEmpty.style.display = 'none';
            // Показываем от новых к старым
            const sorted = [...list].reverse();
            notesHistoryList.innerHTML = sorted.map(entry => {
                const text = (entry.text || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
                const date = entry.created_at || '';
                return `<div class="notes-history-item"><div class="notes-date">${date}</div><div>${text}</div></div>`;
            }).join('');
        }

        if (pageContent) {
            const notesJson = pageContent.getAttribute('data-notes');
            if (notesJson) {
                try {
                    const entries = JSON.parse(notesJson);
                    renderNotesHistory(entries);
                } catch (e) { /* ignore */ }
            }
        }

        if (notesModal) {
            notesModal.addEventListener('show.bs.modal', function () {
                if (pageContent) {
                    const notesJson = pageContent.getAttribute('data-notes');
                    if (notesJson) {
                        try {
                            renderNotesHistory(JSON.parse(notesJson));
                        } catch (e) { /* ignore */ }
                    }
                }
            });
        }

        if (notesSaveBtn && notesTextarea && pageContent) {
            const campaignId = pageContent.getAttribute('data-campaign-id');
            notesSaveBtn.addEventListener('click', function () {
                const text = notesTextarea.value.trim();
                if (!text) return;
                if (!campaignId) return;

                // Отправляем запрос
                const formData = new FormData();
                formData.append('action', 'save_notes');
                formData.append('campaign_id', campaignId);
                formData.append('text', text);

                notesSaveBtn.disabled = true;
                notesSaveBtn.textContent = 'Сохранение...';

                fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.ok) {
                            notesTextarea.value = '';
                            pageContent.setAttribute('data-notes', JSON.stringify(data.entries || []));
                            renderNotesHistory(data.entries || []);
                            notesSaveBtn.textContent = 'Сохранено';
                        } else {
                            notesSaveBtn.textContent = 'Ошибка';
                        }
                    })
                    .catch(() => {
                        notesSaveBtn.textContent = 'Ошибка';
                    })
                    .finally(() => {
                        notesSaveBtn.disabled = false;
                        setTimeout(() => { notesSaveBtn.textContent = 'Сохранить запись'; }, 2000);
                    });
            });
        }

        // Кнопки аукциона - открытие попапа с таблицей ставок
        const auctionModal = document.getElementById('auctionModal');
        const auctionModalTitle = document.getElementById('auctionModalTitle');
        const auctionModalBody = document.getElementById('auctionModalBody');
        
        document.querySelectorAll('.btn-auction').forEach(btn => {
            btn.addEventListener('click', function () {
                const row = this.closest('.keyword-row');
                if (!row) return;
                
                const keywordId = this.getAttribute('data-keyword-id');
                const keywordText = this.getAttribute('data-keyword-text') || '';
                const trafficAuctionJson = row.getAttribute('data-traffic-auction');
                
                // Обновляем заголовок
                if (auctionModalTitle) {
                    auctionModalTitle.textContent = 'Аукцион: ' + keywordText + ' (ID: ' + keywordId + ')';
                }
                
                // Очищаем таблицу
                if (auctionModalBody) {
                    auctionModalBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Загрузка...</td></tr>';
                }
                
                // Парсим данные аукциона
                try {
                    const trafficAuction = JSON.parse(trafficAuctionJson || '[]');
                    
                    if (!Array.isArray(trafficAuction) || trafficAuction.length === 0) {
                        if (auctionModalBody) {
                            auctionModalBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Данные аукциона отсутствуют</td></tr>';
                        }
                        return;
                    }
                    
                    // Сортируем по убыванию трафика для удобства просмотра
                    const sorted = [...trafficAuction].sort((a, b) => {
                        const volA = a.traffic_volume || 0;
                        const volB = b.traffic_volume || 0;
                        return volB - volA;
                    });

                    // Текущая ставка из поля ввода строки
                    const currentBid = parseFloat(
                        (row.querySelector('.input-bid')?.value || '0').replace(',', '.')
                    );

                    // Находим позицию: первый элемент (сверху = больший трафик),
                    // где наша ставка >= ставки входа — это позиция, которую мы занимаем
                    let highlightIdx = -1;
                    if (currentBid > 0) {
                        for (let i = 0; i < sorted.length; i++) {
                            if (currentBid >= (sorted[i].bid_rubles || 0)) {
                                highlightIdx = i;
                                break;
                            }
                        }
                    }

                    // Заполняем таблицу
                    if (auctionModalBody) {
                        auctionModalBody.innerHTML = sorted.map((item, idx) => {
                            const traffic = item.traffic_volume !== null && item.traffic_volume !== undefined ? item.traffic_volume : '—';
                            const bid = item.bid_rubles !== null && item.bid_rubles !== undefined ?
                                parseFloat(item.bid_rubles).toFixed(2) : '—';
                            const price = item.price_rubles !== null && item.price_rubles !== undefined ?
                                parseFloat(item.price_rubles).toFixed(2) : '—';
                            const highlight = idx === highlightIdx ? ' class="table-success fw-semibold"' : '';
                            const marker   = idx === highlightIdx ? ' ◀' : '';

                            return `<tr${highlight}>
                                <td>${traffic}${marker}</td>
                                <td>${bid !== '—' ? bid + '₽' : bid}</td>
                                <td>${price !== '—' ? price + '₽' : price}</td>
                            </tr>`;
                        }).join('');
                    }
                } catch (e) {
                    console.error('Ошибка парсинга данных аукциона:', e);
                    if (auctionModalBody) {
                        auctionModalBody.innerHTML = '<tr><td colspan="3" class="text-center text-danger">Ошибка загрузки данных</td></tr>';
                    }
                }
            });
        });

        // Свернуть / развернуть группы
        document.querySelectorAll('.btn-toggle-group').forEach(btn => {
            btn.addEventListener('click', function () {
                const group = this.closest('.group-block');
                if (!group) return;
                const collapsed = group.classList.toggle('collapsed-group');
                this.textContent = collapsed ? 'Развернуть' : 'Свернуть';
            });
        });

        // Кнопка "Свернуть все" / "Развернуть все"
        const toggleAllBtn = document.getElementById('toggleAllGroupsBtn');
        if (toggleAllBtn) {
            toggleAllBtn.addEventListener('click', function () {
                const allGroups = document.querySelectorAll('.group-block');
                if (allGroups.length === 0) return;

                // Определяем текущее состояние: если хотя бы одна группа развернута, считаем что нужно свернуть все
                let hasExpanded = false;
                allGroups.forEach(group => {
                    if (!group.classList.contains('collapsed-group')) {
                        hasExpanded = true;
                    }
                });

                // Сворачиваем или разворачиваем все группы
                allGroups.forEach(group => {
                    if (hasExpanded) {
                        // Сворачиваем все
                        group.classList.add('collapsed-group');
                        const btn = group.querySelector('.btn-toggle-group');
                        if (btn) {
                            btn.textContent = 'Развернуть';
                        }
                    } else {
                        // Разворачиваем все
                        group.classList.remove('collapsed-group');
                        const btn = group.querySelector('.btn-toggle-group');
                        if (btn) {
                            btn.textContent = 'Свернуть';
                        }
                    }
                });

                // Меняем текст кнопки
                this.textContent = hasExpanded ? 'Развернуть все' : 'Свернуть все';
            });
        }
    })();
</script>

</body>
</html>
