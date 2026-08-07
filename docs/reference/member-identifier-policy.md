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
사용하지 않는다. `getPublicId()`와 안전한 `getDisplayName()`을 사용한다. 이 접근자는 deprecated
상태이며 다음 major에서 제거할 예정이다.

`BalanceRankingQueryInterface`의 `BalanceRankingEntry::memberId`도 서버 내부 결합 키다.
소비 확장은 프로필을 배치 결합한 뒤 프론트 경계에서 `is_me`, `public_id`, 회원 액션으로
투영하고 `member_id`를 제거한다. 공개 랭킹 검색은 로그인 ID가 아니라 닉네임만 대상으로 한다.

## 명시적 예외

- 뷰어 자신의 계정·세션 화면은 자신의 `member_id`와 `user_id`를 표시할 수 있다.
- 도메인 관리자 전용 회원 관리 화면은 업무상 내부 ID와 로그인 ID를 사용할 수 있다.
- 이미 배포된 운영 API가 회원 식별 필드를 응답에 담고 있다면, 현장 클라이언트 호환을 위해
  현재 버전에서 유지할 수 있다. 각 Controller는 응답 허용 목록을 고정하며 테이블 행을 그대로
  반환하지 않는다. API 버전 정책을 도입할 때 `public_id` 이관을 재검토한다.

기존 API 예외를 두는 확장은 **어느 라우트의 어느 필드가 예외인지 자기 문서에 목록으로 고정한다.**
목록에 없는 필드는 예외가 아니다. 예외는 이미 배포된 엔드포인트에만 적용하며, 신규 API에는
만들지 않는다.

예외는 공개 일반 화면으로 확장하지 않는다. 새 API에는 예외를 복사하지 않고 공개 식별자를 쓴다.
