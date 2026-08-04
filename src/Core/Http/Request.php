<?php
declare(strict_types=1);
namespace Mublo\Core\Http;

/**
 * Class Request
 *
 * HTTP 요청 정보를 캡슐화하는 객체
 *
 * 책임:
 * - PHP 전역 변수($_SERVER, $_GET, $_POST 등) 접근을 이 클래스 하나로 제한
 * - 요청 메서드, URI, 쿼리 파라미터 보관
 * - PayloadType 판별 (JSON / FORM / QUERY)
 *
 * 금지:
 * - 인증 판단
 * - 권한 판단
 * - 비즈니스 로직
 * - DB / Session 직접 접근
 */
class Request
{
    public const PAYLOAD_JSON = 'json';
    public const PAYLOAD_FORM = 'form';
    public const PAYLOAD_QUERY = 'query';

    /**
     * 신뢰 프록시 목록
     * - 빈 배열: 프록시 헤더 무시 (REMOTE_ADDR만 사용)
     * - ['*']: 모든 프록시 신뢰
     * - ['192.168.1.0/24']: 특정 IP/CIDR만 신뢰
     */
    protected static array $trustedProxies = [];

    /**
     * HTTP Method (GET, POST, PUT, DELETE ...)
     */
    protected string $method;

    /**
     * 요청 URI (query string 제외)
     */
    protected string $uri;

    /**
     * Query Parameters ($_GET)
     */
    protected array $query = [];

    /**
     * Request Body ($_POST)
     */
    protected array $body = [];

    /**
     * Server Parameters ($_SERVER)
     */
    protected array $server = [];

    /**
     * Uploaded Files ($_FILES)
     */
    protected array $files = [];

    /**
     * Cookie Parameters ($_COOKIE)
     */
    protected array $cookies = [];

    /**
     * JSON Input (php://input 파싱 결과)
     */
    protected ?array $jsonInput = null;

    /**
     * JSON parse error message, if the request body was invalid JSON.
     */
    protected ?string $jsonParseError = null;

    /**
     * 잘못된 요청 사유. 라우팅 전에 위험한 경로(널바이트·인코딩된 구분자 등)를
     * 감지하면 설정되며, Application이 400으로 응답한다.
     */
    protected ?string $invalidReason = null;

    /**
     * 생성자
     * - Application 단계에서 생성됨
     */
    public function __construct(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        array $server = [],
        array $files = [],
        array $cookies = []
    ) {
        $this->method  = strtoupper($method);
        $this->uri     = $uri;
        $this->query   = $query;
        $this->body    = $body;
        $this->server  = $server;
        $this->files   = $files;
        $this->cookies = $cookies;
    }

    /**
     * HTTP Method 반환
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * URI 반환 (query 제외)
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * 전체 Query Parameters 반환
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * 특정 Query Parameter 반환
     */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Request Body 반환
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * 특정 Body 값 반환
     */
    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * POST 데이터 반환 (input 별칭)
     */
    public function post(string $key, $default = null)
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * GET 파라미터 반환 (query 별칭)
     */
    public function get(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Server 값 반환
     */
    public function server(string $key, $default = null)
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * 특정 Cookie 값 반환
     */
    public function cookie(string $key, $default = null)
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * 요청 경로
     *
     * 예:
     *  /               → /
     *  /board/list     → /board/list
     */
    public function getPath(): string
    {
        // URI는 이미 query string 제거된 상태
        return $this->uri === '' ? '/' : $this->uri;
    }

    /**
     * 호스트명 반환
     *
     * 예:
     *  localhost
     *  example.com
     *  www.example.com:8080
     */
    public function getHost(): ?string
    {
        return $this->normalizeHost($this->server['HTTP_HOST'] ?? null);
    }

    /**
     * HTTPS 여부 반환
     */
    public function isHttps(): bool
    {
        if (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return true;
        }
        if (($this->server['SERVER_PORT'] ?? null) == 443) {
            return true;
        }
        // 리버스 프록시 지원 (X-Forwarded-Proto) — 신뢰 프록시에서만 허용
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($this->isFromTrustedProxy($remoteAddr)
            && isset($this->server['HTTP_X_FORWARDED_PROTO'])
            && $this->server['HTTP_X_FORWARDED_PROTO'] === 'https'
        ) {
            return true;
        }
        return false;
    }

    /**
     * 스킴(http/https) 반환
     */
    public function getScheme(): string
    {
        return $this->isHttps() ? 'https' : 'http';
    }

    /**
     * 스킴 + 호스트 반환 (예: https://shop.mublo.kr)
     */
    public function getSchemeAndHost(): string
    {
        return $this->getScheme() . '://' . ($this->getHost() ?? 'localhost');
    }

    /**
     * Host 헤더 정규화.
     *
     * 허용:
     * - example.com
     * - example.com:8080
     * - localhost
     * - 127.0.0.1:8080
     * - [::1]:8080
     */
    private function normalizeHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        if (preg_match('/[\x00-\x20\x7f\/\\\\@#?]/', $host) === 1) {
            return null;
        }

        if (str_starts_with($host, '[')) {
            if (preg_match('/^\[([0-9a-f:.]+)\](?::(\d{1,5}))?$/', $host, $matches) !== 1) {
                return null;
            }

            if (!filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return null;
            }

            return $this->hasValidPort($matches[2] ?? null) ? $host : null;
        }

        if (preg_match('/^([^:]+)(?::(\d{1,5}))?$/', $host, $matches) !== 1) {
            return null;
        }

        $name = $matches[1];
        if (!$this->isValidHostName($name)) {
            return null;
        }

        return $this->hasValidPort($matches[2] ?? null) ? $host : null;
    }

    private function isValidHostName(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        if (strlen($host) > 253 || str_contains($host, '..')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === ''
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    private function hasValidPort(?string $port): bool
    {
        if ($port === null || $port === '') {
            return true;
        }

        $portNumber = (int) $port;
        return (string) $portNumber === $port && $portNumber >= 1 && $portNumber <= 65535;
    }

    /**
     * JSON 입력 설정 (php://input 파싱 결과)
     */
    public function setJsonInput(?array $jsonInput): void
    {
        $this->jsonInput = $jsonInput;
        $this->jsonParseError = null;
    }

    /**
     * JSON 입력 반환
     */
    public function getJsonInput(): ?array
    {
        return $this->jsonInput;
    }

    public function setJsonParseError(string $message): void
    {
        $this->jsonInput = null;
        $this->jsonParseError = $message;
    }

    public function hasJsonParseError(): bool
    {
        return $this->jsonParseError !== null;
    }

    public function getJsonParseError(): ?string
    {
        return $this->jsonParseError;
    }

    /**
     * 요청을 잘못된 것으로 표시(라우팅 전 위험 경로 등). Application이 400으로 응답.
     */
    public function setInvalid(string $reason): void
    {
        $this->invalidReason = $reason;
    }

    public function isInvalid(): bool
    {
        return $this->invalidReason !== null;
    }

    public function getInvalidReason(): ?string
    {
        return $this->invalidReason;
    }

    /**
     * JSON 입력에서 특정 값 반환 (키 생략 시 전체 반환)
     */
    public function json(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->jsonInput;
        }
        return $this->jsonInput[$key] ?? $default;
    }

    /**
     * PayloadType 판별
     *
     * @return string PAYLOAD_JSON | PAYLOAD_FORM | PAYLOAD_QUERY
     */
    public function getPayloadType(): string
    {
        $contentType = $this->getContentType();

        if ($contentType && str_contains($contentType, 'application/json')) {
            return self::PAYLOAD_JSON;
        }

        if ($this->method === 'POST' && !empty($this->body)) {
            return self::PAYLOAD_FORM;
        }

        return self::PAYLOAD_QUERY;
    }

    /**
     * Content-Type 헤더 반환
     */
    public function getContentType(): ?string
    {
        return $this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? null;
    }

    /**
     * AJAX 요청 여부 판별
     */
    public function isAjax(): bool
    {
        $xRequestedWith = $this->server['HTTP_X_REQUESTED_WITH'] ?? '';
        return strtolower($xRequestedWith) === 'xmlhttprequest';
    }

    /**
     * JSON 요청 여부 판별
     */
    public function isJson(): bool
    {
        return $this->getPayloadType() === self::PAYLOAD_JSON;
    }

    /**
     * HTTP 헤더 반환
     */
    public function header(string $key, $default = null): ?string
    {
        // HTTP_로 시작하는 서버 변수 검색
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $this->server[$serverKey] ?? $default;
    }

    /**
     * Bearer 토큰 추출
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    /**
     * 통합 입력값 반환 (PayloadType에 따라 적절한 소스에서 가져옴)
     */
    public function all(): array
    {
        $payloadType = $this->getPayloadType();

        return match ($payloadType) {
            self::PAYLOAD_JSON => $this->jsonInput ?? [],
            self::PAYLOAD_FORM => $this->body,
            default => $this->query,
        };
    }

    /**
     * 통합 입력값에서 특정 키 반환
     */
    public function getData(string $key, $default = null)
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    /**
     * 신뢰 프록시 설정 (Application 초기화 시 호출)
     *
     * @param array $proxies 신뢰 프록시 목록 (IP 또는 CIDR)
     */
    public static function setTrustedProxies(array $proxies): void
    {
        self::$trustedProxies = $proxies;
    }

    /**
     * 클라이언트 IP 주소 반환
     *
     * 프록시 환경 고려 (X-Forwarded-For, X-Real-IP)
     * 신뢰 프록시가 설정된 경우에만 프록시 헤더 사용
     */
    public function getClientIp(): string
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';

        // 신뢰 프록시가 설정되지 않았거나 현재 요청이 신뢰 프록시에서 온 게 아니면
        // REMOTE_ADDR만 반환
        if (!$this->isFromTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        // Cloudflare: 신뢰 프록시에서 들어온 요청일 때만 CF-Connecting-IP 사용.
        // 신뢰 프록시가 전달했다는 사실과 값이 유효한 IP라는 사실은 별개이므로,
        // 형식이 잘못되면 감사로그·레이트리밋 키를 오염시키지 않고 직전 홉으로 폴백한다.
        if (!empty($this->server['HTTP_CF_CONNECTING_IP'])) {
            return $this->normalizeForwardedIp($this->server['HTTP_CF_CONNECTING_IP']) ?? $remoteAddr;
        }

        if (!empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            // XFF 체인: "client, proxy1, proxy2" — 오른쪽일수록 우리에게 가까운 홉이다.
            // REMOTE_ADDR(직전 홉)이 신뢰 프록시임을 이미 확인했으므로, 오른쪽부터 신뢰 프록시를
            // 벗겨내고 첫 '비신뢰' IP 를 클라이언트로 채택한다. 최좌측을 그대로 쓰면 클라이언트가
            // 임의 값을 prepend 해 IP 를 위조(레이트리밋·감사로그·차단 우회)할 수 있다.
            $ips = array_map('trim', explode(',', $this->server['HTTP_X_FORWARDED_FOR']));
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                $ip = $this->normalizeForwardedIp($ips[$i]);
                if ($ip === null) {
                    // 체인 중간의 빈 값·임의 문자열을 건너뛰면 그 왼쪽의 공격자 제공 값을
                    // 실제 클라이언트로 오인할 수 있다. 체인 전체를 불신하고 직전 홉으로 폴백한다.
                    return $remoteAddr;
                }
                if (!$this->isFromTrustedProxy($ip)) {
                    return $ip;
                }
            }
            // 체인 전체가 신뢰 프록시면 최좌측을 원 클라이언트로 간주.
            // 최좌측이 비어("" — 예: ", 10.0.0.9") 있으면 빈 IP 가 레이트리밋·감사로그로
            // 새지 않도록 REMOTE_ADDR 로 폴백한다.
            return $this->normalizeForwardedIp($ips[0]) ?? $remoteAddr;
        }

        if (!empty($this->server['HTTP_X_REAL_IP'])) {
            return $this->normalizeForwardedIp($this->server['HTTP_X_REAL_IP']) ?? $remoteAddr;
        }

        if (!empty($this->server['HTTP_CLIENT_IP'])) {
            return $this->normalizeForwardedIp($this->server['HTTP_CLIENT_IP']) ?? $remoteAddr;
        }

        return $remoteAddr;
    }

    /**
     * XFF 항목에서 포트/브래킷 래퍼를 제거해 순수 IP 만 남긴다.
     *
     * 일부 프록시는 XFF 에 "1.2.3.4:5678" 또는 "[2001:db8::1]:443" 형태로 포트를 붙인다.
     * 이대로 ipMatches(inet_pton)에 넣으면 실패해 신뢰 프록시 홉을 인식하지 못하고, 그 값을
     * 그대로 클라이언트 IP 로 반환해 쓰레기 IP(포트 포함)가 레이트리밋·감사로그로 새어나간다.
     */
    private function stripPortFromIp(string $entry): string
    {
        $entry = trim($entry);
        if ($entry === '') {
            return '';
        }

        // 브래킷 IPv6: [2001:db8::1] 또는 [2001:db8::1]:443 → 대괄호 안의 주소만
        if ($entry[0] === '[') {
            $close = strpos($entry, ']');
            return $close !== false ? substr($entry, 1, $close - 1) : $entry;
        }

        // 콜론이 정확히 하나면 IPv4:port → host 만. IPv6 는 콜론이 여러 개라 그대로 둔다.
        if (substr_count($entry, ':') === 1) {
            return substr($entry, 0, (int) strpos($entry, ':'));
        }

        return $entry;
    }

    /**
     * 전달 헤더의 한 항목을 순수하고 유효한 IPv4/IPv6 주소로 정규화한다.
     *
     * @return string|null 유효한 IP, 형식이 잘못됐거나 비어 있으면 null
     */
    private function normalizeForwardedIp(string $entry): ?string
    {
        $ip = $this->stripPortFromIp($entry);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }

    /**
     * 요청이 신뢰 프록시에서 왔는지 확인
     */
    private function isFromTrustedProxy(string $remoteAddr): bool
    {
        // 신뢰 프록시가 설정되지 않음
        if (empty(self::$trustedProxies)) {
            return false;
        }

        // 모든 프록시 신뢰 ('*')
        if (in_array('*', self::$trustedProxies, true)) {
            return true;
        }

        foreach (self::$trustedProxies as $proxy) {
            if ($this->ipMatches($remoteAddr, $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IP 주소가 CIDR 패턴과 일치하는지 확인
     */
    private function ipMatches(string $ip, string $cidr): bool
    {
        // CIDR 패턴이 아니면 단일 IP 비교. inet_pton 이진 비교로 IPv6 표기 차이
        // (대문자/축약 vs 비압축)를 흡수한다 — 문자열 === 만으로는 2400:CB00::1 과
        // 2400:cb00:0:0:0:0:0:1 이 같은 주소인데도 불일치해 신뢰 프록시 판정이 새어나간다.
        if (!str_contains($cidr, '/')) {
            if ($ip === $cidr) {
                return true;
            }
            $ipBin = @inet_pton($ip);
            $cidrBin = @inet_pton($cidr);
            return $ipBin !== false && $cidrBin !== false && $ipBin === $cidrBin;
        }

        [$subnet, $bitsRaw] = explode('/', $cidr, 2);

        // 프리픽스 길이가 숫자가 아니면(설정 오타 '/abc' · 빈 '/' 등) fail-closed.
        // (int) 캐스팅은 'abc'→0 으로 삼켜 $bits===0 → match-all(모든 IP를 신뢰 프록시로
        // 오판 → XFF 스푸핑)이 되므로, 반드시 숫자 검사를 먼저 한다.
        if ($bitsRaw === '' || !ctype_digit($bitsRaw)) {
            return false;
        }
        $bits = (int) $bitsRaw;

        // inet_pton 으로 이진 변환 — IPv4(4바이트)·IPv6(16바이트) 모두 지원.
        // 기존 ip2long 은 IPv4 전용이라 IPv6 프록시 CIDR(예: 2400:cb00::/32)이 항상 실패해,
        // IPv6 CDN 뒤에서는 신뢰 프록시 판정이 안 돼 XFF/CF-IP 가 전부 무시됐다.
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        // 주소 패밀리(길이)가 다르면(IPv4 4바이트 vs IPv6 16바이트) 매칭 불가
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits > $maxBits) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        // 바이트 단위 마스크 비교
        $fullBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainderBits === 0) {
            return true;
        }
        $mask = 0xFF << (8 - $remainderBits) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    // === File Methods ===

    /**
     * 업로드된 파일 전체 배열 반환 (raw $_FILES 구조)
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * 특정 키의 파일 존재 여부
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key])
            && ($this->files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * 특정 키의 raw 파일 배열 반환
     *
     * 중첩 구조(column_images[col][img][pc]) 등을 직접 처리할 때 사용
     */
    public function getRawFile(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }
}
