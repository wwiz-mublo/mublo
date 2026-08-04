# 블록 편집 개요

Mublo의 블록 시스템은 운영자가 코드를 수정하지 않고 페이지를 구성할 수 있게 하는 편집 계층입니다.

개발자는 Plugin/Package에서 콘텐츠 타입과 출력 아이템을 제공하고, 운영자는 관리자에서 이를 조합합니다.

## 기본 구조

```text
BlockPage
  └── BlockRow
        └── BlockColumn
              └── Content Type
                    └── Items
```

- **BlockPage**: 하나의 페이지입니다.
- **BlockRow**: 페이지 안의 가로 행입니다.
- **BlockColumn**: 행 안의 열입니다.
- **Content Type**: HTML, 게시판, 배너, 상품 같은 출력 종류입니다.
- **Items**: 콘텐츠 타입이 출력할 구체적인 대상입니다.

## 운영자 관점

운영자는 관리자에서 다음을 선택합니다.

- 페이지 레이아웃
- 행과 열 구성
- 콘텐츠 타입
- 스킨
- 출력할 아이템
- 제목, 여백, 배경, 정렬 같은 표시 옵션

예를 들어 메인 페이지에 다음 구성을 만들 수 있습니다.

- 상단: 배너 슬라이드
- 중단: 최신 공지 게시글
- 중단: 추천 상품
- 하단: FAQ 또는 설문

이 과정은 코드 배포 없이 관리자에서 처리할 수 있습니다.

## 개발자 관점

개발자는 블록 콘텐츠 타입을 등록합니다.

```php
BlockRegistry::registerContentType(
    type: 'board',
    kind: BlockContentKind::PACKAGE->value,
    title: '게시판 최신글',
    rendererClass: BoardRenderer::class,
    configFormClass: BoardConfigForm::class,
    options: [
        'hasItems' => true,
        'hasStyle' => true,
        'skinBasePath' => MUBLO_PACKAGE_PATH . '/Board/views/Block/',
    ]
);
```

콘텐츠 타입은 보통 다음 요소를 가집니다.

| 요소 | 역할 |
|------|------|
| Renderer | 실제 HTML 렌더링 |
| Config Form | 관리자 설정 UI |
| Items Provider | 선택 가능한 출력 아이템 공급 |
| Skin | 프론트 출력 템플릿 |
| Admin Script | 관리자 설정 UI 보조 스크립트 |

## Block Item System

블록 아이템 시스템은 "어떤 항목을 출력할 것인가"를 확장이 공급하는 구조입니다.

예시:

- Board 패키지: 게시판, 게시글, 게시판 그룹
- Shop 패키지: 상품, 기획전, 리뷰
- Banner 플러그인: 배너 그룹, 배너 항목
- Survey 플러그인: 설문 항목

Core는 아이템의 의미를 알 필요가 없습니다. 각 Package/Plugin이 자신이 제공하는 아이템 목록을 공급하고, Renderer가 이를 해석해 출력합니다.

## 장점

- 운영자는 페이지를 직접 구성할 수 있습니다.
- 개발자는 재사용 가능한 콘텐츠 타입을 만들 수 있습니다.
- Core는 특정 업무 도메인을 몰라도 됩니다.
- Package/Plugin은 자신만의 스킨과 설정 UI를 제공할 수 있습니다.
- 같은 콘텐츠 타입도 스킨을 바꿔 다른 형태로 출력할 수 있습니다.

## 관련 개발 문서

- [블록 시스템 개발](dev-guide/block-system.md)
- [패키지 만들기](dev-guide/package-development.md)
- [플러그인 만들기](dev-guide/plugin-development.md)
- [이벤트 시스템](dev-guide/event-system.md)

