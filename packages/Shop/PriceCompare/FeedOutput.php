<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare;

/**
 * 완성된 피드 본문과 그 본문의 Content-Type
 *
 * 형식은 채널이 고르므로(TSV/RSS) 응답 헤더도 채널에 따라 갈린다. 라우트가 채널
 * 형식을 다시 판정하지 않도록 본문과 함께 들고 나온다.
 */
final readonly class FeedOutput
{
    public function __construct(
        public string $body,
        public string $contentType,
    ) {
    }
}
