<?php

namespace Mublo\Packages\Board\Api;

use Mublo\Contract\Board\BoardProvisioningInterface;
use Mublo\Core\Result\Result;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Service\BoardConfigService;
use Mublo\Packages\Board\Service\BoardGroupService;

/**
 * BoardProvisioningGateway
 *
 * `Contract\Board\BoardProvisioningInterface` 의 Board 패키지 구현.
 *
 * 확장이 사이트를 프로그래밍으로 구축할 때 쓰는 좁은 표면이다. 새 동작을
 * 만들지 않고 기존 서비스에 위임하되, 두 가지를 보장한다.
 *
 * 1. **멱등** — `$provisioningKey` 를 슬러그로 써서 이미 있으면 그대로 반환한다.
 *    `UNIQUE(domain_id, board_slug)` · `UNIQUE(domain_id, group_slug)` 가
 *    동시 재시도까지 막고, 위반이 나면 먼저 들어간 행을 다시 읽는다.
 * 2. **덮지 않음** — 기존 자원의 이름·설정을 프리셋으로 갱신하지 않는다.
 *    운영자가 고친 것을 워커 재시도가 되돌리면 안 된다.
 */
class BoardProvisioningGateway implements BoardProvisioningInterface
{
    public function __construct(
        private BoardGroupService $groupService,
        private BoardGroupRepository $groupRepository,
        private BoardConfigService $boardService,
        private BoardConfigRepository $boardRepository
    ) {
    }

    public function ensureGroup(int $domainId, string $provisioningKey, array $preset = []): Result
    {
        $slug = trim($provisioningKey);
        if ($slug === '') {
            return Result::failure('프로비저닝 키는 필수입니다.');
        }

        $existing = $this->groupRepository->findBySlug($domainId, $slug);
        if ($existing !== null) {
            return $this->groupResult($existing->getGroupId(), $slug, false);
        }

        $data = $preset;
        $data['group_slug'] = $slug;
        $data['group_name'] = trim((string) ($preset['group_name'] ?? '')) !== ''
            ? $preset['group_name']
            : $slug;

        $result = $this->groupService->createGroup($domainId, $data);

        if (!$result->isSuccess()) {
            // 동시 호출이 먼저 만들었으면 UNIQUE 로 막힌다. 그 행을 읽어 같은 결과를 준다.
            $raced = $this->groupRepository->findBySlug($domainId, $slug);
            if ($raced !== null) {
                return $this->groupResult($raced->getGroupId(), $slug, false);
            }

            return $result;
        }

        return $this->groupResult((int) $result->getData()['group_id'], $slug, true);
    }

    public function ensureBoard(int $domainId, string $provisioningKey, array $preset = []): Result
    {
        $slug = trim($provisioningKey);
        if ($slug === '') {
            return Result::failure('프로비저닝 키는 필수입니다.');
        }

        $existing = $this->boardRepository->findBySlug($domainId, $slug);
        if ($existing !== null) {
            return $this->boardResult($existing->getBoardId(), $slug, false);
        }

        $groupSlug = trim((string) ($preset['group_slug'] ?? ''));
        if ($groupSlug === '') {
            return Result::failure('게시판을 만들려면 group_slug 가 필요합니다.');
        }

        $group = $this->groupRepository->findBySlug($domainId, $groupSlug);
        if ($group === null) {
            return Result::failure("게시판 그룹 '{$groupSlug}' 이(가) 없습니다. ensureGroup() 을 먼저 호출하세요.");
        }

        $data = $preset;
        unset($data['group_slug']);
        $data['board_slug'] = $slug;
        $data['group_id'] = $group->getGroupId();
        $data['board_name'] = trim((string) ($preset['board_name'] ?? '')) !== ''
            ? $preset['board_name']
            : $slug;

        $result = $this->boardService->createBoard($domainId, $data);

        if (!$result->isSuccess()) {
            $raced = $this->boardRepository->findBySlug($domainId, $slug);
            if ($raced !== null) {
                return $this->boardResult($raced->getBoardId(), $slug, false);
            }

            return $result;
        }

        return $this->boardResult((int) $result->getData()['board_id'], $slug, true);
    }

    private function groupResult(int $groupId, string $slug, bool $created): Result
    {
        return Result::success(
            $created ? '게시판 그룹을 생성했습니다.' : '기존 게시판 그룹을 사용합니다.',
            ['group_id' => $groupId, 'group_slug' => $slug, 'created' => $created]
        );
    }

    private function boardResult(int $boardId, string $slug, bool $created): Result
    {
        return Result::success(
            $created ? '게시판을 생성했습니다.' : '기존 게시판을 사용합니다.',
            ['board_id' => $boardId, 'board_slug' => $slug, 'created' => $created]
        );
    }
}
