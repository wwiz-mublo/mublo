# Shop 패키지 프레임 스킨 (오버라이드)

이 디렉터리는 **패키지 프레임 스킨** 배선의 스캐폴드입니다. 지금은 비어 있고(스킨 없음),
그러면 Shop 프론트(`/shop/*`)도 **코어 프레임 그대로** 사용합니다.

## 프레임 스킨 추가 방법

1. 이 폴더 아래에 스킨 디렉터리를 만들고 프레임 파트를 넣습니다:

   ```
   packages/Shop/views/Front/frame/{스킨명}/
       Head.php  Header.php  LayoutOpen.php  LayoutClose.php  Footer.php  Foot.php
   ```
   (일부만 둬도 됩니다 — 없는 파트는 코어 프레임으로 per-file 폴백)

2. 관리자 쇼핑몰 설정에서 그 `{스킨명}`을 선택합니다.
   - 저장 위치: `domain_configs.theme_config.frame_overrides.package.shop` (코어 `frame` 키는 불침범)

## 동작

- 선택값 있음 + 스킨 폴더 존재 → `/shop/*` 렌더 시 이 프레임 사용
- 선택 없음 / 폴더 없음 → 코어 프레임 (안전 폴백)

해석 배선: `Mublo\Core\Theme\FrameOverride` (저장 규격) + `Context::setFrameBasePath()` +
`FrontViewRenderer::includeFrameView()` (per-file 코어 폴백). Shop 적용은 `ShopProvider::applyFrameOverride()`.
