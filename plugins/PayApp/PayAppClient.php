<?php

namespace Mublo\Plugin\PayApp;

/**
 * PayApp REST API 클라이언트
 *
 * POST api.payapp.kr/oapi/apiLoad.html
 * 모든 API 호출은 FORM POST 방식, UTF-8 인코딩.
 */
class PayAppClient
{
    private const API_URL = 'https://api.payapp.kr/oapi/apiLoad.html';

    private string $userid;
    private string $linkkey;
    private string $linkval;

    public function __construct(string $userid, string $linkkey, string $linkval)
    {
        $this->userid = $userid;
        $this->linkkey = $linkkey;
        $this->linkval = $linkval;
    }

    /**
     * 결제 요청
     *
     * @return array ['state' => '1'|error, 'mul_no' => '결제번호', 'payurl' => '결제URL', ...]
     */
    public function payRequest(array $params): array
    {
        $data = array_merge([
            'cmd' => 'payrequest',
            'userid' => $this->userid,
            'linkkey' => $this->linkkey,
        ], $params);

        return $this->post($data);
    }

    /**
     * 결제 상태 조회
     *
     * 페이앱 오픈API 의 조회 명령은 payinfo 다. statecheck 는 받아들이지 않는다
     * (errno=70040 "cmd 값을 확인 하세요"). 응답 필드도 webhook 규격과 달라
     * 상태는 usingstate, 금액은 goodprice, 승인번호는 payauthcode 로 온다.
     */
    public function stateCheck(string $mulNo): array
    {
        return $this->post([
            'cmd' => 'payinfo',
            'userid' => $this->userid,
            'linkkey' => $this->linkkey,
            'mul_no' => $mulNo,
        ]);
    }

    /**
     * 결제 취소
     */
    public function payCancel(string $mulNo, string $reason = '', ?int $cancelAmount = null): array
    {
        $data = [
            'cmd' => 'paycancel',
            'userid' => $this->userid,
            'linkkey' => $this->linkkey,
            'mul_no' => $mulNo,
            'cancelmemo' => $reason ?: '관리자 취소',
        ];

        if ($cancelAmount !== null) {
            $data['partcancel'] = '1';
            $data['cancelprice'] = (string) $cancelAmount;
        }

        return $this->post($data);
    }

    /**
     * Feedback 검증 — POST로 받은 데이터의 userid/linkkey/linkval 확인
     *
     * 공유 시크릿 비교는 타이밍 side-channel을 피하기 위해 hash_equals()로 수행하고,
     * 세 필드를 모두 계산한 뒤 결합해 어느 필드가 틀렸는지 조기 반환으로 드러나지 않게 한다.
     */
    public function verifyFeedback(array $postData): bool
    {
        $okUser = hash_equals($this->userid, (string) ($postData['userid'] ?? ''));
        $okKey  = hash_equals($this->linkkey, (string) ($postData['linkkey'] ?? ''));
        $okVal  = hash_equals($this->linkval, (string) ($postData['linkval'] ?? ''));

        return $okUser && $okKey && $okVal;
    }

    private function post(array $data): array
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data, '', '&'),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['state' => '-1', 'errorMessage' => 'cURL error: ' . $error];
        }

        // PayApp 응답: key=value&key=value 또는 JSON
        $result = [];
        if (str_starts_with(trim($response), '{')) {
            $result = json_decode($response, true) ?: [];
        } else {
            parse_str($response, $result);
        }

        $result['_http_code'] = $httpCode;
        return $result;
    }
}
