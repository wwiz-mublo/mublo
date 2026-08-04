<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

use Mublo\Entity\Block\BlockPage;

/**
 * 블록 페이지 조회 계약
 *
 * 코어(BlockPageService)가 구현하고, 확장이 소비합니다.
 * 코어가 DI 컨테이너에 단일 구현을 제공하므로 생성자 타입힌트로 주입받습니다.
 *
 * BlockPage 엔티티는 안정 API(Entity\Block\*)이므로 계약 반환 타입으로 노출한다.
 */
interface BlockPageQueryInterface
{
    /**
     * 활성(삭제되지 않은) 블록 페이지를 코드로 조회
     */
    public function findActiveByCode(int $domainId, string $pageCode): ?BlockPage;
}
