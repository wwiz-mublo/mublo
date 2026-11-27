<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Contract\Member\MemberPublicAppearance;
use Mublo\Contract\Member\MemberPublicAppearanceQueryInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Service\CustomField\CustomFieldFileHandler;

final class MemberPublicAppearanceQueryService implements MemberPublicAppearanceQueryInterface
{
    public function __construct(private Database $db)
    {
    }

    public function forMembers(int $domainId, array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_filter(
            array_map('intval', $memberIds),
            static fn (int $memberId): bool => $memberId > 0
        )));
        if ($domainId <= 0 || $memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        // 도메인·아바타 필드 조건을 JOIN 안에 고정해 다른 사이트의 필드 값이 섞이지 않게 한다.
        // 공개 ID, 등급 표시, 아바타를 한 번에 읽어 게시판 목록의 추가 쿼리를 1회로 제한한다.
        $members = $this->db->select(
            "SELECT m.member_id, m.public_id, COALESCE(ml.is_super, 0) AS is_super, v.field_value
               FROM members m
               LEFT JOIN member_levels ml ON ml.level_value = m.level_value
               LEFT JOIN member_fields f ON f.domain_id = m.domain_id AND f.field_type = 'avatar'
               LEFT JOIN member_field_values v ON v.member_id = m.member_id AND v.field_id = f.field_id
              WHERE m.domain_id = ? AND m.member_id IN ({$placeholders})",
            [$domainId, ...$memberIds]
        );

        $appearances = [];
        foreach ($members as $member) {
            $memberId = (int) ($member['member_id'] ?? 0);
            if ($memberId <= 0) {
                continue;
            }

            $current = $appearances[$memberId] ?? null;
            $publicId = (string) ($member['public_id'] ?? '');
            if (preg_match('/\A[0-9a-f]{22}\z/', $publicId) !== 1) {
                $publicId = '';
            }
            $meta = CustomFieldFileHandler::parseFileMeta(
                is_string($member['field_value'] ?? null) ? $member['field_value'] : null
            );
            $url = is_string($meta['url'] ?? null) ? $meta['url'] : '';
            if ($url === '' || !$this->publicFileExists($url)) {
                $url = $current?->avatarUrl ?? '';
            }

            $appearances[$memberId] = new MemberPublicAppearance(
                publicId: $publicId,
                avatarUrl: $url,
                super: (bool) ($member['is_super'] ?? false)
            );
        }

        return $appearances;
    }

    private function publicFileExists(string $url): bool
    {
        if (!defined('MUBLO_PUBLIC_STORAGE_URI') || !defined('MUBLO_PUBLIC_STORAGE_PATH')) {
            return true;
        }

        $uri = (string) MUBLO_PUBLIC_STORAGE_URI;
        if (!str_starts_with($url, $uri . '/')) {
            return true;
        }

        $relativePath = substr($url, strlen($uri));
        return is_file((string) MUBLO_PUBLIC_STORAGE_PATH . $relativePath);
    }
}
