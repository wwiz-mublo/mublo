<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\Domain\DomainCreatedEvent;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Helper\String\StringHelper;

/**
 * 도메인 생성 시 Board 기본 데이터 자동 시딩
 *
 * 패키지가 활성화된 상태에서 새 도메인이 생성되면
 * install()과 동일한 기본 게시판(공지사항, 자유게시판)을 생성한다.
 */
class DomainEventSubscriber implements EventSubscriberInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DomainCreatedEvent::class => 'onDomainCreated',
        ];
    }

    public function onDomainCreated(DomainCreatedEvent $event): void
    {
        $domainId = $event->getDomainId();
        self::seedBoards($this->db, $domainId);
    }

    /**
     * Board 기본 데이터 시딩 — 하위사이트 / install() 훅 공용 단일 소스.
     *
     * 모든 트리거가 이 메서드로 수렴한다:
     *  - 하위사이트: DomainCreatedEvent → onDomainCreated()
     *  - 초기설치(default=true) / toggle-on: BoardProvider::install() 훅
     *    (초기설치는 부팅 reconcile이 install()을 1회 호출 → seedBoards)
     *
     * 항목별 멱등 — 여러 번/여러 경로로 호출돼도 중복 없이 누락분만 채운다.
     */
    public static function seedBoards(Database $db, int $domainId): void
    {
        if (!$domainId) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // 기본 그룹 (멱등: uk_domain_slug(domain_id, group_slug))
        $groupId = $db->insert(
            "INSERT IGNORE INTO board_groups (domain_id, group_slug, group_name, sort_order, is_active, created_at, updated_at)
             VALUES (?, 'default', '기본 그룹', 0, 1, ?, ?)",
            [$domainId, $now, $now]
        );

        if (!$groupId) {
            $row = $db->selectOne(
                "SELECT group_id FROM board_groups WHERE domain_id = ? AND group_slug = 'default'",
                [$domainId]
            );
            $groupId = $row['group_id'] ?? null;
        }

        if (!$groupId) {
            return;
        }

        // 기본 게시판 정의
        $boards = [
            [
                'slug' => 'notice',
                'name' => '공지사항',
                'description' => '사이트 공지사항을 안내하는 게시판입니다.',
                'write_level' => 230,
                'use_reaction' => 0,
                'sort_order' => 0,
            ],
            [
                'slug' => 'free',
                'name' => '자유게시판',
                'description' => '자유롭게 글을 작성할 수 있는 게시판입니다.',
                'write_level' => 1,
                'use_reaction' => 1,
                'sort_order' => 1,
            ],
        ];

        foreach ($boards as $board) {
            // 게시판 (멱등: uk_domain_slug(domain_id, board_slug))
            $db->insert(
                "INSERT IGNORE INTO board_configs
                    (domain_id, group_id, board_slug, board_name, board_description,
                     list_level, read_level, write_level, comment_level,
                     board_skin, use_comment, use_reaction, use_file,
                     sort_order, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?,
                     0, 0, ?, 1, 'basic', 1, ?, 1, ?, 1, ?, ?)",
                [$domainId, $groupId, $board['slug'], $board['name'], $board['description'],
                 $board['write_level'], $board['use_reaction'], $board['sort_order'], $now, $now]
            );

            // 게시판 메뉴 아이템 (멱등: url+provider 기준) — 미배치 등록(배치는 별도 트랙)
            self::ensureMenuItem($db, $domainId, $board['name'], '/board/' . $board['slug']);
        }

        self::seedFirstNotice($db, $domainId, $now);

        // Board 고정 메뉴 후보 — 커뮤니티 + 마이페이지 섹션(글/댓글). 미배치 등록 → 운영자가 배치.
        // (게시판이 코어→패키지로 분리됨에 따라 코어 시드에서 이관. Board가 곧 메뉴 소유.)
        self::ensureMenuItem($db, $domainId, '커뮤니티', '/community');
        self::ensureMenuItem($db, $domainId, '마이보드', '/mypage/board', 'member');
        self::ensureMenuItem($db, $domainId, '내가 쓴 글', '/mypage/board/articles', 'member');
        self::ensureMenuItem($db, $domainId, '내가 쓴 댓글', '/mypage/board/comments', 'member');
    }

    /**
     * 공지사항의 첫 글 — 빈 게시판을 방문자에게 보이지 않게
     *
     * 갓 만들어진 사이트를 처음 여는 순간이 "등록된 글이 없습니다" 면 안 된다.
     * 홈에 소식 블록을 얹는 구성(SiteKit 이 만드는 사이트가 그렇다)에서는
     * 그 문구가 **첫 화면 한가운데**에 뜬다.
     *
     * 게시판을 만드는 이 자리가 첫 글의 자리이기도 하다 — 나중에 다른 코드가
     * "비었으면 채운다" 를 하면 **운영자가 지운 글이 되살아난다.**
     * 그건 시드가 아니라 되돌리기다.
     *
     * 문구는 어느 사이트에서도 참인 것으로 고른다. 방문자에게도 보이는
     * 글이라 지어낸 내용을 적으면 거짓말이 된다.
     */
    private static function seedFirstNotice(Database $db, int $domainId, string $now): void
    {
        $board = $db->selectOne(
            "SELECT board_id FROM board_configs WHERE domain_id = ? AND board_slug = 'notice' LIMIT 1",
            [$domainId]
        );
        $boardId = (int) ($board['board_id'] ?? 0);
        if ($boardId <= 0) {
            return;
        }

        // 이미 글이 있으면 손대지 않는다 — 재설치·재시도가 남의 글 사이에
        // 인사말을 끼워 넣으면 안 된다.
        $existing = $db->selectOne(
            'SELECT article_id FROM board_articles WHERE domain_id = ? AND board_id = ? LIMIT 1',
            [$domainId, $boardId]
        );
        if ($existing) {
            return;
        }

        try {
            $db->insert(
                'INSERT INTO board_articles
                    (domain_id, board_id, author_name, title, content, status,
                     created_at, updated_at, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $domainId,
                    $boardId,
                    '관리자',
                    '홈페이지를 새로 열었습니다',
                    '<p>방문해 주셔서 감사합니다.</p>'
                    . '<p>앞으로 소식과 안내를 이곳에 올리겠습니다.</p>',
                    'published',
                    $now,
                    $now,
                    $now,
                ]
            );
        } catch (\Throwable) {
            // 첫 글은 편의지 사이트 성립 조건이 아니다. 실패해도 설치를 막지 않는다.
        }
    }

    /**
     * 게시판 메뉴 아이템 멱등 등록
     *
     * 같은 url의 Board 메뉴 아이템이 이미 있으면 건너뛴다.
     * 배치(메인 트리/푸터/유틸/마이페이지)는 지정하지 않는다 — 미배치 등록.
     */
    private static function ensureMenuItem(Database $db, int $domainId, string $label, string $url, string $visibility = 'all'): void
    {
        $existing = $db->selectOne(
            "SELECT item_id FROM menu_items
             WHERE domain_id = ? AND url = ? AND provider_type = 'package' AND provider_name = 'Board'
             LIMIT 1",
            [$domainId, $url]
        );
        if ($existing) {
            return;
        }

        $menuCode = StringHelper::random(8);

        $db->insert(
            "INSERT IGNORE INTO unique_codes (domain_id, code_type, code) VALUES (?, 'menu', ?)",
            [$domainId, $menuCode]
        );

        $db->insert(
            "INSERT INTO menu_items
                (domain_id, menu_code, label, url, visibility, provider_type, provider_name, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'package', 'Board', 1, NOW(), NOW())",
            [$domainId, $menuCode, $label, $url, $visibility]
        );
    }
}
