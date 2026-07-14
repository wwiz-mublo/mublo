<?php
namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Service\Extension\ExtensionService;
use Mublo\Service\Extension\ExtensionInstaller;
use Mublo\Service\Auth\AuthService;
use Mublo\Infrastructure\Storage\UploadPolicy;

/**
 * Admin ExtensionsController
 *
 * 플러그인/패키지 확장 기능 관리 컨트롤러
 */
class ExtensionsController
{
    /** 업로드 zip 최대 크기 (bytes) */
    private const MAX_UPLOAD_SIZE = 50 * 1024 * 1024;

    private ExtensionService $extensionService;
    private DependencyContainer $container;
    private ?AuthService $authService;
    private ?ExtensionInstaller $installer;

    public function __construct(
        ExtensionService $extensionService,
        DependencyContainer $container,
        ?AuthService $authService = null,
        ?ExtensionInstaller $installer = null
    ) {
        $this->extensionService = $extensionService;
        $this->container = $container;
        $this->authService = $authService;
        $this->installer = $installer;
    }

    /**
     * 확장 기능 관리 페이지
     *
     * GET /admin/extensions
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        // 플러그인/패키지 목록 (활성화 상태 포함)
        $extensions = $this->extensionService->getExtensionsWithManifests($domainId);

        return ViewResponse::view('extensions/index')
            ->withData([
                'pageTitle' => '확장 기능',
                'plugins' => $extensions['plugins'] ?? [],
                'packages' => $extensions['packages'] ?? [],
                'isSuper' => $this->authService?->isSuper() ?? false,
            ]);
    }

    /**
     * 확장 기능 저장 (AJAX)
     *
     * POST /admin/extensions/update
     */
    public function update(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $formData = $request->input('formData') ?? [];

        // formData가 비어있어도 됨 (모든 확장 기능 비활성화 가능)
        try {
            $extensionConfig = [
                'plugins' => $formData['plugins'] ?? [],
                'packages' => $formData['packages'] ?? [],
            ];

            // container + context 전달 → install/uninstall 라이프사이클 자동 실행
            $result = $this->extensionService->saveExtensionConfig(
                $domainId,
                $extensionConfig,
                $this->container,
                $context
            );

            if ($result->isSuccess()) {
                return JsonResponse::success($result->getMessage());
            }

            return JsonResponse::error($result->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error('확장 기능 저장 중 오류가 발생했습니다: ' . $e->getMessage());
        }
    }

    /**
     * 확장 zip 업로드 설치 (AJAX)
     *
     * POST /admin/extensions/upload
     *
     * 업로드된 파일이 실제 zip 인지까지만 여기서 확인하고,
     * zip 내부 구조·manifest·호환성 검증은 ExtensionInstaller 가 담당한다.
     * 코드 설치는 사이트 전체에 영향을 주므로 super 관리자 전용이다.
     */
    public function upload(array $params, Context $context): JsonResponse
    {
        if (!($this->authService?->isSuper() ?? false)) {
            return JsonResponse::forbidden('확장 업로드는 최고 관리자만 할 수 있습니다.');
        }

        if ($this->installer === null) {
            return JsonResponse::error('설치 서비스를 사용할 수 없습니다.', null, 500);
        }

        $request = $context->getRequest();

        $type = (string) ($request->input('extension_type') ?? '');
        if (!in_array($type, ['plugin', 'package'], true)) {
            return JsonResponse::error('확장 종류를 선택하세요.');
        }

        if (!$request->hasFile('extension_zip')) {
            return JsonResponse::error('업로드할 zip 파일을 선택하세요.');
        }

        $file = $request->getRawFile('extension_zip');
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            return JsonResponse::error('단일 파일만 업로드할 수 있습니다.');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return JsonResponse::error('파일 업로드에 실패했습니다. (error ' . $error . ')');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_SIZE) {
            $maxMb = round(self::MAX_UPLOAD_SIZE / 1024 / 1024);
            return JsonResponse::error("파일 크기가 허용 범위를 초과했습니다. (최대 {$maxMb}MB)");
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return JsonResponse::error('업로드 파일을 찾을 수 없습니다.');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension !== 'zip') {
            return JsonResponse::error('zip 파일만 업로드할 수 있습니다.');
        }

        if (!class_exists('finfo')) {
            return JsonResponse::error('서버에 fileinfo 확장이 필요합니다.', null, 500);
        }

        // 확장자-실제 MIME 일치는 다른 업로드 경로와 동일하게 코어 UploadPolicy 가 판정한다
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (!UploadPolicy::matches('zip', $mime)) {
            return JsonResponse::error('zip 파일이 아닙니다. (감지된 형식: ' . $mime . ')');
        }

        $result = $this->installer->installFromZip($tmpName, $type);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }
}
