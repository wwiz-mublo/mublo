<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\Auth\LoginFormRenderingEvent;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Core\Rendering\FrontViewRuntime;
use Mublo\Core\Rendering\ViewContext;
use Mublo\Packages\Shop\Service\ShopConfigService;

/**
 * Shop 로그인 폼 Subscriber
 *
 * 체크아웃에서 로그인 페이지로 넘어온 경우,
 * "비회원으로 주문하기" 버튼을 로그인 폼에 주입한다.
 *
 * 조건: active_package=shop AND shop.is_checkout=true
 */
class LoginFormSubscriber implements EventSubscriberInterface
{
    private const VIEW_BASE_PATH = '/Shop/views/Front';

    public function __construct(
        private FrontViewRuntime $viewRuntime,
        private ShopConfigService $shopConfigService,
        private AssetManager $assetManager
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFormRenderingEvent::class => 'onLoginFormRendering',
        ];
    }

    public function onLoginFormRendering(LoginFormRenderingEvent $event): void
    {
        $context = $event->getContext();

        // Shop 패키지 활성 영역에서만 주입
        if ($context->getAttribute('active_package') !== 'shop') {
            return;
        }

        // 체크아웃 흐름 → "비회원으로 주문하기", 주문조회 흐름 → "비회원 주문 조회"
        if ($context->getAttribute('shop.is_checkout')) {
            $event->addHtml($this->renderView('Ui/GuestOrderButton', $context), 100);
        } elseif ($context->getAttribute('shop.is_order_lookup')) {
            $event->addHtml($this->renderView('Ui/GuestOrderLookupButton', $context), 100);
        }
    }

    /**
     * 패키지 프론트 뷰 렌더링
     *
     * 요청값은 여기서 검증한 뒤 표시 전용 redirectUrl만 뷰에 전달한다.
     *
     * 이 이벤트는 두 곳에서 발행되고 렌더 상태가 서로 다르다.
     *  - 로그인 블록(OutloginRenderer): 프론트 렌더 도중 → FrontViewRuntime 초기화됨
     *  - 로그인 페이지(AuthController): 컨트롤러 단계 → 아직 초기화 전
     * 초기화 전에 FrontViewRuntime::render 를 부르면 LogicException 이 나고, 디스패처가
     * 그것을 삼켜서 버튼만 조용히 사라진다. 그래서 상태를 확인하고 갈래를 나눈다.
     */
    private function renderView(string $viewName, Context $context): string
    {
        // 스킨 해석(기능별) — 예: 'Ui/GuestOrderButton' → Front/Ui/{skin}/GuestOrderButton.php (basic 폴백)
        $viewFile = $this->shopConfigService->frontView($context->getDomainId() ?? 1, $viewName) . '.php';
        $redirectUrl = '/shop/checkout';
        $candidate = (string) $context->getRequest()->query('redirect', '');
        if ($candidate !== '' && str_starts_with($candidate, '/shop/checkout')) {
            $redirectUrl = $candidate;
        }

        if ($this->viewRuntime->isInitialized()) {
            return $this->viewRuntime->render($viewFile, ['redirectUrl' => $redirectUrl]);
        }

        return $this->renderOutsidePipeline($viewFile, ['redirectUrl' => $redirectUrl]);
    }

    /**
     * 프론트 렌더 파이프라인 밖에서의 뷰 렌더링.
     *
     * 코어가 블록 include 에 쓰는 것과 같은 방식이다(IncludeRenderer) — 뷰어 상태가 없는
     * 빈 mublo 를 넣어 프론트 뷰 데이터 계약의 키 구조는 그대로 유지한다.
     * assets 헬퍼도 함께 넘긴다. 뷰가 자기 CSS 를 addCss 로 요구하기 때문이다.
     *
     * @param array<string, mixed> $data
     */
    private function renderOutsidePipeline(string $viewFile, array $data): string
    {
        $viewContext = new ViewContext('front');
        $viewContext->setHelper('assets', $this->assetManager);

        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $viewContext->render($viewFile, ['mublo' => FrontViewRuntime::emptyMublo(true)] + $data);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
