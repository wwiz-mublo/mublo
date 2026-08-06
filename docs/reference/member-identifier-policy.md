# 회원 식별자 경계 정책

회원의 내부 식별자와 클라이언트에 노출 가능한 식별자를 분리한다.

## 기본 규칙

- `member_id`는 서버 내부 FK·조인·세션·권한 판정에만 사용한다.
- `user_id`는 로그인 자격증명이다. 다른 회원을 가리키는 HTML, URL, JSON, 알림 본문에 쓰지 않는다.
- 타인을 클라이언트에서 가리킬 때는 22자 소문자 16진수 `public_id`만 사용한다.
- 남에게 보이는 이름은 `publicDisplayName()`을 사용한다. 닉네임이 없으면 `회원 {public_id 앞 12자}`이며 실명·로그인 아이디로 떨어지지 않는다.
- 공개 읽기 자원은 `public_id`를 경로 또는 고정 `member` 쿼리에 둘 수 있다. 관계·의도가 드러나는 이동은 POST 본문 `target_public_id`를 사용한다.

## 서버 확장 경계

서버 안에서 실행되는 Plugin·Package는 조회와 관계 판정을 위해 정수 `member_id`를 사용할 수
있다. 그 값을 응답이나 HTML로 내보내는 순간 위 클라이언트 규칙이 적용된다. 신규 이벤트는
`MemberExtensionIdentity`를 사용한다. 이 DTO를 JSON 직렬화하면 `publicId`와 `displayName`만
나간다.

기존 안정 이벤트의 `MemberIdentity::getUserId()`는 하위 호환 때문에 유지하지만 신규 코드에서
사용하지 않는다. `getPublicId()`와 안전한 `getDisplayName()`을 사용한다. 이 접근자는 1.1.0에서
deprecated 되었고 다음 major인 2.0.0에서 제거할 예정이다.

`BalanceRankingQueryInterface`의 `BalanceRankingEntry::memberId`도 서버 내부 결합 키다.
PointRanking은 프로필을 배치 결합한 뒤 프론트 경계에서 `is_me`, `public_id`, 회원 액션으로
투영하고 `member_id`를 제거한다. 공개 랭킹 검색은 로그인 ID가 아니라 닉네임만 대상으로 한다.

## 명시적 예외

- 뷰어 자신의 계정·세션 화면은 자신의 `member_id`와 `user_id`를 표시할 수 있다.
- 도메인 관리자 전용 회원 관리 화면은 업무상 내부 ID와 로그인 ID를 사용할 수 있다.
- Rental 운영 API의 기존 회원 식별 필드는 현장 클라이언트 호환을 위해 현재 버전에서 유지한다.
  각 Controller는 응답 허용 목록을 고정하며 테이블 행을 그대로 반환하지 않는다. API 버전 정책을
  도입할 때 `public_id` 이관을 재검토한다.

Rental 예외의 JSON 경로는 `JsonResponse::success()`의 `data` 내부를 기준으로 한다.

| 메서드·라우트 | 허용된 회원 식별 필드 | 사유 |
|---|---|---|
| `GET /api/auth/me` | `member_id`, `user_id` | 뷰어 본인 |
| `POST /api/auth/login` | `user.member_id`, `user.user_id` | 뷰어 본인 |
| `GET /api/config` | `user.member_id`, `user.user_id` | 뷰어 본인 |
| `GET /api/staff` | `staff[].member_id`, `staff[].user_id` | 기존 운영 앱 직원 선택 계약 |
| `GET /api/orders` | `items[].member_id`, `items[].staff_id`, `items[].received_by_member_id`, `items[].commission_confirmed_by`, `items[].gift_updated_by` | 기존 운영 앱 주문 계약 |
| `GET /api/orders/poll` | `orders[].staff_id` | 기존 폴링 계약 |
| `GET /api/orders/{id}` | `order.member_id`, `order.staff_id`, `order.received_by_member_id`, `order.commission_confirmed_by`, `order.gift_updated_by`, `order.seller_user_id`, `order.items[].member_id`, `items[].member_id`, `logs[].member_id`·`changed_by` | 기존 주문 상세 계약 |
| `GET /api/orders/{id}/attachments` | `items[].uploaded_by_member_id` | 기존 첨부 감사 필드 |
| `GET /api/consultations` | `items[].member_id` | 기존 상담 계약 |
| `GET /api/consultations/{id}` | `consultation.member_id` | 기존 상담 상세 계약 |
| `POST /api/orders/{id}/assign` | 요청·응답 `staff_member_id` | 기존 직원 배정 계약 |

위 목록은 기존 엔드포인트에만 적용한다. 새 Rental API에는 내부 ID 예외를 추가하지 않는다.

예외는 공개 일반 화면으로 확장하지 않는다. 새 API에는 예외를 복사하지 않고 공개 식별자를 쓴다.
