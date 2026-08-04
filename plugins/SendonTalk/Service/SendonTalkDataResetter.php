<?php
declare(strict_types=1);

namespace Mublo\Plugin\SendonTalk\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

final class SendonTalkDataResetter implements DataResettableInterface
{
    public function __construct(private Database $db) {}
    public function getResetCategories(): array
    {
        return [new DataResetCategory('talk_logs', '알림톡 발송 이력', '알림톡 발송 이력을 삭제합니다. (설정·채널·템플릿 보존)', 'bi-chat-dots')];
    }
    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'talk_logs') return new DataResetResult(details: '알 수 없는 카테고리');
        if (!$this->db->tableExists('plugin_sendon_talk_logs')) return new DataResetResult(details: '발송 이력 테이블 없음');
        $this->db->execute('DELETE FROM plugin_sendon_talk_logs WHERE domain_id = ?', [$domainId]);
        return new DataResetResult(1, details: '알림톡 발송 이력 삭제 (설정·채널·템플릿 보존)');
    }
}
