<?php

namespace Mublo\Service\Domain;

use Mublo\Contract\Site\CompanyInfoInterface;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Core\Result\Result;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Search\SearchSourceCollectEvent;
use Mublo\Core\Rendering\ThemeSkinPolicy;

/**
 * DomainSettingsService
 *
 * 도메인별 설정(사이트/회사/SEO/테마/확장) 관리
 *
 * DomainService에서 분리된 Settings 전담 서비스:
 * - 설정 조회 (기본값 병합)
 * - 설정 저장 (정제 + 검증)
 * - 개별 설정 업데이트
 * - CompanyInfoInterface(안정 API) 구현 — 확장의 회사 정보 읽기 통로
 *
 * 사용처: SettingsController (자신의 도메인 설정)
 */
class DomainSettingsService implements CompanyInfoInterface
{
    // =========================================================================
    // 설정 키 상수
    // =========================================================================

    /** Site config keys */
    public const SITE_KEYS = [
        'site_title', 'site_subtitle', 'admin_email', 'timezone', 'language',
        'editor', 'per_page', 'use_email_as_userid',
        'layout_type', 'layout_left_width', 'layout_right_width',
        'sidebar_left_mobile', 'sidebar_right_mobile',
        'layout_max_width', 'content_max_width', 'use_main_layout',
        'primary_color',
        'search_source_order', 'search_enabled_sources', 'search_per_source',
        'join_type', 'default_level_value',
    ];

    /** Company config keys */
    public const COMPANY_KEYS = [
        'name', 'owner', 'tel', 'fax', 'email',
        'business_number', 'tongsin_number',
        'zipcode', 'address', 'address_detail',
        'privacy_officer', 'privacy_email',
        'cs_tel', 'cs_time',
    ];

    /** SEO config keys (includes logo, favicon, tracking pixels) */
    public const SEO_KEYS = [
        'logo_pc', 'logo_mobile', 'favicon', 'app_icon', 'og_image',
        'meta_title', 'meta_description', 'meta_keywords',
        'google_analytics', 'google_site_verification', 'naver_site_verification',
        'meta_pixel_id', 'kakao_pixel_id', 'naver_analytics_id',
        'custom_head_script', 'custom_body_script',
        'sns_channels',
        'robots_txt',
    ];

    /** Theme config keys */
    public const THEME_KEYS = [
        'admin', 'frame', 'index', 'member', 'auth', 'mypage', 'policy', 'search',
    ];

    private DomainRepository $domainRepository;
    private DomainResolver $domainResolver;
    private ?EventDispatcher $eventDispatcher;

    public function __construct(
        DomainRepository $domainRepository,
        DomainResolver $domainResolver,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->domainRepository = $domainRepository;
        $this->domainResolver = $domainResolver;
        $this->eventDispatcher = $eventDispatcher;
    }

    // =========================================================================
    // 설정 조회 (기본값 병합)
    // =========================================================================

    /**
     * 사이트 설정 조회
     */
    public function getSiteConfig(int $domainId): array
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return $this->getDefaultSiteConfig();
        }
        return array_merge($this->getDefaultSiteConfig(), $domain->getSiteConfig());
    }

    /**
     * 회사 정보 조회
     */
    public function getCompanyConfig(int $domainId): array
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return $this->getDefaultCompanyConfig();
        }
        return array_merge($this->getDefaultCompanyConfig(), $domain->getCompanyConfig());
    }

    /**
     * SEO 설정 조회
     */
    public function getSeoConfig(int $domainId): array
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return $this->getDefaultSeoConfig();
        }
        return array_merge($this->getDefaultSeoConfig(), $domain->getSeoConfig());
    }

    /**
     * 테마 설정 조회
     */
    public function getThemeConfig(int $domainId): array
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return $this->getDefaultThemeConfig();
        }
        return array_merge($this->getDefaultThemeConfig(), $domain->getThemeConfig());
    }

    // =========================================================================
    // 기본 설정값
    // =========================================================================

    public function getDefaultSiteConfig(): array
    {
        return [
            'site_title' => '',
            'site_subtitle' => '',
            'admin_email' => '',
            // 이메일 채널 전송 정책 — 코어 이메일 게이트웨이가 발송 전 확인 (false 면 채널 전체 중단)
            'email_channel_enabled' => true,
            'timezone' => 'Asia/Seoul',
            'language' => 'ko',
            'editor' => 'mublo-editor',
            'per_page' => 20,
            // 레이아웃 설정
            'layout_type' => 'full',
            'layout_left_width' => 250,
            'layout_right_width' => 250,
            'sidebar_left_mobile' => false,
            'sidebar_right_mobile' => false,
            'layout_max_width' => 1200,
            'content_max_width' => 0,
            'use_main_layout' => false,
            // 전체 검색 설정. enabled_sources: null=미설정(전체 활성) / []=전체 해제 / [목록]=명시
            'search_source_order'    => [],
            'search_enabled_sources' => null,
            'search_per_source'      => 5,
            // 회원가입 설정
            'join_type'           => 'immediate',
            'default_level_value' => 1,
        ];
    }

    public function getDefaultCompanyConfig(): array
    {
        return [
            'name' => '',
            'owner' => '',
            'tel' => '',
            'fax' => '',
            'email' => '',
            'business_number' => '',
            'tongsin_number' => '',
            'zipcode' => '',
            'address' => '',
            'address_detail' => '',
            'privacy_officer' => '',
            'privacy_email' => '',
            'cs_tel' => '',
            'cs_time' => '',
        ];
    }

    public function getDefaultSeoConfig(): array
    {
        return [
            'logo_pc' => '',
            'logo_mobile' => '',
            'favicon' => '',
            'app_icon' => '',
            'og_image' => '',
            'meta_title' => '',
            'meta_description' => '',
            'meta_keywords' => '',
            'google_analytics' => '',
            'google_site_verification' => '',
            'naver_site_verification' => '',
            'meta_pixel_id' => '',
            'kakao_pixel_id' => '',
            'naver_analytics_id' => '',
            'custom_head_script' => '',
            'custom_body_script' => '',
            'sns_channels' => [],
            'robots_txt' => '',
        ];
    }

    public function getDefaultThemeConfig(): array
    {
        return [
            'admin' => 'basic',
            'frame' => 'basic',
            'index' => 'basic',
            'member' => 'basic',
            'auth' => 'basic',
            'mypage' => 'basic',
            'policy' => 'basic',
            'search' => 'basic',
        ];
    }

    // =========================================================================
    // 통합 설정 저장
    // =========================================================================

    /**
     * 설정 통합 저장
     *
     * @param int $domainId 도메인 ID
     * @param array $settings 섹션별 설정 데이터 ['site' => [...], 'company' => [...], ...]
     * @return Result
     */
    public function saveSettings(int $domainId, array $settings): Result
    {
        $errors = [];

        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return Result::failure('도메인을 찾을 수 없습니다.', ['errors' => ['domain' => '도메인을 찾을 수 없습니다.']]);
        }

        // site 설정
        if (isset($settings['site'])) {
            $siteConfig = $this->sanitizeSiteConfig($settings['site']);
            $validation = $this->validateSiteConfig($siteConfig);
            if (!$validation['valid']) {
                $errors = array_merge($errors, $validation['errors']);
            } else {
                $this->domainRepository->updateSiteConfig($domainId, $siteConfig);
            }
        }

        // company 설정
        if (isset($settings['company'])) {
            $companyConfig = $this->sanitizeCompanyConfig($settings['company']);
            $validation = $this->validateCompanyConfig($companyConfig);
            if (!$validation['valid']) {
                $errors = array_merge($errors, $validation['errors']);
            } else {
                $this->domainRepository->updateCompanyConfig($domainId, $companyConfig);
            }
        }

        // seo 설정
        if (isset($settings['seo'])) {
            $seoConfig = $this->sanitizeSeoConfig($settings['seo']);
            $this->domainRepository->updateSeoConfig($domainId, $seoConfig);
        }

        // theme 설정
        if (isset($settings['theme'])) {
            $themeConfig = $this->sanitizeThemeConfig($settings['theme']);
            $validation = $this->validateThemeConfig($themeConfig);
            if (!$validation['valid']) {
                $errors = array_merge($errors, $validation['errors']);
            } else {
                // 확장·기능 소유 버킷은 사이트설정 폼이 모르므로 기존 값 보존
                // - frame_overrides: Package 프레임 교체 (FrameOverride)
                // - frame_edit: 도메인 프레임 편집 게시 플래그 (DomainFrameService)
                $preserveBuckets = [
                    \Mublo\Core\Theme\FrameOverride::BUCKET,
                    \Mublo\Service\Frame\DomainFrameService::BUCKET,
                ];
                foreach ($preserveBuckets as $bucket) {
                    $existingBucket = $domain->getThemeConfig()[$bucket] ?? null;
                    if ($existingBucket !== null) {
                        $themeConfig[$bucket] = $existingBucket;
                    }
                }
                $this->domainRepository->updateThemeConfig($domainId, $themeConfig);
            }
        }

        // extension 설정 (플러그인/패키지 활성화)
        if (isset($settings['extension'])) {
            $extensionConfig = $this->sanitizeExtensionConfig($settings['extension']);
            $this->domainRepository->updateExtensionConfig($domainId, $extensionConfig);
        }

        // 캐시 무효화
        $this->domainResolver->invalidate($domain->getDomain());

        if (!empty($errors)) {
            return Result::failure('일부 설정 저장에 실패했습니다.', ['errors' => $errors]);
        }

        return Result::success('설정이 저장되었습니다.');
    }

    // =========================================================================
    // 개별 설정 업데이트
    // =========================================================================

    /**
     * 사이트 설정 업데이트
     */
    public function updateSiteConfig(int $domainId, array $config): Result
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return Result::failure('도메인을 찾을 수 없습니다.');
        }

        $mergedConfig = array_merge($domain->getSiteConfig(), $config);
        $this->domainRepository->updateSiteConfig($domainId, $mergedConfig);
        $this->domainResolver->invalidate($domain->getDomain());

        return Result::success('사이트 설정이 저장되었습니다.');
    }

    /**
     * 회사 정보 업데이트
     */
    public function updateCompanyConfig(int $domainId, array $config): Result
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return Result::failure('도메인을 찾을 수 없습니다.');
        }

        $mergedConfig = array_merge($domain->getCompanyConfig(), $config);
        $this->domainRepository->updateCompanyConfig($domainId, $mergedConfig);
        $this->domainResolver->invalidate($domain->getDomain());

        return Result::success('회사 정보가 저장되었습니다.');
    }

    /**
     * SEO 설정 업데이트
     */
    public function updateSeoConfig(int $domainId, array $config): Result
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return Result::failure('도메인을 찾을 수 없습니다.');
        }

        $mergedConfig = array_merge($domain->getSeoConfig(), $config);
        $this->domainRepository->updateSeoConfig($domainId, $mergedConfig);
        $this->domainResolver->invalidate($domain->getDomain());

        return Result::success('SEO 설정이 저장되었습니다.');
    }

    /**
     * 테마 설정 업데이트
     */
    public function updateThemeConfig(int $domainId, array $config): Result
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return Result::failure('도메인을 찾을 수 없습니다.');
        }

        $themeSelection = array_intersect_key($config, array_flip(self::THEME_KEYS));
        $validation = $this->validateThemeConfig($themeSelection, false);
        if (!$validation['valid']) {
            return Result::failure('올바르지 않은 테마 설정입니다.', ['errors' => $validation['errors']]);
        }

        $mergedConfig = array_merge($domain->getThemeConfig(), $config);
        $this->domainRepository->updateThemeConfig($domainId, $mergedConfig);
        $this->domainResolver->invalidate($domain->getDomain());

        return Result::success('테마 설정이 저장되었습니다.');
    }

    /**
     * 확장 기능 설정 업데이트
     */
    public function updateExtensionConfig(int $domainId, array $config): Result
    {
        $domain = $this->domainRepository->find($domainId);
        if (!$domain) {
            return Result::failure('도메인을 찾을 수 없습니다.');
        }

        // 확장 기능은 병합이 아닌 덮어쓰기 (plugins, packages 배열)
        $this->domainRepository->updateExtensionConfig($domainId, $config);
        $this->domainResolver->invalidate($domain->getDomain());

        return Result::success('확장 기능 설정이 저장되었습니다.');
    }

    // =========================================================================
    // 설정 정제 (Sanitize)
    // =========================================================================

    protected function sanitizeSiteConfig(array $data): array
    {
        $validLayouts = ['full', 'left-sidebar', 'right-sidebar', 'three-column'];

        return [
            'site_title' => trim($data['site_title'] ?? ''),
            'site_subtitle' => trim($data['site_subtitle'] ?? ''),
            'admin_email' => trim($data['admin_email'] ?? ''),
            'email_channel_enabled' => !empty($data['email_channel_enabled']),
            'timezone' => $data['timezone'] ?? 'Asia/Seoul',
            'language' => $data['language'] ?? 'ko',
            'editor' => $data['editor'] ?? 'mublo-editor',
            'per_page' => (int)($data['per_page'] ?? 20),
            'use_email_as_userid' => (int)!empty($data['use_email_as_userid']),
            // 레이아웃 설정
            'layout_type' => in_array($data['layout_type'] ?? '', $validLayouts) ? $data['layout_type'] : 'full',
            'layout_left_width' => max(0, (int)($data['layout_left_width'] ?? 250)),
            'layout_right_width' => max(0, (int)($data['layout_right_width'] ?? 250)),
            'sidebar_left_mobile' => !empty($data['sidebar_left_mobile']),
            'sidebar_right_mobile' => !empty($data['sidebar_right_mobile']),
            'layout_max_width' => max(0, (int)($data['layout_max_width'] ?? 1200)),
            'content_max_width' => max(0, (int)($data['content_max_width'] ?? 0)),
            'use_main_layout' => !empty($data['use_main_layout']),
            // 전체 검색 설정
            'search_source_order'    => $this->sanitizeSearchSourceList($data['search_source_order'] ?? []),
            'search_enabled_sources' => $this->sanitizeSearchSourceList($data['search_enabled_sources'] ?? []),
            'search_per_source'      => max(1, min(20, (int)($data['search_per_source'] ?? 5))),
            // 회원가입 설정
            'join_type'           => in_array($data['join_type'] ?? '', ['immediate', 'approval']) ? $data['join_type'] : 'immediate',
            'default_level_value' => max(1, (int)($data['default_level_value'] ?? 1)),
            // 비밀번호 정책 (기본 최소 6자, 복잡도 규칙 기본 off)
            'password_min_length'      => max(4, min(32, (int)($data['password_min_length'] ?? 6))),
            'password_require_number'  => !empty($data['password_require_number']),
            'password_require_special' => !empty($data['password_require_special']),
            'password_require_lower'   => !empty($data['password_require_lower']),
        ];
    }

    protected function sanitizeCompanyConfig(array $data): array
    {
        return [
            'name' => trim($data['name'] ?? ''),
            'owner' => trim($data['owner'] ?? ''),
            'tel' => trim($data['tel'] ?? ''),
            'fax' => trim($data['fax'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'business_number' => trim($data['business_number'] ?? ''),
            'tongsin_number' => trim($data['tongsin_number'] ?? ''),
            'zipcode' => trim($data['zipcode'] ?? ''),
            'address' => trim($data['address'] ?? ''),
            'address_detail' => trim($data['address_detail'] ?? ''),
            'privacy_officer' => trim($data['privacy_officer'] ?? ''),
            'privacy_email' => trim($data['privacy_email'] ?? ''),
            'cs_tel' => trim($data['cs_tel'] ?? ''),
            'cs_time' => trim($data['cs_time'] ?? ''),
        ];
    }

    /**
     * 검색 가능한 소스 목록 수집 (이벤트 기반)
     *
     * 각 Package의 SearchSubscriber가 SearchSourceCollectEvent를 구독하여
     * 자신의 소스를 등록한다. Core는 패키지별 소스를 하드코딩하지 않는다.
     *
     * @return array<array{source: string, label: string, always: bool}>
     */
    public function getAvailableSearchSources(): array
    {
        $event = new SearchSourceCollectEvent();
        $this->eventDispatcher?->dispatch($event);
        return $event->getSources();
    }

    /**
     * 검색 소스 목록 정제 (이벤트로 수집한 허용 소스만, 중복 제거)
     */
    private function sanitizeSearchSourceList(mixed $value): array
    {
        $event = new SearchSourceCollectEvent();
        $this->eventDispatcher?->dispatch($event);
        $allowed = $event->getSourceKeys();

        if (is_string($value)) {
            // JSON 문자열 또는 CSV 허용
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $source) {
            $source = trim((string) $source);
            if (in_array($source, $allowed, true) && !in_array($source, $result, true)) {
                $result[] = $source;
            }
        }

        // 빈 배열 허용 (빈 배열 = 전체 활성화 의미)
        return $result;
    }

    protected function sanitizeSeoConfig(array $data): array
    {
        return [
            'logo_pc' => trim($data['logo_pc'] ?? ''),
            'logo_mobile' => trim($data['logo_mobile'] ?? ''),
            'favicon' => trim($data['favicon'] ?? ''),
            'app_icon' => trim($data['app_icon'] ?? ''),
            'og_image' => trim($data['og_image'] ?? ''),
            'meta_title' => trim($data['meta_title'] ?? ''),
            'meta_description' => trim($data['meta_description'] ?? ''),
            'meta_keywords' => trim($data['meta_keywords'] ?? ''),
            'google_analytics' => trim($data['google_analytics'] ?? ''),
            'google_site_verification' => trim($data['google_site_verification'] ?? ''),
            'naver_site_verification' => trim($data['naver_site_verification'] ?? ''),
            // 트래킹 픽셀 ID
            'meta_pixel_id' => trim($data['meta_pixel_id'] ?? ''),
            'kakao_pixel_id' => trim($data['kakao_pixel_id'] ?? ''),
            'naver_analytics_id' => trim($data['naver_analytics_id'] ?? ''),
            // 사용자 정의 스크립트(<script> 포함 원본 그대로 저장)
            'custom_head_script' => (string) ($data['custom_head_script'] ?? ''),
            'custom_body_script' => (string) ($data['custom_body_script'] ?? ''),
            'sns_channels' => $data['sns_channels'] ?? [],
            'robots_txt' => trim($data['robots_txt'] ?? ''),
        ];
    }

    protected function sanitizeThemeConfig(array $data): array
    {
        return [
            'admin' => $data['admin'] ?? 'basic',
            'frame' => $data['frame'] ?? 'basic',
            'index' => $data['index'] ?? 'basic',
            'member' => $data['member'] ?? 'basic',
            'auth' => $data['auth'] ?? 'basic',
            'mypage' => $data['mypage'] ?? 'basic',
            'policy' => $data['policy'] ?? 'basic',
            'search' => $data['search'] ?? 'basic',
        ];
    }

    protected function sanitizeExtensionConfig(array $data): array
    {
        $plugins = array_values(array_filter(
            $data['plugins'] ?? [],
            fn($v) => is_string($v) && !empty(trim($v))
        ));

        $packages = array_values(array_filter(
            $data['packages'] ?? [],
            fn($v) => is_string($v) && !empty(trim($v))
        ));

        return [
            'plugins' => $plugins,
            'packages' => $packages,
        ];
    }

    // =========================================================================
    // 설정 검증 (Validate)
    // =========================================================================

    protected function validateSiteConfig(array $config): array
    {
        $errors = [];

        if (!empty($config['admin_email']) && !filter_var($config['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = '올바른 이메일 형식으로 입력해주세요.';
        }

        $validTimezones = [
            'Asia/Seoul', 'Asia/Tokyo', 'Asia/Shanghai',
            'America/New_York', 'America/Los_Angeles',
            'Europe/London', 'Europe/Paris', 'UTC',
        ];
        if (!empty($config['timezone']) && !in_array($config['timezone'], $validTimezones)) {
            $errors['timezone'] = '올바른 타임존을 선택해주세요.';
        }

        $validLanguages = ['ko', 'en', 'ja', 'zh'];
        if (!empty($config['language']) && !in_array($config['language'], $validLanguages)) {
            $errors['language'] = '올바른 언어를 선택해주세요.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    protected function validateCompanyConfig(array $config): array
    {
        $errors = [];

        if (!empty($config['email']) && !filter_var($config['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '올바른 이메일 형식으로 입력해주세요.';
        }

        if (!empty($config['privacy_email']) && !filter_var($config['privacy_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['privacy_email'] = '올바른 이메일 형식으로 입력해주세요.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param bool $requireAll 통합 설정 저장은 모든 스킨 그룹을 검증하고,
     *                         부분 업데이트는 전달된 그룹만 검증한다.
     */
    protected function validateThemeConfig(array $config, bool $requireAll = true): array
    {
        $errors = [];
        $keys = $requireAll ? self::THEME_KEYS : array_keys($config);

        foreach ($keys as $key) {
            if (!in_array($key, self::THEME_KEYS, true)) {
                continue;
            }

            $skin = $config[$key] ?? null;
            if (!ThemeSkinPolicy::isValidName($skin)) {
                $errors["theme.{$key}"] = '스킨 이름 형식이 올바르지 않습니다.';
                continue;
            }

            if (!ThemeSkinPolicy::componentExists($key, $skin)) {
                $errors["theme.{$key}"] = '존재하지 않는 스킨입니다.';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
