<?php

// Путь к SQLite базе данных
define('DB_PATH', __DIR__ . '/db/calltracking.db');

// TTL сессии в минутах (как долго номер закреплён за посетителем)
define('SESSION_TTL_MINUTES', 15);

// Название цели в Яндекс.Метрике
define('METRIKA_GOAL_ID', 'send_lead');

// ID счётчика Яндекс.Метрики
define('METRIKA_COUNTER_ID', '107404488');

// Статичный номер-заглушка когда пул исчерпан (без трекинга)
define('FALLBACK_PHONE', '+79884007097');

// OAuth токен Яндекс.Метрики (https://oauth.yandex.ru/)
define('METRIKA_ACCESS_TOKEN', 'y0__xClvatBGIaSPSDw9JWUFogXGTav5e_wSbeLD9UrUG48h1We');
