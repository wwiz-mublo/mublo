<?php
declare(strict_types=1);

namespace Mublo\Plugin\SendonSms\Service;

/**
 * 센드온(Sendon) REST API 클라이언트
 *
 * 인증: HTTP Basic Auth (api_id:api_password → base64)
 * Base URL: https://api.sendon.io
 */
class SendonApiClient
{
    private const BASE_URL = 'https://api.sendon.io';

    public function __construct(
        private string $apiId,
        private string $apiPassword,
        private bool $testMode = false
    ) {
    }

    // === 메시지 발송 ===

    /**
     * 문자 메시지 발송 (SMS/LMS/MMS 통합)
     *
     * @param array $params [
     *   'type' => 'SMS'|'LMS'|'MMS',
     *   'from' => '발신번호',
     *   'to' => ['수신번호', ...],
     *   'message' => '본문',
     *   'title' => 'LMS/MMS 제목' (optional),
     *   'images' => ['imageId', ...] (MMS, optional),
     *   'reservation' => ['datetime' => '2026-03-20 10:00:00'] (optional),
     *   'isAd' => false (optional),
     * ]
     * @return array ['code' => 200, 'message' => '성공', 'data' => ['groupId' => '...']]
     */
    public function sendMessage(array $params): array
    {
        $body = [
            'type' => $params['type'] ?? 'SMS',
            'from' => $params['from'] ?? '',
            'to' => $params['to'] ?? [],
            'message' => $params['message'] ?? '',
        ];

        if (!empty($params['title'])) {
            $body['title'] = $params['title'];
        }

        if (!empty($params['images'])) {
            $body['images'] = $params['images'];
        }

        if (!empty($params['reservation'])) {
            $body['reservation'] = $params['reservation'];
        }

        if (isset($params['isAd'])) {
            $body['isAd'] = (bool) $params['isAd'];
        }

        return $this->request('POST', '/v2/messages/sms', $body);
    }

    // === 발송 결과 조회 ===

    /**
     * 발송 목록 조회
     */
    public function getMessageList(array $filters = []): array
    {
        $query = http_build_query($filters);
        $path = '/v2/messages/sms' . ($query ? '?' . $query : '');

        return $this->request('GET', $path);
    }

    /**
     * 발송 상세 조회
     */
    public function getMessageDetail(string $groupId): array
    {
        return $this->request('GET', '/v2/messages/sms/' . urlencode($groupId));
    }

    // === MMS 이미지 ===

    /**
     * MMS 이미지 업로드
     *
     * @param string $filePath 이미지 파일 경로
     * @return array ['code' => 200, 'data' => ['imageId' => '...']]
     */
    public function uploadMmsImage(string $filePath): array
    {
        return $this->requestMultipart('/v2/messages/sms/images', $filePath);
    }

    /**
     * 업로드된 MMS 이미지 목록
     */
    public function getImageList(): array
    {
        return $this->request('GET', '/v2/messages/sms/images');
    }

    // === 발신번호 ===

    /**
     * 등록된 발신번호 목록 조회
     */
    public function getSenderNumbers(): array
    {
        return $this->request('GET', '/v2/senders');
    }

    // === 포인트/잔액 ===

    /**
     * 포인트 잔액 조회
     */
    public function getPointBalance(): array
    {
        return $this->request('GET', '/v2/point');
    }

    // === HTTP 헬퍼 ===

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = self::BASE_URL . $path;
        $ch = curl_init();

        $headers = [
            'Authorization: Basic ' . base64_encode($this->apiId . ':' . $this->apiPassword),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => !$this->testMode,
            CURLOPT_SSL_VERIFYHOST => $this->testMode ? 0 : 2,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'code' => -1,
                'message' => 'cURL 오류: ' . $error,
            ];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return [
                'code' => -2,
                'message' => 'JSON 파싱 실패 (HTTP ' . $httpCode . ')',
                'raw' => $response,
            ];
        }

        return $decoded;
    }

    /**
     * Multipart 파일 업로드 (MMS 이미지)
     */
    private function requestMultipart(string $path, string $filePath): array
    {
        $url = self::BASE_URL . $path;
        $ch = curl_init();

        $headers = [
            'Authorization: Basic ' . base64_encode($this->apiId . ':' . $this->apiPassword),
            'Accept: application/json',
        ];

        $postFields = [
            'image' => new \CURLFile($filePath),
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => !$this->testMode,
            CURLOPT_SSL_VERIFYHOST => $this->testMode ? 0 : 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'code' => -1,
                'message' => 'cURL 오류: ' . $error,
            ];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return [
                'code' => -2,
                'message' => 'JSON 파싱 실패 (HTTP ' . $httpCode . ')',
                'raw' => $response,
            ];
        }

        return $decoded;
    }
}
