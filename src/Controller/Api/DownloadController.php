<?php
declare(strict_types=1);

namespace Mublo\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Storage\SecureFileAccessEvent;
use Mublo\Core\Event\Storage\SecureFileDownloadedEvent;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\FileResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Service\Auth\AuthService;

/**
 * DownloadController
 *
 * 보안 파일 다운로드 컨트롤러
 *
 * 토큰 검증 → 권한 확인 → 파일 스트리밍
 * 비즈니스 로직은 이벤트로 위임, 컨트롤러는 오케스트레이션만.
 *
 * GET /download/{token}
 */
class DownloadController
{
    public function __construct(
        private SecureFileService $secureFileService,
        private AuthService $authService,
        private ?EventDispatcher $eventDispatcher = null,
        private ?Logger $logger = null,
    ) {}

    /**
     * 보안 파일 다운로드
     * GET /download/{token}
     */
    public function download(Request $request, Context $context, array $params = []): FileResponse|JsonResponse
    {
        $token = $params['token'] ?? '';
        $clientIp = $request->getClientIp();

        if (empty($token)) {
            return JsonResponse::error('유효하지 않은 요청입니다.', 403);
        }

        // 1. 토큰 검증
        $resolved = $this->secureFileService->resolveToken($token, $clientIp);

        if ($resolved === null) {
            $this->logger?->warning('[SecureFile] 토큰 검증 실패', [
                'ip'    => $clientIp,
                'token' => substr($token, 0, 20) . '...',
            ]);
            return JsonResponse::error('유효하지 않거나 만료된 링크입니다.', 403);
        }

        $filePath    = $resolved['path'];
        $domainId    = $resolved['domainId'];
        $category    = $resolved['category'];
        $entityId    = $resolved['entityId'];
        $disposition = $resolved['disposition'];
        $filename    = $resolved['filename'];

        // 2. 파일 존재 확인
        if (!file_exists($filePath)) {
            $this->logger?->warning('[SecureFile] 파일 없음', [
                'path'     => $resolved['path'],
                'domainId' => $domainId,
            ]);
            return JsonResponse::error('파일을 찾을 수 없습니다.', 404);
        }

        // 3. 도메인 검증
        if ($domainId !== $context->getDomainId()) {
            $this->logger?->warning('[SecureFile] 도메인 불일치', [
                'tokenDomain'  => $domainId,
                'activeDomain' => $context->getDomainId(),
                'ip'           => $clientIp,
            ]);
            return JsonResponse::error('접근 권한이 없습니다.', 403);
        }

        // 4. 권한 검증
        if (!$this->checkAccess($category, $entityId, $domainId, $resolved, $context, $clientIp)) {
            return JsonResponse::error('접근 권한이 없습니다.', 403);
        }

        // 5. 다운로드 이벤트 발행
        $memberId = $this->authService->user()['member_id'] ?? null;

        $this->eventDispatcher?->dispatch(new SecureFileDownloadedEvent(
            $domainId,
            $category,
            $entityId,
            $resolved['path'],
            $memberId ? (int) $memberId : null,
            $clientIp,
        ));

        $this->logger?->info('[SecureFile] 다운로드', [
            'memberId' => $memberId,
            'category' => $category,
            'entityId' => $entityId,
            'ip'       => $clientIp,
        ]);

        // 6. 파일 응답
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $downloadFilename = $filename ?: basename($filePath);

        // UTF-8 파일명 처리
        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $downloadFilename);
        $encodedName = rawurlencode($downloadFilename);

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) filesize($filePath),
            'Content-Disposition' => "{$disposition}; filename=\"{$asciiName}\"; filename*=UTF-8''{$encodedName}",
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return new FileResponse($filePath, 200, $headers);
    }

    /**
     * 카테고리별 권한 검증
     */
    private function checkAccess(
        string $category,
        string $entityId,
        int $domainId,
        array $resolved,
        Context $context,
        string $clientIp,
    ): bool {
        // Core 처리 카테고리
        switch ($category) {
            case 'member-fields':
                return $this->checkMemberFieldsAccess($entityId);

            case 'autoform':
                return $this->checkAdminAccess($entityId, $category, $clientIp);
        }

        // Core 미처리 카테고리 → 이벤트로 위임
        if ($this->eventDispatcher) {
            $event = new SecureFileAccessEvent(
                $domainId,
                $category,
                $entityId,
                $resolved['path'],
                $context,
            );
            $this->eventDispatcher->dispatch($event);

            if ($event->isGranted()) {
                return true;
            }
        }

        // 아무도 grant 안 했으면 → 관리자만 허용 (안전 기본값)
        if ($this->authService->isAdmin()) {
            return true;
        }

        $this->logger?->warning('[SecureFile] 권한 거부', [
            'memberId' => $this->authService->user()['member_id'] ?? null,
            'category' => $category,
            'entityId' => $entityId,
            'ip'       => $clientIp,
        ]);

        return false;
    }

    /**
     * member-fields: 본인 또는 관리자
     */
    private function checkMemberFieldsAccess(string $entityId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }

        $user = $this->authService->user();
        if ($user && (string) $user['member_id'] === $entityId) {
            return true;
        }

        return false;
    }

    /**
     * 관리자 전용 카테고리
     */
    private function checkAdminAccess(string $entityId, string $category, string $clientIp): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }

        $this->logger?->warning('[SecureFile] 관리자 전용 접근 시도', [
            'memberId' => $this->authService->user()['member_id'] ?? null,
            'category' => $category,
            'entityId' => $entityId,
            'ip'       => $clientIp,
        ]);

        return false;
    }
}
