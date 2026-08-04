<?php
declare(strict_types=1);
namespace Mublo\Repository\AI;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

class AiUsageRepository
{
    private Database $db;
    public function __construct(?Database $db = null) { $this->db = $db ?? DatabaseManager::getInstance()->connect(); }

    public function consume(int $domainId, int $limit, int $inputChars): bool
    {
        $date = date('Y-m-d');
        $this->db->execute(
            'INSERT IGNORE INTO domain_ai_usage_daily (domain_id, usage_date, request_count, input_chars, output_chars) VALUES (?, ?, 0, 0, 0)',
            [$domainId, $date]
        );
        return $this->db->execute(
            'UPDATE domain_ai_usage_daily SET request_count = request_count + 1, input_chars = input_chars + ?
             WHERE domain_id = ? AND usage_date = ? AND request_count < ?',
            [$inputChars, $domainId, $date, $limit]
        ) === 1;
    }

    public function addOutput(int $domainId, int $outputChars): void
    {
        $this->db->execute(
            'UPDATE domain_ai_usage_daily SET output_chars = output_chars + ? WHERE domain_id = ? AND usage_date = ?',
            [$outputChars, $domainId, date('Y-m-d')]
        );
    }
}
