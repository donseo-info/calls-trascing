<?php
/**
 * init.php — единоразовый скрипт инициализации БД
 * Запускать: php init.php
 */

require_once __DIR__ . '/rb.php';
require_once __DIR__ . '/config.php';

R::setup('sqlite:' . DB_PATH);
R::freeze(false);

// --- phonepool: пул подменных номеров ---
R::exec("CREATE TABLE IF NOT EXISTS phonepool (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    phone       TEXT NOT NULL UNIQUE,
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
)");

// --- sessions: привязка посетителя к номеру ---
R::exec("CREATE TABLE IF NOT EXISTS sessions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
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

// --- calls: лог звонков из вебхука Novofon ---
R::exec("CREATE TABLE IF NOT EXISTS calls (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
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
    raw_data        TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (session_id) REFERENCES sessions(id)
)");

echo "БД инициализирована: " . DB_PATH . PHP_EOL;
echo "Таблицы созданы: phonepool, sessions, calls" . PHP_EOL;

R::close();
