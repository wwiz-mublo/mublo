<?php

namespace Mublo\Core\Report\Security;

use Mublo\Core\Report\Contract\PermissionGateInterface;
use Mublo\Core\Report\Exception\ReportPermissionDeniedException;
use Mublo\Service\Admin\AdminPermissionService;
use Mublo\Service\Auth\AuthService;

class AdminPermissionGate implements PermissionGateInterface
{
    private AuthService $auth;
    private AdminPermissionService $permissionService;

    public function __construct(
        AuthService $auth,
        AdminPermissionService $permissionService
    ) {
        $this->auth = $auth;
        $this->permissionService = $permissionService;
    }

    public function assertDownloadAllowed(int $domainId, string $menuCode): void
    {
        $user = $this->auth->user();

        if (!$user || !$this->auth->isAdmin()) {
            throw new ReportPermissionDeniedException('관리자 인증이 필요합니다.');
        }

        if ($this->auth->isSuper()) {
            return;
        }

        if ($menuCode === '') {
            throw new ReportPermissionDeniedException('menuCode가 필요합니다.');
        }

        $isDenied = $this->permissionService->isDenied(
            $domainId,
            (int) ($user['level_value'] ?? 0),
            $menuCode,
            'download'
        );

        if ($isDenied) {
            throw new ReportPermissionDeniedException('리포트 다운로드 권한이 없습니다.');
        }
    }
}

