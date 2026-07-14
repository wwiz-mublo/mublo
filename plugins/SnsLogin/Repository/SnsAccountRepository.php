<?php
namespace Mublo\Plugin\SnsLogin\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Service\Member\FieldEncryptionService;

class SnsAccountRepository
{
    private string $table = 'plugin_sns_login_accounts';

    public function __construct(
        private Database               $db,
        private FieldEncryptionService $encryption,
    ) {}

    public function findByProvider(int $domainId, string $provider, string $providerUid): ?SnsAccount
    {
        $row = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('provider', '=', $provider)
            ->where('provider_uid', '=', $providerUid)
            ->first();

        return $row ? $this->hydrate($row) : null;
    }

    /** 회원이 연결한 모든 SNS 계정 */
    public function findByMember(int $memberId): array
    {
        $rows = $this->db->table($this->table)
            ->where('member_id', '=', $memberId)
            ->get();

        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    public function findByMemberAndProvider(int $memberId, string $provider): ?SnsAccount
    {
        $row = $this->db->table($this->table)
            ->where('member_id', '=', $memberId)
            ->where('provider', '=', $provider)
            ->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByIdAndDomain(int $id, int $domainId): ?SnsAccount
    {
        $row = $this->db->table($this->table)
            ->where('id', '=', $id)
            ->where('domain_id', '=', $domainId)
            ->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(array $data): void
    {
        if (isset($data['access_token'])) {
            $data['access_token'] = $this->encryption->encrypt($data['access_token']);
        }
        if (isset($data['refresh_token'])) {
            $data['refresh_token'] = $this->encryption->encrypt($data['refresh_token']);
        }

        $this->db->table($this->table)->insert(array_merge($data, [
            'linked_at' => date('Y-m-d H:i:s'),
        ]));
    }

    public function updateTokens(int $id, string $accessToken, ?string $refreshToken, ?string $expiresAt): void
    {
        $data = [
            'access_token'     => $this->encryption->encrypt($accessToken),
            'token_expires_at' => $expiresAt,
        ];

        // Google 등은 재로그인 때 refresh_token을 다시 주지 않을 수 있으므로
        // 새 값이 없다는 이유로 기존 장기 토큰을 지우지 않는다.
        if ($refreshToken !== null && $refreshToken !== '') {
            $data['refresh_token'] = $this->encryption->encrypt($refreshToken);
        }

        $this->db->table($this->table)->where('id', '=', $id)->update($data);
    }

    public function deleteByMemberAndProvider(int $memberId, string $provider): bool
    {
        return $this->db->table($this->table)
            ->where('member_id', '=', $memberId)
            ->where('provider', '=', $provider)
            ->delete() > 0;
    }

    public function deleteByMember(int $memberId): int
    {
        return $this->db->table($this->table)
            ->where('member_id', '=', $memberId)
            ->delete();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SnsAccount
    {
        $row['access_token'] = $this->encryption->readFieldValue(
            isset($row['access_token']) ? (string) $row['access_token'] : null,
            true,
        );
        $row['refresh_token'] = $this->encryption->readFieldValue(
            isset($row['refresh_token']) ? (string) $row['refresh_token'] : null,
            true,
        );

        return SnsAccount::fromArray($row);
    }

    /**
     * 관리자 목록: members JOIN, 페이지네이션
     */
    public function listPaginated(int $domainId, ?string $provider, int $perPage, int $offset): array
    {
        $pdo      = $this->db->getPdo();
        $saTable  = $this->db->prefixTable('plugin_sns_login_accounts');
        $memTable = $this->db->prefixTable('members');

        $sql = "SELECT sa.*, m.nickname, m.user_id
                FROM `{$saTable}` sa
                LEFT JOIN `{$memTable}` m ON sa.member_id = m.member_id
                WHERE sa.domain_id = ?";

        $params = [$domainId];

        if ($provider !== null && $provider !== '') {
            $sql     .= ' AND sa.provider = ?';
            $params[] = $provider;
        }

        $sql .= ' ORDER BY sa.linked_at DESC LIMIT ? OFFSET ?';
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countFiltered(int $domainId, ?string $provider): int
    {
        $qb = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($provider !== null && $provider !== '') {
            $qb->where('provider', '=', $provider);
        }

        return $qb->count();
    }

    public function deleteById(int $id, int $domainId): bool
    {
        return $this->db->table($this->table)
            ->where('id', '=', $id)
            ->where('domain_id', '=', $domainId)
            ->delete() > 0;
    }
}
