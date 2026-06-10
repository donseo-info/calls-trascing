<?php
/**
 * init.php — единоразовый скрипт инициализации БД
 * Запускать: php init.php
 */

require_once __DIR__ . '/rb.php';
require_once __DIR__ . '/config.php';

R::setup('sqlite:' . DB_PATH);
R::freeze(false);

// --- sites: сайты (мультисайт) ---
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

// --- phonepool: пул подменных номеров (per-site) ---
R::exec("CREATE TABLE IF NOT EXISTS phonepool (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id     INTEGER,
    phone       TEXT NOT NULL UNIQUE,
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
)");

// --- sessions: привязка посетителя к номеру (per-site) ---
R::exec("CREATE TABLE IF NOT EXISTS sessions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id         INTEGER,
    session_cookie  TEXT NOT NULL UNIQUE,
    client_id       TEXT,
    ip              TEXT,
    phonepool_id    INTEGER,
    phone           TEXT,
    utm_source      TEXT,
    utm_medium      TEXT,
    utm_campaign    TEXT,
    utm_term        TEXT,
    utm_content     TEXT,
    landing_page    TEXT,
    referrer        TEXT,
    revealed_at     TEXT,
    expires_at      TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (phonepool_id) REFERENCES phonepool(id)
)");

// --- calls: лог звонков из вебхука Novofon (per-site) ---
R::exec("CREATE TABLE IF NOT EXISTS calls (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id         INTEGER,
    session_id      INTEGER,
    caller          TEXT,
    called          TEXT,
    direction       TEXT,
    call_time       TEXT,
    talk_duration   INTEGER,
    total_duration  INTEGER,
    wait_duration   INTEGER,
    call_session_id TEXT,
    employee_name   TEXT,
    scenario_name   TEXT,
    goal_sent       INTEGER NOT NULL DEFAULT 0,
    sent_client_id  TEXT,
    raw_data        TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (session_id) REFERENCES sessions(id)
)");

echo "БД инициализирована: " . DB_PATH . PHP_EOL;
echo "Таблицы созданы: sites, phonepool, sessions, calls" . PHP_EOL;

R::close();
