<?php
declare(strict_types=1);

namespace Mublo\Contract\Board;

use Mublo\Core\Result\Result;

/**
 * BoardProvisioningInterface
 *
 * 확장이 사이트를 프로그래밍으로 구축할 때 게시판을 멱등 생성한다.
 *
 * 코어가 인터페이스를 정의하고 Board 패키지가 구현한다 —
 * `Contract\Faq\FaqQueryInterface` 와 같은 구조다. 확장 안에 두면
 * `tools/check-extension-api.php` 의 `Contract\Extension\` 예외가 패키지 종속
 * 플러그인 전용이라 형제 확장이 소비할 수 없다.
 *
 * @see \Mublo\Contract\Site\SiteProvisioningInterface 메뉴·설정·메인화면 프로비저닝
 */
interface BoardProvisioningInterface
{
    /**
     * 게시판 그룹을 멱등 보장
     *
     * `$provisioningKey` 는 `board_groups.group_slug` 로 쓰인다 —
     * `UNIQUE(domain_id, group_slug)` 가 동시 재시도를 막는다.
     *
     * @param array $preset group_name 등. 기존 그룹이 있으면 **덮지 않는다**
     * @return Result 성공 data: {group_id: int, group_slug: string, created: bool}
     */
    public function ensureGroup(int $domainId, string $provisioningKey, array $preset = []): Result;

    /**
     * 게시판을 멱등 보장
     *
     * `$provisioningKey` 는 `board_configs.board_slug` 로 쓰인다 —
     * `UNIQUE(domain_id, board_slug)` 가 동시 재시도를 막는다.
     *
     * @param array $preset board_name · group_slug · skin 등.
     *                      기존 게시판이 있으면 **덮지 않는다** — 운영자 편집을 보존한다
     * @return Result 성공 data: {board_id: int, board_slug: string, created: bool}
     */
    public function ensureBoard(int $domainId, string $provisioningKey, array $preset = []): Result;
}
