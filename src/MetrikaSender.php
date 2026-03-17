<?php
/**
 * MetrikaSender — отправка офлайн конверсий в Яндекс Метрику
 *
 * Формат CSV: ClientId,phones,Target,DateTime
 * API: POST https://api-metrica.yandex.net/management/v1/counter/{id}/offline_conversions/upload
 */
class MetrikaSender
{
    private $accessToken;
    private $csvDir;
    private $apiUrl = 'https://api-metrica.yandex.net/management/v1/counter/{counterId}/offline_conversions/upload';

    public function __construct($accessToken, $csvDir = null)
    {
        $this->accessToken = $accessToken;
        $this->csvDir = $csvDir ?: __DIR__ . '/../logs/csv_files';

        if (!is_dir($this->csvDir)) {
            mkdir($this->csvDir, 0755, true);
        }
    }

    /**
     * Отправить конверсию
     *
     * @param string $counterId  ID счётчика Метрики
     * @param string $goalName   Название цели
     * @param int    $timestamp  Unix timestamp события
     * @param string $clientId   ClientID Метрики (из cookie _ym_uid)
     * @param string $phone      Телефон звонящего (нормализованный, только цифры)
     * @return array ['success', 'http_code', 'response', 'error']
     */
    public function send($counterId, $goalName, $timestamp, $clientId = null, $phone = null)
    {
        if (!$this->accessToken) {
            return ['success' => false, 'error' => 'access_token not set'];
        }

        if (!$clientId && !$phone) {
            return ['success' => false, 'error' => 'no identifier (client_id or phone)'];
        }

        // Строим CSV
        $headers = [];
        $values  = [];

        if ($clientId) {
            $headers[] = 'ClientId';
            $values[]  = $clientId;
        }

        if ($phone) {
            $headers[] = 'phones';
            $values[]  = preg_replace('/\D/', '', $phone);
        }

        $headers[] = 'Target';
        $headers[] = 'DateTime';
        $values[]  = $goalName;
        $values[]  = $timestamp;

        $csv = implode(',', $headers) . "\n"
             . implode(',', $values)  . "\n";

        // Сохраняем CSV файл
        $filename = $this->csvDir . '/conv_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.csv';
        file_put_contents($filename, $csv);

        // Отправляем через CURL
        $url  = str_replace('{counterId}', $counterId, $this->apiUrl);
        $ch   = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['file' => new CURLFile($filename, 'text/csv', 'conversions.csv')],
            CURLOPT_HTTPHEADER     => ['Authorization: OAuth ' . $this->accessToken],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => $curlError, 'http_code' => $httpCode];
        }

        return [
            'success'      => $httpCode === 200,
            'http_code'    => $httpCode,
            'response'     => json_decode($response, true),
            'raw_response' => $response,
            'csv'          => $csv,
        ];
    }
}
