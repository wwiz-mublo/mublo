# 21. 포인트(Balance) 시스템

포인트는 Mublo에서 확장 간 공유되는 몇 안 되는 상태다. 게시판이 적립하고, 쇼핑몰이 차감하고, 관리자가 수동 조정한 금액이 하나의 잔액으로 합산된다. 이 장의 중심 질문은 하나다 — **여러 확장이 같은 잔액을 만지면서 어떻게 원장이 깨지지 않는가.** 답은 단일 진입점(`BalanceManager::adjust`)과 불변 원장(`balance_logs`)이다.

이 장이 다루는 기능: 관리자 포인트 내역 조회·수동 조정(지급/차감)·대상 회원 검색·무결성 검증(`src/Controller/Admin/PointController.php` — index, adjust, adjustStore, searchMember, verify), 마이페이지 포인트(잔액) 내역 조회(`src/Controller/Front/MypageController.php`의 balance), MemberPoint 플러그인 설정·설치·프론트 내 포인트 내역(`plugins/MemberPoint/routes.php`), Balance 이벤트 2종(`src/Core/Event/Balance/BalanceAdjustingEvent.php`, `BalanceAdjustedEvent.php`).

## 개요 — 3층 역할 분리

```text
[정책층]  Board · Shop · MemberPoint     — 언제, 얼마를, 왜 (각 확장 소유)
              │  adjust() 호출
[원장층]  BalanceManager (Core)          — 원장 기록·잔액 스냅샷·동시성·멱등성
              │  balance_logs / members.point_balance
[화면층]  /admin/point · /mypage/balance · /memberpoint/my — 내역·조정 UI
```

- **원장층** — `src/Service/Balance/BalanceManager.php`. 잔액 조정의 단일 진입점. 금액의 의미(글 작성 보상인지, 주문 결제인지)는 알지 못한다.
- **정책층** — 각 확장이 소유한다. Board는 글/댓글/반응 적립과 열람/다운로드 소비 정책을(`packages/Board/Service/BoardPointService.php`), Shop은 주문 적립·사용·환불을(`packages/Shop/Service/OrderPointService.php` 등), MemberPoint는 회원 생애주기(가입·레벨업) 적립을(`plugins/MemberPoint/Service/MemberPointService.php`) 담당한다.
- **화면층** — 코어 관리자 화면(`src/Controller/Admin/PointController.php`, `views/Admin/Point/Index.php`·`Adjust.php`), 마이페이지 내역(`src/Controller/Front/MypageController.php`의 `balance()`), MemberPoint 프론트 내역(`plugins/MemberPoint/Controller/Front/PointController.php`).

코어의 비책임: 적립 금액·조건·정책 설정 화면은 코어에 없다. Board의 포인트 설정은 Board가(`packages/Board/Controller/Admin/BoardPointController.php`), 회원 가입 적립 설정은 MemberPoint가(`plugins/MemberPoint/Controller/Admin/SettingsController.php`) 스스로 제공한다.

## BalanceManager — 원장층

`src/Service/Balance/BalanceManager.php`의 핵심 원칙(클래스 주석에 명문화):

- **원장 불변성** — `balance_logs`는 INSERT ONLY.
- **원장 = 진실** — Source of Truth는 `balance_logs`의 합, `members.point_balance`는 스냅샷.
- **동시성 제어** — `SELECT ... FOR UPDATE` 행 락(`MemberRepository::getBalanceForUpdate`, 도메인 소유 검증 포함).
- **트랜잭션 원자성** — 원장 INSERT + 스냅샷 UPDATE가 하나의 트랜잭션.
- **음수 거부** — 차감 결과가 음수면 실패("잔액이 부족합니다").
- **확장 소비는 계약으로** — Package/Plugin 은 `BalanceManager` 를 직접 import 하지 않고 공개 계약 `Mublo\Contract\Balance\BalanceGatewayInterface`(adjust/getBalance/history/sumGrantedByReference/findLogByIdempotencyKey — 조회는 domainId 필수, 반환은 배열)로 소비한다. 번들 소비자 전부(Board·Shop·Mshop·MemberPoint)가 이 계약 경유이며, `tools/check-extension-api.php` 가 직접 import 를 검사한다. `getHistory`/`verifyIntegrity`/`repair` 등 나머지 메서드는 코어 내부 API 다.

### adjust() — 단일 진입점

```php
public function adjust(array $params): Result
// 필수: domain_id, member_id, amount(+지급/-차감, 0 불가),
//       source_type('plugin'|'package'|'admin'|'system'), source_name, action, message
// 선택: reference_type, reference_id, admin_id, memo, ip_address, idempotency_key
// 성공 데이터: log_id, balance_before, balance_after, (멱등 응답 시) idempotent=true
```

트랜잭션 경계와 처리 순서:

1. 필수 필드 검증(`REQUIRED_FIELDS`) → 실패 시 즉시 `Result::failure`.
2. `idempotency_key`가 있으면 도메인 스코프로 기존 원장 조회(`BalanceLogRepository::findByIdempotencyKey`) — 있으면 트랜잭션 없이 기존 결과를 `idempotent: true`로 반환.
3. `beginTransaction` → `getBalanceForUpdate`(행 락) → 잔액 검증(음수 거부).
4. `BalanceAdjustingEvent` 발행 — `isBlocked()`면 롤백 후 실패 반환.
5. 원장 INSERT → 스냅샷 UPDATE(실패 시 예외 → 롤백) → `commit`.
6. 커밋 후 `BalanceAdjustedEvent` 발행.

멱등성은 이중으로 보장된다. 사전 조회(2번)에 더해, 동시 요청이 경합하면 `balance_logs`의 UNIQUE 제약(`uk_domain_idempotency`) 위반이 발생하는데, `adjust()`는 SQLSTATE 23000 / "Duplicate entry"를 감지해(`isDuplicateKeyError`) 먼저 커밋된 요청의 결과를 멱등 응답으로 반환한다.

### 조회·무결성 API

- `getBalance(int $memberId, ?int $domainId = null): int` — 스냅샷 조회.
- `getHistory(int $memberId, array $filters = [], int $page = 1, int $perPage = 20, ?int $domainId = null): array` — 회원 내역 + 페이지네이션. `$filters` 는 허용 키 동등 조건(domain_id, action, source_type, source_name, reference_type, reference_id)으로 실제 적용된다. 확장은 domainId를 필수로 받는 계약 메서드 `history(int $memberId, int $domainId, ...)`를 쓴다.
- `sumGrantedByReference(int $memberId, int $domainId, string $action, string $referenceType, string $referenceId): int` — 특정 참조(주문 등)로 지급된 총액(amount > 0)의 SQL SUM 집계. 포인트 "전액 환수"가 로그를 로드하지 않고 이걸 쓴다.
- `getPaginatedLogs(int $domainId, ...)` — 관리자용 도메인 전체 내역(member_id·source_type·기간 필터).
- `verifyIntegrity(int $memberId, int $domainId): array` — `SUM(balance_logs.amount)`와 스냅샷 비교, `diff` 반환.
- `repair(int $memberId, int $domainId, int $adminId, string $reason): Result` — 관리자 전용. 원장 합 기준으로 **스냅샷만** 복구하고(원장에는 아무것도 쓰지 않는다) `BalanceRepairAuditRepository`로 감사 기록을 남긴다.

## Balance 이벤트 2종

`src/Core/Event/Balance/` 소속. 두 이벤트의 역할 분담이 확장 지점의 전부다.

| 이벤트 | NAME | 발행 시점 | 차단 | 용도 |
|---|---|---|---|---|
| `BalanceAdjustingEvent` | `balance.adjusting` | 원장 INSERT 전, 트랜잭션 내 | 가능 | 추가 검증·차단 (어뷰징 필터 등) |
| `BalanceAdjustedEvent` | `balance.adjusted` | 커밋 후 | 불가 (readonly) | 알림·통계·로깅 |

**Adjusting** — `getMemberId()`, `getAmount()`, `getCurrentBalance()`, `getNewBalance()`, `isAddition()`, `isDeduction()`으로 조정 내용을 읽고, `setBlocked(true)` + `setBlockReason(?string)`으로 차단한다. 차단되면 `adjust()`가 롤백하고 사유를 실패 메시지로 반환한다.

**Adjusted** — `getMemberId()`, `getLogId()`, `getNewBalance()`뿐이다. 이미 커밋된 뒤라 구독자가 예외를 던져도 잔액 변경은 되돌아가지 않는다 — `adjust()`는 구독자 예외를 로그로 남기고 호출자에게는 **성공을 유지**한다(조정은 커밋된 사실이므로 실패로 오보하지 않는다). 검증 로직을 여기에 두면 안 되는 이유다.

이벤트 구독 방법 자체는 [08. Event](08-event.md)를 따른다.

## 실사용 사례 — 정책층

### Board — 게시판별 적립·소비 정책

`packages/Board/Subscriber/BoardPointSubscriber.php`가 Board 도메인 이벤트를 구독해 `BoardPointService`(award/revoke/consume)를 호출한다. `source_type='package'`, `source_name='Board'`.

| Board 이벤트 | 처리 | action | 멱등키 |
|---|---|---|---|
| ArticleCreated / Deleted | 적립 / 회수 | `article_write`(`_revoke`) | `bp_article_{domain}_{articleId}` |
| CommentCreated / Deleted | 적립 / 회수 | `comment_write`(`_revoke`) | `bp_comment_{domain}_{commentId}` |
| ArticleViewing | 소비(열람 비용) | `article_read` | `bp_read_{domain}_{member}_{articleId}` |
| FileDownloading | 소비(다운로드 비용) | `file_download` | `bp_download_{domain}_{member}_{attachmentId}` |
| ReactionAdded / Removed | 적립 / 회수 (`packages/Board/Subscriber/ReactionPointSubscriber.php`) | `reaction_received`(`_revoke`) | `bp_reaction_{domain}_{reactionId}` |

주목할 설계 세 가지.

- **소비 실패 = 행위 차단**: 열람/다운로드 시 잔액 부족으로 `consume()`이 실패하면 구독자가 `ArticleViewingEvent`·`FileDownloadingEvent`의 `setBlocked(true)`를 호출해 행위 자체를 막는다. 멱등키 덕에 같은 글 재열람은 `idempotent: true`(이미 지불)로 통과한다.
- **설정 해석 체인**: `packages/Board/Service/BoardPointConfigService.php`가 게시판별 → 그룹별 → 도메인 기본값 순으로 설정을 해석한다(`getBoardActionConfig`). 관리자 설정 화면은 `/admin/board/point`(선언 경로 `/admin/point`에 Package 접두사가 붙는다 — `src/Core/App/PrefixedRouteCollector.php`).
- **멱등키 짝 맞춤**: 반응 회수 키는 `(대상, 반응자)` 고정 키가 아니라 `reactionId` 기준이다. 고정 키를 쓰면 toggle 반복 시 회수는 한 번만 되고 적립만 쌓이는 포인트 파밍이 가능해진다(`ReactionPointSubscriber.php` 주석에 명문화).

### Shop — 주문 사용·적립·환불

- **사용(차감)**: `packages/Shop/Service/OrderPointService.php`. `validate()`가 사용 단위·레벨별 최소/최대 한도·잔액·주문금액 초과를 검증하고, `reserve()`가 `action='order_point_use'`, 멱등키 `shop_point_use_{orderNo}`로 차감한다. `restore()`는 취소/반품 시 복원하되, 차감 원장(`findByIdempotencyKey`)이 실제 존재하는 주문만 복원한다.
- **적립**: `packages/Shop/Action/PointActionHandler.php` — 주문 상태 전이 액션으로 정액/비율 적립(`order_point_grant`, 멱등키 `shop_point_grant_{orderNo}`). 환수는 `PointDeductActionHandler.php`(`order_point_deduct`) — 전액 환수(`deduct_type='all'`)는 해당 주문으로 지급된 총액을 `BalanceManager::sumGrantedByReference()` SUM 집계로 구한다(로그 로드 없음, Mshop 판도 동일 구조).
- **환불**: `packages/Shop/Service/RefundService.php` — POINT 환불 시 환불 기록 커밋 **이후** `adjust()`로 지급한다. `adjust()`가 자체 트랜잭션을 열기 때문에 외부 트랜잭션 안에 중첩할 수 없다는 제약이 주석에 명시돼 있고, 멱등키는 환불 트랜잭션 id 기준(`shop_refund_point_{txnId}`)이다.

### MemberPoint — 생애주기 적립 + 내역 UI

독립 Plugin `plugins/MemberPoint/`. `Subscriber/MemberEventSubscriber.php`가 `MemberRegisteredEvent`(가입 적립 `awardSignup`)와 `MemberUpdatedByAdminEvent`(레벨 상승 시 `awardLevelUp`)를 구독한다. `source_type='plugin'`, `source_name='MemberPoint'`, 멱등키는 `mp_signup_{domain}_{member}` 형식이라 재발행 이벤트에도 중복 지급이 없다. 프론트 내역 화면은 `/memberpoint/my`(`Controller/Front/PointController.php` — `getBalance` + `getHistory` 출력). 포인트 내역·수동조정·무결성 검증 화면은 코어 `/admin/point`로 일원화됐고 이 플러그인은 적립 설정만 담당한다(`plugins/MemberPoint/routes.php` 주석).

데이터 리셋도 격리 모범 사례다. `MemberPointDataResetter`는 `BalanceResetGatewayInterface`에 `(plugin, MemberPoint)` 출처 초기화를 요청한다. 코어 `BalanceResetManager`가 해당 원장만 삭제하고 영향 회원의 스냅샷을 남은 원장 합계로 재계산한다. Board·Shop·Mshop도 각각 자기 `(package, source_name)`만 같은 방식으로 정리한다.

### 관리자·마이페이지 화면

- `/admin/point` — `src/Controller/Admin/PointController.php`. 내역 목록(source_type·회원·기간 필터), 수동 조정(`source_type='admin'`, `action='admin_adjust'`, `admin_id`·`ip_address` 기록), 회원 자동완성 검색, 회원별 무결성 검증(`verifyIntegrity` JSON 반환).
- `/mypage/balance` — `src/Controller/Front/MypageController.php`의 `balance()`. 본인 내역과 잔액 조회.

## 예제 — 확장에서 포인트 지급

```php
// 실제 규약 (BoardPointService·MemberPointService의 호출 형태)
$result = $this->balanceManager->adjust([
    'domain_id'       => $domainId,
    'member_id'       => $memberId,
    'amount'          => 100,                     // +지급 / -차감
    'source_type'     => 'plugin',                // 자기 신분 그대로
    'source_name'     => 'MyPlugin',              // 활성 키와 일치시킨다
    'action'          => 'quiz_reward',
    'message'         => '퀴즈 정답 포인트',        // 회원 내역 화면에 노출
    'reference_type'  => 'quiz',
    'reference_id'    => (string) $quizId,
    'idempotency_key' => "myplugin_quiz_{$domainId}_{$quizId}_{$memberId}",
]);
if ($result->isSuccess()) { $newBalance = $result->get('balance_after'); }
```

## 확장 개발자 규약

포인트를 다루는 확장이 지켜야 할 것.

- **테이블 직접 수정 금지** — `balance_logs`·`members.point_balance`에 UPDATE/DELETE/INSERT를 직접 하지 않는다. 모든 조정은 `BalanceManager::adjust()` 경유. 원장 불변성과 멱등성 장치가 전부 이 진입점에 있다.
- **멱등키를 항상 넣는다** — 재시도·이중 클릭·이벤트 재발행에서 중복 지급을 막는 유일한 수단이다. 키는 도메인 스코프이므로 자기 접두사 + 참조 식별자로 구성한다(`bp_article_…`, `shop_point_use_…` 패턴).
- **source_name은 자기 이름** — 내역 추적과 데이터 리셋 격리의 기준이다. 확장 Resetter가 원장을 직접 수정하지 말고 `BalanceResetGatewayInterface::resetSource()`에 자기 `source_type`·`source_name`을 전달한다.
- **외부 트랜잭션 안에서 adjust() 호출 금지** — `adjust()`는 자체 트랜잭션을 연다. 자기 도메인 기록을 먼저 커밋한 뒤 호출하고, 멱등키로 재시도를 안전하게 만든다(`RefundService` 패턴).
- **차단은 Adjusting, 부수효과는 Adjusted** — 커밋 후 이벤트에서 검증해봐야 되돌릴 수 없다.
- **잔액 검증을 직접 하지 않는다** — 음수 거부는 `adjust()`가 트랜잭션과 행 락 안에서 수행한다. 확장이 미리 `getBalance()`로 검사한 값은 경합 상황에서 이미 낡은 값이다(Shop의 `validate()`는 UX용 사전 안내이고, 최종 방어는 `reserve()`의 `adjust()` 실패다).

## 관련 문서

- [08. Event](08-event.md) — 이벤트 구독·차단형 이벤트 규약
- [16. Contract 카탈로그](16-contract-catalog.md) — Core 공개 계약 전수
- [33. Reference Packages](33-reference-packages.md) — 번들 확장 카탈로그의 MemberPoint 항목
- `docs/dev-guide/*` — 확장 개발 일반 규약
