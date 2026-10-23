<?php
declare(strict_types=1);

namespace Mublo\Service\Member\Report;

use Mublo\Core\Report\Contract\RowProviderInterface;
use Mublo\Enum\Member\MemberStatus;
use Mublo\Service\Member\MemberAdminService;

/**
 * 회원 목록 내보내기의 행 공급자
 *
 * MemberAdminService::getListWithFields(추가 필드 복호화 포함)를 페이지 단위로 조회한다.
 * 검색 조건은 관리자 회원 목록(MemberController::index)과 같은 키를 받는다:
 * search_field, search_keyword, level, status, sort, order.
 */
class MemberListReportRowProvider implements RowProviderInterface
{
    private const BATCH = 500;

    private ?int $total = null;

    /** @var array{0: array, 1: array}|null [search, filters] */
    private ?array $conditions = null;

    /**
     * @param array<int|string, string> $levelNames level_value => 등급명
     */
    public function __construct(
        private MemberAdminService $memberAdminService,
        private array $levelNames,
        private int $domainId,
        private array $filters
    ) {
    }

    public function getChunk(int $offset, int $limit): array
    {
        $limit = max(1, $limit);
        $page = (int) floor(max(0, $offset) / $limit) + 1;

        $result = $this->fetch($page, $limit);
        if (!($result['success'] ?? false)) {
            return [];
        }

        $this->total = (int) ($result['data']['total'] ?? $this->total ?? 0);

        return array_map(fn(array $member) => $this->mapRow($member), $result['data']['members'] ?? []);
    }

    public function rows(): iterable
    {
        $offset = 0;
        do {
            $chunk = $this->getChunk($offset, self::BATCH);
            foreach ($chunk as $row) {
                yield $row;
            }
            $offset += self::BATCH;
        } while (count($chunk) === self::BATCH);
    }

    public function totalCount(): ?int
    {
        if ($this->total === null) {
            $result = $this->fetch(1, 1);
            $this->total = (int) ($result['data']['total'] ?? 0);
        }
        return $this->total;
    }

    public function isRewindable(): bool
    {
        return true;
    }

    private function fetch(int $page, int $perPage): array
    {
        [$search, $filters] = $this->conditions();

        return $this->memberAdminService->getListWithFields(
            $this->domainId,
            $page,
            $perPage,
            $search,
            null,
            (string) ($this->filters['sort'] ?? ''),
            (string) ($this->filters['order'] ?? ''),
            $filters
        );
    }

    /**
     * 목록 화면과 같은 방식으로 검색·필터 조건을 만든다.
     *
     * @return array{0: array, 1: array}
     */
    private function conditions(): array
    {
        if ($this->conditions !== null) {
            return $this->conditions;
        }

        $searchField = (string) ($this->filters['search_field'] ?? '');
        $searchKeyword = (string) ($this->filters['search_keyword'] ?? '');

        $search = [];
        if ($searchKeyword !== '' && $searchField !== '') {
            $searchFields = $this->memberAdminService->getSearchFields($this->domainId);
            $search = [
                'keyword' => $searchKeyword,
                'field' => $searchField,
                'field_info' => $searchFields[$searchField]['field_info'] ?? null,
            ];
        } elseif ($searchKeyword !== '') {
            $search = ['keyword' => $searchKeyword, 'field' => ''];
        }

        $filters = [];
        if (($this->filters['level'] ?? '') !== '') {
            $filters['level_value'] = (int) $this->filters['level'];
        }
        if (($this->filters['status'] ?? '') !== '') {
            $filters['status'] = (string) $this->filters['status'];
        }

        return $this->conditions = [$search, $filters];
    }

    private function mapRow(array $member): array
    {
        $levelValue = $member['level_value'] ?? '';
        $status = (string) ($member['status'] ?? '');

        $row = [
            'user_id' => (string) ($member['user_id'] ?? ''),
            'nickname' => (string) ($member['nickname'] ?? ''),
            'level_name' => (string) ($this->levelNames[$levelValue] ?? $levelValue),
            'status_label' => MemberStatus::tryFrom($status)?->label() ?? $status,
            'point' => (int) ($member['point'] ?? 0),
            'created_at' => (string) ($member['created_at'] ?? ''),
            'last_login_at' => (string) ($member['last_login_at'] ?? ''),
        ];

        foreach ($member as $key => $value) {
            if (str_starts_with((string) $key, 'field_')) {
                $row[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $row;
    }
}
