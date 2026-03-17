<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
/**
 * API для ручной передачи оффлайн конверсий в Яндекс Метрику
 * Поддерживает идентификаторы: ClientId, phones, emails, phones_md5, emails_md5
 */
require_once __DIR__ . '/vendor/autoload.php';
use BitrixIntegration\YandexMetricaIntegration;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Обработка preflight запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Только POST запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Получаем данные из запроса
$input = json_decode(file_get_contents('php://input'), true);

// Валидация входных данных
$errors = [];

// Проверяем наличие хотя бы одного идентификатора
$hasClientId = !empty($input['client_id']);
$hasPhone = !empty($input['phone']);
$hasEmail = !empty($input['email']);

if (!$hasClientId && !$hasPhone && !$hasEmail) {
    $errors[] = 'Необходимо указать хотя бы один идентификатор: ClientId, телефон или email';
}

if (empty($input['goal_name'])) {
    $errors[] = 'Название цели обязательно для заполнения';
}

if (empty($input['counter_id'])) {
    $errors[] = 'ID счетчика обязательно для заполнения';
}

if (empty($input['date_time'])) {
    $errors[] = 'Дата и время обязательны для заполнения';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// Загружаем конфигурацию
$config = require __DIR__ . '/config.php';
$accessToken = $config['yandex_metrica']['access_token'] ?? null;

if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Access token not configured']);
    exit;
}

// Инициализируем интеграцию с Яндекс Метрикой
$metrica = new YandexMetricaIntegration($accessToken);

// Преобразуем дату и время в Unix timestamp (секунды)
$timestamp = convertDateTimeToTimestamp($input['date_time']);

if ($timestamp === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Неверный формат даты и времени. Используйте формат: YYYY-MM-DD HH:MM:SS или Unix timestamp'
    ]);
    exit;
}

// Подготавливаем идентификаторы
$identifiers = [
    'use_md5' => !empty($input['use_md5']) // Опция использования MD5 хешей
];

if ($hasClientId) {
    $identifiers['client_id'] = trim($input['client_id']);
}

if ($hasPhone) {
    // Нормализуем телефон (убираем пробелы, скобки, дефисы)
    $phone = preg_replace('/[^0-9+]/', '', $input['phone']);
    $identifiers['phone'] = $phone;
}

if ($hasEmail) {
    $identifiers['email'] = trim($input['email']);
}

// Отправляем конверсию
$result = $metrica->sendConversionWithIdentifiers(
    $input['counter_id'],
    $input['goal_name'],
    $timestamp,
    $identifiers
);

// Логируем результат
logManualConversion([
    'identifiers' => $identifiers,
    'goal_name' => $input['goal_name'],
    'counter_id' => $input['counter_id'],
    'date_time' => $input['date_time'],
    'timestamp' => $timestamp,
    'result' => $result
]);

// Возвращаем результат
if ($result['success']) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Конверсия успешно отправлена в Яндекс Метрику',
        'data' => $result['sent_data']
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $result['error'] ?? 'Неизвестная ошибка',
        'details' => $result
    ]);
}

/**
 * Преобразует дату и время в Unix timestamp (секунды) для Яндекс Метрики
 * 
 * @param string|int $dateTime Дата и время в различных форматах или Unix timestamp
 * @return int|false Timestamp в секундах или false при ошибке
 */
function convertDateTimeToTimestamp($dateTime) {
    // Если уже число (Unix timestamp), возвращаем как есть
    if (is_numeric($dateTime)) {
        $timestamp = (int)$dateTime;
        // Проверяем что это разумная дата (не миллисекунды)
        if ($timestamp > 1000000000 && $timestamp < 2147483647) {
            return $timestamp;
        }
    }
    
    // Убираем лишние пробелы
    $dateTime = trim($dateTime);
    
    // Пробуем разные форматы
    $formats = [
        'Y-m-d H:i:s',      // 2025-01-27 14:30:00
        'Y-m-d H:i',        // 2025-01-27 14:30
        'd.m.Y H:i:s',      // 27.01.2025 14:30:00
        'd.m.Y H:i',        // 27.01.2025 14:30
        'Y/m/d H:i:s',      // 2025/01/27 14:30:00
        'Y/m/d H:i',        // 2025/01/27 14:30
    ];
    
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateTime);
        if ($date !== false) {
            return $date->getTimestamp();
        }
    }
    
    // Пробуем стандартный парсер
    $timestamp = strtotime($dateTime);
    if ($timestamp !== false) {
        return $timestamp;
    }
    
    return false;
}

/**
 * Логирование ручных конверсий
 */
function logManualConversion($data) {
    $logFile = __DIR__ . '/logs/manual_conversion_log.txt';
    
    // Создаем папку logs если её нет
    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] Ручная отправка конверсии\n";
    $logMessage .= print_r($data, true) . "\n";
    $logMessage .= str_repeat('=', 80) . "\n";
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}
