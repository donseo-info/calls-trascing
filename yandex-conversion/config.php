<?php
/**
 * Конфигурация для интеграции с Яндекс Метрикой
 * 
 * ВАЖНО: Не коммитьте этот файл в публичный репозиторий!
 * Добавьте config.php в .gitignore
 */

return [
    // OAuth токен для доступа к API Яндекс Метрики
    // Получить можно на https://oauth.yandex.ru/
    'yandex_metrica' => [
        'access_token' => 'y0__xClvatBGIaSPSDw9JWUFogXGTav5e_wSbeLD9UrUG48h1We',
        'client_id' => 'a738812d976e4e288cb4f61ab47b017b',
        'client_secret' => '44827ee245c14f55900f1cf2c61d1a2c',
    ],

    // Конфигурация счетчиков Метрики для каждого сайта
    'site_counters' => [
        'sto.souzkachestva.ru' => '106337966',
    ],

    // Конфигурация целей для каждого домена Битрикс24
    'goals_config' => [
        'astratest.bitrix24.ru' => [
            'NEW' => 'larin_new_lead',
            'CONVERTED' => 'larin_converce_lead',
        ],
        'ros-test.bitrix24.ru' => [
            'NEW' => 'larin_new_lead',
            'CONVERTED' => 'larin_converce_lead',
        ],
        'b24-ecx9cu.bitrix24.ru' => [
        ],
    ],

    // Настройки базы данных
    'database' => [
        'host' => 'localhost',
        'dbname' => 'susilknv_gate',
        'user' => 'susilknv_gate',
        'password' => 'f*GjmYk&FN1m',
    ],

    // Пути для сохранения данных
    'paths' => [
        'logs' => __DIR__ . '/logs',
        'last_conversions' => __DIR__ . '/logs/last_conversions.json',
        'csv_files' => __DIR__ . '/logs/csv_files',
    ],
];
