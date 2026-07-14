<?php

namespace Mublo\Service\Block;

/**
 * 블록 이미지 변경의 파일 시스템 부수효과를 DB 저장 경계까지 지연한다.
 *
 * 새 파일은 렌더/저장을 위해 먼저 업로드할 수 있지만, 기존 파일 삭제는 commit 때만
 * 실행한다. 저장이 실패하거나 미리보기 요청이면 rollback으로 새 파일만 제거한다.
 */
final class BlockImageMutationPlan
{
    /** @var array<string, true> */
    private array $created = [];

    /** @var array<string, true> */
    private array $obsolete = [];

    public function recordCreated(string $imageUrl): void
    {
        if ($imageUrl !== '') {
            $this->created[$imageUrl] = true;
        }
    }

    public function recordObsolete(string $imageUrl): void
    {
        if ($imageUrl !== '') {
            $this->obsolete[$imageUrl] = true;
        }
    }

    /** @return string[] */
    public function createdImages(): array
    {
        return array_keys($this->created);
    }

    /** @return string[] */
    public function obsoleteImages(): array
    {
        return array_keys($this->obsolete);
    }
}
