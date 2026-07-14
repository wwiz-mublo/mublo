<?php
namespace Mublo\Core\Block\Renderer;

use Mublo\Entity\Block\BlockColumn;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\Auth\LoginFormRenderingEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Service\Auth\AuthService;

/**
 * OutloginRenderer
 *
 * 로그인 위젯 콘텐츠 렌더러
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumn 엔티티
 * - $isLoggedIn: 로그인 상태
 * - $member: 회원 정보 (로그인 시)
 * - $loginFormExtras: 로그인 폼 확장 HTML 배열 (소셜 로그인 등, 이벤트 주입)
 * - $deviceClass: PC/모바일 출력 제어 CSS 클래스
 */
class OutloginRenderer implements RendererInterface
{
    use SkinRendererTrait;

    private ?AuthService $authService;
    private ?EventDispatcher $eventDispatcher;
    private ?Context $context;

    public function __construct(
        ?AuthService $authService = null,
        ?EventDispatcher $eventDispatcher = null,
        ?Context $context = null
    ) {
        $this->authService = $authService;
        $this->eventDispatcher = $eventDispatcher;
        $this->context = $context;
    }

    /**
     * 스킨 타입 반환
     */
    protected function getSkinType(): string
    {
        return 'outlogin';
    }

    /**
     * {@inheritdoc}
     */
    public function render(BlockColumn $column): string
    {
        $config = $column->getContentConfig() ?? [];
        $skin = $column->getContentSkin() ?: 'basic';
        $showPc = $config['show_pc'] ?? true;
        $showMobile = $config['show_mobile'] ?? true;

        // PC/모바일 출력 제어 CSS 클래스
        $deviceClass = '';
        if (!$showPc && !$showMobile) {
            $deviceClass = 'd-none';
        } elseif (!$showPc) {
            $deviceClass = 'd-block d-md-none';
        } elseif (!$showMobile) {
            $deviceClass = 'd-none d-md-block';
        }

        $isLoggedIn = $this->authService?->check() ?? false;
        $member = $isLoggedIn ? $this->getMemberInfo() : null;

        // 로그인 폼 확장 이벤트 (소셜 로그인 등 Plugin/Package 주입)
        $loginFormExtras = [];
        if (!$isLoggedIn && $this->eventDispatcher && $this->context) {
            $event = new LoginFormRenderingEvent($this->context);
            $this->eventDispatcher->dispatch($event);
            $loginFormExtras = $event->getHtmlSorted();
        }

        return $this->renderSkin($column, $skin, [
            'isLoggedIn' => $isLoggedIn,
            'member' => $member,
            'loginFormExtras' => $loginFormExtras,
            'deviceClass' => $deviceClass,
        ]);
    }

    /**
     * 회원 정보 반환
     */
    private function getMemberInfo(): array
    {
        $user = $this->authService->user();

        return [
            'id' => $user['member_id'] ?? 0,
            'name' => $user['nickname'] ?? $user['name'] ?? '회원',
            'level_title' => $user['level_title'] ?? '',
        ];
    }
}
