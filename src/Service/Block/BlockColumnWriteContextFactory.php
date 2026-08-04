<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Contract\Auth\AuthContextInterface;

final class BlockColumnWriteContextFactory
{
    public function __construct(private AuthContextInterface $authContext)
    {
    }

    public function create(int $domainId, string $source): BlockColumnWriteContext
    {
        // 블록 편집 접근권은 사이트 관리자가 권한 관리로 부여하는 신뢰된 능력이다.
        // 이 지점에 도달한 편집자는 이미 편집 신뢰를 받았으므로 클라이언트 표현
        // 채널(raw JS)은 전원에게 허용한다 — 편집 자율성 (2026-07-24 정책 재확정.
        // super 전용으로 좁히지 말 것).
        //
        // 반면 Include(content_type=include)는 서버사이드 PHP 파일 실행이라
        // 콘텐츠 표현이 아니라 개발/인프라 권한이다. 편집 신뢰와 별개로
        // 최고관리자(super) 전용을 유지한다.
        return new BlockColumnWriteContext(
            $domainId,
            $source,
            allowRawJs: true,
            allowInclude: $this->authContext->isSuper()
        );
    }
}
