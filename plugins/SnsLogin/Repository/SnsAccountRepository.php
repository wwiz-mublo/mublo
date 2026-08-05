<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Contract\Security\SensitiveValueCodecInterface;

class SnsAccountRepository
{
    private string $table = 'plugin_sns_login_accounts';

    public function __construct(
        private Database               $db,
        private SensitiveValueCodecInterface $encryption,
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
            // 새 토큰을 받았다는 건 연결이 다시 살아났다는 뜻이다.
            // 과거 폐기 실패 표시를 남겨두면 관리자 화면에 유령 항목이 쌓인다.
            'revoke_failed_at'      => null,
            'revoke_failure_reason' => null,
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

    /**
     * 제공자 폐기 실패를 행에 기록한다 (행은 지우지 않는다).
     *
     * 재시도에 쓸 토큰이 이 행에 있으므로 보존이 목적이다.
     */
    public function markRevokeFailed(int $id, string $reason): void
    {
        $this->db->table($this->table)
            ->where('id', '=', $id)
            ->update([
                'revoke_failed_at'      => date('Y-m-d H:i:s'),
                'revoke_failure_reason' => mb_substr($reason, 0, 255),
            ]);
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
    public function listPaginated(int $domainId, ?string $provider, int $perPage, int $offset, bool $revokeFailedOnly = false): array
    {
        $pdo      = $this->db->getPdo();
        $saTable  = 'plugin_sns_login_accounts';
        $memTable = 'members';

        $sql = "SELECT sa.*, m.nickname, m.user_id
                FROM `{$saTable}` sa
                LEFT JOIN `{$memTable}` m ON sa.member_id = m.member_id
                WHERE sa.domain_id = ?";

        $params = [$domainId];

        if ($provider !== null && $provider !== '') {
            $sql     .= ' AND sa.provider = ?';
            $params[] = $provider;
        }

        if ($revokeFailedOnly) {
            $sql .= ' AND sa.revoke_failed_at IS NOT NULL';
        }

        $sql .= ' ORDER BY sa.linked_at DESC LIMIT ? OFFSET ?';
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countFiltered(int $domainId, ?string $provider, bool $revokeFailedOnly = false): int
    {
        $qb = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($provider !== null && $provider !== '') {
            $qb->where('provider', '=', $provider);
        }

        if ($revokeFailedOnly) {
            $qb->whereNotNull('revoke_failed_at');
        }

        return $qb->count();
    }

    /** 폐기 실패로 남은 연결 수 — 관리자에게 상시 노출할 경고 배지용 */
    public function countRevokeFailed(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->whereNotNull('revoke_failed_at')
            ->count();
    }

    public function deleteById(int $id, int $domainId): bool
    {
        return $this->db->table($this->table)
            ->where('id', '=', $id)
            ->where('domain_id', '=', $domainId)
            ->delete() > 0;
    }
}
