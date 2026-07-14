# 19. 회원·커스텀 필드

회원 시스템은 코어가 직접 소유하는 몇 안 되는 도메인 데이터다. 확장은 회원 테이블을 만지지 않는다 — 폼에 끼어들고(Rendering), 검증에 참여하고(Validating), 자기 데이터를 격리해 붙이고(Preparing/pluginData), 조회는 Query Event로 요청한다. 이 장의 중심 질문은 **확장이 회원 데이터에 어떻게 안전하게 개입하는가**다.

이 장이 다루는 기능:

- 관리자 화면 5건 — 회원 CRUD·일괄 처리(`src/Controller/Admin/MemberController.php`), 회원 중복 확인·자동완성 검색(같은 파일), 회원 등급 CRUD·퀵/일괄 수정(`src/Controller/Admin/MemberLevelsController.php`), 커스텀 필드 CRUD·순서 변경(`src/Controller/Admin/MemberFieldController.php`), 약관·정책 CRUD·정렬(`src/Controller/Admin/PolicyController.php`)
- 프론트 화면 6건 — 로그인·로그아웃(`src/Controller/Front/AuthController.php`), 계정 찾기·비밀번호 재설정(같은 파일), 회원가입 3단계(`src/Controller/Front/MemberController.php`), 중복 확인·필드 파일 업로드(같은 파일), 회원 탈퇴(`src/Controller/Front/MypageController.php` withdraw), 약관 열람(`src/Controller/Front/PolicyController.php`)
- 라우트 3건 — `/policy/view/{slug}`·`/terms`·`/privacy`, `/member/register*`·`/member/check-*`·`/member/upload-field-file`, `/login`·`/logout`·`/find-account*` (모두 `src/Core/App/Router.php`)
- 번들 확장 라우트 1건 — SnsLogin(`plugins/SnsLogin/routes.php`)
- 개입형(확장점) 이벤트 10건 — `LoginFormRenderingEvent`(`src/Core/Event/Auth/`), `RegisterFormRenderingEvent`·`MemberRegisterValidatingEvent`·`MemberRegisterPreparingEvent`·`MemberFormRenderingEvent`·`MemberDataEnrichingEvent`·`MemberListQueryEvent`·`MemberLevelListQueryEvent`(모두 `src/Core/Event/Member/`), `MemberWithdrawingEvent`·`MemberDeletingEvent`(차단 가능 — `src/Service/Member/Event/`)
- 통지형(완료) 이벤트 9건 — `MemberLoggedInEvent`(`src/Service/Auth/Event/`), `MemberRegisteredEvent`와 ByUser/ByAdmin 파생, `MemberUpdatedEvent`와 BySelf/ByAdmin 파생, `MemberWithdrawnEvent`·`MemberDeletedEvent`(모두 `src/Service/Member/Event/`)

## 개요 — 회원 시스템의 위치

회원은 **도메인별**이다. 한 설치본의 여러 도메인([09. 멀티 도메인](09-multi-domain.md))은 각자 회원을 가지며, 가입·중복 확인·커스텀 필드·약관이 전부 `domain_id` 스코프로 동작한다. 아이디 중복 검사는 현재 도메인 사용 여부에 더해 태생 도메인(origin) 예약까지 함께 본다(`MemberService::isUserIdAvailable()` — `src/Service/Member/MemberService.php`).

반면 **회원 등급(member_levels)은 전역 테이블**이다. `src/Service/Member/MemberLevelService.php` 주석이 명시하듯 등급은 설치본 전체가 공유하고 슈퍼관리자만 관리한다. 약관(Policy)은 다시 도메인별이다(`src/Service/Member/PolicyService.php`).

서비스 계층은 `src/Service/Member/`에 있다.

| 서비스 | 책임 |
|---|---|
| `MemberService` | 검증(아이디·닉네임·비밀번호·필드), Front 가입/수정/탈퇴, 필드 값 저장·조회 |
| `MemberAdminService` | 관리자에 의한 등록/수정/삭제, 목록 조회 (검증은 MemberService에 위임) |
| `MemberFieldService` | 커스텀 필드 **정의** CRUD·정렬 |
| `MemberLevelService` | 등급 CRUD (전역, 옵션 캐시 10분) |
| `PolicyService` | 약관·정책 CRUD, 슬러그, 치환 변수 |
| `FieldEncryptionService` | 필드 암호화 + Blind Index(HMAC-SHA256 + pepper) 생성 |
| `PasswordResetService` | 이메일 토큰 기반 비밀번호 재설정 (30분 만료, 1회용, rate limit) |
| `PasswordPolicy` | 도메인 site_config 기반 비밀번호 규칙 값 객체 |

## 가입 이벤트 체인 — 이 장의 중심

프론트 회원가입은 3단계다(`src/Controller/Front/MemberController.php`): 약관 동의(`registerAgree`, 동의 내역은 세션에 30분 보관) → 정보 입력(`registerForm`) → 처리(`register`) → 완료/승인대기. 이 흐름에 확장이 개입하는 지점이 시간 순서대로 세 개 있고, 완료 후 통지가 하나 있다.

```text
[폼 표시]   RegisterFormRenderingEvent    — HTML/JS 주입 (registerForm에서 발행)
[제출 직후] MemberRegisterValidatingEvent — addError()로 검증 참여 (register에서 발행)
[저장 직전] MemberRegisterPreparingEvent  — set()/setPluginData() (MemberService::register에서 발행)
[저장 완료] MemberRegisteredByUserEvent   — 통지 + pluginData 전달 (트랜잭션 커밋 후 발행)
```

### 1단계 — RegisterFormRenderingEvent (폼 확장)

`src/Core/Event/Member/RegisterFormRenderingEvent.php`. `registerForm()`이 발행하고, 구독자는 `addHtml(string $html, int $order = 500)`과 `addScript()`로 폼 필드 영역에 마크업과 스크립트를 주입한다. order가 낮을수록 먼저 표시된다. 컨트롤러는 `getHtmlSorted()`/`getScriptsSorted()` 결과를 뷰 변수 `registerFormExtras`/`registerFormScripts`로 넘긴다.

### 2단계 — MemberRegisterValidatingEvent (검증 참여)

`src/Core/Event/Member/MemberRegisterValidatingEvent.php`. `register()`가 비밀번호 확인 직후 발행한다. 설계 원칙이 소스 주석에 명시돼 있다.

- `addError()`는 에러만 쌓고 **전파를 중단하지 않는다** — 여러 확장의 검증 에러를 사용자에게 한 번에 보여주기 위함이다.
- 봇 탐지 같은 치명적 상황에서만 `stopPropagation()`을 명시적으로 호출한다.

`hasErrors()`가 참이면 컨트롤러가 가입을 중단하고 에러 목록을 반환한다. 즉 확장의 검증은 Core 검증(아이디 형식·중복, 비밀번호 정책, 필드 검증)과 **동급의 거부권**을 가진다.

### 3단계 — MemberRegisterPreparingEvent (데이터 가공·격리)

`src/Core/Event/Member/MemberRegisterPreparingEvent.php`. 발행 위치가 앞의 둘과 다르다 — 컨트롤러가 아니라 `MemberService::register()` 내부, Core 검증을 모두 통과한 뒤 DB 저장 직전이다. 확장 데이터를 다루는 두 API의 구분이 이 이벤트의 핵심이다.

- `set(string $key, mixed $value)` — **Core 필드 수정**. 가입 데이터 배열을 직접 바꾼다. 소스 주석대로 "주의해서 사용".
- `setPluginData(string $pluginName, array $data)` — **확장 전용 데이터를 이름공간으로 격리**. Core insert에는 전혀 섞이지 않는다.

### 완료 — MemberRegisteredByUserEvent와 pluginData 릴레이

`MemberService::register()`는 회원 insert·커스텀 필드 저장·약관 동의 기록을 한 트랜잭션으로 묶고, 성공 후 `MemberRegisteredByUserEvent`(`src/Service/Member/Event/`)를 발행한다. 이때 Preparing 단계에서 수집한 pluginData 전체를 `setAllPluginData()`로 완료 이벤트에 실어 보낸다. 확장은 완료 이벤트에서 `getPluginData('referral')`처럼 자기 이름공간의 데이터를 꺼내 **자기 테이블에** 저장한다. 즉 확장 데이터의 생애는 "Preparing에서 격리 수집 → Registered에서 자기 소유 저장"이며, Core 회원 테이블은 한 번도 거치지 않는다.

통지 이벤트는 출처별로 나뉜다. `MemberRegisteredEvent`를 구독하면 모든 등록(user/admin/api)에, `MemberRegisteredByUserEvent`는 직접 가입에만, `MemberRegisteredByAdminEvent`(발행: `src/Service/Member/MemberAdminService.php`)는 관리자 등록에만 반응한다. 수정도 같은 구조다 — `MemberUpdatedEvent` 부모에 BySelf/ByAdmin 파생이 있고, `isLevelChanged()`·`isPasswordChanged()` 같은 변경 플래그를 제공한다.

**소멸(탈퇴/삭제) 수명주기** — 가입과 대칭으로 4종이 있다. 본인 탈퇴는 `MemberWithdrawingEvent`(DB 반영 전, `setBlocked()`로 차단 가능 — 미정산 포인트·진행 주문 보류 용도) → 소프트삭제 커밋 → `MemberWithdrawnEvent`(readonly), 관리자 하드삭제는 `MemberDeletingEvent`(차단 가능) → 삭제 → `MemberDeletedEvent`(readonly). 완료 이벤트의 `Member`는 **소멸 전 스냅샷**이다 — Deleted 시점엔 DB 에 행이 없으므로 구독자는 재조회 대신 스냅샷을 써야 한다. 확장 소유 데이터(자체 테이블)의 정리는 이 완료 이벤트에서 한다. 파일형 커스텀 필드의 디스크 파일은 Core 가 직접 정리한다(메타 선수집 → 커밋/삭제 후 삭제, 실패는 로그만 — `MemberService::collectMemberFileMetas()/deleteFilesByMetas()`).

**실구독 사례** (번들 확장, 소스 확인 기준):

- `packages/Shop/EventSubscriber/CouponAutoIssueSubscriber.php` — `MemberRegisteredByUserEvent`(가입 쿠폰), `MemberLoggedInEvent`(로그인 쿠폰), `MemberUpdatedEvent`+`isLevelChanged()`(등급 승급 쿠폰).
- `plugins/MemberPoint/Subscriber/MemberEventSubscriber.php` — `MemberRegisteredEvent`(가입 포인트), `MemberUpdatedByAdminEvent`(등급 상승 시 포인트).
- `plugins/SnsLogin/Subscriber/LoginFormSubscriber.php` — `LoginFormRenderingEvent`를 구독해 로그인 폼에 SNS 버튼을 `addHtml(..., 50)`으로 주입. 같은 이벤트를 Front `AuthController::loginForm`과 `MemberController::registerAgree`가 발행한다.

개입형 3종(RegisterFormRendering/Validating/Preparing)은 현재 번들 확장 중 구독자가 없다. 각 이벤트 클래스의 독블록에 있는 ReferralPlugin 예제는 사용법 설명용 가상 코드이며 실존 플러그인이 아니다.

### 예제 — 체인 전체를 쓰는 구독자

아래는 추천인 코드를 받는 확장이 네 단계를 모두 쓰는 형태다. 각 메서드 시그니처는 위 이벤트 클래스들의 실제 API 그대로다.

```php
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Member\RegisterFormRenderingEvent;
use Mublo\Core\Event\Member\MemberRegisterValidatingEvent;
use Mublo\Core\Event\Member\MemberRegisterPreparingEvent;
use Mublo\Service\Member\Event\MemberRegisteredByUserEvent;

class ReferralSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RegisterFormRenderingEvent::class    => 'onFormRendering',
            MemberRegisterValidatingEvent::class => 'onValidating',
            MemberRegisterPreparingEvent::class  => 'onPreparing',
            MemberRegisteredByUserEvent::class   => 'onRegistered',
        ];
    }

    public function onFormRendering(RegisterFormRenderingEvent $event): void
    {
        // formData[...] 이름공간을 쓰면 제출 시 검증/가공 이벤트의 데이터로 들어온다
        $event->addHtml('<div class="custom-field-group">
            <label class="custom-field-label">추천인 코드</label>
            <input type="text" name="formData[plugin_referral_code]">
        </div>', 600);
    }

    public function onValidating(MemberRegisterValidatingEvent $event): void
    {
        $code = $event->get('plugin_referral_code');
        if (!empty($code) && !$this->referralService->exists($code)) {
            $event->addError('추천인 코드가 올바르지 않습니다.');
            // stopPropagation() 호출 금지 — 다른 확장의 검증도 계속되어야 한다
        }
    }

    public function onPreparing(MemberRegisterPreparingEvent $event): void
    {
        $code = $event->get('plugin_referral_code');
        if (!empty($code)) {
            $event->setPluginData('referral', ['referral_code' => $code]);
        }
    }

    public function onRegistered(MemberRegisteredByUserEvent $event): void
    {
        $data = $event->getPluginData('referral');
        if (!empty($data)) {
            // 확장 자신의 테이블에 저장 — Core 회원 테이블은 건드리지 않는다
            $this->referralService->link($event->getMemberId(), $data['referral_code']);
        }
    }
}
```

Subscriber 등록 방법(Provider의 `addSubscriber()`)은 [08. Event](08-event.md)와 [30. Plugin Guide](30-plugin-guide.md)를 따른다.

관리자 쪽 폼에는 별도 이벤트가 있다. `MemberFormRenderingEvent`(`src/Core/Event/Member/MemberFormRenderingEvent.php`)는 Admin `MemberController::create()/edit()`에서 mode(`create`/`edit`)와 회원 데이터를 실어 발행한다. Front 가입 이벤트와 분리한 이유도 소스 주석에 있다 — 관리자 화면에는 SMS 인증·캡차 같은 가입 전용 검증이 불필요하고, 주로 읽기 전용 정보 표시나 관리자 전용 필드에 쓰인다.

## 커스텀 필드

운영자는 관리자 Memberfield 화면(`src/Controller/Admin/MemberFieldController.php` — index, create, edit, store, delete, orderUpdate)에서 필드를 **코드 없이** 정의한다. 지원 타입은 12종이다: `text, textarea, email, tel, number, date, select, radio, checkbox, address, file, avatar` (같은 파일의 enum 정의). 필드별 플래그로 필수(`is_required`), 암호화(`is_encrypted`), 검색 인덱스(`is_searched`), 유일성(`is_unique`), 노출 위치(`is_visible_signup/profile/list`), 관리자 전용(`is_admin_only`)을 켠다. `avatar`는 도메인당 1개 예약 필드로 필드명이 `avatar`로 고정된다(`src/Service/Member/MemberFieldService.php`).

정의된 필드의 렌더·검증·파일 처리는 `src/Service/CustomField/`의 공용 3종이 담당한다. "공용"이 요점이다 — 회원 필드만이 아니라 주문 필드 등 모든 커스텀 필드 시스템이 같은 클래스를 쓴다.

- **`CustomFieldRenderer`** — 타입별 입력 HTML 생성. `render($field, $currentValue, ['namePrefix' => ..., 'showExisting' => ...])` 정적 호출 하나로 라벨·입력·설명까지 자기완결 마크업을 만들고, 위젯 CSS를 스킨과 무관하게 head에 등록한다.
- **`CustomFieldValidator`** — 타입별 값 검증(`validateByType`), 운영자 정의 정규식 검증(`validateRegex`), 타입별 빈값 판정(`isEmpty` — file의 `__delete__`, address의 부분 입력, checkbox 배열까지 통합 처리). `MemberService::validateFieldValues()`가 가입·수정 시 이 유틸로 전 필드를 검사한다.
- **`CustomFieldFileHandler`** — 파일 타입의 2단계 흐름을 처리한다. ① 사용자가 파일을 선택하면 AJAX(`POST /member/upload-field-file`)로 `uploadTemp()` → 임시 경로 반환, ② 폼 제출 시 `processFileValue()`가 임시→최종 이동 후 JSON 메타를 반환한다. 일반 첨부는 `storage/files/`(웹 접근 불가)에 저장돼 HMAC 토큰 URL로만 내려받고, `avatar`만 공개 저장소 직링크를 쓴다(`MemberService::saveFileFieldValue()`).

클라이언트 짝은 `public/assets/js/MubloCustomField.js`다. `CustomFieldRenderer::renderFileScript()`가 로드하며, `.custom-field-file` 입력에 자동 바인딩되어 크기 검사·임시 업로드·메타 hidden 필드 갱신·아바타 즉시 미리보기를 처리한다.

`is_encrypted` 필드는 `FieldEncryptionService`(`src/Service/Member/FieldEncryptionService.php`)가 대칭키 암호화하고, `is_searched`가 켜져 있으면 pepper 포함 HMAC-SHA256 **Blind Index**를 함께 저장한다 — DB가 유출돼도 원문 복호화와 rainbow table 추론이 모두 막힌다. 아이디 찾기(`MemberService::findUserIdsByEmail()`)가 이 인덱스로 이메일 필드를 검색하는 실사용 예다. 암호화+`is_unique` 필드는 `is_searched` 와 무관하게 Blind Index 가 생성된다(유일성 판정에 필요). 복호화 실패(키 로테이션·데이터 손상)는 무음이 아니라 로그로 남는다(요청당 상한, 원문 미기록).

**`is_unique` 는 서버가 강제한다** — 프론트 AJAX 사전확인은 UX 일 뿐이고, 실제 보장은 3중이다: ① 검증 계층 — `validateFieldValues()`가 저장 전 `checkDuplicate()`로 재검사(수정 시 본인 값은 `excludeMemberId` 로 제외) ② DB 계층 — `member_field_values.unique_key`(is_unique 필드만 결정적 해시를 채우는 nullable 컬럼) + `UNIQUE(field_id, unique_key)` 가 동시 가입 경합(TOCTOU)까지 차단, 충돌은 `DuplicateFieldValueException` 으로 친화 메시지 반환 ③ 토글 운영 — 관리자가 is_unique 를 켜면 기존 값 백필(중복 발견 시 건수와 함께 거부), 끄면 키 일괄 해제(`MemberFieldController::store()` 오케스트레이션).

**필드 쓰기 경계** — `saveFieldValues()`는 저장 직전 최종 방어선으로 ① 도메인에 정의되지 않은 field_id 거부(무시 아님) ② 타 도메인 필드 거부 ③ `is_admin_only` 필드는 관리자 경로(`allowAdminOnly=true`)에서만 허용을 강제한다. 프론트 3경로(가입·본인수정·SNS 보완)는 admin_only 불허, 관리자 2경로(등록·수정)는 허용 명시 전달. 검증 계층(`validateFieldValues`)도 같은 규칙을 선검사하므로 확장이 서비스를 직접 호출해도 경계가 유지된다.

### 확장 관점 — 회원 데이터를 붙이는 두 경로

확장이 "회원에게 데이터를 더한다"고 할 때 경로는 두 가지고, 성격이 다르다.

| | 커스텀 필드 | Preparing의 pluginData |
|---|---|---|
| 정의 주체 | **운영자** (관리자 화면) | **확장 코드** |
| 저장 위치 | Core 필드 값 테이블 | 확장 자신의 테이블 |
| Core 기능 연동 | 렌더·검증·암호화·목록 노출을 Core가 제공 | 없음 — 전부 확장 소유 |
| 적합한 데이터 | 사용자가 입력하는 프로필성 값 | 추천인 ID, SNS 연동 키 등 시스템 값 |

운영자가 화면에서 관리해야 할 입력이면 커스텀 필드로 유도하고, 확장 내부 로직의 부속 데이터면 pluginData → 자기 테이블 경로를 쓴다. 확장이 커스텀 필드 정의를 코드로 자동 생성하는 공식 경로는 현재 없다.

## 회원 데이터 조회 확장 — Query Event 패턴

확장이 회원을 **읽는** 방향도 이벤트다. 확장이 `MemberRepository`를 직접 의존하거나 회원 테이블을 SELECT하지 않는 이유는 [15. Public API](15-public-api.md)의 경계 그대로다 — Repository와 스키마는 공개 표면이 아니므로, 코어가 회원 테이블 구조를 바꿔도 확장이 깨지지 않으려면 결과 계약(이벤트의 배열 형태)만 약속해야 한다.

- **`MemberDataEnrichingEvent`** — 회원 상세 1건에 부가 데이터 첨부. Admin `MemberController::edit()`이 `viewContext='admin_detail'`로 발행하고, 구독자가 `addExtra('point', [...])`처럼 이름공간별로 데이터를 붙인다. 소스 주석이 적용 범위를 못박는다: **상세 조회 전용, 목록은 성능 문제로 제외**.
- **`MemberListQueryEvent`** — 확장이 회원 목록이 필요할 때 **역방향으로 발행**하는 이벤트. 확장이 `new MemberListQueryEvent($domainId, ['level_type' => 'SUPPLIER'])`를 dispatch하면 Core 구독자 `src/Core/Event/Subscriber/MemberQuerySubscriber.php`가 `MemberRepository::findByCriteria()`로 조회해 `setMembers()`로 채워 돌려준다. 지원 조건은 `level_type`, `level_value`, `status`, `keyword`, `limit`(기본 1000)이다.
- **`MemberLevelListQueryEvent`** — 등급 목록의 같은 패턴. `member_only` 필터로 일반 회원 등급만 받아 `getOptionsForSelect()`로 select box 옵션을 만든다. 실사용: `plugins/SnsLogin/Controller/Admin/SettingsController.php`가 SNS 가입자 기본 등급 선택 UI에 이 이벤트를 쓴다.

앞의 세 이벤트 중 Enriching은 "코어가 발행하고 확장이 채우는" 개입형, Query 2종은 "확장이 발행하고 코어가 채우는" 질의형이다. 방향은 반대지만 목적은 같다 — 확장과 회원 스키마 사이에 이벤트라는 완충층을 두는 것.

## 회원 등급·약관

**등급** — `src/Controller/Admin/MemberLevelsController.php`가 CRUD·퀵 수정·일괄 수정·값 중복 확인을 제공한다. 등급은 `level_value`(서열), `level_type`(SUPPLIER 등 역할 분류), `is_admin`/`is_super` 플래그를 가진다. 등급 할당에는 서열 규칙이 강제된다(`MemberService::validateLevelAssignment()`): 최고관리자 등급은 최고관리자만 부여할 수 있고, 일반 관리자는 자신보다 높은 등급을 줄 수 없으며 자기 등급을 확인할 수 없으면 거부한다(fail-closed). 확장 접점은 앞 절의 `MemberLevelListQueryEvent`와, 등급 변경 통지(`MemberUpdatedEvent::isLevelChanged()`)다.

**약관(Policy)** — `src/Controller/Admin/PolicyController.php`(CRUD·정렬·퀵 수정·슬러그 중복 확인)와 `PolicyService`. 가입 1단계에서 `getRegisterPolicies()`로 노출할 약관을, `getRequiredForSignup()`으로 필수 동의를 가려낸다. 본문에는 `{#회사명}` 같은 치환 변수가 지원되고(`PolicyService::replaceVariables()`), 동의 내역은 가입 트랜잭션 안에서 policy_id·버전·IP와 함께 기록된다(`MemberRepository::savePolicyAgreement()`). 프론트 열람은 `/policy/view/{slug}`와 별칭 `/terms`·`/privacy`(`src/Controller/Front/PolicyController.php`). 약관에 대한 확장 이벤트는 현재 없다 — 확장이 자체 동의 항목이 필요하면 가입 폼 확장(RegisterFormRendering + Validating) 경로를 쓴다.

**탈퇴** — `MemberService::withdraw()`는 소프트 삭제다. 커스텀 필드 값(개인정보)을 전부 삭제하고 status를 withdrawn으로 전환하되 user_id·가입일은 보존한다. 도메인 운영자는 탈퇴가 차단된다(`ownsDomain()` — domain_configs의 소유자 참조가 고아가 되는 것을 방지).

## 경계·Best Practice

- **회원 테이블 직접 접근 금지.** 읽기는 Query Event, 상세 부가 데이터는 Enriching, 쓰기는 커스텀 필드 또는 pluginData→자기 테이블. `MemberRepository`·`members` 스키마는 공개 표면이 아니다([32. Anti Pattern](32-anti-pattern.md)).
- **pluginData는 반드시 이름공간으로.** `setPluginData('myplugin', [...])`의 첫 인자가 충돌 방지 장치다. `set()`으로 Core 필드를 바꾸는 것은 좌표가 명확할 때만.
- **Validating에서 stopPropagation을 남용하지 않는다.** addError는 누적 설계다 — 전파를 끊으면 다른 확장의 검증 에러가 사용자에게 전달되지 않는다.
- **완료 이벤트는 구독 범위를 좁게.** 모든 등록에 반응해야 하면 부모(`MemberRegisteredEvent`), 직접 가입에만 반응해야 하면 `ByUserEvent` — MemberPoint(전자)와 Shop 쿠폰(후자)의 선택이 좋은 대비다.
- **민감 정보는 커스텀 필드 암호화로.** 확장이 자체 테이블에 개인정보를 평문 저장하지 말고, 운영자 정의 암호화 필드(is_encrypted + Blind Index)를 활용한다.

## 관련 문서

- [08. Event](08-event.md) — EventDispatcher, Subscriber 등록, 개입형/통지형 이벤트 규약
- [16. Contract 카탈로그](16-contract-catalog.md) — 본인인증 등 회원 주변의 공개 Contract
- [09. 멀티 도메인](09-multi-domain.md) — 도메인별 회원 스코프, origin 도메인 예약
- `docs/reference/event-reference.md` — 회원 이벤트 전 목록과 페이로드 표
- `docs/user-guide/member-management.md` — 운영자 관점의 회원·필드·약관 관리 절차
