<?php
declare(strict_types=1);
namespace Mublo\Core\Block\Form;

/**
 * ConfigFormInterface
 *
 * 블록 콘텐츠 설정 폼 인터페이스
 *
 * @deprecated 관리자 블록 설정 UI는 JS(registerContentType의 adminScript 옵션)
 *             기반으로 정착했고, 이 PHP 서버사이드 폼 렌더 경로는 사용되지 않는다.
 *             다음 major에서 제거 예정 — 신규 콘텐츠 타입은 adminScript를 사용할 것.
 *             (2026-07-15 결정, storage/mublo-book/pending-decisions.md B1)
 */
interface ConfigFormInterface
{
    /**
     * 설정 폼 HTML 렌더링
     *
     * @param array $currentConfig 현재 설정 값
     * @return string 렌더링된 폼 HTML
     */
    public function render(array $currentConfig = []): string;

    /**
     * 아이템 선택 UI 렌더링 (선택)
     *
     * 게시판 선택, 배너 그룹 선택 등의 아이템 선택 UI
     *
     * @param array $selectedItems 현재 선택된 아이템
     * @param int $domainId 도메인 ID
     * @return string 렌더링된 선택 UI HTML
     */
    public function renderItemSelector(array $selectedItems = [], int $domainId = 0): string;
}
