<?php
declare(strict_types=1);

namespace Mublo\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Mypage\MypageMenuBuilder;
use Mublo\Service\Mypage\MypageSectionRegistry;

/**
 * 마이페이지 섹션 제네릭 컨트롤러
 *
 * GET /mypage/{provider}/{section}
 *
 * 레지스트리에서 (provider, section) 섹션을 찾아, 코어 셸(액자) 안에 패키지가
 * 등록한 콘텐츠 partial(그림)을 데이터와 함께 렌더한다. 코어는 섹션 내용을 모른다.
 * 섹션 미등록(패키지 비활성/오타) 시 마이페이지 홈으로 리다이렉트.
 */
class MypageSectionController
{
    public function __construct(
        private AuthService           $authService,
        private MypageMenuBuilder     $menuBuilder,
        private MypageSectionRegistry $registry,
    ) {}

    public function view(Request $request, Context $context, array $params): ViewResponse|RedirectResponse
    {
        $provider   = (string) ($params['provider'] ?? '');
        $sectionKey = (string) ($params['section'] ?? '');

        $section = $this->registry->getSection($provider, $sectionKey);
        if ($section === null) {
            return RedirectResponse::to('/mypage');
        }

        $user     = $this->authService->user();
        $domainId = $context->getDomainId();
        $page     = max(1, (int) $request->query('page', 1));

        $data = ($section['dataProvider'])((int) $user['member_id'], $domainId, $page, 15);

        $currentUrl = '/mypage/' . $provider . '/' . $sectionKey;

        return ViewResponse::view('mypage/Section')
            ->withData(array_merge([
                'user'         => $user,
                'mypageMenus'  => $this->menuBuilder->buildMenus($currentUrl, $domainId),
                'sectionLabel' => $section['label'],
                'partialPath'  => $section['viewPath'],
            ], is_array($data) ? $data : []));
    }

    /**
     * GET /mypage/{provider} — 패키지 마이페이지 허브(요약).
     *
     * 패키지당 1개 허브를 코어 셸 안에 렌더한다. 미등록(패키지 비활성/오타) 시 마이페이지 홈으로.
     */
    public function hub(Request $request, Context $context, array $params): ViewResponse|RedirectResponse
    {
        $provider = (string) ($params['provider'] ?? '');

        $hub = $this->registry->getHub($provider);
        if ($hub === null) {
            return RedirectResponse::to('/mypage');
        }

        $user     = $this->authService->user();
        $domainId = $context->getDomainId();

        $data = ($hub['dataProvider'])((int) $user['member_id'], $domainId);

        $currentUrl = '/mypage/' . $provider;

        // viewPath가 콜백이면 렌더 시점에 domainId로 해석(패키지 스킨 지원). 문자열이면 그대로.
        $partialPath = is_callable($hub['viewPath'])
            ? ($hub['viewPath'])($domainId)
            : $hub['viewPath'];

        return ViewResponse::view('mypage/Section')
            ->withData(array_merge([
                'user'         => $user,
                'mypageMenus'  => $this->menuBuilder->buildMenus($currentUrl, $domainId),
                'sectionLabel' => $hub['label'],
                'partialPath'  => $partialPath,
            ], is_array($data) ? $data : []));
    }
}
