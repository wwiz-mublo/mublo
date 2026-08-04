# 에러 처리 컨벤션 (Result vs 예외)

Mublo는 실패를 표현하는 두 가지 수단 — **`Result` 반환값**과 **예외(throw)** — 을 목적에 따라 구분해 사용한다. 계층마다 기준이 다르면 컨트롤러가 두 방식을 섞어 처리해야 하므로, 아래 경계 규칙을 지킨다.

## 핵심 원칙

| 상황 | 처리 방식 | 이유 |
|------|-----------|------|
| **예상 가능한 업무 실패** (검증 실패, 권한 없음, 재고 부족, 중복 요청 등) | `Result::failure(...)` 반환 | 호출자가 정상 흐름에서 사용자에게 사유를 돌려줘야 함 |
| **프로그래밍 오류 / 계약 위반** (필수 의존성 미주입, 잘못된 enum, 있을 수 없는 상태) | 예외 `throw` | 복구 불가. 빠르게 실패시켜 버그를 드러냄 |
| **인프라 장애** (DB 연결 끊김, 파일시스템 오류) | 예외 (하위 계층에서 발생) | 개별 호출자가 처리할 성질이 아님 → 전역 ErrorHandler가 처리 |

## 계층별 기준

### Service (Package / 업무 로직)
사용자에게 결과를 돌려주는 업무 연산은 **`Result`를 반환한다.**

```php
public function placeOrder(array $data, int $domainId): Result
{
    if ($stock < $qty) {
        return Result::failure('재고가 부족합니다.');
    }
    // ...
    return Result::success('주문이 접수되었습니다.', ['order_no' => $orderNo]);
}
```

- Shop/Board 패키지의 서비스는 이 규칙을 따른다.
- 서비스 내부에서 발생한 인프라 예외를 업무 실패로 바꿔야 한다면, 잡아서 `Result::failure`로 변환한다.

### Core 서비스 / 인프라
프레임워크 불변식 위반, 잘못된 설정, 계약 위반은 **예외를 던진다.** (예: `DependencyContainer`의 순환 의존성, `QueryBuilder`의 식별자 검증 실패, 필수 설정 부재.)

### Controller (경계 변환기)
컨트롤러는 두 방식을 **HTTP 응답으로 번역하는 유일한 경계**다.

```php
$result = $this->orderService->placeOrder($data, $domainId);
if ($result->isFailure()) {
    return JsonResponse::error($result->getMessage());
}
return JsonResponse::success($result->getData(), $result->getMessage());
```

- 업무 실패(`Result`)는 컨트롤러가 명시적으로 응답으로 바꾼다.
- 예상치 못한 예외는 컨트롤러가 잡지 않고 전역 ErrorHandler로 흘려보낸다(500 + 로깅). 업무 실패를 예외로 흘리지 말 것.

## 하지 말아야 할 것

- ❌ 업무 실패를 예외로 던지고 컨트롤러에서 try/catch로 사용자 메시지를 만들기 — 정상 흐름을 예외로 다루는 안티패턴.
- ❌ 인프라/계약 위반을 `Result::failure`로 삼켜서 버그를 숨기기.
- ❌ 한 서비스가 같은 메서드에서 어떤 실패는 throw, 어떤 실패는 Result로 섞어 반환하기 — 호출자가 두 경로를 모두 방어해야 함.

## 요약

> **업무 실패는 `Result`, 버그·계약위반·인프라 장애는 예외.** 변환은 컨트롤러 경계에서만.

---

[< 개발자 가이드로](README.md)
