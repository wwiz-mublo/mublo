<?php
declare(strict_types=1);
namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Service\Domain\DomainService;
use Mublo\Service\Domain\DomainSettingsService;
use Mublo\Service\Migration\CoreMigrationService;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Helper\Sns\SnsHelper;
use Mublo\Helper\Directory\DirectoryHelper;
use Mublo\Service\Member\MemberLevelService;
use Mublo\Core\Rendering\ThemeSkinPolicy;

/**
 * Admin SettingsController
 *
 * 사이트 환경 설정 관리 컨트롤러
 * 자신의 도메인 설정을 관리
 */
class SettingsController
{
    private DomainService $domainService;
    private DomainSettingsService $settingsService;
    private FileUploader $fileUploader;
    private CoreMigrationService $migrationService;
    private MemberLevelService $levelService;

    public function __construct(
        DomainService $domainService,
        DomainSettingsService $settingsService,
        FileUploader $fileUploader,
        CoreMigrationService $migrationService,
        MemberLevelService $levelService
    ) {
        $this->domainService = $domainService;
        $this->settingsService = $settingsService;
        $this->fileUploader = $fileUploader;
        $this->migrationService = $migrationService;
        $this->levelService = $levelService;
    }

    /**
     * 설정 페이지 (메인)
     *
     * 확장 기능(플러그인/패키지)은 별도 관리 메뉴에서 처리
     *
     * GET /admin/settings
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        // 미실행 Core 마이그레이션 자동 실행
        $migrationResult = null;
        if ($this->migrationService->hasPending()) {
            $migrationResult = $this->migrationService->runPending();
        }

        // 도메인 정보 조회
        $domain = $this->domainService->findById($domainId);

        // 각 설정 데이터 조회
        $siteConfig = $this->settingsService->getSiteConfig($domainId);
        $companyConfig = $this->settingsService->getCompanyConfig($domainId);
        $seoConfig = $this->settingsService->getSeoConfig($domainId);
        $themeConfig = $this->settingsService->getThemeConfig($domainId);

        // 탭 메뉴
        $anchor = [
            'anc_basic' => '기본 정보',
            'anc_company' => '회사 정보',
            'anc_seo' => '로고 및 SEO 설정',
            'anc_theme' => '테마 설정',
            'anc_search' => '검색 설정',
        ];

        // 이벤트 기반 검색 소스 목록 수집
        $searchSources = $this->settingsService->getAvailableSearchSources();

        return ViewResponse::view('settings/index')
            ->withData([
                'pageTitle' => '기본 설정',
                'description' => '홈페이지 기본 환경을 설정합니다.',
                'anchor' => $anchor,
                'domain' => $domain,
                'siteConfig' => $siteConfig,
                'companyConfig' => $companyConfig,
                'seoConfig' => $seoConfig,
                'themeConfig' => $themeConfig,
                'editorOptions' => $this->getEditorOptions(),
                'snsTypes' => SnsHelper::getTypes(),
                'skinOptions' => $this->getSkinOptions($context->isSuperDomain()),
                'searchSources' => $searchSources,
                'migrationResult' => $migrationResult,
                'activeCode' => '002_001',
                'levels' => array_map(fn($l) => $l->toArray(), $this->levelService->getAll()),
            ]);
    }

    /**
     * 설정 저장 (AJAX)
     *
     * POST /admin/settings/update
     */
    public function update(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $formData = $request->input('formData') ?? [];

        if (empty($formData)) {
            return JsonResponse::error('저장할 데이터가 없습니다.');
        }

        try {
            // 파일 업로드 처리 (logo_pc, logo_mobile, favicon, og_image)
            $uploadedFiles = $this->processImageUploads($domainId);

            // SEO 설정에 업로드된 파일 경로 병합
            if (!empty($uploadedFiles)) {
                $formData['seo'] = array_merge($formData['seo'] ?? [], $uploadedFiles);
            }

            // SNS 채널 데이터 정규화
            if (isset($formData['seo']['sns_channels'])) {
                $formData['seo']['sns_channels'] = SnsHelper::normalizeFormData(
                    $formData['seo']['sns_channels']
                );
            }

            $result = $this->settingsService->saveSettings($domainId, $formData);

            if ($result->isSuccess()) {
                return JsonResponse::success(null, $result->getMessage());
            }

            return JsonResponse::error($result->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error('설정 저장 중 오류가 발생했습니다: ' . $e->getMessage());
        }
    }

    /**
     * 이미지 파일 업로드 처리
     *
     * @return array 업로드된 파일 경로 배열 ['logo_pc' => '/storage/...', ...]
     */
    private function processImageUploads(int $domainId): array
    {
        $result = [];

        // 업로드할 이미지 필드 목록
        $imageFields = [
            'logo_pc' => ['allowed' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'max_size' => 2 * 1024 * 1024],
            'logo_mobile' => ['allowed' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'max_size' => 2 * 1024 * 1024],
            'favicon' => ['allowed' => ['ico', 'png'], 'max_size' => 512 * 1024],
            'app_icon' => ['allowed' => ['png'], 'max_size' => 1 * 1024 * 1024],
            'og_image' => ['allowed' => ['jpg', 'jpeg', 'png', 'webp'], 'max_size' => 5 * 1024 * 1024],
        ];

        foreach ($imageFields as $field => $options) {
            $file = UploadedFile::fromGlobalNested('fileData', $field);

            // fromGlobalNested는 배열을 반환하므로 첫 번째 파일만 처리
            if (!empty($file) && is_array($file)) {
                $file = $file[0];
            }

            if ($file instanceof UploadedFile && $file->isValid()) {
                $uploadResult = $this->fileUploader->upload($file, $domainId, [
                    'subdirectory' => 'site',
                    'allowed_extensions' => $options['allowed'],
                    'max_size' => $options['max_size'],
                ]);

                if ($uploadResult->isSuccess()) {
                    // 웹 접근 가능한 URL 경로 저장
                    $result[$field] = '/storage/' . $uploadResult->getRelativePath() . '/' . $uploadResult->getStoredName();
                }
            }
        }

        return $result;
    }

    // =========================================================================
    // Helper 메서드
    // =========================================================================

    /**
     * 스킨 옵션 목록 (디렉토리 스캔)
     *
     * @param bool $includeSuperOnly SUPER 도메인만 참 — `super_` 스킨 관례
     *                               (ThemeSkinPolicy::isSuperOnly)
     */
    private function getSkinOptions(bool $includeSuperOnly = false): array
    {
        $options = [
            'admin' => DirectoryHelper::getSubdirectories('views/Admin/frame'),
            'frame' => DirectoryHelper::getSubdirectories('views/Front/frame'),
            'index' => DirectoryHelper::getSubdirectories('views/Front/Index'),
            'member' => DirectoryHelper::getSubdirectories('views/Front/Member'),
            'auth' => DirectoryHelper::getSubdirectories('views/Front/Auth'),
            'mypage' => DirectoryHelper::getSubdirectories('views/Front/Mypage'),
            'policy' => DirectoryHelper::getSubdirectories('views/Front/Policy'),
            'search' => DirectoryHelper::getSubdirectories('views/Front/Search'),
        ];

        foreach ($options as $component => $skins) {
            $options[$component] = array_values(array_filter(
                $skins,
                static fn (mixed $skin): bool => ThemeSkinPolicy::componentExists($component, $skin)
                    && ($includeSuperOnly || !ThemeSkinPolicy::isSuperOnly($skin))
            ));
        }

        return $options;
    }

    /**
     * 에디터 옵션 목록 (디렉토리 스캔)
     *
     * 런타임 기본값(mublo-editor)과 일치하도록 mublo-editor 를 최상단에,
     * textarea 는 마지막 폴백으로 둔다.
     */
    private function getEditorOptions(): array
    {
        $editors = DirectoryHelper::getSubdirectories('public/assets/lib/editor');

        $options = [];
        if (in_array('mublo-editor', $editors, true)) {
            $options['mublo-editor'] = 'mublo-editor';
        }
        foreach ($editors as $editor) {
            if ($editor !== 'mublo-editor') {
                $options[$editor] = $editor;
            }
        }

        // textarea는 항상 포함 (HTML 미지원 폴백)
        $options['textarea'] = '기본 텍스트 (HTML 미지원)';

        return $options;
    }
}
