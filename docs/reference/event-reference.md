# 이벤트 목록

## Core 이벤트

### 인증 (Auth)

| 이벤트 | 위치 | 시점 | 주요 데이터 |
|--------|------|------|-------------|
| `MemberLoggedInEvent` | Service/Auth/Event/ | 로그인 성공 후 | Member, source |
| `LoginFormRenderingEvent` | Core/Event/Auth/ | 로그인 폼 렌더링 | form mode, context |

### 회원 (Member)

| 이벤트 | 위치 | 시점 | 주요 데이터 |
|--------|------|------|-------------|
| `MemberRegisteredByUserEvent` | Service/Member/Event/ | 사용자 직접 가입 | Member, pluginData |
| `MemberRegisteredByAdminEvent` | Service/Member/Event/ | 관리자 등록 | Member, pluginData |
| `MemberUpdatedByAdminEvent` | Service/Member/Event/ | 관리자 수정 | Member(수정 전), 변경 플래그 |
| `MemberUpdatedBySelfEvent` | Service/Member/Event/ | 본인 정보 수정 | Member |
| `MemberWithdrawingEvent` | Service/Member/Event/ | 본인 탈퇴 진행 (DB 반영 전, **차단 가능** setBlocked) | Member, reason |
| `MemberWithdrawnEvent` | Service/Member/Event/ | 탈퇴 완료 (소프트삭제 커밋 후, readonly) | Member(탈퇴 전 스냅샷), reason |
| `MemberDeletingEvent` | Service/Member/Event/ | 관리자 삭제 진행 (DB 삭제 전, **차단 가능**) | Member, adminId |
| `MemberDeletedEvent` | Service/Member/Event/ | 삭제 완료 (하드삭제 후, readonly — 재조회 불가, 스냅샷 사용) | Member(삭제 전 스냅샷), adminId |
| `MemberFormRenderingEvent` | Core/Event/Member/ | 관리자 회원 폼 렌더링 | mode(create/edit), member, context |
| `MemberDataEnrichingEvent` | Core/Event/Member/ | 회원 상세 데이터 보강 | member 데이터 |
| `MemberRegisterPreparingEvent` | Core/Event/Member/ | 가입 전 Plugin 데이터 수집 | |
| `MemberRegisterValidatingEvent` | Core/Event/Member/ | 가입 검증 | |
| `RegisterFormRenderingEvent` | Core/Event/Member/ | 프론트 가입 폼 렌더링 | |
| `MemberListQueryEvent` | Core/Event/Member/ | 회원 목록 쿼리 수정 | |
| `MemberLevelListQueryEvent` | Core/Event/Member/ | 등급 목록 옵션 수집 | |

### 도메인 (Domain)

| 이벤트 | 위치 | 시점 | 주요 데이터 |
|--------|------|------|-------------|
| `DomainCreatedEvent` | Core/Event/Domain/ | 도메인 생성 후 | domainId, domainGroup, ownerId |
| `DomainUpdatedEvent` | Core/Event/Domain/ | 도메인 수정 후 | domain 데이터 |
| `DomainDeletedEvent` | Core/Event/Domain/ | 도메인 삭제 후 | domainId |
| `DomainFormRenderingEvent` | Core/Event/Domain/ | 도메인 설정 폼 렌더링 | |
| `DomainSettingsLinksEvent` | Core/Event/Domain/ | 도메인 설정 메뉴 수집 | |

### Balance (포인트)

| 이벤트 | 위치 | 시점 | 주요 데이터 |
|--------|------|------|-------------|
| `BalanceAdjustingEvent` | Core/Event/Balance/ | 잔액 변경 전 (차단 가능) | memberId, amount, currentBalance |
| `BalanceAdjustedEvent` | Core/Event/Balance/ | 잔액 변경 완료 후 | memberId, logId, newBalance |

### 블록 (Block)

| 이벤트 | 위치 | 시점 | 주요 데이터 |
|--------|------|------|-------------|
| `BlockPageRenderingEvent` | Core/Event/Block/ | 블록 페이지 렌더링 전 | pageId |
| `BlockPageCreatedEvent` | Core/Event/Block/ | 블록 페이지 생성 후 | pageId |
| `BlockPageDeletedEvent` | Core/Event/Block/ | 블록 페이지 삭제 후 | pageId |
| `BlockContentItemsCollectEvent` | Core/Event/Block/ | 블록 항목 선택 목록 수집 | |

### 렌더링 (Rendering)

| 이벤트 | 위치 | 시점 | 주요 데이터 |
|--------|------|------|-------------|
| `SiteContextReadyEvent` | Core/Event/Rendering/ | 세션 시작 후 | Context, Request |
| `RendererResolveEvent` | Core/Event/Rendering/ | Renderer 선택 | setRenderer()로 교체 가능 |
| `ViewContextCreatedEvent` | Core/Event/Rendering/ | ViewContext 초기화 후 | setHelper()로 ViewHelper 등록 |
| `FrontFootRenderEvent` | Core/Event/Rendering/ | 프론트 푸터 HTML 삽입 | |
| `PageTypeResolveEvent` | Core/Event/Rendering/ | 페이지 타입 판별 | |

### 검색 (Search)

| 이벤트 | 위치 | 시점 | 주요 데이터 |
|--------|------|------|-------------|
| `SearchEvent` | Core/Event/Search/ | 검색 실행 | keyword, sources |
| `SearchSourceCollectEvent` | Core/Event/Search/ | 검색 소스 등록 | addSource(key, label) |

### 기타

| 이벤트 | 위치 | 시점 | 주요 데이터 |
|--------|------|------|-------------|
| `RequestInterceptEvent` | Core/Event/ | 라우팅 전 | Context, Request. setResponse()로 요청 가로채기 |
| `AdminMenuBuildingEvent` | Service/Admin/Event/ | 관리자 메뉴 구성 | addMenu(), addPluginMenu(), addPackageMenu() |
| `PageViewedEvent` | Core/Event/Tracking/ | 페이지 뷰 추적 | URL, 세션 정보 |
| `SecureFileAccessEvent` | Core/Event/Storage/ | 보안 파일 접근 전 | |
| `SecureFileDownloadedEvent` | Core/Event/Storage/ | 보안 파일 다운로드 후 | |
| `MypageSectionBuildingEvent` | Core/Event/Mypage/ | 마이페이지 섹션 수집 | addSection() |

## Board 패키지 이벤트

| 이벤트 | 시점 | 주요 데이터 |
|--------|------|-------------|
| `ArticleCreatingEvent` | 글 작성 전 (차단 가능) | |
| `ArticleCreatedEvent` | 글 작성 후 | articleId, domainId |
| `ArticleUpdatingEvent` | 글 수정 전 | |
| `ArticleUpdatedEvent` | 글 수정 후 | |
| `ArticleViewingEvent` | 글 조회 전 | |
| `ArticleViewedEvent` | 글 조회 후 | |
| `ArticleDeletingEvent` | 글 삭제 전 | |
| `ArticleDeletedEvent` | 글 삭제 후 | |
| `CommentCreatingEvent` | 댓글 작성 전 | |
| `CommentCreatedEvent` | 댓글 작성 후 | |
| `CommentDeletedEvent` | 댓글 삭제 후 | |
| `FileUploadedEvent` | 파일 업로드 후 | |
| `FileDownloadingEvent` | 파일 다운로드 전 | |
| `FileDownloadedEvent` | 파일 다운로드 후 | |
| `ReactionAddedEvent` | 리액션 추가 | |
| `ReactionRemovedEvent` | 리액션 제거 | |
| `BoardConfigCreatedEvent` | 게시판 생성 후 | |
| `BoardConfigDeletedEvent` | 게시판 삭제 후 | |

## MemberFormRenderingEvent 상세

Plugin이 관리자 회원 폼에 UI를 추가하는 이벤트입니다.

```php
// Plugin Subscriber에서
public function onMemberFormRendering(MemberFormRenderingEvent $event): void
{
    if ($event->isEdit()) {
        $event->addSection('<div>추가 HTML</div>', 500);  // order=500 (기본)
        $event->addScript('<script>...</script>', 500);
    }
}

// Controller에서
$event = $dispatcher->dispatch(new MemberFormRenderingEvent('edit', $member, $context));
$sections = $event->getSectionsSorted();  // order 순 정렬
$scripts = $event->getScriptsSorted();
```

---

[< 레퍼런스 목록](README.md)
