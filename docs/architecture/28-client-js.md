# 28. 클라이언트 JS 라이브러리

Mublo의 클라이언트 사이드는 빌드 도구 없이 `<script>` 태그로 로드되는 전역 라이브러리들로 구성된다. 스킨·확장 개발자가 사용해도 되는 공개 라이브러리는 `public/assets/js/`의 `Mublo*.js` 9종이며, 각 파일은 전역 이름 하나(`MubloRequest`, `MubloForm` 등)만 노출한다. 이 장은 그 9종의 공개 API와 서버 짝, 로드 방법, 안정성 위치를 다룬다.

이 장이 다루는 기능: 공개 라이브러리 9종 — `public/assets/js/MubloCore.js`(순수 유틸리티), `MubloRequest.js`(Front/Admin 공용 AJAX 코어, CSRF 연동), `MubloForm.js`(폼 채우기·검증·입력 마스크·파일 미리보기), `MubloModal.js`(인스턴스 기반 모달, Remote Load), `MubloAddress.js`(다음(카카오) 우편번호 주소 검색), `MubloItemLayout.js`(ul 기반 list/slide(Swiper) 레이아웃), `MubloCustomField.js`(커스텀 필드 파일 업로드), `MubloPasswordPolicy.js`(비밀번호 정책 클라이언트 실시간 검증), `MubloTracking.js`(외부 픽셀 전환 이벤트 전송, 26장 상호참조).

## 개요 — 공개 라이브러리와 내부 스크립트의 경계

`public/assets/js/` 아래의 스크립트가 모두 공개인 것은 아니다.

| 구분 | 위치 | 성격 |
|---|---|---|
| 공개 라이브러리 | `public/assets/js/Mublo*.js` (9종) | 스킨·확장 개발자가 사용하는 표면. 이 장의 대상 |
| 내부 관리자 스크립트 | `public/assets/js/admin/` (`blockrow-form.js`, `block-html-editor/`) | 관리자 화면(블록 행 편집 폼, 블록 코드 에디터)의 내부 구현. **의존 금지** |
| 기타 프레임 보조 | `public/assets/js/manual.js`, `theme.js` | 매뉴얼 화면·테마 토글 등 기본 프레임의 보조 스크립트. 공개 API 아님 |

`admin/blockrow-form.js`는 블록 행/칸 설정 폼의 전체 로직을 담는 `BlockRowForm` 클래스이고, `admin/block-html-editor/`는 신뢰 관리자 코드 편집용 에디터 래퍼다. 둘 다 관리자 화면의 HTML 구조와 함께 언제든 바뀔 수 있다 — 호환성 정책이 "관리자 화면의 HTML 구조"를 내부 API로 명시하는 것(`docs/compatibility-policy.md`)과 같은 구도다.

공개 라이브러리 9종은 ES 모듈이 아니다. 각 파일이 IIFE 또는 클래스 선언으로 전역 이름 하나를 만든다(`const MubloCore = (() => {...})()`, `window.MubloItemLayout = ...`, `class MubloModal` 등). 별도 번들링 없이 로드 순서만 지키면 된다 — `MubloForm`은 `MubloCore`와 `MubloRequest`에, `MubloModal`의 Remote Load는 `MubloRequest`에 의존한다(각 파일 상단 주석에 의존이 명시돼 있다).

### 책임과 비책임

이 계층의 설계 원칙은 `MubloItemLayout.js`와 `MubloRequest.js`의 상단 주석에 명문화돼 있고, 9종 전체가 같은 태도를 공유한다.

- **콘텐츠는 서버가 완성한다.** 클라이언트 사이드 템플릿·렌더링 프레임워크가 아니다. JS는 이미 DOM에 존재하는 마크업에 동작(레이아웃, 마스크, 업로드, 검증)만 얹는다.
- **HTML은 선언, JS는 해석.** 스킨은 클래스와 `data-*` 속성으로 의도만 표현하고(`.mublo-submit`, `.mask-hp`, `.mublo-item-layout`, `data-pw-min` 등), 라이브러리가 이벤트 위임으로 해석한다. 페이지별 JS 로직을 HTML에 직접 쓰지 않는다.
- **판정은 서버가 authoritative.** 클라이언트 검증(MubloForm, MubloPasswordPolicy)은 UX 보조일 뿐이며, 최종 판정은 항상 서버 측(PHP)이 한다.
- **비책임**: SPA 라우팅, 상태 관리, 컴포넌트 시스템은 제공하지 않는다. 그런 것이 필요한 확장은 자체적으로 도입하되, 서버 통신만 MubloRequest 규약에 맞추면 된다.

## MubloRequest — 표준 AJAX 경로

`public/assets/js/MubloRequest.js`는 Front/Admin 공용 AJAX 코어 모듈이다. 요청·CSRF·로딩 UX·콜백·렌더러를 하나의 규칙으로 통합하며, 스킨과 확장의 모든 서버 통신은 이 모듈을 거치는 것이 표준이다. 상세 사용법은 [클라이언트 AJAX 시스템](../dev-guide/client-ajax.md)에 정리돼 있으므로 여기서는 구조와 서버 규약과의 결합만 짚는다.

### JsonResponse 규약과의 결합

서버 컨트롤러는 `src/Core/Response/JsonResponse.php`로 응답한다. `JsonResponse::success($data, $message)` / `JsonResponse::error($message)`는 항상 아래 형태를 만든다(`buildResponseData()`).

```json
{ "result": "success" | "error", "success": true | false, "message": "...", "data": { } }
```

(`success` 필드는 하위 호환용이다.) MubloRequest는 이 규약을 클라이언트에서 그대로 해석한다 — HTTP 200이라도 `json.result === 'success'`가 아니면 에러로 취급해 alert를 자동 표시하고 reject한다. 따라서 `.then()`에 도달했다는 것 자체가 성공 확정이며, `res.result` 재확인은 불필요하다. Response 계층 전반은 [07. Response](07-response.md) 참조.

### CSRF 자동 처리

토큰 처리는 전부 내부에서 일어난다(개발자가 직접 다룰 일이 없다).

1. 첫 요청 시 `GET /api/v1/csrf/token`(`config.apiBaseUrl` + `csrfTokenEndpoint`, 라우트는 `src/Core/App/Router.php`, 컨트롤러는 `src/Controller/Api/CsrfController.php`)으로 토큰을 받아 캐싱한다. 동시 요청은 하나의 fetch Promise를 공유한다.
2. 모든 요청에 `X-CSRF-Token`과 `X-Requested-With: XMLHttpRequest` 헤더를 자동 첨부한다.
3. 419 응답을 받으면 `resetCsrfToken()`으로 캐시를 비우고 재시도한다 — `retryableStatuses: [419, 503]`, 최대 `maxRetries: 3`회 지수 백오프.

서버 측 CSRF 검증은 [10. 인프라 서비스](10-infrastructure.md)에서 다룬다. `fetch`를 직접 써야 하는 예외 상황에서만 `MubloRequest.getCsrfToken()`으로 토큰을 수동 획득한다.

### 공개 API

`MubloRequest.js` 말미의 반환 객체가 공개 표면 전부다.

- **요청**: `requestJson(url, data, options)`, `requestQuery(url, params, options)`, `sendRequest(config)`, `submitForm(button)` — PayloadType은 `JSON`/`FORM`/`QUERY` 세 가지(`MubloRequest.PayloadType`)
- **콜백·렌더러**: `registerCallback`/`executeCallback`, `registerRenderer`/`render`
- **CSRF**: `getCsrfToken`, `resetCsrfToken`
- **UI**: `showToast`, `showAlert`, `showConfirm` — 다른 Mublo 라이브러리들도 alert 대신 이것을 쓴다
- **유틸**: `debounce`, `throttle`, `syncAllEditors`(MubloEditor/CKEditor/TinyMCE 동기화), `escapeHtml`, `clearValidationErrors`
- **설정**: `configure(options)`, `getConfig`, `init`, `destroy`

`DOMContentLoaded`에서 `MubloRequest.init()`이 자동 실행되어 `.mublo-submit` 버튼(선언형 폼 제출: `data-target`, `data-callback`, `data-confirm`, `data-loading`)의 이벤트 위임이 등록된다. `MubloRequest.request()` 같은 메서드는 존재하지 않고, options에 `onSuccess`/`onError`를 넣어도 무시된다(Promise 체인만 유효) — 흔한 실수 목록은 `docs/dev-guide/client-ajax.md`의 "피해야 할 패턴" 절에 있다.

## 라이브러리 카탈로그 — 나머지 8종

| 라이브러리 | 전역 API | 용도 | 서버 짝 |
|---|---|---|---|
| `MubloCore.js` | `MubloCore.safeInt/parseNumber/numberFormat/phoneFormat/birthFormat/isValidBirthDate/formatDateYMD` | DOM 무관 순수 유틸리티 | 없음 |
| `MubloForm.js` | `MubloForm.fill/validate/toObject/util.*` | 폼 채우기·검증·입력 마스크·파일 미리보기 | 폼 name 규약(`formData[...]`) |
| `MubloModal.js` | `new MubloModal({...})`, `MubloModal.alert/confirm` | 인스턴스 기반 모달 | Remote Load 시 JsonResponse의 `data.html` |
| `MubloItemLayout.js` | `MubloItemLayout.init/initAll/destroy/instances` | `<ul>` 목록의 list/slide(Swiper) 레이아웃 | 블록 스킨이 완성한 마크업([17장](17-block-system.md)) |
| `MubloCustomField.js` | `MubloCustomField.setUploadUrl/initFileUploads/removeFile/deleteExisting` | 커스텀 필드 파일 업로드 자동 바인딩 | `CustomFieldRenderer::renderFileScript()`([19장](19-member-custom-fields.md)) |
| `MubloAddress.js` | `MubloAddress.search(fieldId)`, `MubloAddress.open(formName, ...)` | 다음(카카오) 우편번호 주소 검색 | `CustomFieldRenderer`의 주소 필드 |
| `MubloPasswordPolicy.js` | `MubloPasswordPolicy.validate(input)` | 비밀번호 정책 실시간 검증(UX 보조) | `src/Service/Member/PasswordPolicy.php` |
| `MubloTracking.js` | `MubloTracking.trackConversion(type, params)` | 외부 픽셀 전환 이벤트 전송 | Head.php의 픽셀 SDK 로드(26장 상호참조) |

- **MubloCore** — 포맷팅·숫자·날짜 순수 함수만 담는다(입력 → 출력, DOM 조작 없음). `MubloForm`의 입력 마스크가 내부적으로 사용하므로 항상 먼저 로드된다. 대표 패턴: `MubloCore.numberFormat(1234567)` → `'1,234,567'`.
- **MubloForm** — `fill(formData, 'formData', formId)`로 서버가 내려준 객체를 `formData[key]` name 규약의 입력들에 채우고, `validate(form)`은 `.require` 클래스 + `data-type`/`data-message` 선언 기반으로 검증한다. `DOMContentLoaded`에서 입력 마스크(`mask-num`, `mask-hp`, `mask-birth`, `number`, `number-format` 클래스)와 파일 미리보기가 이벤트 위임으로 자동 초기화되므로, 스킨은 클래스만 붙이면 된다.
- **MubloModal** — 유일하게 클래스 형태다. `new MubloModal({ title, content, url, footer, onAfterOpen })` 후 `open()`. `url` 옵션을 주면 MubloRequest로 원격 콘텐츠를 로드해 응답의 `data.html`(없으면 `data`)을 본문에 넣는다. 정적 `MubloModal.confirm()`은 `Promise<boolean>`을 반환한다. CSS(`public/assets/css/components/mublo-modal.css`)는 첫 사용 시 자동 로드된다.
- **MubloItemLayout** — 서버(PHP 스킨)가 완성한 `<ul><li>` 목록에 레이아웃만 적용한다는 원칙의 산물이다(파일 상단 "설계 원칙" 주석). `.mublo-item-layout` 컨테이너에 `data-pc-style`/`data-mo-style`(`list`|`slide`), `data-pc-cols`/`data-mo-cols`, `data-swiper`(JSON)를 선언하면 자동 초기화되고, slide 모드는 전역 `Swiper`가 있을 때만 동작한다. 블록 스킨의 아이템 목록 출력이 주 사용처이며, 관리자 블록 미리보기(`views/Admin/Blockrow/Index.php`)도 이 파일을 로드한다.
- **MubloCustomField** — `.custom-field-file` 입력에 change 리스너를 자동 바인딩해 파일을 즉시 업로드하고, 결과 메타를 hidden 입력(`{prefix}{fieldId}_meta`)에 JSON으로 저장한다. 개발자가 직접 로드하지 않는다 — `CustomFieldRenderer::renderFileScript($uploadUrl)`(`src/Service/CustomField/CustomFieldRenderer.php`)이 `<script>` 태그와 `MubloCustomField.setUploadUrl(...)` 호출을 함께 출력한다.
- **MubloAddress** — 카카오 우편번호 스크립트를 필요 시점에 동적 로드한다. `search(fieldId)`는 `field_{id}_zipcode` / `field_{id}_address1` / `field_{id}_address2` ID 규약(커스텀 필드 주소 타입이 만드는 마크업)을 자동으로 채우고, `open(formName, zipField, addr1Field, addr2Field, ...)`은 임의 폼에 쓰는 범용 버전이다.
- **MubloPasswordPolicy** — 서버 `PasswordPolicy`(PHP)가 authoritative이고 이 스크립트는 규칙을 1:1 미러링한다(파일 상단 주석 원문). `<input data-pw-min="8" data-pw-lower="1" ...>`처럼 서버가 출력한 `data-pw-*` 속성을 읽어 `{ valid, message }`만 반환한다 — 메시지 표시(위치·색·시점)는 스킨의 몫이다. 기본 스킨의 사용례는 `views/Front/Member/basic/Register.php`, `views/Front/Mypage/basic/Profile.php`, `views/Front/Auth/basic/ResetPassword.php`.
- **MubloTracking** — `trackConversion('purchase', { value: 50000, currency: 'KRW' })` 한 번으로 GA4·Meta Pixel·카카오 픽셀·네이버 애널리틱스에 전환 이벤트를 동시 전송한다(각 SDK가 로드된 것만 동작). 픽셀 SDK 자체는 `views/Front/frame/basic/Head.php`가 SEO 설정의 픽셀 ID 기준으로 로드한다. Mublo 서버로는 아무것도 전송하지 않으며, 코어에 호출부가 없다 — 전환 시점을 아는 것은 스킨·확장이므로 호출 책임도 그쪽에 있다. 전송 후 `mublo:conversion` CustomEvent를 window에 발행하므로 외부 JS(히트맵, CRM 등)가 구독할 수 있다. 서버 측 방문·전환 통계는 별개 경로다([26. 통계·트래킹](26-tracking.md)).

## 스킨·확장에서 쓰는 법

### 로드 경로

기본 프레임 스킨이 대부분을 이미 로드한다. `views/Front/frame/basic/Head.php`는 8종(`MubloCore`, `MubloRequest`, `MubloForm`, `MubloModal`, `MubloAddress`, `MubloPasswordPolicy`, `MubloTracking`, `MubloItemLayout`)을 `defer`로, `views/Admin/frame/basic/Head.php`는 5종(`MubloCore`, `MubloRequest`, `MubloModal`, `MubloForm`, `MubloAddress`)을 로드한다. 따라서 기본 프레임 위에서 도는 콘텐츠 스킨·블록 스킨은 로드를 신경 쓸 필요 없이 전역 객체를 바로 쓰면 된다. 예외는 `MubloCustomField.js` 하나로, 커스텀 필드 파일 입력이 있는 페이지에서 `CustomFieldRenderer::renderFileScript()`가 필요 시점에 출력한다.

프레임 스킨을 직접 만드는 경우에는 `asset()` 헬퍼로 태그를 쓴다.

```php
<script defer src="<?= asset('/assets/js/MubloRequest.js') ?>"></script>
```

`asset()`(`src/Helper/EnvHelpers.php`)은 `AssetManager::versionedPath()`를 호출해 파일 수정시각 기반 캐시버스팅 쿼리(`?<filemtime>`)를 붙인다 — `AssetManager::addCss/addJs`와 동일한 버전 로직이므로, AssetManager를 거치지 않고 Head/Footer에 직접 태그를 쓰는 프레임 스킨 자산에 쓰라고 만든 헬퍼다(메서드 주석 원문). 렌더링 파이프라인과 AssetManager 전반은 [18. 테마·스킨·렌더링](18-theme-rendering.md) 참조.

### 전역 네임스페이스 관례

- 라이브러리 하나 = 전역 이름 하나, 접두사는 `Mublo`. 그 외 전역을 오염시키지 않는다.
- 초기화 호출이 필요 없다 — 각 라이브러리가 `DOMContentLoaded`에서 스스로 초기화한다(MubloRequest의 버튼 위임, MubloForm의 입력 마스크, MubloItemLayout·MubloCustomField의 자동 바인딩).
- 동적으로 삽입된 DOM도 대부분 이벤트 위임으로 처리되지만, MubloItemLayout과 MubloCustomField는 삽입 후 `MubloItemLayout.init(el)` / `MubloCustomField.initFileUploads()`를 수동 호출해야 한다.

확장이 자체 스크립트를 추가할 때는 이 관례를 따라 자기 전역 하나만 만들고, 서버 통신은 `fetch` 직접 호출 대신 MubloRequest를 쓰는 것이 표준이다 — CSRF·재시도·에러 UX를 공짜로 얻는다.

### 동작 흐름 예제 — 검증 후 제출

여러 라이브러리가 결합하는 전형적인 흐름은 "MubloForm으로 검증 → MubloRequest로 전송 → MubloModal로 안내"다.

```html
<form id="inquiryForm">
    <input name="email" class="require" data-type="email"
           data-message="이메일을 입력하세요.">
    <input name="phone" class="mask-hp"><!-- 입력 마스크 자동 적용 -->
    <button type="button" id="btn-save">저장</button>
</form>
```

```javascript
document.getElementById('btn-save').addEventListener('click', async function () {
    var form = document.getElementById('inquiryForm');
    if (!MubloForm.validate(form)) return;            // .require 선언 기반 검증

    var ok = await MubloModal.confirm('저장하시겠습니까?');
    if (!ok) return;

    var data = MubloForm.toObject(new FormData(form)); // FormData → 객체
    MubloRequest.requestJson('/inquiry/save', data, { loading: true })
        .then(function (res) {                          // 도달 = 성공 확정
            MubloModal.alert(res.message);
        });                                             // 에러 alert는 자동
});
```

서버는 `JsonResponse::success()` / `JsonResponse::error()`만 반환하면 되고, CSRF·재시도·에러 표시는 전부 클라이언트 계층이 처리한다. 검증·확인 다이얼로그까지 선언형으로 충분하다면 JS 없이 `.mublo-submit` + `data-target` + `data-confirm` 버튼 하나로도 같은 흐름을 만들 수 있다.

### Best Practice / Anti Pattern

- **권장**: 서버 통신은 항상 MubloRequest 경유. 입력 마스크·검증·레이아웃은 클래스/`data-*` 선언으로 해결하고, 직접 구현은 선언으로 안 되는 경우에만.
- **금지**: `public/assets/js/admin/*` 의존, `fetch` 직접 호출로 CSRF 토큰 수동 관리(불가피한 경우에만 `getCsrfToken()` 사용), `.then()` 안에서 `res.result` 재확인, options의 `onSuccess`/`onError` 콜백(무시된다).
- **주의**: 클라이언트 검증 통과를 서버 검증 생략의 근거로 삼지 않는다 — MubloPasswordPolicy 주석의 표현대로 서버가 authoritative다.

## 경계

이 라이브러리들은 `docs/compatibility-policy.md`의 안정 API 목록에 **등재돼 있지 않다**. 그 문서와 CI 도구(`tools/check-extension-api.php`)는 PHP 심볼만 다루며, 클라이언트 JS의 호환성 정책은 아직 없다.

현재 상태를 정직하게 말하면 "정책 미등재, 사실상 표준"이다. 기본 프레임 스킨이 전 페이지에 로드하고, 코어 서비스(`CustomFieldRenderer`)가 직접 출력하며, 상세 가이드(`docs/dev-guide/client-ajax.md`)가 사용을 안내하는 표면이므로 스킨·확장이 의존해도 되는 실질적 표준이다. 다만 CI가 강제하는 보장은 없으므로, 문서화된 공개 API(각 파일 상단 주석의 API 목록과 이 장의 표)만 쓰고 내부 함수나 DOM 구조 세부에는 의존하지 않는 것이 안전하다.

`public/assets/js/admin/` 아래는 위치와 무관하게 내부 구현이다. 스킨·확장이 `BlockRowForm`이나 `BlockHtmlEditor`에 의존해서는 안 된다.

## 관련 문서

- [클라이언트 AJAX 시스템](../dev-guide/client-ajax.md) — MubloRequest·MubloModal 상세 사용법
- [07. Response](07-response.md) — JsonResponse를 포함한 Response 계층
- [10. 인프라 서비스](10-infrastructure.md) — 서버 측 CSRF 검증
- [17. 블록 시스템](17-block-system.md) — MubloItemLayout이 전제하는 블록 스킨 마크업
- [18. 테마·스킨·렌더링](18-theme-rendering.md) — AssetManager와 프레임 스킨
- [19. 회원·커스텀 필드](19-member-custom-fields.md) — CustomFieldRenderer와 커스텀 필드 규약
- [26. 통계·트래킹](26-tracking.md) — 서버 측 방문·캠페인·전환 통계
