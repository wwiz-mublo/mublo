<?php
declare(strict_types=1);

namespace Mublo\Service\Domain;

use Mublo\Core\Env\Env;
use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Repository\Domain\DomainVerificationRepository;

/**
 * DomainVerificationService
 *
 * 호스트명을 실제로 쓸 수 있는지 "측정"하는 서비스.
 *
 * 판정 신호는 두 개다.
 *  1) DNS 조회 (A/AAAA/CNAME) — 보고용. 관리자가 무엇이 잘못됐는지 알 수 있게 기록만 한다.
 *  2) 루프백 프로브 — 합격 판정. 새 호스트로 실제 HTTP 요청을 보내고,
 *     그 요청이 "이 설치본"에 도달했는지를 서버가 발급한 일회용 nonce 일치로 확인한다.
 *
 * 프로브를 판정 기준으로 삼는 이유: A 레코드와 서버 IP를 비교하는 방식은
 * Cloudflare 등 프록시/CDN 뒤에서 항상 실패한다(A 레코드가 프록시 IP).
 * 반면 프로브는 경로가 몇 단이든 "결국 우리 앱이 응답했다"를 직접 증명한다.
 *
 * 신뢰 경계: 브라우저가 보내온 "검증 통과" 주장은 믿지 않는다. 변경 API는
 * consumeForChange()로 DB의 passed 기록을 다시 찾아 게이트하고, 그 기록은
 * 1회만 소진된다.
 *
 * @see DomainService::changeDomainName() 이 검증을 게이트로 쓰는 변경 경로
 */
class DomainVerificationService
{
    /**
     * 검증 유효시간(초).
     *
     * "DNS 확인" 후 관리자가 실제 변경 버튼을 누르기까지의 유예다.
     * 짧으면 재확인이 번거롭고, 길면 그사이 DNS가 바뀐 채로 변경될 수 있어 30분으로 둔다.
     */
    public const TTL_SECONDS = 1800;

    /** 프로브 응답 경로 (도메인 검증을 우회하는 공개 경로) */
    public const PROBE_PATH = '/.well-known/mublo-domain-verify';

    private const AUDIT_CHANNEL = 'domain';

    /** 프로브 타임아웃 (연결 / 전체) */
    private const PROBE_CONNECT_TIMEOUT = 3;
    private const PROBE_TIMEOUT = 6;

    /**
     * 도달 확인 생략(dev_local)을 허용하는 APP_ENV 값.
     *
     * APP_DEBUG가 아니라 APP_ENV로 판정한다. APP_DEBUG는 운영에서도 장애 진단을 위해
     * 일시적으로 켤 수 있는 값이라, 그 순간 검증 게이트가 느슨해지는 결합은 위험하다.
     * APP_ENV는 배포 환경 정체성이며, 같은 목적(운영에서 위험한 우회를 막는다)의 선례가
     * 이미 있다 — plugins/TestPay/TestPayProvider.php.
     *
     * 화이트리스트로 두는 이유: 미설정 시 기본값이 'production'이고, `!== 'production'`
     * 방식이면 오타(APP_ENV=prod 등)가 곧 우회 허용이 된다. 모르는 값은 거부한다(fail-closed).
     */
    private const DEV_LOCAL_ENVS = ['local', 'development', 'dev', 'testing'];

    /**
     * 도달 확인을 생략해 주는 로컬 호스트 접미사.
     *
     * 개발 APP_ENV + (이 접미사이거나 사설/루프백 IP로 해석) 두 조건을 모두
     * 만족할 때만 dev_local 합격이 된다. 운영에서는 어떤 경우에도 적용되지 않는다.
     */
    private const DEV_LOCAL_SUFFIXES = ['localhost', '.localhost', '.local', '.test', '.internal', '.example'];

    private DomainVerificationRepository $repository;
    private ?Logger $logger;

    public function __construct(
        DomainVerificationRepository $repository,
        ?Logger $logger = null
    ) {
        $this->repository = $repository;
        $this->logger = $logger;
    }

    // =========================================================================
    // 호스트명 정규화
    // =========================================================================

    /**
     * 호스트명 정규화 (검증 기록과 변경 요청이 같은 문자열을 쓰도록 단일화)
     *
     * 소문자·공백 제거만 한다. 포트는 개발환경(localhost:8080)에서 필요하므로 유지한다.
     */
    public static function normalizeHost(string $host): string
    {
        return strtolower(trim($host));
    }

    // =========================================================================
    // 검증 실행
    // =========================================================================

    /**
     * 호스트명 검증 실행 (DNS 조회 + 루프백 프로브)
     *
     * @param string $host 검증할 호스트명
     * @param int|null $domainId 변경 대상 도메인 ID (신규 등록 검증은 null)
     * @param int|null $requestedBy 요청 관리자 회원 ID
     * @return Result 성공/실패 모두 data에 리포트를 담는다 (화면에 사유를 보여주기 위함)
     */
    public function verify(string $host, ?int $domainId, ?int $requestedBy = null): Result
    {
        $host = self::normalizeHost($host);

        if ($host === '') {
            return Result::failure('검증할 도메인명이 없습니다.');
        }

        // 응답 없이 만료된 pending 정리 (테이블 증식 방지)
        $this->repository->purgeExpiredPending();

        $nonce = bin2hex(random_bytes(32));
        $verificationId = $this->repository->createPending(
            $host,
            $domainId,
            $nonce,
            $requestedBy,
            self::TTL_SECONDS
        );

        $dns = $this->lookupDns($this->stripPort($host));
        $probe = $this->probe($host, $nonce);

        [$status, $verdict, $message] = $this->judge($host, $dns, $probe);

        $this->repository->saveResult($verificationId, $status, $verdict, $message, $dns, $probe);

        $this->logger?->channel(self::AUDIT_CHANNEL)->info('도메인 검증 실행', [
            'verification_id' => $verificationId,
            'host' => $host,
            'domain_id' => $domainId,
            'requested_by' => $requestedBy,
            'status' => $status,
            'verdict' => $verdict,
        ]);

        $report = [
            'verification_id' => $verificationId,
            'host' => $host,
            'status' => $status,
            'verdict' => $verdict,
            'dns' => $dns,
            'probe' => [
                // 프로브 응답 본문은 클라이언트에 돌려주지 않는다 (SSRF 정보 유출 방지).
                'url' => $probe['url'] ?? '',
                'http_code' => $probe['http_code'] ?? 0,
                'ok' => (bool) ($probe['ok'] ?? false),
                'error' => $probe['error'] ?? '',
            ],
            'expires_in' => self::TTL_SECONDS,
        ];

        return $status === 'passed'
            ? Result::success($message, $report)
            : Result::failure($message, $report);
    }

    /**
     * 판정
     *
     * @return array{0:string,1:string,2:string} [status, verdict, message]
     */
    private function judge(string $host, array $dns, array $probe): array
    {
        if (!empty($probe['ok'])) {
            return [
                'passed',
                'reachable',
                '이 서버로 정상 연결됩니다. 도메인을 변경할 수 있습니다.',
            ];
        }

        $hasRecord = !empty($dns['a']) || !empty($dns['aaaa']) || !empty($dns['cname']);

        // 개발환경 예외: 개발 APP_ENV + 로컬 호스트일 때만 도달 확인을 생략한다.
        // (컨테이너 안에서는 자기 자신의 공개 호스트명으로 되돌아오는 요청이
        //  성립하지 않는 경우가 많아, 개발 중에는 변경 자체가 불가능해진다.)
        if ($this->isDevLocalHost($host)) {
            return [
                'passed',
                'dev_local',
                '개발환경(로컬 호스트)이라 도달 확인을 생략했습니다. 운영에서는 실제 연결 확인이 필요합니다.',
            ];
        }

        if (!$hasRecord) {
            return [
                'failed',
                'dns_missing',
                'DNS에 A/AAAA/CNAME 레코드가 없습니다. 도메인의 DNS를 이 서버로 먼저 설정하세요.',
            ];
        }

        $code = (int) ($probe['http_code'] ?? 0);
        $detail = $code > 0
            ? "응답 코드 {$code}"
            : ($probe['error'] ?: '연결 실패');

        // 코드로 원인이 갈리면 그 단서를 주고, 아니면 일반 안내를 붙인다.
        $cause = $this->unreachableHint($code)
            ?: 'DNS 전파 대기 중이거나, 웹서버가 이 호스트명의 요청을 받도록 설정되지 않았습니다.';

        return [
            'failed',
            'unreachable',
            "DNS는 설정됐지만 이 서버로 연결되지 않습니다 ({$detail}). {$cause}",
        ];
    }

    /**
     * 도달 실패 응답 코드별 원인 힌트
     *
     * "응답 코드 403"만 보여주면 관리자가 어디를 봐야 할지 알 수 없다. 특히 CDN/프록시
     * (Cloudflare 등) 경유 구성에서 실제로 겪는 실패가 코드로 구분되므로 그 단서를 준다.
     *
     * 200인데 실패로 온 경우는 "응답은 왔지만 우리 nonce가 아니다" — 즉 다른 설치본이 답한 것이다.
     */
    private function unreachableHint(int $code): string
    {
        return match (true) {
            $code === 200 => '응답은 왔지만 이 설치본이 아닙니다. 그 호스트명이 다른 사이트로 연결되고 있습니다.',
            $code === 404 => '다른 서버가 응답했거나, 연결된 오리진에 이 버전이 배포되지 않았습니다.',
            in_array($code, [401, 403, 429, 503], true)
                => '프록시·보안 설정(Cloudflare 봇 차단/챌린지, IP 제한 등)에 막혔을 수 있습니다. ' . self::PROBE_PATH . ' 경로를 예외로 두고 다시 시도하세요.',
            in_array($code, [502, 521, 522, 523, 525], true)
                => '프록시가 오리진 서버에 연결하지 못했습니다. 오리진 주소·포트·인증서 설정을 확인하세요.',
            $code >= 300 && $code < 400 => '리다이렉트가 반복되고 있습니다. 프록시의 HTTPS 강제 설정을 확인하세요.',
            default => '',
        };
    }

    // =========================================================================
    // 변경 게이트
    // =========================================================================

    /**
     * 변경 직전 게이트: 유효한 합격 기록을 찾아 소진한다.
     *
     * 검증을 거치지 않은(또는 만료·이미 사용된) 변경 요청은 여기서 반려된다.
     * 소진되는 행에 감사 정보(직전 호스트명·실행자)를 함께 남겨, 그 행만으로
     * 변경 이력을 읽을 수 있게 한다.
     *
     * @param string|null $previousHost 변경 직전 호스트명 (감사용)
     * @param int|null $actorMemberId 변경을 실행한 관리자 (검증 요청자와 다를 수 있음)
     * @return Result 성공 시 data.verification_id
     */
    public function consumeForChange(
        string $host,
        ?int $domainId,
        ?string $previousHost = null,
        ?int $actorMemberId = null
    ): Result {
        $host = self::normalizeHost($host);

        $row = $this->repository->findUsablePassed($host, $domainId);

        if (!$row) {
            return Result::failure('DNS 확인을 통과한 기록이 없습니다. "DNS 확인"을 먼저 실행하세요. (확인 후 30분간 유효)');
        }

        $consumed = $this->repository->consume(
            (int) $row['verification_id'],
            $previousHost !== null ? self::normalizeHost($previousHost) : null,
            $actorMemberId
        );

        if (!$consumed) {
            return Result::failure('이미 사용된 검증입니다. "DNS 확인"을 다시 실행하세요.');
        }

        return Result::success('검증 확인됨', [
            'verification_id' => (int) $row['verification_id'],
            'verdict' => (string) ($row['verdict'] ?? ''),
        ]);
    }

    /**
     * 도메인의 실제 변경 이력 (확인에서 끝난 기록은 제외)
     *
     * @return array<int,array<string,mixed>> 최신순
     */
    public function getChangeHistory(int $domainId, int $limit = 10): array
    {
        if ($domainId <= 0) {
            return [];
        }

        return $this->repository->findChangeHistory($domainId, $limit);
    }

    // =========================================================================
    // 프로브 응답 (공개 엔드포인트가 호출)
    // =========================================================================

    /**
     * 프로브 요청이 우리가 발급한 nonce인지 확인
     *
     * 아직 등록되지 않은 호스트로 들어오는 요청이므로 도메인 정보에 의존하지 않는다.
     * nonce는 pending·미만료·같은 호스트일 때만 인정한다.
     */
    public function acceptProbe(string $host, string $nonce): bool
    {
        $host = self::normalizeHost($host);

        if ($host === '' || !preg_match('/^[0-9a-f]{64}$/', $nonce)) {
            return false;
        }

        return $this->repository->findLiveNonce($host, $nonce) !== null;
    }

    // =========================================================================
    // 측정 도구
    // =========================================================================

    /**
     * DNS 레코드 조회 (보고용)
     *
     * 하위 클래스가 대체할 수 있게 protected로 둔다 (테스트에서 네트워크 없이 판정 검증).
     *
     * @return array{a:string[],aaaa:string[],cname:string[],error:string}
     */
    protected function lookupDns(string $host): array
    {
        $result = ['a' => [], 'aaaa' => [], 'cname' => [], 'error' => ''];

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            // IP 직접 지정은 조회 대상이 아니다
            if ($host !== '') {
                $result['a'] = [$host];
            }
            return $result;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);

        if ($records === false) {
            $result['error'] = 'DNS 조회에 실패했습니다.';
            return $result;
        }

        foreach ($records as $record) {
            switch ($record['type'] ?? '') {
                case 'A':
                    $result['a'][] = (string) ($record['ip'] ?? '');
                    break;
                case 'AAAA':
                    $result['aaaa'][] = (string) ($record['ipv6'] ?? '');
                    break;
                case 'CNAME':
                    $result['cname'][] = (string) ($record['target'] ?? '');
                    break;
            }
        }

        return $result;
    }

    /**
     * 루프백 프로브 — 새 호스트로 요청을 보내 우리 앱이 응답하는지 확인
     *
     * http를 먼저 시도하고 실패 시 https를 시도한다(반대 순서면 인증서 미발급
     * 상태의 신규 도메인이 전부 실패한다). TLS 검증은 끄는데, 이 프로브가 확인하는
     * 것은 "도달"이지 인증서 유효성이 아니며 진위는 nonce로 판정하기 때문이다.
     *
     * 하위 클래스가 대체할 수 있게 protected로 둔다 (테스트에서 네트워크 없이 판정 검증).
     *
     * @return array{url:string,http_code:int,ok:bool,error:string}
     */
    protected function probe(string $host, string $nonce): array
    {
        $query = '?nonce=' . urlencode($nonce);
        $last = ['url' => '', 'http_code' => 0, 'ok' => false, 'error' => 'cURL을 사용할 수 없습니다.'];

        if (!function_exists('curl_init')) {
            return $last;
        }

        foreach (['http', 'https'] as $scheme) {
            $url = $scheme . '://' . $host . self::PROBE_PATH . $query;
            $attempt = $this->requestProbe($url, $host, $nonce);
            $last = $attempt;

            if ($attempt['ok']) {
                return $attempt;
            }
        }

        return $last;
    }

    /**
     * @return array{url:string,http_code:int,ok:bool,error:string}
     */
    private function requestProbe(string $url, string $host, string $nonce): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => self::PROBE_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::PROBE_TIMEOUT,
            // 도달 확인이 목적이므로 인증서는 보지 않는다 (진위는 nonce가 판정).
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            // 관리자 입력 호스트로 나가는 요청이므로 http(s) 외 프로토콜·리다이렉트는 차단한다.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mublo-Domain-Verify/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['url' => $url, 'http_code' => $httpCode, 'ok' => false, 'error' => $error ?: '연결 실패'];
        }

        return [
            'url' => $url,
            'http_code' => $httpCode,
            'ok' => $httpCode === 200 && $this->matchesProbeResponse((string) $body, $host, $nonce),
            'error' => '',
        ];
    }

    /**
     * 프로브 응답이 우리 앱의 응답인지 확인
     *
     * 응답에 실린 host/nonce가 요청한 값과 같아야 한다 — 다른 사이트가 200을
     * 반환하거나, 다른 호스트의 응답이 프록시로 섞여 오는 경우를 걸러낸다.
     */
    private function matchesProbeResponse(string $body, string $host, string $nonce): bool
    {
        $json = json_decode($body, true);

        if (!is_array($json)) {
            return false;
        }

        $data = $json['data'] ?? [];

        return ($json['result'] ?? '') === 'success'
            && ($data['verified'] ?? false) === true
            && hash_equals($nonce, (string) ($data['nonce'] ?? ''))
            && self::normalizeHost((string) ($data['host'] ?? '')) === $host;
    }

    /**
     * 개발환경 로컬 호스트 판정
     *
     * 개발 APP_ENV 이고, 호스트가 로컬 접미사이거나 루프백/사설 IP로 해석될 때만 true.
     *
     * @see self::DEV_LOCAL_ENVS APP_DEBUG가 아니라 APP_ENV로 판정하는 이유
     */
    private function isDevLocalHost(string $host): bool
    {
        $appEnv = strtolower(trim((string) Env::get('APP_ENV', 'production')));

        if (!in_array($appEnv, self::DEV_LOCAL_ENVS, true)) {
            return false;
        }

        $hostOnly = $this->stripPort($host);

        foreach (self::DEV_LOCAL_SUFFIXES as $suffix) {
            if ($hostOnly === $suffix || str_ends_with($hostOnly, $suffix)) {
                return true;
            }
        }

        $ips = filter_var($hostOnly, FILTER_VALIDATE_IP)
            ? [$hostOnly]
            : (gethostbynamel($hostOnly) ?: []);

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true; // 사설/예약 대역 = 로컬 환경
            }
        }

        return false;
    }

    /**
     * 포트 제거 (test.localhost:8080 → test.localhost)
     */
    private function stripPort(string $host): string
    {
        if (preg_match('/^(.+):\d+$/', $host, $m)) {
            return $m[1];
        }

        return $host;
    }
}
