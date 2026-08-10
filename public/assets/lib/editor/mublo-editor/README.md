# MubloEditor

외부 의존성 없이 동작하는 Mublo 전용 WYSIWYG 에디터다.

## 기본 사용법

CSS와 JavaScript를 로드한 뒤 `mublo-editor` 클래스를 붙이면 자동으로 초기화된다.

```html
<link rel="stylesheet" href="MubloEditor.css">

<textarea class="mublo-editor"
          id="content"
          data-toolbar="basic"
          data-height="300"
          data-placeholder="내용을 입력하세요"></textarea>

<script src="MubloEditor.js"></script>
```

JavaScript로 직접 생성할 때는 같은 옵션을 camelCase로 넘긴다.

```javascript
MubloEditor.create('#content', {
    toolbar: 'basic',
    height: 300
});
```

## 툴바

`data-toolbar`는 아래 프리셋을 받는다. 항목을 직접 선택하려면
`data-toolbar-items`에 쉼표로 구분해 지정한다.

| 프리셋 | 버튼 | 용도 |
|---|---|---|
| `minimal` | 3 | 한 줄 입력칸 |
| `compact` | 7 | 좁은 화면·좁은 칸(댓글 폼·사이드바). 320px 폭에서 한 줄 |
| `basic` | 20 | 일반 작성 |
| `full` | 35 | 전체 (기본값) |

```html
<textarea class="mublo-editor"
          data-toolbar-items="undo,redo,separator,bold,italic,link,image"></textarea>
```

`separator`는 버튼이 아니라 항목 그룹 사이의 구분선이다.

## 반응형 툴바

모바일 툴바는 데스크톱 설정과 독립적으로 지정할 수 있다. 프리셋을 쓰는 것이
기본이며, 버튼 이름을 알 필요가 없다.

```html
<textarea class="mublo-editor"
          data-toolbar="full"
          data-toolbar-mobile="compact"></textarea>
```

프리셋으로 표현할 수 없을 때만 `data-toolbar-items-mobile`로 항목을 직접
나열한다.

```html
<textarea class="mublo-editor"
          data-toolbar="full"
          data-toolbar-items-mobile="undo,redo,bold,italic,underline,link,image"
          data-toolbar-breakpoint="640"></textarea>
```

적용 우선순위는 다음과 같다.

1. `data-toolbar-items-mobile`
2. `data-toolbar-mobile`
3. `data-toolbar-items` 또는 `data-toolbar`

`data-toolbar-breakpoint`의 기본값은 768px이다. 화면 너비가 기준을 넘나들면
편집 본문을 유지한 채 툴바만 다시 구성한다.

JavaScript 옵션으로는 각각 `toolbarMobile`, `toolbarItemsMobile`,
`toolbarBreakpoint`를 사용한다.
