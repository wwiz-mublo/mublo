<?php
declare(strict_types=1);
namespace Mublo\Core\Block\Renderer;

use Mublo\Contract\Block\BlockColumnView;

/**
 * RendererInterface
 *
 * 블록 콘텐츠 렌더러 인터페이스
 *
 * 모든 콘텐츠 타입은 이 인터페이스를 구현해야 함
 *
 * 받는 것은 칸 엔티티가 아니라 BlockColumnView 다. 렌더러가 읽어야 하는 것만
 * 담겨 있고, 저장·정렬·스택 구성 같은 코어 내부 표면은 빠져 있다. 덕분에
 * 코어가 칸 구조를 바꿔도 설치된 블록이 함께 깨지지 않는다.
 */
interface RendererInterface
{
    /**
     * 콘텐츠 렌더링
     *
     * @param BlockColumnView $column 렌더러가 읽을 수 있는 칸 표면
     * @return string 렌더링된 HTML
     */
    public function render(BlockColumnView $column): string;
}
