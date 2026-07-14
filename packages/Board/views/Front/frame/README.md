# Board 패키지 프레임 스킨 (오버라이드)

이 디렉터리는 **패키지 프레임 스킨** 배선의 스캐폴드입니다. 지금은 비어 있고(스킨 없음),
그러면 Board 프론트도 **코어 프레임 그대로** 사용합니다.

## 프레임 스킨 추가 방법

1. 이 폴더 아래에 스킨 디렉터리를 만들고 프레임 파트를 넣습니다:

   ```
   packages/Board/views/Front/frame/{스킨명}/
       Head.php  Header.php  LayoutOpen.php  LayoutClose.php  Footer.php  Foot.php
   ```
   (일부만 둬도 됩니다 — 없는 파트는 코어 프레임으로 per-file 폴백)

2. 관리자에서 그 `{스킨명}`을 선택합니다.
   - 저장 위치: `domain_configs.theme_config.frame_overrides.packages.board` (코어 `frame` 키는 불침범)

## 상태 (현재 스캐폴드만)

- **폴더 스캐폴드만 존재.** 런타임 적용(Provider의 `applyFrameOverride`)과 관리자 프레임 스킨 드롭다운은 **아직 미배선.**
- **구현 레퍼런스 = Shop 패키지**: `ShopProvider::applyFrameOverride()` (shop 프론트 영역에서 오버라이드 적용) + 쇼핑몰 설정의 "프레임 스킨" 드롭다운. Board도 동일 패턴으로 배선하면 됨.

## 배선 규격 (코어 제공)

- `Mublo\Core\Theme\FrameOverride` — theme_config 내 `frame_overrides.packages.{pkg}` read/write
- `Context::setFrameBasePath()` — 프레임 스킨 폴더 지정
- `FrontViewRenderer::includeFrameView()` — per-file 코어 폴백
- 저장은 `DomainSettingsService::updateThemeConfig()` 경유 (DomainCache 무효화 포함)
