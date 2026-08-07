# 확장 API 안정화로 신설한 Contract

> **전체 Contract 목록을 찾는다면 [16. Contract 카탈로그](../architecture/16-contract-catalog.md)를 봅니다.**
> 이 문서는 전수 목록이 아닙니다.

이 문서는 확장 API 안정화 과정에서 **내부 구현 의존을 걷어내려고 신설·확장한 Contract**만
다룹니다. 각 계약의 책임, 실제 번들 소비자, DTO 하위 호환 규칙을 여기에 고정합니다
([호환성 정책](../compatibility-policy.md)이 이 문서를 그 기준으로 지목합니다).

소비자 목록은 배포 대상 운영 코드 기준이며 테스트는 제외합니다. 새 Contract가 추가되어도
이 문서를 갱신할 필요는 없습니다 — 전체 목록의 진실은 16장입니다.

## 인증과 회원

| Contract | 책임 | 실제 소비자 |
|---|---|---|
| `AuthContextInterface` | 현재 사용자, 관리자·SUPER 권한, 대리 로그인 여부를 세션 배열 없이 조회 | Board, Mshop, Rental, Reservation, Shop, SiteKit, AutoForm, Promotion, Qna, Survey |
| `MemberQueryInterface` | 내부 Member Entity 없이 단건·일괄 프로필 및 활성 회원 닉네임 검색 | Board, Mshop, Rental, MemberPoint, SnsLogin, DirectMessage |
| `MemberActionQueryInterface` | 로그인·자기 자신·위치·상태 정책을 공통 적용한 회원 액션 단건/일괄 조회 | Board 및 회원 작성자 화면 |
| `MemberAccountGatewayInterface` | 계정 생성·자격 검증·커스텀 필드 저장을 회원 테이블과 해시 형식 없이 수행 | Rental, SnsLogin |
| `MemberLevelCatalogInterface` | 내부 레벨 Entity를 `MemberLevelDescriptor`로 변환해 조회 | Board, Mshop, Shop, MemberPoint |
| `PolicyQueryInterface` | 도메인 소유권이 확인된 활성·단건 약관과 렌더 결과 조회 | Mshop, Rental, Shop, AutoForm |
| `BalanceRankingQueryInterface` | 코어 원장·잔액으로 현재/기간 랭킹과 회원 순위를 조회 | PointRanking |

`AuthContextInterface::currentUser()`는 `AuthenticatedUser`를 반환합니다. 표시 이름과 식별자,
고정 레벨 타입은 DTO가 제공하며 세션 키나 내부 Member Entity는 공개하지 않습니다.

회원 액션 Provider는 `MemberActionBuildingEvent`에서 값 DTO만 등록합니다. 민감한 이동은
`PrivateBody`, 공유 가능한 읽기 전용 화면은 `PublicPath` 또는 `PublicQuery`를 선택합니다.
endpoint에는 대상·쿼리·fragment를 넣지 않으며 최종 URL과 POST hidden 필드는 코어가
검증된 `public_id`로 조립합니다.

## 블록, 메뉴와 도메인

| Contract | 책임 | 실제 소비자 |
|---|---|---|
| `BlockContentCacheInvalidatorInterface` | 콘텐츠 타입·항목·도메인 단위 블록 캐시 무효화 | Board, Rental, Shop, Banner, Faq, Manual |
| `BlockPreviewRendererInterface` | 저장 전 Block 행 구성을 실제 런타임과 같은 경로로 렌더링 | SiteKit |
| `BlockRenderContextInterface` | 요청별 블록 렌더 variant 설정 | Rental |
| `MenuManagementInterface` | 도메인 소유권을 강제한 확장 메뉴 조회·수정·삭제 | Board, Mshop, Rental, Shop, Faq, Manual, Promotion, Qna |
| `DomainQueryInterface` | 내부 Domain Entity 없이 ID·hostname·활성 도메인 조회 | Rental, EmailNotify |

메뉴 생성과 배치는 `SiteProvisioningInterface`가 담당하고, 운영 중 변경·삭제는
`MenuManagementInterface`가 담당합니다. 이 분리는 확장이 메뉴 Repository나 트리 저장 형식을
알지 못하게 합니다. `BlockPreviewRendererInterface`는 기존 Renderer 시그니처 때문에
`BlockRow`·`BlockColumn`을 사용하며, 이 Block Entity namespace만 명시적 안정 예외입니다.

## 민감정보와 커스텀 필드

| Contract | 책임 | 실제 소비자 |
|---|---|---|
| `SensitiveValueCodecInterface` | 민감 값 암복호화, blind index, 필드 저장·읽기 정책 | Mshop, Shop, AutoForm, SendonSms, SendonTalk, SnsLogin |
| `CustomFieldValueValidatorInterface` | 필드 타입·패턴 검증과 저장값 정규화 | Mshop, Rental, Shop |
| `CustomFieldFileManagerInterface` | 임시 업로드, 응답 구성, 파일 교체·삭제와 메타 조회 | Mshop, Shop |

암호문 형식, 키 위치와 알고리즘은 Contract 밖의 코어 구현 세부입니다. 기존 암호문 읽기와 새 암호문
쓰기는 코어 adapter가 책임지며 확장은 암호문을 직접 해석하지 않습니다.

## 확장 카탈로그

`ExtensionCatalogInterface`는 발견된 Plugin·Package 이름만 반환합니다. Rental 예약 작업자가 전체
확장을 부팅할 때 사용하며, manifest 내부 구조와 `ExtensionService`의 캐시·검색 구현은 노출하지
않습니다.

## DTO 하위 호환 규칙

이번 경계가 사용하는 대표 DTO는 `AuthenticatedUser`, `MenuDescriptor`, `DomainDescriptor`,
`MemberIdentity`, `MemberLevelDescriptor`, `MemberProfile`, `MemberRegistrationRequest`,
`PolicyDocument`입니다.

- 공개 프로퍼티·접근자·생성자 인자의 제거, 이름 변경, 의미 변경은 major 변경입니다.
- 선택 정보는 기본값이 있는 생성자 후행 인자나 새 접근자로만 추가합니다.
- enum·고정 타입 코드는 기존 값의 의미를 바꾸지 않고 새 값만 추가합니다.
- 배열 반환은 PHPDoc shape에 없는 필드를 소비자가 필수로 가정하지 않게 하며, 기존 키를 제거하지
  않습니다.
- Contract 메서드 추가도 외부 구현체에는 breaking change가 될 수 있으므로 새 책임은 별도
  인터페이스로 분리하는 것을 우선합니다.
- 실제 확장이 요구하지 않는 동작은 편의를 이유로 Contract에 추가하지 않습니다.
