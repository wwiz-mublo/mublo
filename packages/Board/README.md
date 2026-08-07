# Mublo Board 패키지

Mublo Board는 Mublo Framework의 기본 게시판 패키지입니다.

단순 글 목록을 넘어서 게시판 그룹, 게시판별 권한, 카테고리, 댓글, 첨부파일, 리액션, 포인트 정책, 블록 연동, 마이페이지 연동을 제공하는 실무형 게시판 모듈입니다.

## 주요 기능

- **게시판 관리**: 게시판 생성/수정, 목록/쓰기/보기 권한, 스킨 설정
- **게시판 그룹**: 여러 게시판을 그룹으로 묶고 커뮤니티 화면 구성
- **카테고리**: 게시판별 카테고리 관리
- **게시글**: 공지, 비밀글, 조회수, 첨부파일, 비회원 작성 지원
- **댓글**: 중첩 댓글, 비밀 댓글, 비회원 댓글
- **리액션**: 좋아요 등 반응 기능
- **포인트 정책**: 글 작성, 댓글, 조회 등 게시판별 포인트 정책
- **마이페이지 연동**: 내가 쓴 글/댓글 목록 공급
- **검색 연동**: 통합 검색 소스와 결과 공급
- **블록 연동**: 최신글, 게시판 그룹 등 블록 콘텐츠 타입 제공
- **관리자 메뉴 연동**: Event 기반으로 관리자 메뉴에 게시판 관리 항목 추가
- **회원 액션 연동**: 글·댓글 작성자 옆에 Core 회원 액션 메뉴 제공

## Mublo 확장 구조와의 관계

Board는 Mublo의 Package 구조를 보여 주는 대표 예시입니다.

| 확장 지점 | Board에서의 사용 |
|-----------|------------------|
| Provider | `BoardProvider.php`에서 서비스, 이벤트, 블록 등록 |
| Routes | `routes.php`에서 프론트/관리자 라우트 정의 |
| Migration | `database/migrations`로 게시판 테이블 생성 |
| Event | 관리자 메뉴, 도메인 생성, 검색, 마이페이지, 블록 아이템 공급 |
| Member Action | Core Contract로 작성자 액션을 조회하는 소비자 역할 |
| Block | 게시판/게시판 그룹 블록 렌더러 제공 |
| Service | 게시글, 댓글, 권한, 파일, 포인트 정책 처리 |
| Repository | 게시판/게시글/댓글/그룹 데이터 접근 |

## 설치

1. `packages/Board` 디렉토리가 존재하는지 확인합니다.
2. 관리자 > 확장 관리에서 Board 패키지를 활성화합니다.
3. 패키지 마이그레이션이 실행되어 게시판 관련 테이블이 생성됩니다.
4. 관리자 메뉴에서 게시판 설정을 진행합니다.

설치 후 주요 진입점:

- 관리자 게시판 설정: `/admin/board/config`
- 관리자 게시글 관리: `/admin/board/article`
- 프론트 게시판: `/board/{board_id}`
- 커뮤니티: `/community`

## 주요 라우트

### Front

| Method | Path | 설명 |
|--------|------|------|
| GET | `/board/{board_id}` | 게시글 목록 |
| GET | `/board/{board_id}/view/{post_no}` | 게시글 보기 |
| GET | `/board/{board_id}/write` | 게시글 작성 화면 |
| POST | `/board/{board_id}/write` | 게시글 저장 |
| POST | `/board/{board_id}/comment` | 댓글 작성 |
| POST | `/board/{board_id}/reaction` | 리액션 처리 |
| POST | `/board/{board_id}/password-check` | 비회원 글 비밀번호 확인 |
| GET | `/community` | 커뮤니티 메인 |

### Admin

| Method | Path | 설명 |
|--------|------|------|
| GET | `/admin/board/config` | 게시판 설정 |
| GET | `/admin/board/group` | 게시판 그룹 관리 |
| GET | `/admin/board/article` | 게시글 관리 |
| GET | `/admin/board/category` | 카테고리 관리 |
| GET/POST | `/admin/board/point` | 게시판 포인트 정책 |

## 디렉토리 구조

```text
packages/Board/
├── Block/                 # 블록 렌더러와 설정 폼
├── Controller/
│   ├── Admin/             # 관리자 컨트롤러
│   └── Front/             # 프론트 컨트롤러
├── Entity/                # BoardArticle, BoardComment 등
├── Enum/                  # 게시글 상태 등 Enum
├── Helper/                # ArticlePresenter 등 표시 보조
├── Repository/            # 게시판 데이터 접근
├── Service/               # 게시판 비즈니스 로직
├── Subscriber/            # 이벤트 구독자
├── database/
│   └── migrations/        # DB 마이그레이션 SQL
├── views/
│   ├── Admin/             # 관리자 뷰
│   ├── Block/             # 블록 스킨
│   └── Front/             # 프론트 게시판 스킨
├── BoardProvider.php
├── manifest.json
└── routes.php
```

## 블록 연동

Board는 블록 시스템과 연결되어 페이지 빌더에서 게시판 콘텐츠를 배치할 수 있습니다.

대표 블록:

- 게시판 최신글
- 게시판 그룹
- 커뮤니티 피드

운영자는 관리자에서 게시판 또는 게시판 그룹을 선택하고, 개발자는 스킨을 추가해 출력 방식을 바꿀 수 있습니다.

## 이벤트 연동

Board는 Core와 직접 결합하지 않고 이벤트를 통해 필요한 지점에 연결됩니다.

대표 연결:

- `AdminMenuBuildingEvent`: 관리자 게시판 메뉴 추가
- `DomainCreatedEvent`: 새 도메인 생성 시 기본 게시판 데이터 준비
- `SearchEvent`: 통합 검색 결과 공급
- `SearchSourceCollectEvent`: 검색 가능한 게시판 소스 공급
- `MypageSectionBuildingEvent`: 마이페이지 내가 쓴 글/댓글 섹션 공급
- `BlockContentItemsCollectEvent`: 블록 편집기의 게시판 아이템 목록 공급

## 회원 액션 연동

Board는 글·댓글 작성자 메뉴의 **소비자**입니다. `MemberActionQueryInterface`로
`board.article_author`, `board.comment_author`, `member.author` 위치의 액션을 조회하고,
Core의 `memberActionMenu()`로 렌더링합니다.

쪽지·팔로우·공개 프로필 같은 기능의 구현 클래스나 URL은 Board가 알지 않습니다.
해당 Plugin이 액션을 등록하면 메뉴에 나타나고, 비활성화하면 Board 수정 없이 사라집니다.
타인 식별에는 `author_public_id`만 사용하며 내부 `member_id`나 로그인 아이디를 HTML에
출력하지 않습니다. 이 기능은 `MemberActionQueryInterface` 를 제공하는 Core 를 요구합니다.

## 권한 모델

게시판별로 다음 접근 정책을 구성할 수 있습니다.

- 목록 보기 권한
- 글 보기 권한
- 글 작성 권한
- 댓글 작성 권한
- 관리자 전용 게시판
- 비밀글/비밀댓글 사용 여부
- 비회원 작성 허용 여부

권한 판단은 Service 계층에서 처리하고, Controller는 요청을 받아 Service 결과를 Response로 변환합니다.

## 개발 참고

- Core 코드를 수정하지 않고 `BoardProvider`에서 서비스를 등록합니다.
- 관리자 메뉴, 검색, 마이페이지, 블록 아이템 공급은 이벤트 구독자로 연결합니다.
- 게시판 화면은 스킨 구조를 통해 교체할 수 있습니다.
- DB 변경은 `database/migrations`에 SQL 파일로 추가합니다.

## Board Extension API

Board 종속 Plugin은 `Service`, `Repository`, `Entity`, `Helper`, DB 테이블을 직접 사용하지 않습니다. 다음 공개 표면만 장기 호환 대상으로 취급합니다.

- `Contract/Extension/BoardExtensionApiInterface.php`: 공개 API 진입점
- `Contract/Extension/BoardArticleReaderInterface.php`: 현재 도메인 및 전역 게시판 정책이 적용된 게시글 조회
- `Contract/Extension/BoardArticleCommandInterface.php`: 게시글 변경 명령
- `Api/DTO/ArticleSnapshot.php`: 내부 Entity 대신 전달되는 readonly 값 객체
- `Event/`: Plugin 개입을 위해 공개된 Board Event

Provider에서는 `BoardExtensionApiInterface`를 주입받아 사용합니다.

```php
$container->singleton(MyBoardPluginService::class, fn($c) =>
    new MyBoardPluginService($c->get(BoardExtensionApiInterface::class))
);
```

Manifest v1에는 부모와 지원하는 Board 버전 범위를 함께 선언합니다.

```json
{
    "type": "plugin",
    "parent": "Board",
    "requires": {
        "core": ">=1.0.0 <2.0.0",
        "package:Board": ">=1.0.0 <2.0.0"
    }
}
```

현재 호환성은 Board Package 버전으로 관리합니다. 별도의 Extension API 버전과 Manifest v2는 Package 버전과 API 버전을 독립적으로 운영할 필요가 생길 때 도입합니다. 실제 구현 예시는 `Plugins/BoardReport`를 참고하십시오.

`findAccessibleById()`가 반환하는 전역 게시판 글은 현재 도메인에서 읽고 신고할 수 있다는 뜻이며 변경 권한을 부여하지 않습니다. 삭제 같은 쓰기 명령은 `BoardArticleCommandInterface` 뒤의 기존 Board 권한과 전역 게시판 쓰기 정책을 다시 통과합니다. BoardReport의 신고·블라인드 데이터는 조치를 실행한 현재 도메인에 귀속됩니다.

### 공식 Board Event

| Event | 발생 시점 | Extension 용도 |
|---|---|---|
| `ArticleActionsCollectEvent` | 게시글 상세의 동작 버튼을 수집할 때 | 신고·북마크 등 사용자 동작 추가 |
| `ArticleViewingEvent` | 게시글을 사용자에게 노출하기 직전 | 블라인드·접근 정책 적용 |
| `ArticleViewedEvent` | 게시글 조회가 완료된 뒤 | 통계·감사 후처리 |
| `ArticleCreatingEvent` / `ArticleUpdatingEvent` | 게시글 저장 직전 | 검증·정규화 |
| `ArticleCreatedEvent` / `ArticleUpdatedEvent` | 게시글 저장 완료 뒤 | 알림·색인·외부 연동 |
| `ArticleDeletingEvent` / `ArticleDeletedEvent` | 게시글 삭제 전·후 | 삭제 차단 또는 Plugin 데이터 정리 |
| `CommentCreatingEvent` / `CommentCreatedEvent` | 댓글 저장 전·후 | 검증·알림 |
| `CommentDeletedEvent` | 댓글 삭제 완료 뒤 | Plugin 데이터 정리 |

Event의 발생 시점과 의미는 안정 계약입니다. 기존 getter는 한 major version 동안 유지하고 payload 확장은 가능한 한 새 getter를 추가하는 방식으로 처리합니다. 현재 일부 Event가 내부 Entity를 전달하는 부분은 하위 호환을 유지하며 향후 readonly Snapshot으로 점진 전환합니다.

## 라이선스

MIT License
