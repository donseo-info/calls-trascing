<?php
/**
 * MetrikaSender — отправка офлайн конверсий в Яндекс Метрику
 *
 * Формат CSV: ClientId,Yclid,phones,Target,DateTime
 * Приоритет идентификатора: ClientId → Yclid → phones
 * API: POST https://api-metrica.yandex.net/management/v1/counter/{id}/offline_conversions/upload
 */
class MetrikaSender
{
    private $accessToken;
    private $apiUrl = 'https://api-metrica.yandex.net/management/v1/counter/{counterId}/offline_conversions/upload';

    public function __construct($accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Отправить конверсию
     *
     * @param string $counterId  ID счётчика Метрики
     * @param string $goalName   Название цели
     * @param int    $timestamp  Unix timestamp события
     * @param string $clientId   ClientID Метрики (_ym_uid)
     * @param string $phone      Телефон звонящего
     * @return array ['success', 'http_code', 'response', 'error', 'csv']
     */
    public function send($counterId, $goalName, $timestamp, $clientId = null, $yclid = null, $phone = null)
    {
        if (!$this->accessToken) {
            return ['success' => false, 'error' => 'access_token not set'];
        }

        if (!$clientId && !$yclid && !$phone) {
            return ['success' => false, 'error' => 'no identifier (client_id, yclid or phone)'];
        }

        // Строим CSV в памяти. Приоритет: ClientId → Yclid → phones
        $headers = [];
        $values  = [];

        if ($clientId) {
            $headers[] = 'ClientId';
            $values[]  = $clientId;
        } elseif ($yclid) {
            $headers[] = 'yclid';
            $values[]  = $yclid;
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

        // Пишем CSV во временный файл и отправляем через CURLFile
        $tmp = tmpfile();
        fwrite($tmp, $csv);
        $tmpPath = stream_get_meta_data($tmp)['uri'];

        $url = str_replace('{counterId}', $counterId, $this->apiUrl);
        $ch  = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['file' => new CURLFile($tmpPath, 'text/csv', 'conversions.csv')],
            CURLOPT_HTTPHEADER     => ['Authorization: OAuth ' . $this->accessToken],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        fclose($tmp); // удаляем временный файл

        if ($curlError) {
            return ['success' => false, 'error' => $curlError, 'http_code' => $httpCode, 'csv' => $csv];
        }

        $success = $httpCode === 200;

        // Сохраняем CSV на диск если задана папка
        $csvFile = null;
        if ($success && defined('METRIKA_CSV_DIR') && METRIKA_CSV_DIR) {
            $dir = METRIKA_CSV_DIR;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $csvFile = $dir . '/conv_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.csv';
            file_put_contents($csvFile, $csv);
        }

        return [
            'success'      => $success,
            'http_code'    => $httpCode,
            'response'     => json_decode($response, true),
            'raw_response' => $response,
            'csv'          => $csv,
            'csv_file'     => $csvFile,
        ];
    }
}
