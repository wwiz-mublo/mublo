<?php

namespace Mublo\Plugin\SendonSms\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

final class SendonSmsDataResetter implements DataResettableInterface
{
    public function __construct(private Database $db) {}
    public function getResetCategories(): array
    {
        return [new DataResetCategory('sms_logs', 'SMS 발송 이력', 'SMS/LMS 발송 이력을 삭제합니다. (설정·채널·템플릿 보존)', 'bi-chat-square-x')];
    }
    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'sms_logs') return new DataResetResult(details: '알 수 없는 카테고리');
        if (!$this->db->tableExists('plugin_sendon_sms_logs')) return new DataResetResult(details: '발송 이력 테이블 없음');
        $this->db->execute('DELETE FROM plugin_sendon_sms_logs WHERE domain_id = ?', [$domainId]);
        return new DataResetResult(1, details: 'SMS/LMS 발송 이력 삭제 (설정·채널·템플릿 보존)');
    }
}
