<?php
/**
 * cron/cleanup.php — освобождение просроченных сессий
 *
 * Запускать каждые 5 минут:
 * *\/5 * * * * php /path/to/call-tracking/cron/cleanup.php >> /path/to/call-tracking/logs/cron.log 2>&1
 */

require_once dirname(__DIR__) . '/rb.php';
require_once dirname(__DIR__) . '/config.php';

R::setup('sqlite:' . DB_PATH);
R::freeze(true);

$now = date('Y-m-d H:i:s');

// Считаем сколько сессий просрочено
$expired = R::getCell(
    'SELECT COUNT(*) FROM sessions WHERE phonepool_id IS NOT NULL AND expires_at < ?',
    [$now]
);

if ($expired > 0) {
    // Освобождаем номера у просроченных сессий
    R::exec(
        'UPDATE sessions SET phonepool_id = NULL, phone = NULL WHERE phonepool_id IS NOT NULL AND expires_at < ?',
        [$now]
    );
    echo '[' . date('Y-m-d H:i:s') . '] Освобождено сессий: ' . $expired . PHP_EOL;
} else {
    echo '[' . date('Y-m-d H:i:s') . '] Просроченных сессий нет' . PHP_EOL;
}

// Статистика пула
$total = R::getCell('SELECT COUNT(*) FROM phonepool WHERE is_active = 1');
$busy  = R::getCell(
    'SELECT COUNT(DISTINCT phonepool_id) FROM sessions WHERE phonepool_id IS NOT NULL AND expires_at > ?',
    [$now]
);
$free  = $total - $busy;

echo '[' . date('Y-m-d H:i:s') . '] Пул: всего=' . $total . ' занято=' . $busy . ' свободно=' . $free . PHP_EOL;

R::close();
