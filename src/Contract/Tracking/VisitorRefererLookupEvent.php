<?php
declare(strict_types=1);

namespace Mublo\Contract\Tracking;

use Mublo\Core\Event\AbstractEvent;

/**
 * 방문 유입경로(레퍼러) 조회 — 관리자 목록에서 "이 고객이 어디서 들어왔나" 표시용
 *
 * 패키지는 방문자 통계 로그를 직접 읽지 않고 이 이벤트로 묻는다.
 * 플러그인이 꺼져 있거나 로그가 지워졌으면(보관 30일) 결과가 비어 표시하지 않는다.
 */
class VisitorRefererLookupEvent extends AbstractEvent
{
    /** @var array<string|int, array{type:string, domain:string, url:string, landing_url:string}> */
    private array $results = [];

    /**
     * @param array<string|int, array{domain_id:int, session_id:?string, ip:?string, at:string}> $lookups
     *        [참조키 => 조회 조건] — at 은 그 시점(Y-m-d H:i:s) 이전의 같은 날 방문을 찾는다
     */
    public function __construct(public readonly array $lookups) {}

    /**
     * @param string $type   direct | search | social | external
     * @param string $domain 검색엔진·SNS 이름 또는 외부 호스트
     */
    public function setResult(string|int $ref, string $type, string $domain, string $url = '', string $landingUrl = ''): void
    {
        $this->results[$ref] = [
            'type' => $type,
            'domain' => $domain,
            'url' => $url,
            'landing_url' => $landingUrl,
        ];
    }

    /** @return array{type:string, domain:string, url:string, landing_url:string}|null */
    public function getResult(string|int $ref): ?array
    {
        return $this->results[$ref] ?? null;
    }

    /** @return array<string|int, array{type:string, domain:string, url:string, landing_url:string}> */
    public function getResults(): array
    {
        return $this->results;
    }
}
