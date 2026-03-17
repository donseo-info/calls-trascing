<?php
/**
 * seed.php — добавление номеров в пул
 * Запускать: php seed.php
 * Редактируй массив $phones под свои реальные номера из Novofon
 */

require_once __DIR__ . '/rb.php';
require_once __DIR__ . '/config.php';

R::setup('sqlite:' . DB_PATH);

$phones = [
    '79581111111',
    '79582222222',
    '79583333333',
];

foreach ($phones as $phone) {
    $exists = R::findOne('phonepool', 'phone = ?', [$phone]);
    if (!$exists) {
        $p = R::dispense('phonepool');
        $p->phone      = $phone;
        $p->is_active  = 1;
        $p->created_at = date('Y-m-d H:i:s');
        R::store($p);
        echo "Добавлен: $phone" . PHP_EOL;
    } else {
        echo "Уже есть: $phone" . PHP_EOL;
    }
}

echo PHP_EOL . "Итого в пуле: " . R::count('phonepool', 'is_active = 1') . " номер(а)" . PHP_EOL;

R::close();
