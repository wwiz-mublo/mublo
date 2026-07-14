<?php

namespace Mublo\Service\System;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\DataResetFilesystemInterface;
use Mublo\Infrastructure\Database\Database;

/**
 * Core 데이터 초기화
 *
 * Core 테이블(회원, 블록, 메뉴)과 업로드 파일의 초기화를 담당합니다.
 */
class CoreDataResetter implements DataResettableInterface, DataResetFilesystemInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('members', '회원', 'SUPER·사이트 소유자를 제외한 회원을 탈퇴 처리하고 개인정보·토큰·잔액 데이터를 삭제합니다.', 'bi-people'),
            new DataResetCategory('blocks', '블록', '블록 페이지, 행, 칼럼, 콘텐츠 스택과 변경 이력을 삭제합니다. (블록 킷 보관소는 보존)', 'bi-grid'),
            new DataResetCategory('menus', '메뉴', '메뉴 트리 및 메뉴 항목을 모두 삭제합니다.', 'bi-list'),
            new DataResetCategory('activity', '운영 이력', '로그인 시도, 알림, 잔액 복구 감사, AI 사용·생성 이력과 발급 코드를 삭제합니다.', 'bi-clock-history'),
            new DataResetCategory('uploads', '업로드 파일', '업로드된 파일을 모두 삭제합니다. (로고/파비콘은 보존)', 'bi-upload'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        return match ($category) {
            'members' => $this->resetMembers($domainId),
            'blocks' => $this->resetBlocks($domainId),
            'menus' => $this->resetMenus($domainId),
            'activity' => $this->resetActivity($domainId),
            'uploads' => new DataResetResult(details: 'DB 커밋 후 업로드 파일 삭제'),
            default => new DataResetResult(details: '알 수 없는 카테고리'),
        };
    }

    public function resetFiles(string $category, int $domainId): int
    {
        return $category === 'uploads' ? $this->resetUploads($domainId) : 0;
    }

    private function resetMembers(int $domainId): DataResetResult
    {
        $cleared = 0;

        // SUPER와 사이트 소유자는 인증·도메인 소유권이 끊기지 않도록 보호한다.
        $protectedRows = $this->db->select(
            "SELECT DISTINCT m.member_id
               FROM members m
               LEFT JOIN member_levels ml ON m.level_value = ml.level_value
               LEFT JOIN domain_configs dc ON dc.member_id = m.member_id
              WHERE m.domain_id = :domain_id
                AND (ml.is_super = 1 OR dc.member_id IS NOT NULL)",
            ['domain_id' => $domainId]
        );
        $protectedIds = array_map('intval', array_column($protectedRows, 'member_id'));

        $params = [$domainId];
        $where = 'domain_id = ?';
        if ($protectedIds !== []) {
            $where .= ' AND member_id NOT IN ('
                . implode(',', array_fill(0, count($protectedIds), '?')) . ')';
            $params = array_merge($params, $protectedIds);
        }

        $memberRows = $this->db->select("SELECT member_id FROM members WHERE {$where}", $params);
        $memberIds = array_map('intval', array_column($memberRows, 'member_id'));
        if ($memberIds === []) {
            return new DataResetResult(details: '초기화할 일반 회원 없음');
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        foreach ([
            ['member_field_values', 'member_id'],
            ['member_policy_agreements', 'member_id'],
            ['password_reset_tokens', 'member_id'],
            ['proxy_login_tokens', 'admin_member_id'],
            ['admin_dashboard_layout', 'user_id'],
            ['domain_ai_generation_records', 'member_id'],
        ] as [$table, $column]) {
            if ($this->db->tableExists($table)) {
                $this->db->execute(
                    "DELETE FROM {$table} WHERE {$column} IN ({$placeholders})",
                    $memberIds
                );
                $cleared++;
            }
        }

        foreach ([
            ['member_notifications', 'member_id', 'actor_member_id'],
            ['balance_repair_audits', 'member_id', 'admin_id'],
        ] as [$table, $memberColumn, $actorColumn]) {
            if ($this->db->tableExists($table)) {
                $this->db->execute(
                    "DELETE FROM {$table} WHERE domain_id = ? AND ({$memberColumn} IN ({$placeholders}) OR {$actorColumn} IN ({$placeholders}))",
                    array_merge([$domainId], $memberIds, $memberIds)
                );
                $cleared++;
            }
        }

        if ($this->db->tableExists('balance_logs')) {
            $this->db->execute(
                "DELETE FROM balance_logs WHERE domain_id = ? AND member_id IN ({$placeholders})",
                array_merge([$domainId], $memberIds)
            );
            $cleared++;
        }

        if ($this->db->tableExists('login_attempts')) {
            $this->db->execute('DELETE FROM login_attempts WHERE domain_id = ?', [$domainId]);
            $cleared++;
        }

        $this->db->execute(
            "UPDATE members
                SET status = 'withdrawn', password = '', nickname = NULL,
                    level_value = 1, domain_group = NULL, can_create_site = 0,
                    point_balance = 0, last_login_at = NULL, last_login_ip = NULL,
                    withdrawn_at = CURRENT_TIMESTAMP,
                    withdrawal_reason = '관리자 데이터 초기화',
                    updated_at = CURRENT_TIMESTAMP
              WHERE member_id IN ({$placeholders})",
            $memberIds
        );
        $cleared++;

        return new DataResetResult($cleared, details: 'SUPER·사이트 소유자 보존, 나머지 회원 탈퇴·개인정보 정리');
    }

    private function resetBlocks(int $domainId): DataResetResult
    {
        $cleared = 0;

        $this->db->execute("SET FOREIGN_KEY_CHECKS = 0");
        try {
            foreach (['block_column_contents', 'block_row_revisions', 'block_kit_applications', 'block_columns', 'block_rows', 'block_pages'] as $table) {
                if ($this->db->tableExists($table)) {
                    $this->db->execute("DELETE FROM {$table} WHERE domain_id = ?", [$domainId]);
                    $cleared++;
                }
            }
        } finally {
            $this->db->execute("SET FOREIGN_KEY_CHECKS = 1");
        }

        return new DataResetResult($cleared, details: '블록 페이지/행/칼럼 삭제');
    }

    private function resetMenus(int $domainId): DataResetResult
    {
        $cleared = 0;

        $this->db->execute("SET FOREIGN_KEY_CHECKS = 0");
        try {
            foreach (['menu_tree', 'menu_items', 'member_level_denied_menus'] as $table) {
                if ($this->db->tableExists($table)) {
                    $this->db->execute("DELETE FROM {$table} WHERE domain_id = ?", [$domainId]);
                    $cleared++;
                }
            }
        } finally {
            $this->db->execute("SET FOREIGN_KEY_CHECKS = 1");
        }

        return new DataResetResult($cleared, details: '메뉴 트리/항목 삭제');
    }

    private function resetActivity(int $domainId): DataResetResult
    {
        $cleared = 0;
        foreach ([
            'login_attempts',
            'member_notifications',
            'balance_repair_audits',
            'domain_ai_usage_daily',
            'domain_ai_generation_records',
            'unique_codes',
        ] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->execute("DELETE FROM {$table} WHERE domain_id = ?", [$domainId]);
                $cleared++;
            }
        }

        return new DataResetResult($cleared, details: '도메인 운영·감사·AI 사용 이력 삭제');
    }

    private function resetUploads(int $domainId): int
    {
        $filesDeleted = 0;
        $storagePath = MUBLO_PUBLIC_STORAGE_PATH . '/D' . $domainId;

        if (!is_dir($storagePath)) {
            return 0;
        }

        $items = array_diff(scandir($storagePath), ['.', '..']);
        foreach ($items as $item) {
            // site/ 디렉토리는 보존 (로고, 파비콘)
            if ($item === 'site') {
                continue;
            }

            $path = $storagePath . '/' . $item;
            if (is_dir($path)) {
                $filesDeleted += $this->deleteDirectoryRecursive($path);
            } else {
                unlink($path);
                $filesDeleted++;
            }
        }

        return $filesDeleted;
    }

    private function deleteDirectoryRecursive(string $dir): int
    {
        $count = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
                $count++;
            }
        }
        rmdir($dir);

        return $count;
    }

}
