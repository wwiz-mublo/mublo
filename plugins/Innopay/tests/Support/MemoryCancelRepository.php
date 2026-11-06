<?php
declare(strict_types=1);

namespace Tests\Innopay\Support;

use Mublo\Plugin\Innopay\Repository\InnopayCancelRepository;

/**
 * 메모리 취소 기록 저장소
 */
final class MemoryCancelRepository extends InnopayCancelRepository
{
    /** @var list<array> */
    public array $rows = [];

    public function recordApi(int $domainId, string $moid, string $tid, int $amount, string $cancelTid): void
    {
        $this->rows[] = ['domain_id' => $domainId, 'moid' => $moid, 'tid' => $tid, 'amount' => $amount,
            'source' => self::SOURCE_API, 'cancel_tid' => $cancelTid, 'noti_key' => null, 'noti_matched' => 0];
    }

    public function claimApiMatch(int $domainId, string $tid, int $amount): bool
    {
        foreach ($this->rows as $i => $row) {
            if ($row['domain_id'] === $domainId && $row['tid'] === $tid && $row['amount'] === $amount
                && $row['source'] === self::SOURCE_API && $row['noti_matched'] === 0) {
                $this->rows[$i]['noti_matched'] = 1;
                return true;
            }
        }
        return false;
    }

    public function existsNoti(int $domainId, string $notiKey): bool
    {
        foreach ($this->rows as $row) {
            if ($row['domain_id'] === $domainId && $row['noti_key'] === $notiKey) {
                return true;
            }
        }
        return false;
    }

    public function recordNoti(int $domainId, string $moid, string $tid, int $amount, string $notiKey): void
    {
        $this->rows[] = ['domain_id' => $domainId, 'moid' => $moid, 'tid' => $tid, 'amount' => $amount,
            'source' => self::SOURCE_NOTI, 'cancel_tid' => null, 'noti_key' => $notiKey, 'noti_matched' => 0];
    }
}
