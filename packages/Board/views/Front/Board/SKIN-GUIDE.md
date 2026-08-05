# 게시판 스킨 제작 가이드

이 문서 하나만 읽고 게시판 스킨을 처음부터 만들 수 있도록 쓴다.
`packages/Board/views/Front/Board/{스킨명}/` 아래 파일 4개 + CSS 1개가 전부다.

---

## 1. 30초 요약

```
packages/Board/views/Front/Board/
    basic/              ← 기본 스킨 (표 형태). 복사 출발점
    gallery/            ← 갤러리 스킨 (썸네일 카드)
    {새스킨}/           ← 여기에 폴더를 만들면 끝. 등록 절차 없음
        List.php        ← 목록  (필수)
        View.php        ← 상세  (필수)
        Write.php       ← 작성/수정 폼 (필수)
        Password.php    ← 비회원 글 비밀번호 확인 (필수)
        _assets/css/board.css
```

- **등록 불필요.** 관리자 스킨 선택 목록은 이 디렉토리를 스캔해서 만든다
  (`BoardConfigService::getAvailableSkins()` → `DirectoryHelper::getSelectOptions`).
  폴더를 만들면 관리자 > 게시판 설정 > **게시판 스킨** 셀렉트에 자동으로 나타난다.
- **4개 파일을 세트로 만든다.** 게시판은 목록 → 상세 → 쓰기로 이어지는 하나의
  흐름이라, 화면마다 다른 스킨이 섞이면 타이포·여백·색이 튄다. 스킨은
  **화면 단위가 아니라 게시판 단위**로 완결한다.
- **파일 단위 폴백은 안전망이다.** 스킨 폴더에 파일이 없으면 `basic` 것으로
  대체되어 오류로 죽지는 않는다(`BoardController::skinView()`). 하지만 이건
  사고 방지용이지 **파일을 생략하라는 뜻이 아니다.** 폴백이 걸린 화면은
  그 스킨의 디자인이 아니라 기본 스킨이 나오므로 톤이 어긋난다.
- 스킨명은 소문자·숫자·하이픈을 쓴다(`gallery`, `card-wide`).
  그 외 문자가 섞이면 경로 조작으로 보고 `basic` 으로 되돌린다.

**만드는 법:** `basic` 폴더를 통째로 복사 → 폴더명 변경 → CSS 경로 문자열 수정(§5) → 마크업 수정.

---

## 2. 데이터 계약 — 뷰가 받는 변수

컨트롤러가 넘겨주는 값이다. **이 이름을 바꿀 수 없고, 없는 값을 지어내면 안 된다.**

### List.php (목록)

| 변수 | 타입 | 설명 |
|---|---|---|
| `$board` | array | 게시판 설정 (§2-1) |
| `$items` | array | 게시글 목록 (Presenter 변환 완료) |
| `$notices` | array | 상단 고정 공지 목록 |
| `$pagination` | array | `currentPage`, `totalItems`, `perPage`, `totalPages` |
| `$filters` | array | `keyword`, `search_field`, `category_id` |
| `$categories` | array | 카테고리 목록 (`use_category` 시) |
| `$canWrite` | bool | 글쓰기 권한 |

### View.php (상세)

| 변수 | 타입 | 설명 |
|---|---|---|
| `$article` | array | 게시글 (Presenter 변환 완료) |
| `$board` | array | 게시판 설정 |
| `$prev` / `$next` | array\|null | 이전/다음 글 |
| `$comments` | array | 댓글 목록 (§2-2) |
| `$canModify` / `$canDelete` | bool | 수정·삭제 권한 |
| `$canComment` / `$canReact` | bool | 댓글·반응 권한 |
| `$canDownload` | bool | 첨부 다운로드 권한 |
| `$isGuestArticle` | bool | 비회원 작성 글 여부 |
| `$attachments` | array | 첨부 목록 (§2-3) |
| `$links` | array | 관련 링크 목록 |

### 2-3. 첨부 항목 (`$attachments[]`)

| 키 | 타입 | 설명 |
|---|---|---|
| `download_url` | string\|null | **다운로드 주소. 이 값을 그대로 출력한다** |
| `original_name` | string | 원본 파일명 |
| `file_size` | int | 바이트 |
| `file_type` | string | 의미 단위 종류 (`image`/`pdf`/`archive`/… → 아이콘 매핑은 스킨 몫) |
| `is_image` | bool | 이미지 여부 |
| `thumb_url` | string\|null | 200px 썸네일 (이미지만) |
| `download_count` | int | 다운로드 횟수 |

> **다운로드 주소를 직접 조립하지 마라.**
>
> `public_id`·`attachment_id` 같은 식별자로 `/board/{slug}/file/download/…` 를 만들면,
> 식별자 규칙이 바뀔 때 **조용히 404가 된다.** 라우트가 hex 22자를 요구하므로 어긋난
> 주소는 매칭조차 되지 않고, 예외가 아니라서 로그에도 남지 않는다. 실제로 두 번 겪었다 —
> 마이그레이션 003 이 `attachment_id` 를 `public_id` 로 바꿨을 때 번들 스킨이 한 번,
> 갱신되지 않은 커스텀 스킨이 또 한 번.
>
> `download_url` 만 쓰면 이후 식별자가 또 바뀌어도 스킨은 손댈 필요가 없다.
>
> `download_url` 이 `null` 이면 주소를 만들 수 없는 첨부다(데이터 이상). 링크 대신
> 받을 수 없음을 표시한다 — 번들 스킨의 `--broken` 분기를 참고하라.

### Write.php (작성/수정)

| 변수 | 타입 | 설명 |
|---|---|---|
| `$board` | array | 게시판 설정 |
| `$article` | array\|null | 수정 시 기존 글 |
| `$isEdit` | bool | 수정 모드 |
| `$isLoggedIn` | bool | 로그인 여부 |

### Password.php (비회원 비밀번호 확인)

| 변수 | 타입 | 설명 |
|---|---|---|
| `$boardSlug` | string | 게시판 slug |
| `$articleId` | int | 글 번호 |
| `$board` | array | 게시판 설정 |

### 2-1. `$board` 키 — 기능 on/off 분기용

스킨은 이 값으로 **무엇을 그릴지 결정한다.** 설정이 꺼진 기능을 그리면
서버에서 막히거나 빈 영역이 남는다.

| 키 | 용도 |
|---|---|
| `board_slug` / `board_name` / `board_description` | 식별자·제목·설명 |
| `use_category` | 카테고리 필터/선택 노출 |
| `use_comment` | 댓글 영역 노출 |
| `use_file` | 첨부 UI 노출 |
| `use_link` | 링크 첨부 UI 노출 |
| `use_reaction` | 좋아요 등 반응 버튼 노출 |
| `use_secret` | 비밀글 체크박스 노출 |
| `is_secret_board` | 게시판 전체가 비밀글 (체크박스 대신 hidden 고정) |
| `allow_guest` | 비회원 작성 허용 → 이름·비밀번호 입력 노출 |
| `write_level` | 글쓰기 최소 등급 |
| `file_count_limit` | 첨부 개수 제한 (JS 검증에 사용) |
| `file_extension_allowed` | 허용 확장자 |

### 2-2. `$comments` 항목 필드

| 필드 | 설명 |
|---|---|
| `comment_id` | 댓글 ID — 수정·삭제 엔드포인트에 사용 |
| `content` | 내용 |
| `author_name` | 작성자명 |
| `member_id` | 회원 ID (비회원 null) |
| `created_at` | 작성일시 |
| `depth` | 답글 깊이 — 들여쓰기에 사용 |
| `is_secret` | 비밀 댓글 여부 |

---

## 3. Presenter 필드 — 직접 가공하지 말 것

`$items` / `$article` 은 `ArticlePresenter` 를 이미 통과했다.
**아래 필드를 그대로 쓴다.** 날짜를 다시 포맷하거나 이름을 다시 마스킹하면
게시판 설정(마스킹 정책 등)과 어긋난다.

| 필드 | 내용 |
|---|---|
| `title_safe` | `htmlspecialchars` 적용된 제목 — **다시 이스케이프하지 말 것** |
| `author_name` / `author_name_masked` | 글쓴이 (원본 / 마스킹). 이스케이프 완료 |
| `author_id` / `author_id_masked` | 아이디 (비회원은 null) |
| `is_member` | 회원 여부 |
| `url` / `edit_url` | 상세·수정 URL |
| `date_short` / `date_relative` / `date_compact` | 날짜 포맷 3종 |
| `view_count_formatted` / `comment_count_formatted` | 포맷된 통계 |
| `badges` | `['notice','secret','new']` 배열 |
| `is_new` | 24시간 이내 신규 |

> **이스케이프 규칙**: `*_safe`, `author_name*` 은 이미 처리됨. 그 외 원본 문자열
> (예: `$board['board_name']`)은 `htmlspecialchars()` 로 감싼다.

---

## 4. URL·엔드포인트 계약

프론트 프리픽스는 `/board` 다. 링크와 폼은 아래를 따른다.

| 화면/동작 | 메서드 | 경로 |
|---|---|---|
| 목록 | GET | `/board/{slug}` |
| 상세 | GET | `/board/{slug}/view/{id}` |
| 글쓰기 폼 / 저장 | GET / POST | `/board/{slug}/write` |
| 수정 폼 / 저장 | GET / POST | `/board/{slug}/edit/{id}` |
| 삭제 | POST | `/board/{slug}/delete/{id}` |
| 댓글 등록 | POST | `/board/{slug}/comment` |
| 댓글 수정·삭제 | POST | `/board/{slug}/comment/{cid}/update` · `/delete` |
| 반응(좋아요 등) | POST | `/board/{slug}/reaction` |
| 첨부 다운로드 | GET | `$att['download_url']` 을 그대로 쓴다 (§2-3) — 직접 조립 금지 |

- 검색 폼은 목록 경로로 `GET` 전송한다 (`keyword`, `search_field`).
- POST 동작(삭제·댓글·반응)은 `MubloRequest` 로 보낸다. 확인창은
  `MubloRequest.showConfirm()` 을 쓴다 — 브라우저 기본 `confirm()` 금지.

### 4-1. 작성 폼 전송 계약 (Write.php)

**여기가 가장 많이 깨지는 지점이다.** 평범한 `<form method="post">` 로 만들면
저장되지 않는다. 아래 세 가지를 반드시 지킨다.

**① 필드명은 `formData[...]` 네임스페이스를 쓴다**

버튼 클릭 시 `MubloRequest` 가 `button.closest('form')` 을 찾아
`new FormData(form)` 으로 수집한다. 따라서 **`name` 속성이 없으면 값이 전송되지 않는다.**
`id` 는 JS 제어용일 뿐 전송과 무관하다.

| 필드 | name |
|---|---|
| 글 번호(수정 시) | `formData[article_id]` (hidden) |
| 제목 | `formData[title]` |
| 본문 | `formData[content]` — 에디터 헬퍼가 생성 |
| 카테고리 | `formData[category_id]` |
| 비밀글 | `formData[is_secret]` |
| 비회원 이름 | `formData[author_name]` |
| 비회원 비밀번호 | `formData[author_password]` |
| 링크 | `formData[links][{i}][url]` · `[title]` (JS 가 hidden 으로 생성) |

**② 저장 버튼은 `.mublo-submit` + `data-*` 로 만든다**

```html
<button type="button" class="mublo-submit"
        data-target="<?= $actionUrl ?>"
        data-callback="articleSaved">등록</button>
```

`type="submit"` 이 아니라 `type="button"` 이다. `data-target` 이 전송 URL,
`data-callback` 이 응답 처리 콜백 이름이다.

**③ 응답 콜백을 등록한다**

```php
MubloRequest.registerCallback('articleSaved', function (response) {
    if (response.result === 'success') { /* response.data.redirect 로 이동 */ }
});
```

**본문 에디터는 직접 만들지 않는다.** 게시판 설정(`board_editor`)에 따라
에디터가 달라지므로 헬퍼를 쓴다.

```php
<?= editor_css() ?>                                  // <head> 영역
<?= editor_html('article_content', $content, ['name' => 'formData[content]']) ?>
<?= editor_js() ?>                                   // 문서 끝
```

---

## 5. 뷰 헬퍼

```php
$this->assets->addCss('/serve/package/Board/views/Front/Board/{스킨명}/_assets/css/board.css');
$this->assets->addJs('...');            // 필요할 때만
echo $this->pagination($pagination);    // 페이지네이션 — 직접 그리지 말 것
$this->getQueryString();                // 목록 복귀용 쿼리 유지
```

> ⚠ **복사 후 반드시 CSS 경로의 스킨명을 바꾼다.** `basic` 을 복사하면 경로가
> `.../Board/basic/_assets/...` 로 남아 원본 CSS 를 로드한다. 스킨을 고쳤는데
> 화면이 그대로인 대부분의 원인이 이것이다.

---

## 6. 스타일 규칙

- **클래스 접두사**를 스킨 단위로 통일한다 (`basic` 은 `board-list__*`,
  `board-view__*`, `board-comment__*`). 새 스킨은 자체 접두사를 쓰되
  한 스킨 안에서는 일관되게 간다.
- **색은 토큰만 소비한다.** `var(--primary)`, `var(--foreground)`,
  `var(--border)`, `var(--muted-foreground)`, `var(--card)` 등.
  브랜드색을 하드코딩하면 프레임 스킨·다크모드를 따라가지 못한다.
- **폭을 하드코딩하지 않는다.** `max-width` 를 걸지 말고 프레임 콘텐츠 폭
  설정을 따른다. 좁은 가독 폭이 꼭 필요하면 본문 등 *내부 요소*에만 건다.
- **반응형은 필수다.** 최소 한 개 이상의 브레이크포인트를 두고, 표 기반
  목록은 모바일에서 열을 줄이거나 카드로 전환한다.
- 부트스트랩에 의존하지 않는다. 프론트 스킨은 자체 CSS 로 완결한다.

---

## 7. 지켜야 할 동작

마크업과 디자인은 자유지만, 아래가 빠지면 게시판이 망가진다.

- [ ] **공지(`$notices`)를 목록 상단에 별도로 출력** — `$items` 와 섞지 않는다.
- [ ] **`badges`(공지/비밀글/신규) 표시** — 비밀글 표시가 없으면 사용자가 오인한다.
- [ ] **비밀글 처리** — 권한 없는 비밀글은 제목만 보이고 클릭 시 `Password.php` 로 간다.
- [ ] **권한 플래그를 존중** — `$canWrite`/`$canModify`/`$canDelete`/`$canComment`
      가 false 면 해당 버튼을 숨기거나 비활성화한다. **UI 로만 막고 끝내지 말 것**
      (서버도 검사하지만, 노출 자체가 혼란을 준다).
- [ ] **카테고리 필터** — `$board['use_category']` 가 켜져 있으면 `$categories` 노출.
- [ ] **검색 폼** — `keyword` + `search_field` 유지.
- [ ] **페이지네이션** — `$this->pagination()` 사용.
- [ ] **빈 상태** — 글이 0건일 때 안내 문구를 낸다.
- [ ] **첨부·댓글·반응** — `use_file`/`use_comment`/`use_reaction` 설정에 따라 조건부 출력.

---

## 8. 작업 순서 (권장)

1. `basic/` 를 새 폴더명으로 복사한다.
2. 4개 파일의 상단 주석에서 스킨명을 바꾼다.
3. `_assets/css/board.css` 로드 경로의 스킨명을 바꾼다 (§5 경고 참고).
4. `List.php` 부터 마크업을 바꾼다. **데이터 접근 코드는 건드리지 말고
   HTML 구조와 클래스만 교체**하는 것이 안전하다.
5. `View.php` → `Write.php` → `Password.php` 순으로 진행한다.
6. CSS 를 새 클래스에 맞춰 다시 쓴다.
7. 관리자 > 게시판 설정에서 새 스킨을 선택해 확인한다.

---

## 9. 검수 체크리스트

- [ ] 4개 파일이 모두 있다 (없으면 `basic` 으로 폴백돼 그 화면만 톤이 어긋난다)
- [ ] CSS 로드 경로가 **새 스킨명**을 가리킨다
- [ ] `php -l` 로 4개 파일 문법 통과
- [ ] 목록: 공지·배지·카테고리·검색·페이지네이션·빈 상태 동작
- [ ] 상세: 이전/다음 글, 첨부, 댓글, 반응, 목록 복귀 링크
- [ ] 작성: 신규/수정 양쪽, 비회원 작성 시 이름·비밀번호 입력
- [ ] 비회원 비밀번호 확인 화면 동작
- [ ] 권한 없는 계정으로 열어 버튼이 감춰지는지
- [ ] 모바일 폭(≤575px)에서 레이아웃이 깨지지 않는지
- [ ] 다크모드에서 색이 뭉개지지 않는지 (토큰만 썼다면 자동)

---

## 10. 흔한 실패

| 증상 | 원인 |
|---|---|
| 스킨을 고쳤는데 화면이 그대로 | CSS 로드 경로가 `basic` 을 가리킴 (§5) |
| 특정 화면만 기본 스킨 디자인으로 나옴 | 그 파일을 안 만들어 `basic` 으로 폴백된 것 |
| 제목에 `&lt;` 가 그대로 보임 | `title_safe` 를 다시 `htmlspecialchars` 함 |
| 날짜·이름 형식이 다른 게시판과 다름 | Presenter 필드 대신 원본을 직접 가공함 |
| 브랜드색이 안 따라옴 | 색을 하드코딩함 — 토큰(`var(--primary)`)을 쓸 것 |
| 관리자 셀렉트에 안 보임 | 폴더 위치가 틀림 — `views/Front/Board/` 바로 아래여야 함 |
