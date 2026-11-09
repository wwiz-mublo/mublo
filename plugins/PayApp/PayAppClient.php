<?php
declare(strict_types=1);

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

    /** 통신 자체가 실패했을 때(cURL 오류) 응답 state — 페이앱이 판단한 실패와 구별한다 */
    public const TRANSPORT_ERROR = '-1';

    /** 로그에 남기면 안 되는 필드 — 통보 본문에 연동 KEY·VALUE 가 그대로 실려 온다 */
    private const SECRET_FIELDS = ['linkkey', 'linkval', 'hash', 'requestkey'];

    private string $userid;
    private string $linkkey;
    private string $linkval;
    private string $requestkey;

    /**
     * @param string $requestkey 결제 KEY(판매자 사이트 → 설정). 선택값이다.
     *                           있으면 통보 hash 를 검증하고 취소 요청에 hash 를 싣는다.
     */
    public function __construct(string $userid, string $linkkey, string $linkval, string $requestkey = '')
    {
        $this->userid = $userid;
        $this->linkkey = $linkkey;
        $this->linkval = $linkval;
        $this->requestkey = $requestkey;
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
     * 결제(요청·승인) 취소
     *
     * 페이앱 문서: partcancel 0=전체취소 / 1=부분취소, 부분취소일 때만 cancelprice 필수.
     * 전체·부분 판단은 호출자(게이트웨이)가 한다 — 여기서는 받은 대로 보낸다.
     *
     * @param int|null $partialAmount 부분취소 금액. null 이면 전체취소.
     */
    public function payCancel(string $mulNo, string $reason = '', ?int $partialAmount = null): array
    {
        $data = [
            'cmd' => 'paycancel',
            'userid' => $this->userid,
            'linkkey' => $this->linkkey,
            'mul_no' => $mulNo,
            'cancelmemo' => $reason ?: '관리자 취소',
        ];

        if ($partialAmount !== null) {
            $data['partcancel'] = '1';
            $data['cancelprice'] = (string) $partialAmount;
        } else {
            $data['partcancel'] = '0';
        }

        // 문서상 취소 요청은 requestdate + hash(sha256(userid+requestdate+requestkey))를 받는다.
        // 결제 KEY 를 등록한 사이트만 싣는다(미등록 사이트는 기존 요청 그대로).
        if ($this->requestkey !== '') {
            $requestDate = date('YmdHis');
            $data['requestdate'] = $requestDate;
            $data['hash'] = hash('sha256', $this->userid . $requestDate . $this->requestkey);
        }

        return $this->post($data);
    }

    /**
     * Feedback 검증 — POST로 받은 데이터의 userid/linkkey/linkval(+hash) 확인
     *
     * 설정값이 하나라도 비어 있으면 무조건 거부한다. 빈 값끼리의 비교(hash_equals('', ''))는
     * 참이라, 설정이 없는 도메인을 겨냥한 위조 통보가 그대로 통과하기 때문이다.
     * (복호화 실패도 빈 값으로 떨어지므로 같은 이유로 막힌다)
     *
     * 공유 시크릿 비교는 타이밍 side-channel을 피하기 위해 hash_equals()로 수행하고,
     * 세 필드를 모두 계산한 뒤 결합해 어느 필드가 틀렸는지 조기 반환으로 드러나지 않게 한다.
     *
     * 결제 KEY(requestkey)를 등록했다면 문서가 요구하는 위변조 방지 hash
     * sha256(userid + responsedate + requestkey) 까지 확인한다.
     */
    public function verifyFeedback(array $postData): bool
    {
        if ($this->userid === '' || $this->linkkey === '' || $this->linkval === '') {
            return false;
        }

        $okUser = hash_equals($this->userid, (string) ($postData['userid'] ?? ''));
        $okKey  = hash_equals($this->linkkey, (string) ($postData['linkkey'] ?? ''));
        $okVal  = hash_equals($this->linkval, (string) ($postData['linkval'] ?? ''));

        $okHash = true;
        if ($this->requestkey !== '') {
            $expected = hash('sha256', $this->userid . (string) ($postData['responsedate'] ?? '') . $this->requestkey);
            $okHash = hash_equals($expected, strtolower((string) ($postData['hash'] ?? '')));
        }

        return $okUser && $okKey && $okVal && $okHash;
    }

    /**
     * 로그용 사본 — 연동 KEY·VALUE·hash 를 가린다.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function maskSecrets(array $data): array
    {
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = '***';
            }
        }
        return $data;
    }

    /**
     * 실제 HTTP 전송. 테스트에서 대역이 덮어쓴다.
     */
    protected function post(array $data): array
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

        if (!is_string($response)) {
            return ['state' => self::TRANSPORT_ERROR, 'errorMessage' => 'cURL error: ' . $error];
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
