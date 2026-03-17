<?php
namespace BitrixIntegration;

class YandexMetricaIntegration
{
    private $clientId;
    private $clientSecret;
    private $accessToken;
    private $config;
    private $apiUrl = 'https://api-metrica.yandex.net/management/v1/counter/{counterId}/offline_conversions/upload';
    private $siteCountersConfig;
    private $goalsConfig;
    private $csvFilesPath;

    public function __construct($accessToken = null)
    {
        // Загружаем конфигурацию
        $configPath = __DIR__ . '/../config.php';
        if (file_exists($configPath)) {
            $this->config = require $configPath;
            
            // Устанавливаем токены из конфига
            $this->clientId = $this->config['yandex_metrica']['client_id'] ?? null;
            $this->clientSecret = $this->config['yandex_metrica']['client_secret'] ?? null;
            $this->accessToken = $accessToken ?? $this->config['yandex_metrica']['access_token'] ?? null;
            
            // Загружаем конфигурацию счетчиков и целей
            $this->siteCountersConfig = $this->config['site_counters'] ?? [];
            $this->goalsConfig = $this->config['goals_config'] ?? [];
            
            // Путь для сохранения CSV файлов
            $this->csvFilesPath = $this->config['paths']['csv_files'] ?? __DIR__ . '/../logs/csv_files';
        } else {
            // Fallback на старые значения если конфиг не найден
            $this->accessToken = $accessToken;
            $this->siteCountersConfig = [];
            $this->goalsConfig = [];
            $this->csvFilesPath = __DIR__ . '/../logs/csv_files';
        }
        
        // Создаем директорию для CSV файлов если её нет
        if (!is_dir($this->csvFilesPath)) {
            mkdir($this->csvFilesPath, 0755, true);
        }
    }

    public function setAccessToken($token)
    {
        $this->accessToken = $token;
    }

    public function isSiteConfigured($site)
    {
        return isset($this->siteCountersConfig[$site]);
    }

    public function getCounterId($site)
    {
        return $this->siteCountersConfig[$site] ?? null;
    }

    public function getGoalName($bitrixDomain, $statusId)
    {
        if (!isset($this->goalsConfig[$bitrixDomain])) {
            return null;
        }
        return $this->goalsConfig[$bitrixDomain][$statusId] ?? null;
    }

    public function shouldSendConversion($site, $bitrixDomain, $statusId)
    {
        if (!$this->isSiteConfigured($site)) {
            return false;
        }
        if (!$this->getGoalName($bitrixDomain, $statusId)) {
            return false;
        }
        return true;
    }

    /**
     * Отправляет оффлайн конверсию в Яндекс Метрику
     * API требует загрузку CSV файла через multipart/form-data
     * Формат CSV: ClientId,Target,DateTime
     */
    public function sendConversion($counterId, $clientId, $goalName, $dateTime)
    {
        if (!$this->accessToken) {
            return [
                'success' => false,
                'error' => 'Access token not set'
            ];
        }

        if (!$clientId) {
            return [
                'success' => false,
                'error' => 'ClientId is empty'
            ];
        }

        $url = str_replace('{counterId}', $counterId, $this->apiUrl);

        // Создаем CSV данные
        // Формат: ClientId,Target,DateTime
        $csvData = "ClientId,Target,DateTime\n";
        $csvData .= sprintf("%s,%s,%d\n", $clientId, $goalName, $dateTime);

        // Сохраняем CSV файл перед отправкой
        $csvFileName = 'conversion_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.csv';
        $csvFilePath = $this->csvFilesPath . '/' . $csvFileName;
        file_put_contents($csvFilePath, $csvData);

        // Используем CURLFile для загрузки файла
        $cfile = new \CURLFile($csvFilePath, 'text/csv', 'conversions.csv');

        $postData = [
            'file' => $cfile
        ];

        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Authorization: OAuth ' . $this->accessToken
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        

        if ($curlError) {
            return [
                'success' => false,
                'error' => $curlError,
                'http_code' => $httpCode
            ];
        }

        $result = json_decode($response, true);

        // Сохраняем последние данные в JSON
        $identifiers = ['client_id' => $clientId];
        $this->saveLastConversionData($counterId, $goalName, $identifiers, $dateTime, $csvFileName);

        return [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $result,
            'sent_data' => [
                'ClientId' => $clientId,
                'Target' => $goalName,
                'DateTime' => $dateTime
            ],
            'csv_data' => $csvData,
            'csv_file' => $csvFileName,
            'raw_response' => $response
        ];
    }

    public function addSiteConfig($site, $counterId)
    {
        $this->siteCountersConfig[$site] = $counterId;
    }

    public function addGoalConfig($bitrixDomain, $statusId, $goalName)
    {
        if (!isset($this->goalsConfig[$bitrixDomain])) {
            $this->goalsConfig[$bitrixDomain] = [];
        }
        $this->goalsConfig[$bitrixDomain][$statusId] = $goalName;
    }

    public function getSiteCountersConfig()
    {
        return $this->siteCountersConfig;
    }

    public function getGoalsConfig()
    {
        return $this->goalsConfig;
    }

    /**
     * Отправляет оффлайн конверсию в Яндекс Метрику с использованием идентификаторов
     * Поддерживает: ClientId, phones, emails, phones_md5, emails_md5
     * 
     * @param string $counterId ID счетчика Метрики
     * @param string $goalName Название цели
     * @param int $dateTime Unix timestamp (секунды)
     * @param array $identifiers Массив идентификаторов ['client_id' => '', 'phone' => '', 'email' => '', 'use_md5' => false]
     * @return array Результат отправки
     */
    public function sendConversionWithIdentifiers($counterId, $goalName, $dateTime, $identifiers = [])
    {
        if (!$this->accessToken) {
            return [
                'success' => false,
                'error' => 'Access token not set'
            ];
        }

        // Проверяем наличие хотя бы одного идентификатора
        $hasClientId = !empty($identifiers['client_id']);
        $hasPhone = !empty($identifiers['phone']);
        $hasEmail = !empty($identifiers['email']);
        $useMd5 = !empty($identifiers['use_md5']);

        if (!$hasClientId && !$hasPhone && !$hasEmail) {
            return [
                'success' => false,
                'error' => 'Необходимо указать хотя бы один идентификатор: ClientId, телефон или email'
            ];
        }

        $url = str_replace('{counterId}', $counterId, $this->apiUrl);

        // Формируем CSV заголовок и строку данных
        $csvHeaders = [];
        $csvValues = [];

        // Добавляем ClientId если есть
        if ($hasClientId) {
            $csvHeaders[] = 'ClientId';
            $csvValues[] = $identifiers['client_id'];
        }

        // Добавляем телефон
        if ($hasPhone) {
            if ($useMd5) {
                $csvHeaders[] = 'phones_md5';
                $csvValues[] = md5($identifiers['phone']);
            } else {
                $csvHeaders[] = 'phones';
                $csvValues[] = $identifiers['phone'];
            }
        }

        // Добавляем email
        if ($hasEmail) {
            if ($useMd5) {
                $csvHeaders[] = 'emails_md5';
                $csvValues[] = md5(strtolower(trim($identifiers['email'])));
            } else {
                $csvHeaders[] = 'emails';
                $csvValues[] = strtolower(trim($identifiers['email']));
            }
        }

        // Добавляем Target и DateTime
        $csvHeaders[] = 'Target';
        $csvHeaders[] = 'DateTime';
        $csvValues[] = $goalName;
        $csvValues[] = $dateTime;

        // Создаем CSV данные
        $csvData = implode(',', $csvHeaders) . "\n";
        $csvData .= implode(',', array_map(function($value) {
            // Экранируем запятые и кавычки в значениях
            if (strpos($value, ',') !== false || strpos($value, '"') !== false) {
                return '"' . str_replace('"', '""', $value) . '"';
            }
            return $value;
        }, $csvValues)) . "\n";

        // Сохраняем CSV файл перед отправкой
        $csvFileName = 'conversion_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.csv';
        $csvFilePath = $this->csvFilesPath . '/' . $csvFileName;
        file_put_contents($csvFilePath, $csvData);

        // Используем CURLFile для загрузки файла
        $cfile = new \CURLFile($csvFilePath, 'text/csv', 'conversions.csv');

        $postData = [
            'file' => $cfile
        ];

        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Authorization: OAuth ' . $this->accessToken
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        

        if ($curlError) {
            return [
                'success' => false,
                'error' => $curlError,
                'http_code' => $httpCode
            ];
        }

        $result = json_decode($response, true);

        // Сохраняем последние данные в JSON
        $this->saveLastConversionData($counterId, $goalName, $identifiers, $dateTime, $csvFileName);

        return [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $result,
            'sent_data' => [
                'identifiers' => $identifiers,
                'Target' => $goalName,
                'DateTime' => $dateTime
            ],
            'csv_data' => $csvData,
            'csv_file' => $csvFileName,
            'raw_response' => $response
        ];
    }

    /**
     * Сохраняет последние данные конверсии в JSON файл
     */
    private function saveLastConversionData($counterId, $goalName, $identifiers, $dateTime, $csvFileName)
    {
        $lastDataPath = $this->config['paths']['last_conversions'] ?? __DIR__ . '/../logs/last_conversions.json';
        
        // Создаем директорию если её нет
        $lastDataDir = dirname($lastDataPath);
        if (!is_dir($lastDataDir)) {
            mkdir($lastDataDir, 0755, true);
        }

        $data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'unix_timestamp' => time(),
            'counter_id' => $counterId,
            'goal_name' => $goalName,
            'identifiers' => $identifiers,
            'date_time' => $dateTime,
            'date_time_formatted' => date('Y-m-d H:i:s', $dateTime),
            'csv_file' => $csvFileName
        ];

        // Читаем существующие данные если есть
        $existingData = [];
        if (file_exists($lastDataPath)) {
            $existingContent = file_get_contents($lastDataPath);
            $existingData = json_decode($existingContent, true) ?: [];
        }

        // Добавляем новую запись в начало массива
        array_unshift($existingData, $data);

        // Оставляем только последние 100 записей
        $existingData = array_slice($existingData, 0, 100);

        // Сохраняем в JSON
        file_put_contents($lastDataPath, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}