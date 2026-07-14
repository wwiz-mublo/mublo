<?php

namespace Mublo\Core\Report\Audit;

use Mublo\Infrastructure\Log\Logger;

/**
 * 리포트 실행 감사 기록기
 *
 * Logger의 report-audit 채널에 기록해 도메인·일별 파일로 남긴다
 * (storage/logs/D{domainId}/report-audit/). Logger가 없으면 error_log로 폴백.
 */
class ReportAuditLogger
{
    private const AUDIT_CHANNEL = 'report-audit';

    public function __construct(private ?Logger $logger = null)
    {
    }

    public function log(string $event, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->channel(self::AUDIT_CHANNEL)->info($event, $context);
            return;
        }

        error_log('[Report] ' . json_encode([
            'event' => $event,
            'at' => date('c'),
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE));
    }
}
