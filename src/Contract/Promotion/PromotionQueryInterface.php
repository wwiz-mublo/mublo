<?php
declare(strict_types=1);

namespace Mublo\Contract\Promotion;

/**
 * 이벤트(프로모션) 조회 계약
 *
 * 구현: Promotion 플러그인 (1:1 바인딩).
 * 소비: 블록·다른 패키지의 스킨/JSON API 표면.
 *
 * 반환은 배열 + 셰이프 주석 — 목록이 블록·프론트 스킨·JSON 으로 흐르는
 * 데이터라 snake_case 키 자체가 외부 계약이다 (FaqQueryInterface 축,
 * docs/dev-guide/contract-system.md "계약 반환 타입 2단 원칙" 참고).
 *
 * 공통 항목 셰이프:
 * array{
 *   event_id: int,
 *   title: string,
 *   thumbnail_url: string,       // 등록 썸네일, 없으면 본문 첫 이미지, 그것도 없으면 ''
 *   starts_at: ?string,          // 'Y-m-d H:i:s', null = 제한 없음
 *   ends_at: ?string,
 *   is_always: bool,             // 시작·종료 모두 비어 있음 (상시)
 *   status: string,              // 'upcoming' | 'ongoing' | 'ended'
 *   comment_enabled: bool,
 *   comment_count: int,
 *   view_count: int,
 *   url: string,                 // 상세 페이지 경로 (/promotion/{id})
 *   created_at: string
 * }
 */
interface PromotionQueryInterface
{
    /**
     * 진행중 이벤트 (상시 포함, sort_order → 최신순)
     *
     * @return list<array{event_id:int, title:string, thumbnail_url:string, starts_at:?string,
     *   ends_at:?string, is_always:bool, status:string, comment_enabled:bool,
     *   comment_count:int, view_count:int, url:string, created_at:string}>
     */
    public function getOngoing(int $domainId, int $limit = 10): array;

    /**
     * 이벤트 목록 (페이지네이션)
     *
     * @param string $status 'all' | 'ongoing' | 'upcoming' | 'ended'
     * @return array{items: list<array{event_id:int, title:string, thumbnail_url:string,
     *   starts_at:?string, ends_at:?string, is_always:bool, status:string, comment_enabled:bool,
     *   comment_count:int, view_count:int, url:string, created_at:string}>,
     *   totalItems:int, currentPage:int, perPage:int, totalPages:int}
     */
    public function getList(int $domainId, string $status = 'all', int $page = 1, int $perPage = 12): array;

    /**
     * 이벤트 단건 (본문 포함). 비활성/미존재 시 null.
     *
     * @return ?array{event_id:int, title:string, content:string, thumbnail_url:string,
     *   starts_at:?string, ends_at:?string, is_always:bool, status:string, comment_enabled:bool,
     *   comment_count:int, view_count:int, url:string, created_at:string}
     */
    public function findById(int $domainId, int $eventId): ?array;
}
