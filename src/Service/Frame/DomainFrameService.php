<?php

namespace Mublo\Service\Frame;

use Mublo\Core\Result\Result;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Repository\Frame\DomainFrameOverrideRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Core\Rendering\ThemeSkinPolicy;

/**
 * 도메인 프레임 편집 서비스
 *
 * header/footer 오버라이드의 초안 저장 → 게시 → 스킨으로 되돌리기 흐름과
 * theme_config `frame_edit.parts` 플래그를 관리한다.
 *
 * 조회 비용 0 원칙 (계획문서 §4):
 * 게시/되돌리기 시점에만 theme_config 플래그를 갱신하고 도메인 캐시를
 * 무효화한다. 프론트 렌더는 캐시된 플래그만 보고, 플래그가 없는 도메인은
 * 오버라이드 쿼리를 아예 하지 않는다.
 *
 * 마커 처리 (계획문서 §3.2):
 * MUBLO_CSS/MUBLO_JS 마커는 Head/Foot 소유라 편집 대상이 아니다.
 * 운영자 HTML에 마커 문자열이 들어오면 flushWithAssets()가 에셋을 중복
 * 주입하므로 저장 시 제거한다.
 */
class DomainFrameService
{
    public const PARTS = ['header', 'footer'];

    /**
     * theme_config 내 프레임 편집 버킷 키
     */
    public const BUCKET = 'frame_edit';

    /**
     * 직접 입력(수동 편집) 크기 상한 — 실수로 수 MB를 붙여 넣으면 모든
     * 프런트 응답에 실리므로 필드별로 제한한다 (mb 문자 수 기준)
     */
    public const MAX_HTML_CHARS = 300_000;
    public const MAX_CSS_CHARS = 150_000;
    public const MAX_JS_CHARS = 150_000;

    public function __construct(
        private DomainFrameOverrideRepository $overrides,
        private DomainRepository $domainRepository,
        private DomainResolver $domainResolver,
        private \Mublo\Infrastructure\Database\Database $db,
    ) {
    }

    /**
     * 편집 상태 조회 (에디터용 — draft 포함 전체 행)
     */
    public function get(int $domainId, string $part): ?array
    {
        $this->assertPart($part);

        return $this->overrides->find($domainId, $part);
    }

    /**
     * 초안 저장 — 게시본·프론트에는 영향 없음
     */
    public function saveDraft(
        int $domainId,
        string $part,
        string $html,
        string $css = '',
        string $js = '',
        ?string $seededFromSkin = null,
        ?int $updatedBy = null
    ): Result {
        $this->assertPart($part);

        foreach ([
            ['HTML', $html, self::MAX_HTML_CHARS],
            ['CSS', $css, self::MAX_CSS_CHARS],
            ['JS', $js, self::MAX_JS_CHARS],
        ] as [$label, $value, $max]) {
            if (mb_strlen($value) > $max) {
                return Result::failure(sprintf(
                    '%s가 허용 크기(%s자)를 초과했습니다. 큰 이미지는 파일로 업로드하고 경로만 참조하세요.',
                    $label,
                    number_format($max)
                ));
            }
        }

        $this->overrides->saveDraft(
            $domainId,
            $part,
            self::stripAssetMarkers($html),
            $css,
            $js,
            $seededFromSkin,
            $updatedBy
        );

        return Result::success('초안이 저장되었습니다.');
    }

    /**
     * 게시 — draft를 게시본으로 승격하고 프론트 렌더 플래그를 켠다
     */
    public function publish(int $domainId, string $part, ?int $updatedBy = null): Result
    {
        $this->assertPart($part);

        // 행 상태 전환과 렌더 플래그는 한 트랜잭션 — 하나만 성공한 불일치
        // 상태(행은 published인데 플래그는 꺼짐)를 만들지 않는다.
        // 캐시 무효화는 커밋이 확정된 뒤에만 수행한다.
        $invalidate = null;
        try {
            $published = $this->db->transaction(function () use ($domainId, $part, $updatedBy, &$invalidate): bool {
                if (!$this->overrides->publish($domainId, $part, $updatedBy)) {
                    return false;
                }
                $invalidate = $this->updateFlag($domainId, $part, true);
                return true;
            });
        } catch (\Throwable $e) {
            error_log("[DomainFrameService] publish failed ({$domainId}/{$part}): " . $e->getMessage());
            return Result::failure('게시 처리에 실패했습니다. 변경 사항은 적용되지 않았습니다.');
        }

        if (!$published) {
            return Result::failure('게시할 초안이 없습니다.');
        }

        if ($invalidate !== null) {
            $invalidate();
        }

        return Result::success('게시되었습니다. 이제 파일 스킨 대신 이 내용이 렌더됩니다.');
    }

    /**
     * 스킨으로 되돌리기 — 비파괴 (명확한 탈출구, §3.7)
     *
     * 게시만 해제하고(status→draft) 편집 내용은 초안으로 보관한다.
     * 프론트는 즉시 파일 스킨으로 복귀하며, 초안은 언제든 재게시할 수 있다.
     * 물리 삭제는 하지 않는다 — "새로 시작"은 에디터에서 시드를 다시 불러
     * 저장하는 것으로 충분하다.
     */
    public function revertToSkin(int $domainId, string $part): Result
    {
        $this->assertPart($part);

        $invalidate = null;
        try {
            $this->db->transaction(function () use ($domainId, $part, &$invalidate): bool {
                $this->overrides->unpublish($domainId, $part);
                $invalidate = $this->updateFlag($domainId, $part, false);
                return true;
            });
        } catch (\Throwable $e) {
            error_log("[DomainFrameService] revert failed ({$domainId}/{$part}): " . $e->getMessage());
            return Result::failure('되돌리기 처리에 실패했습니다. 변경 사항은 적용되지 않았습니다.');
        }

        if ($invalidate !== null) {
            $invalidate();
        }

        return Result::success('파일 스킨으로 되돌렸습니다. 편집 내용은 초안으로 보관되어 언제든 다시 게시할 수 있습니다.');
    }

    /**
     * 편집 시드 로드 (§3.6 — 빈 캔버스 금지)
     *
     * 1순위: 현재 사용 중인 프레임 스킨의 {Part}.seed.html
     * 폴백: 코어 basic 스킨 공식 시드 (이때 skin 값으로 폴백 여부를 알 수 있어
     *        에디터가 "이 스킨은 시드를 제공하지 않습니다" 안내를 띄운다)
     *
     * @return array{html: string, skin: string} skin = 시드를 제공한 스킨 ('' = 시드 없음)
     */
    public static function loadSeed(string $frameSkin, string $part): array
    {
        if (!in_array($part, self::PARTS, true)) {
            error_log("[DomainFrameService] Invalid seed part rejected: {$part}");
            return ['html' => '', 'skin' => ''];
        }

        $frameSkin = ThemeSkinPolicy::resolveExisting('frame', $frameSkin);
        $file = ucfirst($part) . '.seed.html';

        foreach (array_unique([$frameSkin, 'basic']) as $skin) {
            $path = MUBLO_VIEW_PATH . "/Front/frame/{$skin}/{$file}";
            if (is_file($path)) {
                return ['html' => (string) file_get_contents($path), 'skin' => $skin];
            }
        }

        return ['html' => '', 'skin' => ''];
    }

    /**
     * theme_config에서 게시된 파트 목록 읽기 (프론트 렌더의 플래그 확인용)
     *
     * @return string[] 예: ['header'] — 게시된 오버라이드가 있는 파트만
     */
    public static function publishedParts(array $themeConfig): array
    {
        $parts = $themeConfig[self::BUCKET]['parts'] ?? [];
        if (!is_array($parts)) {
            return [];
        }

        return array_values(array_intersect($parts, self::PARTS));
    }

    /**
     * MUBLO_CSS / MUBLO_JS 마커 주석 제거 (§3.2 — 중복 주입 방지)
     */
    public static function stripAssetMarkers(string $html): string
    {
        return (string) preg_replace('/<!--\s*MUBLO_(?:CSS|JS)[^>]*-->/u', '', $html);
    }

    /**
     * theme_config `frame_edit.parts` 플래그 갱신
     *
     * 확장 소유 버킷 보존 원칙에 따라 frame_edit 버킷만 갈아끼운다.
     * 트랜잭션 안에서 호출된다 — 저장 실패는 예외로 승격해 전체를 롤백시키고,
     * 캐시 무효화는 커밋 후에 해야 하므로 콜백으로 반환한다.
     *
     * @return callable 커밋 후 호출할 도메인 캐시 무효화 콜백
     */
    private function updateFlag(int $domainId, string $part, bool $on): callable
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            throw new \RuntimeException("도메인을 찾을 수 없습니다: {$domainId}");
        }

        $themeConfig = $domain->getThemeConfig();
        $parts = self::publishedParts($themeConfig);

        $newParts = $on
            ? array_values(array_unique(array_merge($parts, [$part])))
            : array_values(array_diff($parts, [$part]));

        if ($newParts === []) {
            unset($themeConfig[self::BUCKET]);
        } else {
            $themeConfig[self::BUCKET] = ['parts' => $newParts];
        }

        if (!$this->domainRepository->updateThemeConfig($domainId, $themeConfig) && $newParts !== $parts) {
            throw new \RuntimeException("렌더 플래그 저장에 실패했습니다: {$domainId}/{$part}");
        }

        $domainName = $domain->getDomain();

        return fn () => $this->domainResolver->invalidate($domainName);
    }

    private function assertPart(string $part): void
    {
        if (!in_array($part, self::PARTS, true)) {
            throw new \InvalidArgumentException("지원하지 않는 프레임 파트: {$part}");
        }
    }
}
