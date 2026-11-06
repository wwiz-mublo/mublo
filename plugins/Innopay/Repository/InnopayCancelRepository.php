<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * 취소 기록 — 우리가 요청한 취소와 외부 취소 통보를 구분한다.
 */
class InnopayCancelRepository
{
    public const SOURCE_API = 'API';
    public const SOURCE_NOTI = 'NOTI';

    private string $table = 'plugin_innopay_cancels';

    public function __construct(private Database $db)
    {
    }

    /** 우리가 API 로 취소에 성공했다 */
    public function recordApi(int $domainId, string $moid, string $tid, int $amount, string $cancelTid): void
    {
        $this->db->table($this->table)->insert([
            'domain_id' => $domainId,
            'moid' => $moid,
            'tid' => $tid,
            'amount' => $amount,
            'source' => self::SOURCE_API,
            'cancel_tid' => $cancelTid !== '' ? $cancelTid : null,
        ]);
    }

    /**
     * 취소 통보가 우리가 요청한 취소에 대응하면 짝을 지어 표시하고 true.
     * 같은 금액의 API 취소 중 아직 짝이 없는 가장 오래된 한 건을 고른다.
     */
    public function claimApiMatch(int $domainId, string $tid, int $amount): bool
    {
        return $this->db->execute(
            "UPDATE {$this->table} SET noti_matched = 1
             WHERE domain_id = ? AND tid = ? AND amount = ? AND source = ? AND noti_matched = 0
             ORDER BY cancel_id ASC LIMIT 1",
            [$domainId, $tid, $amount, self::SOURCE_API]
        ) > 0;
    }

    public function existsNoti(int $domainId, string $notiKey): bool
    {
        return $this->db->selectOne(
            "SELECT cancel_id FROM {$this->table} WHERE domain_id = ? AND noti_key = ?",
            [$domainId, $notiKey]
        ) !== null;
    }

    /** 외부 취소 통보를 소비 패키지에 반영했다 */
    public function recordNoti(int $domainId, string $moid, string $tid, int $amount, string $notiKey): void
    {
        $this->db->table($this->table)->insert([
            'domain_id' => $domainId,
            'moid' => $moid,
            'tid' => $tid,
            'amount' => $amount,
            'source' => self::SOURCE_NOTI,
            'noti_key' => mb_substr($notiKey, 0, 100),
        ]);
    }
}
