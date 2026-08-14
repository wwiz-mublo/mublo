<?php
declare(strict_types=1);

namespace Mublo\Service\Editor;

use Mublo\Infrastructure\Cache\CacheInterface;

/**
 * OgMetaFetcher
 *
 * 에디터의 링크 카드가 쓰는 OG 메타 수집기. 회원이 붙여넣은 주소로 서버가 대신
 * 나가서 제목·설명·대표이미지를 읽어 온다.
 *
 * ## 이 클래스의 위험과 방어
 *
 * 사용자가 준 주소로 서버가 요청을 보내므로, 그대로 두면 외부에서 내부망을
 * 두드리는 통로(SSRF)가 된다. 방어는 층으로 쌓는다.
 *
 * - 스킴은 http/https, 포트는 80/443 만. `file:`·`gopher:` 같은 통로를 닫는다.
 * - `user:pass@` 가 붙은 주소는 거부한다. 파서마다 호스트를 다르게 읽어
 *   검사한 곳과 요청 가는 곳이 갈릴 수 있다.
 * - DNS 를 직접 풀어 공인 IP 인지 본다. 사설·루프백·링크로컬·예약 대역이면 막는다.
 * - **검사한 IP 로 못을 박아 요청한다(CURLOPT_RESOLVE).** 검사할 때와 요청할 때
 *   각각 DNS 를 풀면 그 사이에 응답이 내부 IP 로 바뀌는 수법(DNS 리바인딩)에
 *   당한다. 검사와 접속이 같은 주소를 보게 만드는 것이 이 방어의 핵심이다.
 * - 리다이렉트는 curl 에 맡기지 않고 손으로 따라가며 매번 다시 검사한다.
 *   자동 추적은 첫 검사를 통과한 뒤 내부로 튀는 경로를 열어 준다.
 * - 3초 타임아웃과 512KB 상한. 응답 본문은 HTML 일 때만 파싱한다.
 *
 * 결과는 캐시에 담는다. 같은 링크를 붙여넣을 때마다 나가지 않게 하는 것이
 * 목적이고, 남용 자체는 호출부(레이트 리밋)가 막는다.
 */
final class OgMetaFetcher
{
    private const MAX_REDIRECTS = 3;
    private const CONNECT_TIMEOUT = 3;
    private const TOTAL_TIMEOUT = 5;
    private const MAX_BYTES = 524288;   // 512KB
    private const CACHE_TTL = 86400;    // 24h

    public function __construct(private readonly ?CacheInterface $cache = null)
    {
    }

    /**
     * @return array{title: string, description: string, image: string, host: string}|null
     *         읽을 수 없으면 null
     */
    public function fetch(string $url): ?array
    {
        if ($this->resolve($url) === null) {
            return null;
        }

        $cacheKey = 'editor:og:' . md5($url);
        $cached = $this->cache?->get($cacheKey);
        if (is_array($cached)) {
            /** @var array{title: string, description: string, image: string, host: string} $cached */
            return $cached;
        }

        $html = $this->fetchHtml($url);
        if ($html === null) {
            return null;
        }

        $meta = $this->parseMeta($html, $url);
        $this->cache?->set($cacheKey, $meta, self::CACHE_TTL);

        return $meta;
    }

    /**
     * 주소를 검사하고 접속할 호스트·포트·IP 를 확정한다.
     *
     * @return array{host: string, port: int, ip: string}|null 통과하지 못하면 null
     */
    public function resolve(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // 자격증명이 붙은 주소는 파서마다 호스트를 다르게 읽는다 — 받지 않는다.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) {
            return null;
        }

        $host = (string) $parts['host'];
        $ips = $this->lookup($host);
        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return null;
            }
        }

        // 여러 개면 첫 번째로 못을 박는다. 검사한 것과 접속하는 것이 같아야 한다.
        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** @return list<string> */
    private function lookup(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $ips[] = (string) $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** 리다이렉트를 손으로 따라가며 매 단계 다시 검사한다 */
    private function fetchHtml(string $url): ?string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->resolve($url);
            if ($target === null) {
                return null;
            }

            $body = '';
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                // 자동 추적은 첫 검사를 통과한 뒤 내부로 튀는 경로를 연다
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
                CURLOPT_USERAGENT      => 'Mublo-Editor-OG/1.0',
                CURLOPT_HTTPHEADER     => ['Accept: text/html'],
                // 검사한 IP 로 못 박기 (DNS 리바인딩 차단)
                CURLOPT_RESOLVE        => [$this->resolveEntry($target)],
                CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$body): int {
                    $body .= $chunk;

                    // 상한을 넘으면 0 을 돌려 전송을 끊는다 (curl 은 에러로 끝나지만
                    // 여기까지 받은 본문은 남아 있고, OG 태그는 대개 head 에 있다)
                    return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk);
                },
            ]);

            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $redirect = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($status >= 300 && $status < 400 && $redirect !== '') {
                $url = $redirect;
                continue;
            }

            if ($status < 200 || $status >= 300 || $body === '') {
                return null;
            }

            // HTML 이 아니면 파싱하지 않는다
            if ($contentType !== ''
                && stripos($contentType, 'text/html') === false
                && stripos($contentType, 'xhtml') === false
            ) {
                return null;
            }

            return $body;
        }

        return null;
    }

    /** @param array{host: string, port: int, ip: string} $target */
    private function resolveEntry(array $target): string
    {
        // IPv6 는 대괄호로 감싸야 curl 이 주소와 구분한다
        $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];

        return $target['host'] . ':' . $target['port'] . ':' . $ip;
    }

    /**
     * HTML 에서 메타를 뽑는다 (순수 함수 — 네트워크를 타지 않는다)
     *
     * @return array{title: string, description: string, image: string, host: string}
     */
    public function parseMeta(string $html, string $url): array
    {
        $meta = [
            'title'       => $this->metaContent($html, 'og:title'),
            'description' => $this->metaContent($html, 'og:description'),
            'image'       => $this->metaContent($html, 'og:image'),
        ];

        if ($meta['title'] === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            $meta['title'] = trim($this->decode($m[1]));
        }

        if ($meta['description'] === '') {
            $meta['description'] = $this->metaContent($html, 'description');
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

        return [
            'title'       => mb_substr($meta['title'], 0, 300),
            'description' => mb_substr($meta['description'], 0, 500),
            'image'       => mb_substr($this->absoluteImage($meta['image'], $url), 0, 2000),
            'host'        => (string) preg_replace('/^www\./', '', $host),
        ];
    }

    /** 속성 순서가 어느 쪽이든(property 먼저 / content 먼저) 읽는다 */
    private function metaContent(string $html, string $property): string
    {
        $quoted = preg_quote($property, '/');

        $patterns = [
            '/<meta[^>]+(?:property|name)=["\']' . $quoted . '["\'][^>]+content=["\']([^"\']*)["\']/i',
            '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . $quoted . '["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                return $this->decode($m[1]);
            }
        }

        return '';
    }

    /** 상대 경로 이미지는 같은 호스트 기준으로 채우고, http(s) 가 아니면 버린다 */
    private function absoluteImage(string $image, string $url): string
    {
        if ($image === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $image) === 1) {
            return $image;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || !str_starts_with($image, '/')) {
            return '';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . $image;
    }

    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
