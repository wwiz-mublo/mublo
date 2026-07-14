<?php

namespace Mublo\Core\Event\Rendering;

use Mublo\Core\Event\AbstractEvent;

/**
 * 프레임 템플릿 소스 수집 이벤트
 *
 * 도메인 프레임 편집(header/footer 오버라이드) 템플릿에서 사용할 수 있는
 * 치환 변수·컴포넌트 슬롯을 Plugin/Package가 등록하도록 한다.
 * "액자=코어, 그림=확장" 수집 패턴 (SearchSourceCollectEvent 등과 동일).
 *
 * 규칙 (storage/docs/Mublo_Domain_Frame_Editing_Implementation_Plan.md §3.9):
 * - 확장은 반드시 `확장명.이름` 네임스페이스로 등록한다 (소문자, 예: shop.cart_count).
 *   무접두사 이름은 코어 전용이라 등록이 거부된다. 'company'는 코어 예약 접두사.
 * - resolver/renderer는 템플릿에 실제 사용된 토큰에 대해서만 렌더 시점에 호출된다
 *   (지연 해석 — 등록 자체는 콜백 보관뿐이라 비용이 없다).
 * - 변수 resolver는 순수 문자열을 반환한다 (HTML 이스케이프는 렌더러가 수행).
 *   슬롯 renderer는 완성된 HTML을 반환하며, 코어가 표준 래퍼로 감싼다.
 * - 등록 거부는 예외를 던지지 않고 rejection 목록에 기록된다 —
 *   한 확장의 등록 실수가 다른 확장의 수집을 깨뜨리지 않는다.
 *
 * 구독자 구현 예시:
 *   public function onCollect(FrameTemplateSourceCollectEvent $event): void
 *   {
 *       $event->addVariable('shop.cart_count', '장바구니 담긴 수',
 *           fn() => (string) $this->cartService->count($this->domainId));
 *       $event->addSlot('shop.cart_widget', '미니 장바구니',
 *           fn() => $this->cartWidgetRenderer->render());
 *   }
 */
class FrameTemplateSourceCollectEvent extends AbstractEvent
{
    /**
     * 확장 등록명 형식: `확장명.이름` (둘 다 소문자 시작, 소문자·숫자·언더스코어)
     */
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /**
     * 코어가 예약한 접두사 — 확장이 사용할 수 없다
     */
    private const RESERVED_PREFIXES = ['company', 'core', 'mublo'];

    /** @var array<string, array{label: string, resolver: callable}> */
    private array $variables = [];

    /** @var array<string, array{label: string, renderer: callable}> */
    private array $slots = [];

    /** @var array<array{name: string, kind: string, reason: string}> */
    private array $rejections = [];

    public function __construct(private int $domainId)
    {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /**
     * 치환 변수 등록
     *
     * @param string   $name     `확장명.이름` 형식 (예: 'shop.cart_count')
     * @param string   $label    에디터 변수 팔레트에 표시할 이름
     * @param callable $resolver fn(): string — 렌더 시점에 호출 (지연 해석)
     */
    public function addVariable(string $name, string $label, callable $resolver): void
    {
        // 중복 검사는 변수·슬롯 통합 — 같은 이름이 변수와 슬롯으로 동시에
        // 등록되면 팔레트에 두 번 뜨고 렌더에선 변수가 조용히 이기는 모호함이 생긴다
        if (!$this->validate($name, 'variable', isset($this->variables[$name]) || isset($this->slots[$name]))) {
            return;
        }

        $this->variables[$name] = ['label' => $label, 'resolver' => $resolver];
    }

    /**
     * 컴포넌트 슬롯 등록
     *
     * @param string   $name     `확장명.이름` 형식 (예: 'shop.cart_widget')
     * @param string   $label    에디터 변수 팔레트에 표시할 이름
     * @param callable $renderer fn(): string — 완성된 HTML 반환 (코어가 표준 래퍼로 감쌈)
     */
    public function addSlot(string $name, string $label, callable $renderer): void
    {
        if (!$this->validate($name, 'slot', isset($this->slots[$name]) || isset($this->variables[$name]))) {
            return;
        }

        $this->slots[$name] = ['label' => $label, 'renderer' => $renderer];
    }

    /**
     * @return array<string, array{label: string, resolver: callable}>
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * @return array<string, array{label: string, renderer: callable}>
     */
    public function getSlots(): array
    {
        return $this->slots;
    }

    /**
     * 거부된 등록 목록 (진단·개발 중 확인용)
     *
     * @return array<array{name: string, kind: string, reason: string}>
     */
    public function getRejections(): array
    {
        return $this->rejections;
    }

    private function validate(string $name, string $kind, bool $duplicate): bool
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            $this->rejections[] = [
                'name' => $name,
                'kind' => $kind,
                'reason' => '확장 등록은 `확장명.이름` 형식만 허용됩니다 (소문자·숫자·언더스코어). 무접두사 이름은 코어 전용입니다.',
            ];
            return false;
        }

        $prefix = explode('.', $name, 2)[0];
        if (in_array($prefix, self::RESERVED_PREFIXES, true)) {
            $this->rejections[] = [
                'name' => $name,
                'kind' => $kind,
                'reason' => "'{$prefix}'는 코어 예약 접두사입니다.",
            ];
            return false;
        }

        if ($duplicate) {
            $this->rejections[] = [
                'name' => $name,
                'kind' => $kind,
                'reason' => '이미 등록된 이름입니다 (먼저 등록한 쪽이 유지됩니다).',
            ];
            return false;
        }

        return true;
    }
}
