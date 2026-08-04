<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Api;

use Mublo\Contract\Board\BoardProvisioningInterface;
use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Database\Database;
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
        private BoardConfigRepository $boardRepository,
        private Database $db
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

        $boardId = (int) $result->getData()['board_id'];
        $this->seedFirstPosts($domainId, $boardId, $preset);

        return $this->boardResult($boardId, $slug, true);
    }

    /**
     * 첫 글을 넣는다 — **신규 생성 경로에서만.**
     *
     * 빈 게시판은 방문자에게 "등록된 글이 없습니다" 로 보인다. 갓 만들어진
     * 사이트를 처음 여는 순간이 그 화면이면 안 된다(MubloCatalog 가 같은
     * 이유로 샘플 항목을 넣는다).
     *
     * 재시도에서 다시 넣지 않는 이유는 **운영자가 지운 것을 되살리면 안 되기**
     * 때문이다. 그건 프로비저닝이 아니라 되돌리기다.
     *
     * ## 왜 서비스가 아니라 저장소인가
     *
     * `BoardArticleService::create()` 는 Context 로 권한을 본다. 이 시드가
     * 도는 자리는 **방문자의 첫 요청**이라 로그인이 없을 수 있고, 그러면
     * 권한 검사에 걸려 글이 안 들어간다. 시스템이 심는 글이므로 저장소로
     * 직접 넣는다 — 작성자도 회원이 아니라 사이트 자신이다.
     *
     * 문구는 **어느 업종에서도 참인 것**으로 고른다. 방문자에게도 보이는
     * 글이라, 지어낸 실적을 적으면 거짓말이 된다.
     */
    private function seedFirstPosts(int $domainId, int $boardId, array $preset): void
    {
        $posts = $preset['placeholder_posts'] ?? null;

        if (!is_array($posts) || $posts === []) {
            $posts = [[
                'title' => '홈페이지를 새로 열었습니다',
                'content' => '<p>방문해 주셔서 감사합니다.</p>'
                    . '<p>앞으로 소식과 안내를 이곳에 올리겠습니다.</p>',
            ]];
        }

        $author = trim((string) ($preset['author_name'] ?? '')) ?: '관리자';

        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            $title = trim((string) ($post['title'] ?? ''));
            $content = trim((string) ($post['content'] ?? ''));
            if ($title === '' || $content === '') {
                continue;
            }

            try {
                $this->db->table('board_articles')->insert([
                    'domain_id' => $domainId,
                    'board_id' => $boardId,
                    'author_name' => $author,
                    'title' => $title,
                    'content' => $content,
                    'status' => 'published',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'published_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable) {
                // 첫 글은 편의지 사이트 성립 조건이 아니다. 실패해도 막지 않는다.
            }
        }
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
