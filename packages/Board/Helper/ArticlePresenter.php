<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Helper;

use Mublo\Packages\Board\Enum\ArticleStatus;
use Mublo\Helper\String\StringHelper;

/**
 * ArticlePresenter
 *
 * DB 원본 데이터를 스킨 개발자가 바로 사용할 수 있는 표시용 데이터로 변환.
 *
 * 설계 원칙:
 * - 저비용 변환만 수행 (문자열 포맷, 날짜 변환 등)
 * - 고비용 변환(HTML 파싱 등)은 ViewContentHelper에서 on-demand
 * - 보안 필드(author_password, ip_address) 자동 제거
 * - 게시판 설정(boardConfig)을 주입받아 마스킹/신규 기준 등에 활용
 *
 * 사용:
 * ```php
 * // Controller에서 (게시판별 설정 주입)
 * $presenter = new ArticlePresenter($data['board']);
 * $items = $presenter->toList($data['items'], $boardSlug);
 * $article = $presenter->toView($data['article'], $boardSlug);
 *
 * // 커뮤니티 (여러 게시판 혼합, 기본 설정)
 * $presenter = new ArticlePresenter();
 * $items = $presenter->toCommunityList($data['items']);
 * ```
 *
 * 스킨에서:
 * ```php
 * <?= $item['author_name'] ?>          // 글쓴이 원본 (회원: 닉네임, 비회원: 입력값)
 * <?= $item['author_name_masked'] ?>   // 글쓴이 마스킹
 * <?= $item['date_relative'] ?>
 * <a href="<?= $item['url'] ?>"><?= $item['title_safe'] ?></a>
 * ```
 */
class ArticlePresenter
{
    /**
     * 게시판 설정 (BoardConfig::toArray() 결과)
     *
     * 활용 가능한 키:
     * - new_threshold (초): 신규 글 기준 시간 (기본: 86400 = 24시간)
     * - 향후: 마스킹 정책, 날짜 포맷 커스텀 등
     */
    private array $boardConfig;

    public function __construct(array $boardConfig = [])
    {
        $this->boardConfig = $boardConfig;
    }

    /* =========================================================
     * Public API
     * ========================================================= */

    /**
     * 목록용 변환
     *
     * @param array $items 게시글 배열 목록 (toArray() + 첨부/링크 흡수 결과)
     * @param string $boardSlug 게시판 슬러그
     * @return array 변환된 게시글 배열 목록
     */
    public function toList(array $items, string $boardSlug): array
    {
        return array_map(
            fn(array $item) => $this->transform($item, $boardSlug),
            $items
        );
    }

    /**
     * 상세용 변환
     *
     * @param array $article 게시글 배열 (toArray() 결과)
     * @param string $boardSlug 게시판 슬러그
     * @return array 변환된 게시글 배열
     */
    public function toView(array $article, string $boardSlug): array
    {
        return $this->transform($article, $boardSlug);
    }

    /**
     * 이전/다음 글 변환
     *
     * @param array|null $article 게시글 배열 (null이면 null 반환)
     * @param string $boardSlug 게시판 슬러그
     * @return array|null 변환된 게시글 배열
     */
    public function toAdjacent(?array $article, string $boardSlug): ?array
    {
        if ($article === null) {
            return null;
        }

        return $this->transform($article, $boardSlug);
    }

    /**
     * 커뮤니티 목록용 변환
     *
     * Repository가 반환하는 복합 구조를 처리:
     * ['article' => BoardArticle, 'board_slug' => ...]
     *
     * @param array $items 커뮤니티 아이템 배열
     * @return array 변환된 게시글 배열 목록
     */
    public function toCommunityList(array $items): array
    {
        return array_map(function (array $item) {
            // Entity → 배열
            $article = $item['article']->toArray();

            $boardSlug = $item['board_slug'] ?? '';
            $transformed = $this->transform($article, $boardSlug);

            // 커뮤니티 전용 필드 추가
            $transformed['board_name'] = $item['board_name'] ?? '';
            $transformed['board_slug'] = $item['board_slug'] ?? '';
            $transformed['group_name'] = $item['group_name'] ?? '';
            $transformed['group_slug'] = $item['group_slug'] ?? '';

            return $transformed;
        }, $items);
    }

    /* =========================================================
     * 변환 로직
     * ========================================================= */

    /**
     * 공통 변환 (목록/상세/이전다음 공용)
     */
    private function transform(array $item, string $boardSlug): array
    {
        // === 공개 필드 허용 목록 ===
        // Entity/DB에 새 내부 컬럼이 추가돼도 View/JSON 계약으로 자동 노출하지 않는다.
        $item = array_intersect_key($item, array_flip([
            'article_id', 'domain_id', 'board_id', 'category_id', 'member_id',
            'author_name', 'title', 'slug', 'content', 'thumbnail',
            'is_notice', 'is_secret', 'status', 'read_level', 'download_level',
            'view_count', 'comment_count', 'reaction_count',
            'location_lat', 'location_lng', 'created_at', 'updated_at', 'published_at',
            'category_name', 'board_name', 'board_slug', 'attachments', 'links',
        ]));

        // === 작성자 ===
        $item = array_merge($item, $this->buildAuthorFields($item));
        // member_id는 권한 판정용 내부 값이다. Presenter 경계를 넘겨 스킨에 노출하지 않는다.
        unset($item['member_id']);

        // === 날짜 (7가지 포맷) ===
        $createdAt = $item['created_at'] ?? '';
        $item = array_merge($item, $this->buildDateFields($createdAt));

        // === URL ===
        $articleId = $item['article_id'] ?? 0;
        $slug = $item['slug'] ?? '';
        $item['url'] = "/board/{$boardSlug}/view/{$articleId}"
            . ($slug !== '' ? '/' . urlencode($slug) : '');
        $item['edit_url'] = "/board/{$boardSlug}/edit/{$articleId}";

        // === 통계 포맷 ===
        $item['view_count_formatted'] = number_format((int) ($item['view_count'] ?? 0));
        $item['comment_count_formatted'] = number_format((int) ($item['comment_count'] ?? 0));
        $item['reaction_count_formatted'] = number_format((int) ($item['reaction_count'] ?? 0));

        // === 상태 ===
        $status = ArticleStatus::tryFrom($item['status'] ?? 'published');
        $item['status_label'] = $status?->label() ?? ($item['status'] ?? 'published');
        $item['badges'] = $this->buildBadges($item);
        $item['is_new'] = $this->isNew($createdAt);
        $item['is_updated'] = ($item['created_at'] ?? '') !== ($item['updated_at'] ?? '');

        // === 보안 (HTML escape) ===
        $item['title_safe'] = htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8');

        // === 첨부/링크 파생 필드 ===
        $item = array_merge($item, $this->buildRelationFields($item));

        // === 대표 썸네일 (폴백 우선순위는 resolveThumbnail이 단독 소유) ===
        $item['thumbnail'] = $this->resolveThumbnail($item);

        return $item;
    }

    /* =========================================================
     * 작성자
     * ========================================================= */

    /**
     * 작성자 필드 생성 (스킨 제작자가 선택하여 사용)
     *
     * author_name은 board_articles에 항상 저장됨:
     * - 회원: 작성 시점의 닉네임
     * - 비회원: 입력한 이름
     *
     * | 필드 | 회원 | 비회원 | 설명 |
     * |------|------|--------|------|
     * | author_name | '홍길동' | '손님이름' | 글쓴이 (escaped) |
     * | author_name_masked | '홍**' | '손**름' | 마스킹된 글쓴이 (escaped) |
     * | is_member | true | false | 회원 여부 |
     */
    private function buildAuthorFields(array $item): array
    {
        $esc = fn(?string $v): ?string =>
            $v !== null ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : null;

        $isMember = !empty($item['member_id']);
        $name = $item['author_name'] ?? '익명';

        return [
            'is_member'          => $isMember,
            'author_name'        => $esc($name),
            'author_name_masked' => $esc(StringHelper::mask($name, 1, 0)),
        ];
    }

    /* =========================================================
     * 날짜
     * ========================================================= */

    /**
     * 7가지 날짜 포맷 생성
     *
     * | 키 | 예시 | 용도 |
     * |---|---|---|
     * | date_raw | 2026-02-05 14:30:00 | 커스텀 가공용 |
     * | date_full | 2026-02-05 14:30 | 상세 표시 |
     * | date_short | 2026-02-05 | 날짜만 |
     * | date_compact | 02-05 | 월-일 (목록) |
     * | date_time | 14:30 | 시간만 |
     * | date_relative | 2분 전 / 어제 / 02-05 | 상대시간 |
     * | date_ymd | 26.02.05 | 축약 연도 |
     */
    private function buildDateFields(string $createdAt): array
    {
        $empty = [
            'date_raw'      => '',
            'date_full'     => '',
            'date_short'    => '',
            'date_compact'  => '',
            'date_time'     => '',
            'date_relative' => '',
            'date_ymd'      => '',
        ];

        if ($createdAt === '') {
            return $empty;
        }

        try {
            $dt = new \DateTimeImmutable($createdAt);
        } catch (\Exception) {
            return $empty;
        }

        return [
            'date_raw'      => $dt->format('Y-m-d H:i:s'),
            'date_full'     => $dt->format('Y-m-d H:i'),
            'date_short'    => $dt->format('Y-m-d'),
            'date_compact'  => $dt->format('m-d'),
            'date_time'     => $dt->format('H:i'),
            'date_relative' => $this->relativeTime($dt),
            'date_ymd'      => $dt->format('y.m.d'),
        ];
    }

    /**
     * 상대시간 계산 (한국어)
     *
     * - 60초 미만: '방금 전'
     * - 60분 미만: 'N분 전'
     * - 24시간 미만: 'N시간 전'
     * - 48시간 미만: '어제'
     * - 이후: 월-일 형식
     */
    private function relativeTime(\DateTimeImmutable $dt): string
    {
        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        if ($diff < 0) {
            return $dt->format('m-d');
        }
        if ($diff < 60) {
            return '방금 전';
        }
        if ($diff < 3600) {
            return (int) ($diff / 60) . '분 전';
        }
        if ($diff < 86400) {
            return (int) ($diff / 3600) . '시간 전';
        }
        if ($diff < 172800) {
            return '어제';
        }

        return $dt->format('m-d');
    }

    /* =========================================================
     * 상태 / 배지
     * ========================================================= */

    /**
     * 배지 배열 생성
     *
     * @return string[] 예: ['notice', 'secret', 'new']
     */
    private function buildBadges(array $item): array
    {
        $badges = [];

        if (!empty($item['is_notice'])) {
            $badges[] = 'notice';
        }
        if (!empty($item['is_secret'])) {
            $badges[] = 'secret';
        }
        if ($this->isNew($item['created_at'] ?? '')) {
            $badges[] = 'new';
        }

        return $badges;
    }

    /**
     * 신규 글 여부
     *
     * boardConfig의 new_threshold(초) 사용, 기본 86400(24시간)
     */
    private function isNew(string $createdAt): bool
    {
        if ($createdAt === '') {
            return false;
        }

        try {
            $dt = new \DateTimeImmutable($createdAt);
        } catch (\Exception) {
            return false;
        }

        $threshold = (int) ($this->boardConfig['new_threshold'] ?? 86400);
        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        return $diff >= 0 && $diff < $threshold;
    }

    /* =========================================================
     * 첨부 / 링크
     * ========================================================= */

    /**
     * 첨부·링크 파생 필드 생성 (스킨 표시용)
     *
     * 첨부/링크 배열은 상위(서비스/렌더러)에서 $item에 흡수해 넘어온다.
     * 여기서는 그 데이터로 개수·유무 등 편의 필드만 만든다.
     * 대표 썸네일 결정은 resolveThumbnail()이 별도로 담당한다(관심사 분리).
     *
     * | 필드 | 설명 |
     * |------|------|
     * | file_count | 첨부 개수 |
     * | image_count | 이미지 첨부 개수 |
     * | link_count | 링크 개수 |
     * | has_file / has_image / has_link | 유무 플래그 |
     */
    private function buildRelationFields(array $item): array
    {
        $attachments = $this->publicAttachments(
            is_array($item['attachments'] ?? null) ? $item['attachments'] : []
        );
        $links = $this->publicLinks(
            is_array($item['links'] ?? null) ? $item['links'] : []
        );
        $images = array_values(array_filter(
            $attachments,
            static fn(array $attachment): bool => !empty($attachment['is_image'])
        ));

        return [
            'attachments' => $attachments,
            'links'       => $links,
            'file_count'  => count($attachments),
            'image_count' => count($images),
            'link_count'  => count($links),
            'has_file'    => $attachments !== [],
            'has_image'   => $images !== [],
            'has_link'    => $links !== [],
        ];
    }

    /**
     * 이미지 첨부만 추려서 반환 (is_image 플래그 기준)
     *
     * @return array<int, array<string, mixed>>
     */
    private function imageAttachments(array $item): array
    {
        $attachments = is_array($item['attachments'] ?? null) ? $item['attachments'] : [];

        return array_values(array_filter(
            $attachments,
            static fn($a) => !empty($a['is_image'])
        ));
    }

    /**
     * 대표 썸네일 결정 — 폴백 우선순위의 단일 소유 지점.
     *
     * 우선순위대로 처음 값이 나오는 소스를 채택한다. 새 소스(예: 유튜브 영상
     * 썸네일 우선 등)는 이 목록의 원하는 위치에 한 줄 끼워 넣으면 된다.
     * 소스는 지연 평가(클로저)라, 비싼 추출(본문 파싱 등)을 추가해도
     * 앞 우선순위에서 결판나면 실행되지 않는다.
     *
     * 주의: 표시용일 뿐 DB에 기록하지 않는다(board_articles.thumbnail은
     * 쓰기 시점 precompute 값 그대로 유지).
     */
    private function resolveThumbnail(array $item): ?string
    {
        $images = $this->imageAttachments($item);

        $sources = [
            // 1. 쓰기 시점 precompute된 대표 썸네일 (본문 이미지/영상에서 추출)
            fn() => $item['thumbnail'] ?? null,
            // 2. 첫 이미지 첨부 썸네일 → 원본 (이미지는 공개 표시 정보)
            fn() => $images[0]['thumb_url'] ?? null,
            fn() => $images[0]['url'] ?? null,
        ];

        foreach ($sources as $source) {
            $value = $source();
            if (!empty($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * 첨부 목록에 표시용 파일 종류(file_type)와 다운로드 주소(download_url)를 부여한다.
     * (목록/뷰 공용)
     *
     * file_type 은 라이브러리 중립적인 의미 단위만 내보내고, 실제 아이콘 매핑은 스킨이
     * 소유한다(부트스트랩/다른 아이콘셋 자유). 미지원 확장자는 'file'(일반)로 떨어진다.
     */
    public function decorateAttachments(array $attachments): array
    {
        $attachments = $this->publicAttachments($attachments);
        foreach ($attachments as &$att) {
            $att['file_type'] = self::fileType($att['file_extension'] ?? '');
            $att['download_url'] = $this->downloadUrl($att['public_id'] ?? null);
        }
        unset($att);

        return $attachments;
    }

    /**
     * 첨부 다운로드 주소를 완성해서 준다. 스킨은 이 값을 출력만 한다.
     *
     * 스킨이 식별자로 URL 을 직접 조립하면, 식별자가 바뀔 때 조용히 깨진다. 실제로 두 번
     * 겪었다 — 마이그레이션 003 이 attachment_id 를 public_id 로 바꿨을 때 번들 스킨이
     * 한 번(7501299), 갱신되지 않은 커스텀 스킨이 또 한 번. 라우트가 hex 22자를 요구하므로
     * 어긋난 주소는 매칭조차 되지 않아 404 로 떨어지고, 예외가 아니라 로그에도 안 남는다.
     * 조립 지점을 여기 하나로 모아 그 부류를 끊는다.
     *
     * public_id 가 없으면 null 을 준다 — 깨진 링크를 그리는 대신 스킨이 '받을 수 없음'으로
     * 표시할 수 있게 한다. 조용한 404 보다 눈에 보이는 상태가 낫다.
     */
    private function downloadUrl(?string $publicId): ?string
    {
        $slug = (string) ($this->boardConfig['board_slug'] ?? '');
        if ($slug === '' || (string) $publicId === '') {
            return null;
        }

        return '/board/' . rawurlencode($slug) . '/file/download/' . $publicId;
    }

    /**
     * 스킨에 넘길 첨부 필드를 고정한다.
     *
     * download_url 은 decorateAttachments() 가 채운다. public_id 도 함께 남기지만 이는
     * 참조용이고, 스킨은 URL 을 직접 만들지 말고 download_url 을 써야 한다.
     *
     * @return list<array<string, mixed>>
     */
    private function publicAttachments(array $attachments): array
    {
        $allowed = array_flip([
            'attachment_id', 'public_id', 'original_name', 'file_size', 'file_extension', 'mime_type',
            'is_image', 'image_width', 'image_height', 'thumbnail_path',
            'download_count', 'created_at',
            'thumb_url', 'url', 'file_type', 'download_url',
        ]);

        return array_values(array_map(
            static fn(array $attachment): array => array_intersect_key($attachment, $allowed),
            $attachments
        ));
    }

    /** @return list<array<string, mixed>> */
    private function publicLinks(array $links): array
    {
        $allowed = array_flip([
            'link_id', 'link_url', 'link_title', 'link_description', 'link_image',
            'click_count', 'created_at',
        ]);

        $safeLinks = [];
        foreach ($links as $link) {
            $url = BoardLinkUrlPolicy::normalize($link['link_url'] ?? null);
            if ($url === null) {
                continue;
            }
            $link['link_url'] = $url;
            $safeLinks[] = array_intersect_key($link, $allowed);
        }

        return array_values($safeLinks);
    }

    /**
     * 확장자 → 파일 종류(semantic) 매핑 (그룹형 + 제너릭 폴백)
     *
     * 반환값: image|pdf|document|spreadsheet|presentation|archive|text|video|audio|file
     * 아이콘 라이브러리에 의존하지 않는 의미 단위라 목록/뷰/관리자 어디서든 재사용 가능.
     */
    public static function fileType(string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' => 'image',
            'pdf'                                       => 'pdf',
            'doc', 'docx', 'hwp', 'hwpx', 'rtf', 'odt'  => 'document',
            'xls', 'xlsx', 'csv'                        => 'spreadsheet',
            'ppt', 'pptx'                               => 'presentation',
            'zip', 'rar', '7z', 'tar', 'gz'             => 'archive',
            'txt', 'md'                                 => 'text',
            'mp4', 'mov', 'avi', 'mkv', 'webm'          => 'video',
            'mp3', 'wav', 'flac', 'ogg'                 => 'audio',
            default                                     => 'file',
        };
    }

    /* =========================================================
     * 설정 접근
     * ========================================================= */

    /**
     * 게시판 설정값 조회
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->boardConfig[$key] ?? $default;
    }
}
