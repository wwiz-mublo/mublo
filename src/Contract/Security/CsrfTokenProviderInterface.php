<?php
declare(strict_types=1);

namespace Mublo\Contract\Security;

/** 폼 렌더링에 필요한 현재 CSRF 토큰의 안정적인 읽기 계약. */
interface CsrfTokenProviderInterface
{
    public function getToken(): string;
}
