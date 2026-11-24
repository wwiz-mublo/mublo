<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;

/**
 * 호스트 제품이 공통 AiSiteEditor 플러그인에 제공하는 클라이언트 인증 경계.
 *
 * 구현은 서명·nonce·토큰 검증을 끝낸 뒤 현재 요청 도메인에 묶인 identity를
 * 반환하며, 실패하면 SiteEditorAuthenticationException을 던진다.
 */
interface SiteEditorClientAuthenticatorInterface
{
    public function authenticate(Request $request, Context $context): SiteEditorClientIdentity;
}
