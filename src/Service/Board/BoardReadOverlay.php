<?php
declare(strict_types=1);

namespace Mublo\Service\Board;

use Mublo\Contract\Board\BoardDescriptor;
use Mublo\Contract\Board\BoardReadOverlayInterface;
use Mublo\Core\Result\Result;

/** 요청 메모리 안에서만 유지되는 게시판 설정 overlay. */
final class BoardReadOverlay implements BoardReadOverlayInterface
{
    /** @var array<int, list<BoardDescriptor>> */
    private array $domains = [];

    private int $nextDraftId = -1;

    public function replaceBoards(int $domainId, array $boards): Result
    {
        if ($domainId <= 0) {
            return Result::failure('미리보기 도메인 ID가 올바르지 않습니다.');
        }

        $normalized = [];
        $ids = [];
        $slugs = [];
        $occupiedIds = [];
        foreach ($this->domains as $registeredDomainId => $registeredBoards) {
            if ($registeredDomainId === $domainId) {
                continue;
            }
            foreach ($registeredBoards as $registeredBoard) {
                $occupiedIds[$registeredBoard->boardId] = true;
            }
        }
        foreach (array_values($boards) as $board) {
            if (!$board instanceof BoardDescriptor) {
                return Result::failure('게시판 미리보기 항목 형식이 올바르지 않습니다.');
            }
            $slug = trim($board->slug);
            $name = trim($board->name);
            if ($slug === '' || $name === '' || $board->groupId <= 0 || $board->articleCount < 0) {
                return Result::failure('게시판 슬러그, 이름, 그룹은 필수입니다.');
            }
            if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
                return Result::failure('게시판 미리보기 슬러그 형식이 올바르지 않습니다.');
            }
            if ($board->boardId === 0) {
                while (isset($occupiedIds[$this->nextDraftId]) || isset($ids[$this->nextDraftId])) {
                    $this->nextDraftId--;
                }
                $boardId = $this->nextDraftId--;
            } else {
                $boardId = $board->boardId;
            }
            if (isset($ids[$boardId]) || isset($occupiedIds[$boardId]) || isset($slugs[$slug])) {
                return Result::failure('게시판 미리보기 ID 또는 슬러그가 중복되었습니다.');
            }
            foreach ($board->categoryIds as $categoryId) {
                if (!is_int($categoryId) || $categoryId <= 0) {
                    return Result::failure('게시판 미리보기 카테고리 ID가 올바르지 않습니다.');
                }
            }
            $values = $board->values;
            $values['board_id'] = $boardId;
            $values['domain_id'] = $domainId;
            $values['group_id'] = $board->groupId;
            $values['board_slug'] = $slug;
            $values['board_name'] = $name;
            $values['is_active'] = $board->active ? 1 : 0;
            $normalized[] = new BoardDescriptor(
                $boardId,
                $slug,
                $name,
                $board->groupId,
                $board->active,
                $board->articleCount,
                $values,
                array_values(array_unique($board->categoryIds))
            );
            $ids[$boardId] = true;
            $slugs[$slug] = true;
        }

        usort($normalized, static fn(BoardDescriptor $left, BoardDescriptor $right): int =>
            [(int) ($left->values['sort_order'] ?? 0), $left->boardId]
            <=> [(int) ($right->values['sort_order'] ?? 0), $right->boardId]
        );
        $this->domains[$domainId] = $normalized;
        return Result::success('게시판 미리보기를 등록했습니다.');
    }

    public function boards(int $domainId, bool $activeOnly = false): ?array
    {
        if (!array_key_exists($domainId, $this->domains)) {
            return null;
        }
        if (!$activeOnly) {
            return $this->domains[$domainId];
        }
        return array_values(array_filter(
            $this->domains[$domainId],
            static fn(BoardDescriptor $board): bool => $board->active
        ));
    }

    public function findBySlug(int $domainId, string $slug): ?BoardDescriptor
    {
        foreach ($this->domains[$domainId] ?? [] as $board) {
            if ($board->slug === $slug) {
                return $board;
            }
        }
        return null;
    }

    public function findById(int $boardId): ?BoardDescriptor
    {
        foreach ($this->domains as $boards) {
            foreach ($boards as $board) {
                if ($board->boardId === $boardId) {
                    return $board;
                }
            }
        }
        return null;
    }

    public function clear(): void
    {
        $this->domains = [];
        $this->nextDraftId = -1;
    }
}
