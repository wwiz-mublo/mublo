<?php
declare(strict_types=1);

namespace Mublo\Plugin\EmailNotify\Repository;

use Mublo\Infrastructure\Database\Database;

class EmailLogRepository
{
    private string $table = 'plugin_email_notify_logs';

    public function __construct(private Database $db)
    {
    }

    public function getList(int $domainId, int $limit = 100, int $offset = 0): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('log_id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    public function countByDomain(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->count();
    }

    public function create(int $domainId, array $data): int
    {
        return (int) $this->db->table($this->table)->insert([
            'domain_id' => $domainId,
            'template_code' => $data['template_code'] ?? '',
            'recipient' => $data['recipient'] ?? '',
            'subject' => $data['subject'] ?? '',
            'result_code' => $data['result_code'] ?? '',
            'result_message' => $data['result_message'] ?? '',
            'request_payload' => $data['request_payload'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
