<?php

namespace Mublo\Plugin\SendonTalk\Service;

/**
 * 센드온 알림톡 API 클라이언트
 *
 * 인증: HTTP Basic Auth (api_id:api_password → base64)
 * Base URL: https://api.sendon.io
 *
 * @see https://sdk.sendon.io/reference
 */
class SendonTalkApiClient
{
    private const BASE_URL = 'https://api.sendon.io';

    public function __construct(
        private string $apiId,
        private string $apiPassword,
        private bool $testMode = false
    ) {}

    // ── 채널(발신프로필) ──

    /**
     * 채널 목록 조회
     * GET /v2/messages/kakao/send-profiles
     */
    public function getSendProfiles(?int $limit = 20, ?string $cursor = null): array
    {
        $query = ['limit' => $limit];
        if ($cursor) $query['cursor'] = $cursor;

        return $this->request('GET', '/v2/messages/kakao/send-profiles?' . http_build_query($query));
    }

    /**
     * 채널 상세 조회
     * GET /v2/messages/kakao/send-profiles/{sendProfileId}
     */
    public function getSendProfile(string $sendProfileId): array
    {
        return $this->request('GET', '/v2/messages/kakao/send-profiles/' . urlencode($sendProfileId));
    }

    // ── 템플릿 ──

    /**
     * 템플릿 목록 조회
     * GET /v2/messages/kakao/send-profiles/{sendProfileId}/templates
     */
    public function getTemplates(string $sendProfileId, ?string $status = null, ?int $limit = 100): array
    {
        $query = ['limit' => $limit];
        if ($status) $query['status'] = $status;

        return $this->request(
            'GET',
            '/v2/messages/kakao/send-profiles/' . urlencode($sendProfileId) . '/templates?' . http_build_query($query)
        );
    }

    /**
     * 템플릿 상세 조회
     * GET /v2/messages/kakao/send-profiles/{sendProfileId}/templates/{templateId}
     */
    public function getTemplate(string $sendProfileId, string $templateId): array
    {
        return $this->request(
            'GET',
            '/v2/messages/kakao/send-profiles/' . urlencode($sendProfileId)
                . '/templates/' . urlencode($templateId)
        );
    }

    /**
     * 템플릿 생성 (카카오 심사 요청)
     * POST /v2/messages/kakao/send-profiles/{sendProfileId}/templates
     *
     * @param array $params [
     *   'templateName'          => string (필수 아님),
     *   'templateContent'       => string (필수, 최대 1000자),
     *   'templateMessageType'   => 'BA'|'EX'|'AD'|'MI' (기본 BA),
     *   'templateEmphasizeType' => 'NONE'|'TEXT'|'IMAGE'|'ITEM_LIST',
     *   'templateExtra'         => string (부가정보, 최대 500자),
     *   'templateTitle'         => string (강조 제목),
     *   'templateSubtitle'      => string (강조 부제목),
     *   'securityFlag'          => bool,
     *   'buttons'               => array (최대 5개),
     * ]
     */
    public function createTemplate(string $sendProfileId, array $params): array
    {
        return $this->request(
            'POST',
            '/v2/messages/kakao/send-profiles/' . urlencode($sendProfileId) . '/templates',
            $params
        );
    }

    /**
     * 템플릿 수정
     * POST /v2/messages/kakao/send-profiles/{sendProfileId}/templates/{templateId}/update
     */
    public function updateTemplate(string $sendProfileId, string $templateId, array $params): array
    {
        return $this->request(
            'POST',
            '/v2/messages/kakao/send-profiles/' . urlencode($sendProfileId)
                . '/templates/' . urlencode($templateId) . '/update',
            $params
        );
    }

    /**
     * 템플릿 삭제
     * POST /v2/messages/kakao/send-profiles/{sendProfileId}/templates/{templateId}/delete
     */
    public function deleteTemplate(string $sendProfileId, string $templateId): array
    {
        return $this->request(
            'POST',
            '/v2/messages/kakao/send-profiles/' . urlencode($sendProfileId)
                . '/templates/' . urlencode($templateId) . '/delete'
        );
    }

    // ── 알림톡 발송 ──

    /**
     * 알림톡 발송
     * POST /v2/messages/kakao/alim-talk
     *
     * @param array $params [
     *   'sendProfileId' => string (필수),
     *   'templateId'    => string (필수, 카카오 승인 템플릿 ID),
     *   'to'            => array (최대 1000명),
     *   'useCredit'     => bool (기본 true),
     *   'fallback'      => ['type'=>'LMS','message'=>'...','title'=>'...'],
     * ]
     */
    public function sendAlimtalk(array $params): array
    {
        return $this->request('POST', '/v2/messages/kakao/alim-talk', $params);
    }

    // ── 부가 ──

    /**
     * 포인트 잔액 조회
     */
    public function getPointBalance(): array
    {
        return $this->request('GET', '/v2/point/points');
    }

    // ── HTTP ──

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
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
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
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['code' => -1, 'message' => 'cURL 오류: ' . $error];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['code' => -2, 'message' => 'JSON 파싱 실패 (HTTP ' . $httpCode . ')', 'raw' => $response];
        }

        // 센드온 응답에 HTTP 코드가 없으면 추가
        if (!isset($decoded['code'])) {
            $decoded['code'] = $httpCode;
        }

        return $decoded;
    }
}
