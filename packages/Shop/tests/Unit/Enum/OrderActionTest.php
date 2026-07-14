<?php
/**
 * packages/Shop/tests/Unit/Enum/OrderActionTest.php
 *
 * OrderAction Enum 단위 테스트
 *
 * 검증 항목:
 * - 11개 시스템/커스텀 상태값 존재
 * - defaultLabel(), defaultDescription() 반환값
 * - isSystem(), isActive(), isCancellable(), isShipped() 판단 로직
 * - defaultStates() FSM 구조 검증
 * - allowedTransitions() (deprecated, 하위호환 검증)
 * - options() 드롭다운 목록 (CUSTOM 제외)
 */

namespace Tests\Shop\Unit\Enum;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Enum\OrderAction;

class OrderActionTest extends TestCase
{
    // =========================================================
    // 기본 케이스 존재 검증
    // =========================================================

    public function testAllSystemCasesExist(): void
    {
        $expected = [
            'received', 'paid', 'preparing', 'shipping', 'delivered',
            'confirmed', 'cancel_requested', 'cancelled',
            'return_requested', 'returned', 'custom',
        ];

        $actual = array_map(fn(OrderAction $a) => $a->value, OrderAction::cases());

        foreach ($expected as $value) {
            $this->assertContains($value, $actual, "OrderAction에 '{$value}' 케이스가 없습니다.");
        }
    }

    public function testFromValueWorks(): void
    {
        $this->assertSame(OrderAction::RECEIVED, OrderAction::from('received'));
        $this->assertSame(OrderAction::PAID, OrderAction::from('paid'));
        $this->assertSame(OrderAction::CONFIRMED, OrderAction::from('confirmed'));
        $this->assertSame(OrderAction::CANCELLED, OrderAction::from('cancelled'));
    }

    // =========================================================
    // defaultLabel()
    // =========================================================

    public function testDefaultLabelReturnsKoreanString(): void
    {
        $this->assertSame('주문접수', OrderAction::RECEIVED->defaultLabel());
        $this->assertSame('결제완료', OrderAction::PAID->defaultLabel());
        $this->assertSame('배송준비', OrderAction::PREPARING->defaultLabel());
        $this->assertSame('배송중', OrderAction::SHIPPING->defaultLabel());
        $this->assertSame('배송완료', OrderAction::DELIVERED->defaultLabel());
        $this->assertSame('구매확정', OrderAction::CONFIRMED->defaultLabel());
        $this->assertSame('취소요청', OrderAction::CANCEL_REQUESTED->defaultLabel());
        $this->assertSame('주문취소', OrderAction::CANCELLED->defaultLabel());
        $this->assertSame('반품요청', OrderAction::RETURN_REQUESTED->defaultLabel());
        $this->assertSame('반품완료', OrderAction::RETURNED->defaultLabel());
    }

    // =========================================================
    // isSystem() — CUSTOM 제외한 모든 케이스가 시스템
    // =========================================================

    public function testIsSystemReturnsTrueForAllExceptCustom(): void
    {
        $systemCases = [
            OrderAction::RECEIVED, OrderAction::PAID, OrderAction::PREPARING,
            OrderAction::SHIPPING, OrderAction::DELIVERED, OrderAction::CONFIRMED,
            OrderAction::CANCEL_REQUESTED, OrderAction::CANCELLED,
            OrderAction::RETURN_REQUESTED, OrderAction::RETURNED,
        ];

        foreach ($systemCases as $action) {
            $this->assertTrue($action->isSystem(), "{$action->value}는 시스템 상태여야 합니다.");
        }
    }

    public function testIsSystemReturnsFalseForCustom(): void
    {
        $this->assertFalse(OrderAction::CUSTOM->isSystem());
    }

    public function testSystemCasesExcludesCustom(): void
    {
        $systemCases = OrderAction::systemCases();

        $this->assertNotContains(OrderAction::CUSTOM, $systemCases);
        $this->assertCount(10, $systemCases);
    }

    // =========================================================
    // ATTEMPTING — 내부 전용 과도상태 (그래프 밖)
    // =========================================================

    public function testAttemptingIsInternal(): void
    {
        $this->assertTrue(OrderAction::ATTEMPTING->isInternal());
        $this->assertFalse(OrderAction::RECEIVED->isInternal());
        $this->assertFalse(OrderAction::PAID->isInternal());
        $this->assertFalse(OrderAction::CUSTOM->isInternal());
    }

    public function testSystemCasesExcludesAttempting(): void
    {
        // 내부 전용 상태는 관리자 그래프/상태관리 UI에 노출되면 안 되므로 systemCases에서 제외
        $this->assertNotContains(OrderAction::ATTEMPTING, OrderAction::systemCases());
    }

    public function testAttemptingIsNotActive(): void
    {
        // 결제 미완료 과도상태는 활성 주문이 아니다
        $this->assertFalse(OrderAction::ATTEMPTING->isActive());
    }

    public function testDefaultStatesExcludesAttempting(): void
    {
        // attempting은 관리자 설정 FSM 그래프(order_states)에 포함되지 않는다
        $ids = array_column(OrderAction::defaultStates(), 'id');
        $this->assertNotContains('attempting', $ids);
    }

    public function testPaidOrBeyondValues(): void
    {
        $this->assertSame(
            ['paid', 'preparing', 'shipping', 'delivered', 'confirmed'],
            OrderAction::paidOrBeyondValues()
        );
        // 미결제/입금대기/취소는 매출 인식에서 제외
        $this->assertNotContains('attempting', OrderAction::paidOrBeyondValues());
        $this->assertNotContains('received', OrderAction::paidOrBeyondValues());
    }

    // =========================================================
    // isActive() — 취소/반품 완료 아닌 상태
    // =========================================================

    public function testIsActiveReturnsTrueForActiveStates(): void
    {
        $activeStates = [
            OrderAction::RECEIVED, OrderAction::PAID, OrderAction::PREPARING,
            OrderAction::SHIPPING, OrderAction::DELIVERED, OrderAction::CONFIRMED,
            OrderAction::CANCEL_REQUESTED, OrderAction::RETURN_REQUESTED,
        ];

        foreach ($activeStates as $action) {
            $this->assertTrue($action->isActive(), "{$action->value}는 활성 상태여야 합니다.");
        }
    }

    public function testIsActiveReturnsFalseForTerminalCancelledAndReturned(): void
    {
        $this->assertFalse(OrderAction::CANCELLED->isActive());
        $this->assertFalse(OrderAction::RETURNED->isActive());
    }

    // =========================================================
    // isCancellable() — 취소 가능 상태 (접수/결제완료)
    // =========================================================

    public function testIsCancellableOnlyForReceiptAndPaid(): void
    {
        $this->assertTrue(OrderAction::RECEIVED->isCancellable());
        $this->assertTrue(OrderAction::PAID->isCancellable());

        $nonCancellable = [
            OrderAction::PREPARING, OrderAction::SHIPPING, OrderAction::DELIVERED,
            OrderAction::CONFIRMED, OrderAction::CANCEL_REQUESTED, OrderAction::CANCELLED,
        ];

        foreach ($nonCancellable as $action) {
            $this->assertFalse($action->isCancellable(), "{$action->value}는 취소 불가여야 합니다.");
        }
    }

    // =========================================================
    // isShipped() — 배송 중/후 상태
    // =========================================================

    public function testIsShippedReturnsTrueAfterShipping(): void
    {
        $shippedStates = [OrderAction::SHIPPING, OrderAction::DELIVERED, OrderAction::CONFIRMED];

        foreach ($shippedStates as $action) {
            $this->assertTrue($action->isShipped(), "{$action->value}는 배송 상태여야 합니다.");
        }
    }

    public function testIsShippedReturnsFalseBeforeShipping(): void
    {
        $notShipped = [OrderAction::RECEIVED, OrderAction::PAID, OrderAction::PREPARING];

        foreach ($notShipped as $action) {
            $this->assertFalse($action->isShipped(), "{$action->value}는 배송 전 상태여야 합니다.");
        }
    }

    // =========================================================
    // isTerminal() (deprecated) — 종료 상태
    // =========================================================

    public function testIsTerminalForTerminalStates(): void
    {
        $this->assertTrue(OrderAction::CONFIRMED->isTerminal());
        $this->assertTrue(OrderAction::CANCELLED->isTerminal());
        $this->assertTrue(OrderAction::RETURNED->isTerminal());
    }

    public function testIsTerminalReturnsFalseForNonTerminal(): void
    {
        $this->assertFalse(OrderAction::RECEIVED->isTerminal());
        $this->assertFalse(OrderAction::PAID->isTerminal());
        $this->assertFalse(OrderAction::PREPARING->isTerminal());
    }

    // =========================================================
    // defaultStates() — FSM 초기값 구조 검증
    // =========================================================

    public function testDefaultStatesReturns10States(): void
    {
        $states = OrderAction::defaultStates();

        $this->assertCount(10, $states, 'defaultStates()는 10개 상태를 반환해야 합니다.');
    }

    public function testDefaultStatesHaveRequiredKeys(): void
    {
        $states = OrderAction::defaultStates();
        $requiredKeys = ['id', 'label', 'description', 'action', 'to', 'terminal', 'system', 'sort_order'];

        foreach ($states as $state) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $state, "상태 '{$state['id']}'에 '{$key}' 키가 없습니다.");
            }
        }
    }

    public function testDefaultStatesTerminalFlagsAreCorrect(): void
    {
        $states = OrderAction::defaultStates();
        $terminalIds = ['confirmed', 'cancelled', 'returned'];

        foreach ($states as $state) {
            $shouldBeTerminal = in_array($state['id'], $terminalIds, true);
            $this->assertSame(
                $shouldBeTerminal,
                $state['terminal'],
                "'{$state['id']}' terminal 플래그가 올바르지 않습니다."
            );
        }
    }

    public function testDefaultStatesTransitionsFromReceipt(): void
    {
        $states = OrderAction::defaultStates();
        $received = array_filter($states, fn($s) => $s['id'] === 'received');
        $received = reset($received);

        $this->assertContains('paid', $received['to']);
        $this->assertContains('cancel_requested', $received['to']);
        $this->assertContains('cancelled', $received['to']);
    }

    public function testDefaultStatesConfirmedHasNoTransitions(): void
    {
        $states = OrderAction::defaultStates();
        $confirmed = array_filter($states, fn($s) => $s['id'] === 'confirmed');
        $confirmed = reset($confirmed);

        $this->assertEmpty($confirmed['to']);
        $this->assertTrue($confirmed['terminal']);
    }

    // =========================================================
    // options() — 드롭다운 목록 (CUSTOM 제외)
    // =========================================================

    public function testOptionsExcludesCustom(): void
    {
        $options = OrderAction::options();

        $this->assertArrayNotHasKey('custom', $options);
    }

    public function testOptionsContainsAllSystemValues(): void
    {
        $options = OrderAction::options();

        $systemValues = ['received', 'paid', 'preparing', 'shipping', 'delivered',
                         'confirmed', 'cancel_requested', 'cancelled', 'return_requested', 'returned'];

        foreach ($systemValues as $value) {
            $this->assertArrayHasKey($value, $options, "options()에 '{$value}'가 없습니다.");
        }
    }

    // =========================================================
    // allowedTransitions() (deprecated)
    // =========================================================

    public function testAllowedTransitionsFromReceipt(): void
    {
        $transitions = OrderAction::RECEIVED->allowedTransitions();

        $this->assertContains(OrderAction::PAID, $transitions);
        $this->assertContains(OrderAction::CANCEL_REQUESTED, $transitions);
        $this->assertContains(OrderAction::CANCELLED, $transitions);
    }

    public function testAllowedTransitionsFromConfirmedIsEmpty(): void
    {
        $this->assertEmpty(OrderAction::CONFIRMED->allowedTransitions());
    }

    public function testAllowedTransitionsFromCustomIsEmpty(): void
    {
        $this->assertEmpty(OrderAction::CUSTOM->allowedTransitions());
    }
}
