<?php
/**
 * migrate_multisite.php — миграция на мультисайтовую схему
 * Запускать один раз: php migrate_multisite.php
 *
 * - создаёт таблицу sites
 * - добавляет site_id в phonepool / sessions / calls
 * - создаёт дефолтный сайт #1 из констант config.php
 * - привязывает все существующие данные к сайту #1
 */

require_once __DIR__ . '/rb.php';
require_once __DIR__ . '/config.php';

R::setup('sqlite:' . DB_PATH);
R::freeze(false);

echo "=== Миграция на мультисайт ===" . PHP_EOL;

// 1. Таблица sites
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
    timezone              TEXT DEFAULT 'Europe/Moscow',
    is_active             INTEGER NOT NULL DEFAULT 1,
    created_at            TEXT NOT NULL DEFAULT (datetime('now'))
)");
echo "✓ Таблица sites" . PHP_EOL;

// 2. Колонки site_id
foreach (['phonepool', 'sessions', 'calls'] as $table) {
    try {
        R::exec("ALTER TABLE {$table} ADD COLUMN site_id INTEGER");
        echo "✓ {$table}.site_id добавлен" . PHP_EOL;
    } catch (Exception $e) {
        echo "· {$table}.site_id уже есть" . PHP_EOL;
    }
}

// 3. Дефолтный сайт #1 из config.php
$existing = R::getCell('SELECT COUNT(*) FROM sites');
if (!$existing) {
    $site = R::dispense('sites');
    $site->name                 = 'Основной сайт';
    $site->domain               = '';
    $site->site_key             = bin2hex(random_bytes(8));
    $site->metrika_counter_id   = defined('METRIKA_COUNTER_ID')   ? METRIKA_COUNTER_ID   : '';
    $site->metrika_access_token = defined('METRIKA_ACCESS_TOKEN') ? METRIKA_ACCESS_TOKEN : '';
    $site->metrika_goal_id      = defined('METRIKA_GOAL_ID')      ? METRIKA_GOAL_ID      : 'send_lead';
    $site->fallback_phone       = defined('FALLBACK_PHONE')       ? FALLBACK_PHONE       : '';
    $site->session_ttl_minutes  = defined('SESSION_TTL_MINUTES')  ? SESSION_TTL_MINUTES  : 10;
    $site->is_active            = 1;
    $site->created_at           = date('Y-m-d H:i:s');
    $siteId = R::store($site);
    echo "✓ Создан сайт #{$siteId} (ключ: {$site->site_key})" . PHP_EOL;
} else {
    $siteId = (int)R::getCell('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
    echo "· Сайты уже есть, использую #{$siteId}" . PHP_EOL;
}

// 4. Привязка существующих данных к сайту #1
foreach (['phonepool', 'sessions', 'calls'] as $table) {
    $n = R::exec("UPDATE {$table} SET site_id = ? WHERE site_id IS NULL", [$siteId]);
    echo "✓ {$table}: привязано к сайту #{$siteId}" . PHP_EOL;
}

echo "=== Готово ===" . PHP_EOL;
R::close();
