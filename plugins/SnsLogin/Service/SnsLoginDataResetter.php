<?php

namespace Mublo\Plugin\SnsLogin\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

class SnsLoginDataResetter implements DataResettableInterface
{
    public function __construct(private Database $db)
    {
    }

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('sns_accounts', 'SNS 연동', '회원 SNS 연동 계정 정보를 삭제합니다. (설정은 보존)', 'bi-person-badge'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'sns_accounts') {
            return new DataResetResult(details: '알 수 없는 카테고리');
        }

        $cleared = 0;
        if ($this->db->tableExists('plugin_sns_login_accounts')) {
            $this->db->execute('DELETE FROM plugin_sns_login_accounts WHERE domain_id = ?', [$domainId]);
            $cleared++;
        }

        return new DataResetResult($cleared, details: 'SNS 연동 계정 삭제 (설정 보존)');
    }
}
