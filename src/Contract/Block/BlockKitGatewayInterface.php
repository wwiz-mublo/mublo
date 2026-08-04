<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

use Mublo\Core\Result\Result;

/**
 * 블록 킷 게이트웨이 계약
 *
 * 코어(BlockKitGateway)가 구현하고, 킷을 제공하는 확장이 소비합니다.
 * 코어가 DI 컨테이너에 단일 구현을 제공하므로 생성자 타입힌트로 주입받습니다.
 *
 * 확장이 배포하는 번들 킷의 보관함 등록과 page 킷 적용을 안정 API로 제공한다 —
 * 확장이 block_kits 스키마나 BlockKitApplier/BlockKitLibrary 내부에 직접
 * 의존하지 않게 하기 위한 정식 통로다.
 */
interface BlockKitGatewayInterface
{
    public const MODE_APPEND = 'append';
    public const MODE_REPLACE = 'replace';

    /**
     * 번들 킷을 블록 킷 보관함(block_kits)에 등록 (멱등)
     *
     * - 킷 JSON 검증(dryRun) 실패 시 실패 Result
     * - 같은 이름의 번들(bundled) 킷이 이미 있으면 건너뜀 — 단, 스크린샷 없이
     *   등록된 기존 킷은 킷 JSON·스크린샷을 백필한다
     * - 킷 JSON 에 임베드된 스크린샷(png/webp data URI)을 목록 썸네일로 굽는다
     *
     * @param string $json 킷 JSON 원문 (mublo-starter-kit 형식)
     * @return Result 성공 data: {kit_id: int|null, status: 'created'|'skipped'|'backfilled'}
     */
    public function registerBundled(int $domainId, string $json): Result;

    /**
     * page 타깃 블록 킷 적용 — 신규 페이지면 생성, 기존 페이지면 mode 에 따라
     * 이어붙이기(append)/디자인 교체(replace)
     *
     * @param array $kit 디코딩된 킷 JSON (target.kind 가 'page' 여야 한다)
     * @param string $mode self::MODE_APPEND | self::MODE_REPLACE
     * @return Result 성공 data: {summary: array{page_id, created_page, created_rows,
     *                deleted_rows, ...}, warnings: string[]}
     */
    public function applyPage(int $domainId, array $kit, string $mode = self::MODE_REPLACE): Result;

    /**
     * position 타깃 블록 킷 적용 — 메인화면(`index`) 처럼 페이지가 아니라
     * **자리**에 붙는 블록
     *
     * `applyPage` 와 갈라 두는 이유는 replace 의 삭제 범위가 다르기 때문이다.
     * 페이지는 그 페이지의 행을, 위치는 그 위치(+menu_code 스코프)의 행을
     * 지운다 — 한 메서드로 합치면 호출자가 무엇이 지워지는지 못 읽는다.
     *
     * @param array $kit 디코딩된 킷 JSON (target.kind 가 'position' 이어야 한다)
     * @param string $mode self::MODE_APPEND | self::MODE_REPLACE
     * @return Result 성공 data: {summary: array{created_rows, deleted_rows, ...},
     *                warnings: string[]}
     */
    public function applyPosition(int $domainId, array $kit, string $mode = self::MODE_REPLACE): Result;
}
