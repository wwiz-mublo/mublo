<?php
namespace Mublo\Core\Block\Renderer;

use Mublo\Entity\Block\BlockColumn;

/**
 * RendererInterface
 *
 * 블록 콘텐츠 렌더러 인터페이스
 *
 * 모든 콘텐츠 타입은 이 인터페이스를 구현해야 함
 */
interface RendererInterface
{
    /**
     * 콘텐츠 렌더링
     *
     * @param BlockColumn $column 칸 엔티티
     * @return string 렌더링된 HTML
     */
    public function render(BlockColumn $column): string;
}
