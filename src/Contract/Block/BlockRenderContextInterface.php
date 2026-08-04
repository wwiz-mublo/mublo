<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

/** 현재 요청의 블록 렌더 결과를 구분하는 변형 문맥을 설정한다. */
interface BlockRenderContextInterface
{
    public function setVariant(string $variant): void;
}
