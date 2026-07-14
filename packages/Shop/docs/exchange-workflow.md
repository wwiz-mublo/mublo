# 교환 클레임 운영 가이드

Shop의 교환은 국내 실물 상품의 **동일 상품·동일 옵션가** 교환을 대상으로 한다. 가격이 다른 상품이나 옵션은 반품 후 재주문으로 처리한다.

## 상태 흐름

```text
REQUESTED → ACCEPTED → COLLECTING → COLLECTED → INSPECTING
    ├─ READY_TO_SHIP → RESHIPPING → COMPLETED
    └─ REJECTED → RETURNING → CLOSED

REQUESTED → REFUSED
REQUESTED/ACCEPTED → CANCELLED
```

- 고객은 배송완료 상품의 일부 수량을 신청할 수 있다.
- 승인 시 교환 상품 재고를 예약하고, 취소·검수거절 시 한 번만 복구한다.
- 회수품이 `SALEABLE`로 판정되면 기존 상품 재고를 한 번만 입고한다. 불량·폐기·오배송품은 자동 입고하지 않는다.
- 고객 귀책은 주문 당시 `shipping_breakdown.exchange_cost`를 적용한다. 고객이 요청에 넣은 귀책 값은 신뢰하지 않고 서버가 사유로 판정한다.
- 회수, 교환 재출고, 검수 거절 반송은 `shipment_role`로 구분한다.

## 중복 후처리 방어

교환 FSM은 주문 FSM과 분리된다. 교환 상태 변경은 `shop_order_details.status` 또는 `shop_orders.order_status`를 변경하지 않으며, 주문 상태 Action을 발화하지 않는다. `shop_order_details.return_status`는 화면 조회용 요약 캐시일 뿐 사이드 이펙트의 기준이 아니다.

교환 전용 확장은 `ClaimStatusChangedEvent`를 구독한다. 재고 작업은 Shop 내부 서비스가 소유하므로 외부 구독자가 반복해서는 안 된다. 관리자 **교환 관리**에서 상태별 알림·웹훅 Action을 설정할 수 있으며, 주문용 재고·포인트 Action은 교환 설정에 등록할 수 없다. 실행 원장은 `claimId + newStatus + actionId`를 기준으로 중복을 막고, 실패한 알림·웹훅을 재시도한다.

## 운영 절차

1. 고객이 주문 상세에서 교환 상품·수량·사유를 선택한다.
2. 관리자 **쇼핑몰 > 교환 관리**에서 승인하고 회수 송장을 등록한다.
3. 회수 완료 후 검수 결과를 기록한다. 고객 귀책 비용이 있으면 납부 확인 후 재출고한다.
4. 교환 재출고 송장이 배송완료된 후만 `COMPLETED`로 종결한다.
5. 검수 거절은 고객 반송 송장이 배송완료된 후 `CLOSED`로 종결한다.
