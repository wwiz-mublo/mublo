# 확장 포인트

Plugin/Package가 Core에 기능을 추가할 수 있는 모든 지점의 목록입니다.

## Provider 생명주기

| 메서드 | 시점 | 용도 |
|--------|------|------|
| `register()` | Context 생성 전 | DI 컨테이너에 서비스 등록 |
| `boot()` | Context 생성 후 | 이벤트 구독, 블록/위젯 등록, Contract 바인딩 |
| `install()` | 최초 활성화 | 마이그레이션 실행, 기본 데이터 시딩 |
| `uninstall()` | 비활성화 | 메뉴 항목 제거 등 (DB 보존) |

## 이벤트 기반 확장

### 관리자 메뉴

```php
// AdminMenuBuildingEvent
$event->addPluginMenu('메뉴명', 'bi-icon', [...]);   // 독립 메뉴
$event->addPackageMenu('메뉴명', 'bi-icon', [...]);   // 독립 메뉴
$event->addSubmenuTo('003', [...]);                    // 기존 메뉴에 하위 추가
$event->insertSubmenuAfter('003', '003_001', [...]);   // 특정 위치 뒤에 삽입
$event->insertSubmenuBefore('003', '003_001', [...]);  // 특정 위치 앞에 삽입
```

### 회원 폼 확장

```php
// MemberFormRenderingEvent (관리자 회원 생성/수정 폼)
$event->addSection('<div>추가 HTML</div>', $order);
$event->addScript('<script>...</script>', $order);

// RegisterFormRenderingEvent (프론트 회원가입 폼)
// 동일 패턴
```

### 회원 데이터 보강

```php
// MemberDataEnrichingEvent
// 회원 상세 조회 시 추가 데이터 삽입
```

### 렌더러 교체

```php
// RendererResolveEvent
$event->setRenderer(new MyCustomRenderer());
// 자동으로 stopPropagation()
```

### 사이트 정보 재정의

```php
// SiteContextReadyEvent (세션 시작 후)
$context->setSiteImageUrl('logo_pc', '/path/to/logo.png');
$context->setSiteLogoText('커스텀 로고');
```

### 요청 가로채기

```php
// RequestInterceptEvent (라우팅 전)
$event->setResponse(RedirectResponse::to('/login'));
// 사이트 접근 제어, 로그인 강제 등
```

### ViewHelper 등록

```php
// ViewContextCreatedEvent
$viewContext->setHelper('shop', new ShopViewHelper());
// 뷰에서 $this->shop->method() 형태로 사용
```

### 프론트 푸터 삽입

```php
// FrontFootRenderEvent
$event->addHtml('<script src="..."></script>');
// 프론트 </body> 직전에 HTML/JS 삽입
```

### 검색 소스 등록

```php
// SearchSourceCollectEvent
$event->addSource('mypackage', '내 패키지');

// SearchEvent
if ($event->isTargetSource('mypackage')) {
    $event->addResults($results);
}
```

### 도메인 설정 메뉴

```php
// DomainSettingsLinksEvent
$event->addLink('내 설정', '/admin/myplugin/domain-settings');
```

### 도메인 생성 시 시딩

```php
// DomainCreatedEvent
// 새 도메인 생성 시 기본 데이터 삽입
```

### 도메인 소유자 검증 정책

```php
// DomainOwnerValidatingEvent
// 코어 기본 검증(회원 존재·운영 권한·그룹 범위) 통과 후 발행.
// 운영 수 제한 같은 상용/임대 정책은 패키지가 여기서 addError()로 거부한다.
// 코어 자체는 소유 사이트 수를 제한하지 않는다.
public function limitOwnedSites(DomainOwnerValidatingEvent $event): void
{
    if ($this->countOwnedSites($event->getMemberId()) >= $this->planLimit()) {
        $event->addError('현재 요금제에서는 더 이상 사이트를 만들 수 없습니다.');
    }
}
```

### 마이페이지 콘텐츠

```php
// MypageSectionBuildingEvent
// 마이페이지에 패키지별 콘텐츠 섹션 추가
```

## Contract 기반 확장

### 1:1 바인딩

| Contract | 용도 | 등록 |
|----------|------|------|
| `FaqQueryInterface` | FAQ 데이터 조회 | `bind()` |
| `IdentityVerificationInterface` | 본인인증 | `bind()` |
| `CachePurgerInterface` | CDN 캐시 퍼지 | `bind()` |

### 1:N 등록

| Contract | 용도 | 등록 |
|----------|------|------|
| `PaymentGatewayInterface` | 결제 게이트웨이 | `register(contract, key, impl, meta)` |
| `NotificationGatewayInterface` | 알림 발송 | `register(contract, key, impl, meta)` |
| `CategoryProviderInterface` | 카테고리 트리 | `register(contract, key, impl, meta)` |

### 직접 구현 (Registry 불필요)

| Interface | 용도 |
|-----------|------|
| `DataResettableInterface` | 데이터 초기화 (Provider가 노출하고 전용 Resetter에 위임) |
| `DataResetFilesystemInterface` | DB 커밋 후 파일 초기화 (선택 계약) |

## 블록 시스템 확장

```php
// BlockRegistry::registerContentType()
BlockRegistry::registerContentType(
    type: 'myblock',
    kind: BlockContentKind::PLUGIN->value,
    title: '내 블록',
    rendererClass: MyRenderer::class,
    options: [...]
);
```

options로 설정 가능한 것: 항목 선택 UI, 디스플레이 스타일, 스킨 경로, 관리자 JS, 캐시 비활성화

## 대시보드 위젯 확장

```php
// DashboardWidgetRegistry — ID 접두사로 출처가 판별된다:
// 'core.*' = 코어, 'plugin.*' = 플러그인, 그 외 = 패키지
$registry->register('plugin.mywidget', new MyWidget(), $priority);   // 플러그인
$registry->register('shop.sales_today', new SalesWidget(), $priority); // 패키지
```

## CSRF 제외 경로

```php
// PG 콜백 등 CSRF 검증 제외
CsrfMiddleware::addExcludePath('/pg-callback/');
```

## 에셋 접근 (Plugin/Package)

Plugin/Package의 정적 파일은 ServeController가 중계합니다.

```
/serve/plugin/{Name}/assets/{path}     # Plugin 에셋
/serve/package/{Name}/assets/{path}    # Package 에셋
/serve/block/{type}/{skin}/{path}      # 블록 스킨 에셋
/serve/admin/{skin}/{path}             # 관리자 스킨 에셋
/serve/front/{skin}/{path}             # 프론트 스킨 에셋
```

---

[< 레퍼런스 목록](README.md)
