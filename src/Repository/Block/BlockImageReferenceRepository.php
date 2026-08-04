<?php
declare(strict_types=1);

namespace Mublo\Repository\Block;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

/** 블록 업로드 파일이 현재 DB에서 참조되는지 확인한다. */
final class BlockImageReferenceRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    public function isReferenced(string $imageUrl): bool
    {
        if ($imageUrl === '' || !str_starts_with($imageUrl, '/storage/')) {
            return false;
        }

        // json_encode() 기본 출력은 슬래시를 `\/` 로 저장한다. LIKE 패턴에서 실제
        // 역슬래시 한 글자를 찾으려면 패턴에는 역슬래시 두 글자가 필요하므로 일반 URL과
        // JSON 표현을 모두 조회한다.
        // URL 안의 %/_가 LIKE 와일드카드가 되더라도 false positive만 만들기 때문에
        // 안전 쪽(파일을 남김)으로 실패한다. 정상 업로드 경로에는 두 문자가 들어가지 않는다.
        $needle = '%' . $imageUrl . '%';
        $jsonNeedle = '%' . str_replace('/', '\\\\/', $imageUrl) . '%';
        $row = $this->db->selectOne(
            'SELECT 1 AS found FROM block_rows'
            . ' WHERE background_config LIKE ? OR background_config LIKE ? LIMIT 1',
            [$needle, $jsonNeedle]
        );
        if ($row !== null) {
            return true;
        }

        $column = $this->db->selectOne(
            'SELECT 1 AS found FROM block_columns'
            . ' WHERE background_config LIKE ? OR background_config LIKE ?'
            . ' OR title_config LIKE ? OR title_config LIKE ?'
            . ' OR content_config LIKE ? OR content_config LIKE ?'
            . ' OR content_items LIKE ? OR content_items LIKE ? LIMIT 1',
            [$needle, $jsonNeedle, $needle, $jsonNeedle, $needle, $jsonNeedle, $needle, $jsonNeedle]
        );

        if ($column !== null) {
            return true;
        }

        // 스택 자식 콘텐츠 — 미러(칸 scalar)에 없는 이미지도 실사용 중이다.
        // 구버전 설치는 테이블 존재 여부로 구분한다. 쿼리 오류까지 테이블 미설치로
        // 취급하면 실제 참조가 있는데도 false 를 반환해 사용 중인 파일을 지울 수 있다.
        if ($this->db->tableExists('block_column_contents')) {
            $content = $this->db->selectOne(
                'SELECT 1 AS found FROM block_column_contents'
                . ' WHERE title_config LIKE ? OR title_config LIKE ?'
                . ' OR content_config LIKE ? OR content_config LIKE ?'
                . ' OR content_items LIKE ? OR content_items LIKE ? LIMIT 1',
                [$needle, $jsonNeedle, $needle, $jsonNeedle, $needle, $jsonNeedle]
            );

            if ($content !== null) {
                return true;
            }
        }

        // 복구 이력에서 참조하는 파일은 이력 보존 기간 동안 지우지 않는다.
        if ($this->db->tableExists('block_row_revisions')) {
            $revision = $this->db->selectOne(
                'SELECT 1 AS found FROM block_row_revisions'
                . ' WHERE snapshot_json LIKE ? OR snapshot_json LIKE ? LIMIT 1',
                [$needle, $jsonNeedle]
            );

            return $revision !== null;
        }

        return false;
    }
}
