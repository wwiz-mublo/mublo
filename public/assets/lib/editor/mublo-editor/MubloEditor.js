/**
 * ============================================================
 * MubloEditor.js
 * (c) 2025 Mublo
 * Author: Mublo
 * Licensed under the MIT License
 * https://opensource.org/licenses/MIT
 * ============================================================
 *
 * MubloEditor는 Mublo Framework 전용 경량 WYSIWYG 에디터이다.
 * 외부 의존성 없이 순수 JavaScript로 구현된 경량 WYSIWYG 에디터이다.
 *
 * ------------------------------------------------------------
 * 핵심 설계 철학
 * ------------------------------------------------------------
 *
 * 1. 선언적 사용 (Declarative)
 *    - data-* 속성으로 에디터 옵션 지정
 *    - JS 코드 없이 HTML만으로 에디터 생성 가능
 *
 * 2. MubloRequest 통합
 *    - syncAllEditors() 자동 지원
 *    - 폼 제출 시 자동 동기화
 *
 * 3. 확장 가능한 플러그인 시스템
 *    - 커스텀 툴바 버튼 추가 가능
 *    - 이미지 업로드 핸들러 교체 가능
 *    - 이벤트 훅 제공
 *
 * 4. 다크 모드 & Bootstrap 5 호환
 *    - CSS 변수 기반 테마
 *    - Bootstrap 클래스 활용
 *
 * ------------------------------------------------------------
 * 플러그인 시스템
 * ------------------------------------------------------------
 *
 * [이미지 업로드 플러그인 예시]
 *
 * MubloEditor.registerPlugin('myImageUploader', (editor) => {
 *     editor.setImageUploadHandler(async (blobInfo, progress) => {
 *         // blobInfo.blob()     - File/Blob 객체
 *         // blobInfo.filename() - 파일명
 *         // blobInfo.base64()   - Base64 문자열
 *         // progress(percent)   - 진행률 콜백 (0-100)
 *
 *         const formData = new FormData();
 *         formData.append('file', blobInfo.blob(), blobInfo.filename());
 *
 *         const res = await fetch('/api/upload', {
 *             method: 'POST',
 *             body: formData
 *         });
 *
 *         if (!res.ok) throw new Error('Upload failed');
 *
 *         const data = await res.json();
 *         return data.url;  // 이미지 URL 반환
 *     });
 * });
 *
 * ------------------------------------------------------------
 * API
 * ------------------------------------------------------------
 *
 * MubloEditor.create(selector, options)  - 에디터 생성
 * MubloEditor.get(id)                    - ID로 에디터 인스턴스 가져오기
 * MubloEditor.getAll()                   - 모든 에디터 인스턴스
 * MubloEditor.destroy(id)                - 에디터 제거
 * MubloEditor.registerPlugin(name, fn)   - 플러그인 등록
 *
 * [인스턴스 메서드]
 * editor.getHTML()                      - HTML 콘텐츠 반환
 * editor.setHTML(html)                  - HTML 콘텐츠 설정
 * editor.getText()                      - 텍스트만 반환
 * editor.isEmpty()                      - 비어있는지 확인
 * editor.focus()                        - 에디터에 포커스
 * editor.blur()                         - 포커스 해제
 * editor.destroy()                      - 에디터 제거
 * editor.sync()                         - textarea와 동기화
 * editor.insertContent(html)            - HTML 삽입 (sanitize 적용)
 * editor.insertTrustedContent(html)     - 신뢰된 HTML 삽입
 * editor.insertImage(url, alt)          - 이미지 삽입
 * editor.setImageUploadHandler(fn)      - 이미지 업로드 핸들러 설정
 * editor.on(event, callback)            - 이벤트 리스너 등록
 * editor.off(event, callback)           - 이벤트 리스너 제거
 * editor.fire(event, data)              - 이벤트 발생
 *
 * ============================================================
 */

const MubloEditor = (() => {
    'use strict';

    const VERSION = '1.4.0';
    const EDITOR_CLASS = 'mublo-editor';
    const EDITOR_WRAPPER_CLASS = 'mublo-editor-wrapper';
    const EDITOR_TOOLBAR_CLASS = 'mublo-editor-toolbar';
    const EDITOR_CONTENT_CLASS = 'mublo-editor-content';
    // 캐럿 위치에 서식을 예약할 때 넣는 자리 문자. 이 문자만 든 span 은 껍데기로 본다.
    const ZERO_WIDTH = '​';

    // =========================================================
    // i18n 시스템
    // =========================================================
    const LOCALE = {
        ko: {
            // 툴바
            bold: '굵게 (Ctrl+B)', italic: '기울임 (Ctrl+I)', underline: '밑줄 (Ctrl+U)',
            strikethrough: '취소선', heading: '제목',
            heading1: '제목 1', heading2: '제목 2', heading3: '제목 3', paragraph: '본문',
            fontname: '글꼴', defaultFont: '기본 서체',
            fontsize: '글자 크기', sizeSmall: '작게', sizeNormal: '보통', sizeLarge: '크게', sizeHuge: '아주 크게',
            subscript: '아래 첨자', superscript: '위 첨자',
            forecolor: '글자 색상', backcolor: '배경 색상',
            alignleft: '왼쪽 정렬', aligncenter: '가운데 정렬', alignright: '오른쪽 정렬',
            orderedlist: '번호 목록', unorderedlist: '글머리 목록',
            indent: '들여쓰기', outdent: '내어쓰기',
            link: '링크 (Ctrl+K)', unlink: '링크 제거',
            image: '이미지', table: '테이블', hr: '수평선', video: '동영상',
            blockquote: '인용구', code: '코드 블록',
            removeformat: '서식 제거', selectall: '전체 선택 (Ctrl+A)',
            print: '인쇄', undo: '실행 취소 (Ctrl+Z)', redo: '다시 실행 (Ctrl+Y)',
            fullscreen: '전체화면', source: 'HTML 소스', findreplace: '찾기/바꾸기 (Ctrl+F)',
            // 폰트 (로케일별)
            localFonts: [
                { label: '맑은 고딕', value: 'Malgun Gothic' },
                { label: '굴림', value: 'Gulim' },
                { label: '바탕', value: 'Batang' }
            ],
            // 모달
            cancel: '취소', confirm: '확인', insert: '삽입', replace: '교체',
            // 링크 모달
            linkInsert: '링크 삽입', linkUrl: 'URL', linkText: '표시할 텍스트', linkNewTab: '새 탭에서 열기',
            // 이미지 모달
            imageAdd: '이미지 추가', imageReplace: '이미지 교체',
            imageDragOrClick: '이미지를 드래그하거나 클릭하여 선택',
            imageHint: '여러 파일 선택 가능 (JPG, PNG, GIF, WebP)',
            imageUrlPlaceholder: '또는 이미지 URL 입력...',
            imageUrlAdd: '추가', imageRemove: '제거',
            imageAlt: '대체 텍스트', imageCaption: '캡션',
            imageAltPlaceholder: '이미지를 설명하는 문구',
            imageCaptionPlaceholder: '이미지 아래에 표시할 캡션',
            imageUpdate: '적용',
            imageDragHint: '드래그하여 순서를 변경할 수 있습니다',
            imageSelected: '선택된 이미지:', imageCount: '개',
            uploading: '업로드 중...',
            imageTooltip: '&#x1F4F7; 더블클릭하여 이미지를 편집하세요',
            urlImage: 'URL 이미지',
            // 동영상 모달
            videoInsert: '동영상 삽입', videoUrl: '동영상 URL (YouTube, Vimeo)',
            videoUrlPlaceholder: 'https://www.youtube.com/watch?v=...',
            // 테이블 모달
            tableInsert: '테이블 삽입',
            // 찾기/바꾸기
            findPlaceholder: '찾기...', replacePlaceholder: '바꾸기...',
            findPrev: '이전', findNext: '다음',
            replaceOne: '바꾸기', replaceAll: '모두', findClose: '닫기',
            foundCount: '{count}개 발견', noResult: '결과 없음', replacedCount: '{count}개 바꿈',
            // 글자 수 카운터
            chars: '글자', charsNoSpace: '공백 제외', words: '단어',
            // 에러/경고
            invalidImageType: '허용되지 않는 이미지 형식입니다.',
            uploadFailed: '이미지 업로드에 실패했습니다. 다시 시도해주세요.',
            unsupportedUrl: '지원하지 않는 URL입니다.',
            // autosave
            autosaveRestore: '저장된 내용이 있습니다. ({date})\n복원하시겠습니까?',
            autosaveRestoreBtn: '복원', autosaveIgnoreBtn: '무시',
            // 테이블 셀 편집 컨텍스트 메뉴
            tableRowAbove: '위에 행 추가', tableRowBelow: '아래에 행 추가',
            tableColLeft: '왼쪽에 열 추가', tableColRight: '오른쪽에 열 추가',
            tableRowDelete: '행 삭제', tableColDelete: '열 삭제',
            tableMerge: '셀 병합', tableSplit: '셀 분할', tableDelete: '테이블 삭제',
            // 코드 블록
            codeLanguage: '언어', codeBlockInsert: '코드 블록',
        },
        en: {
            bold: 'Bold (Ctrl+B)', italic: 'Italic (Ctrl+I)', underline: 'Underline (Ctrl+U)',
            strikethrough: 'Strikethrough', heading: 'Heading',
            heading1: 'Heading 1', heading2: 'Heading 2', heading3: 'Heading 3', paragraph: 'Paragraph',
            fontname: 'Font', defaultFont: 'Default',
            fontsize: 'Font Size', sizeSmall: 'Small', sizeNormal: 'Normal', sizeLarge: 'Large', sizeHuge: 'Huge',
            subscript: 'Subscript', superscript: 'Superscript',
            forecolor: 'Text Color', backcolor: 'Background Color',
            alignleft: 'Align Left', aligncenter: 'Align Center', alignright: 'Align Right',
            orderedlist: 'Ordered List', unorderedlist: 'Unordered List',
            indent: 'Indent', outdent: 'Outdent',
            link: 'Link (Ctrl+K)', unlink: 'Remove Link',
            image: 'Image', table: 'Table', hr: 'Horizontal Rule', video: 'Video',
            blockquote: 'Blockquote', code: 'Code Block',
            removeformat: 'Remove Format', selectall: 'Select All (Ctrl+A)',
            print: 'Print', undo: 'Undo (Ctrl+Z)', redo: 'Redo (Ctrl+Y)',
            fullscreen: 'Fullscreen', source: 'HTML Source', findreplace: 'Find & Replace (Ctrl+F)',
            localFonts: [
                { label: 'Georgia', value: 'Georgia' },
                { label: 'Trebuchet MS', value: 'Trebuchet MS' },
                { label: 'Palatino', value: 'Palatino Linotype' }
            ],
            cancel: 'Cancel', confirm: 'OK', insert: 'Insert', replace: 'Replace',
            linkInsert: 'Insert Link', linkUrl: 'URL', linkText: 'Display Text', linkNewTab: 'Open in new tab',
            imageAdd: 'Add Image', imageReplace: 'Replace Image',
            imageDragOrClick: 'Drag or click to select images',
            imageHint: 'Multiple files supported (JPG, PNG, GIF, WebP)',
            imageUrlPlaceholder: 'Or enter image URL...',
            imageUrlAdd: 'Add', imageRemove: 'Remove',
            imageAlt: 'Alternative text', imageCaption: 'Caption',
            imageAltPlaceholder: 'Describe the image',
            imageCaptionPlaceholder: 'Caption shown below the image',
            imageUpdate: 'Apply',
            imageDragHint: 'Drag to reorder',
            imageSelected: 'Selected:', imageCount: '',
            uploading: 'Uploading...',
            imageTooltip: '&#x1F4F7; Double-click to edit image',
            urlImage: 'URL image',
            videoInsert: 'Insert Video', videoUrl: 'Video URL (YouTube, Vimeo)',
            videoUrlPlaceholder: 'https://www.youtube.com/watch?v=...',
            tableInsert: 'Insert Table',
            findPlaceholder: 'Find...', replacePlaceholder: 'Replace...',
            findPrev: 'Previous', findNext: 'Next',
            replaceOne: 'Replace', replaceAll: 'All', findClose: 'Close',
            foundCount: '{count} found', noResult: 'No results', replacedCount: '{count} replaced',
            chars: 'Characters', charsNoSpace: 'No spaces', words: 'Words',
            invalidImageType: 'Image type not allowed.',
            uploadFailed: 'Image upload failed. Please try again.',
            unsupportedUrl: 'Unsupported URL.',
            autosaveRestore: 'Saved content found. ({date})\nRestore?',
            autosaveRestoreBtn: 'Restore', autosaveIgnoreBtn: 'Ignore',
            // Table cell editing context menu
            tableRowAbove: 'Insert row above', tableRowBelow: 'Insert row below',
            tableColLeft: 'Insert column left', tableColRight: 'Insert column right',
            tableRowDelete: 'Delete row', tableColDelete: 'Delete column',
            tableMerge: 'Merge cells', tableSplit: 'Split cell', tableDelete: 'Delete table',
            // Code block
            codeLanguage: 'Language', codeBlockInsert: 'Code block',
        }
    };

    let _globalLocale = 'ko';
    // 인스턴스별 locale — _buildToolbar 등에서 참조할 현재 활성 인스턴스 locale
    let _activeInstanceLocale = null;

    function _t(key, params = {}) {
        if (typeof key !== 'string') return key;
        const loc = _activeInstanceLocale || _globalLocale;
        const str = LOCALE[loc]?.[key] ?? LOCALE.ko[key] ?? key;
        if (typeof str !== 'string') return str;
        return str.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`);
    }

    // TOOLBAR_ITEMS — title은 _t()로 런타임 해석
    const TOOLBAR_ICONS = {
        bold: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/><path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/></svg>',
        italic: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/></svg>',
        underline: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 3v7a6 6 0 0 0 6 6 6 6 0 0 0 6-6V3"/><line x1="4" y1="21" x2="20" y2="21"/></svg>',
        strikethrough: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.3 4.9c-2.3-.6-4.4-1-6.2-.9-2.7 0-5.3.7-5.3 3.6 0 1.5 1.8 3.3 3.6 3.9h.2m8.2 3.7c.3.4.4.8.4 1.3 0 2.9-2.7 3.6-6.2 3.6-2.3 0-4.4-.3-6.2-.9M4 11.5h16"/></svg>',
        heading: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 4v16"/><path d="M18 4v16"/><path d="M6 12h12"/></svg>',
        fontname: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3"/><path d="M9 20h6"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
        fontsize: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
        subscript: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 5l11 11"/><path d="M16 5l-11 11"/><path d="M20 20h2v2h-2z"/></svg>',
        superscript: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 19l11-11"/><path d="M16 19l-11-11"/><path d="M20 4h2v2h-2z"/></svg>',
        forecolor: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20h16"/><path d="M6.5 16L9.354 5h5.292L18 16" fill="currentColor" opacity="0.2"/></svg>',
        backcolor: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" fill="currentColor" opacity="0.2"/></svg>',
        alignleft: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>',
        aligncenter: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>',
        alignright: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/></svg>',
        orderedlist: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h2v2H4z" fill="currentColor"/><path d="M4 12h2v2H4z" fill="currentColor"/><path d="M4 18h2v2H4z" fill="currentColor"/></svg>',
        unorderedlist: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="9" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="9" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1.5" fill="currentColor"/><circle cx="4" cy="12" r="1.5" fill="currentColor"/><circle cx="4" cy="18" r="1.5" fill="currentColor"/></svg>',
        indent: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="9" y1="18" x2="21" y2="18"/><polyline points="3 9 6 12 3 15"/></svg>',
        outdent: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="9" y1="18" x2="21" y2="18"/><polyline points="6 9 3 12 6 15"/></svg>',
        link: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        unlink: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.84 12.25l1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M5.17 11.75L3.45 13.46a5 5 0 0 0 7.07 7.07l1.71-1.71"/><line x1="2" y1="2" x2="22" y2="22"/></svg>',
        image: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        table: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>',
        hr: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/></svg>',
        video: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 8 16 12 10 16 10 8" fill="currentColor"/></svg>',
        blockquote: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V21c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/></svg>',
        code: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>',
        removeformat: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3"/><path d="M9 20h6"/><line x1="4" y1="20" x2="20" y2="4"/></svg>',
        selectall: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/></svg>',
        print: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>',
        undo: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
        redo: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>',
        fullscreen: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>',
        fullscreenExit: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>',
        source: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        findreplace: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>'
    };

    function _getToolbarItems() {
        const localFonts = _t('localFonts') || [];
        return {
            bold: { icon: TOOLBAR_ICONS.bold, title: _t('bold'), command: 'bold' },
            italic: { icon: TOOLBAR_ICONS.italic, title: _t('italic'), command: 'italic' },
            underline: { icon: TOOLBAR_ICONS.underline, title: _t('underline'), command: 'underline' },
            strikethrough: { icon: TOOLBAR_ICONS.strikethrough, title: _t('strikethrough'), command: 'strikeThrough' },
            separator: { type: 'separator' },
            heading: {
                icon: TOOLBAR_ICONS.heading, title: _t('heading'), type: 'dropdown',
                items: [
                    { label: _t('heading1'), command: 'formatBlock', value: 'h1' },
                    { label: _t('heading2'), command: 'formatBlock', value: 'h2' },
                    { label: _t('heading3'), command: 'formatBlock', value: 'h3' },
                    { label: _t('paragraph'), command: 'formatBlock', value: 'p' }
                ]
            },
            fontname: {
                icon: TOOLBAR_ICONS.fontname, title: _t('fontname'), type: 'dropdown',
                items: [
                    { label: _t('defaultFont'), command: 'fontName', value: 'inherit' },
                    { label: 'Arial', command: 'fontName', value: 'Arial' },
                    { label: 'Verdana', command: 'fontName', value: 'Verdana' },
                    { label: 'Times New Roman', command: 'fontName', value: 'Times New Roman' },
                    { label: 'Courier New', command: 'fontName', value: 'Courier New' },
                    ...localFonts.map(f => ({ label: f.label, command: 'fontName', value: f.value }))
                ]
            },
            fontsize: {
                icon: TOOLBAR_ICONS.fontsize, title: _t('fontsize'), type: 'dropdown',
                items: [
                    { label: '10px', command: 'fontSize', value: '10px' },
                    { label: '11px', command: 'fontSize', value: '11px' },
                    { label: '12px', command: 'fontSize', value: '12px' },
                    { label: '13px', command: 'fontSize', value: '13px' },
                    { label: '14px', command: 'fontSize', value: '14px' },
                    { label: '15px', command: 'fontSize', value: '15px' },
                    { label: '16px', command: 'fontSize', value: '16px' },
                    { label: '18px', command: 'fontSize', value: '18px' },
                    { label: '20px', command: 'fontSize', value: '20px' },
                    { label: '22px', command: 'fontSize', value: '22px' },
                    { label: '24px', command: 'fontSize', value: '24px' },
                    { label: '28px', command: 'fontSize', value: '28px' },
                    { label: '32px', command: 'fontSize', value: '32px' },
                    { label: '36px', command: 'fontSize', value: '36px' },
                    { label: '40px', command: 'fontSize', value: '40px' },
                    { label: '48px', command: 'fontSize', value: '48px' }
                ]
            },
            subscript: { icon: TOOLBAR_ICONS.subscript, title: _t('subscript'), command: 'subscript' },
            superscript: { icon: TOOLBAR_ICONS.superscript, title: _t('superscript'), command: 'superscript' },
            forecolor: { icon: TOOLBAR_ICONS.forecolor, title: _t('forecolor'), type: 'color', command: 'foreColor' },
            backcolor: { icon: TOOLBAR_ICONS.backcolor, title: _t('backcolor'), type: 'color', command: 'hiliteColor' },
            alignleft: { icon: TOOLBAR_ICONS.alignleft, title: _t('alignleft'), command: 'justifyLeft' },
            aligncenter: { icon: TOOLBAR_ICONS.aligncenter, title: _t('aligncenter'), command: 'justifyCenter' },
            alignright: { icon: TOOLBAR_ICONS.alignright, title: _t('alignright'), command: 'justifyRight' },
            orderedlist: { icon: TOOLBAR_ICONS.orderedlist, title: _t('orderedlist'), command: 'insertOrderedList' },
            unorderedlist: { icon: TOOLBAR_ICONS.unorderedlist, title: _t('unorderedlist'), command: 'insertUnorderedList' },
            indent: { icon: TOOLBAR_ICONS.indent, title: _t('indent'), command: 'indent' },
            outdent: { icon: TOOLBAR_ICONS.outdent, title: _t('outdent'), command: 'outdent' },
            link: { icon: TOOLBAR_ICONS.link, title: _t('link'), type: 'link' },
            unlink: { icon: TOOLBAR_ICONS.unlink, title: _t('unlink'), command: 'unlink' },
            image: { icon: TOOLBAR_ICONS.image, title: _t('image'), type: 'image' },
            table: { icon: TOOLBAR_ICONS.table, title: _t('table'), type: 'table' },
            hr: { icon: TOOLBAR_ICONS.hr, title: _t('hr'), command: 'insertHorizontalRule' },
            video: { icon: TOOLBAR_ICONS.video, title: _t('video'), type: 'video' },
            blockquote: { icon: TOOLBAR_ICONS.blockquote, title: _t('blockquote'), command: 'formatBlock', value: 'blockquote' },
            code: { icon: TOOLBAR_ICONS.code, title: _t('code'), type: 'codeblock' },
            removeformat: { icon: TOOLBAR_ICONS.removeformat, title: _t('removeformat'), command: 'removeFormat' },
            selectall: { icon: TOOLBAR_ICONS.selectall, title: _t('selectall'), command: 'selectAll' },
            print: { icon: TOOLBAR_ICONS.print, title: _t('print'), type: 'print' },
            undo: { icon: TOOLBAR_ICONS.undo, title: _t('undo'), command: 'undo' },
            redo: { icon: TOOLBAR_ICONS.redo, title: _t('redo'), command: 'redo' },
            fullscreen: { icon: TOOLBAR_ICONS.fullscreen, iconExit: TOOLBAR_ICONS.fullscreenExit, title: _t('fullscreen'), type: 'fullscreen' },
            source: { icon: TOOLBAR_ICONS.source, title: _t('source'), type: 'source' },
            findreplace: { icon: TOOLBAR_ICONS.findreplace, title: _t('findreplace'), type: 'findreplace' }
        };
    }

    const TOOLBAR_PRESETS = {
        minimal: ['bold', 'italic', 'separator', 'link'],
        // 좁은 화면이나 좁은 칸(댓글 폼·사이드바)용. 스킨이 버튼 이름을 몰라도 쓸 수 있게 둔다.
        // 320px 폭에서 한 줄에 들어가는 것이 이 프리셋의 조건이다. 항목을 늘리면 두 줄이 된다.
        compact: ['undo', 'redo', 'separator', 'bold', 'italic', 'underline', 'separator', 'link', 'image'],
        basic: ['heading', 'fontname', 'fontsize', 'separator', 'bold', 'italic', 'underline', 'separator', 'forecolor', 'backcolor', 'separator', 'alignleft', 'aligncenter', 'alignright', 'separator', 'orderedlist', 'unorderedlist', 'separator', 'link', 'image', 'video', 'table'],
        full: ['source', 'separator', 'undo', 'redo', 'separator', 'heading', 'fontname', 'fontsize', 'separator', 'bold', 'italic', 'underline', 'strikethrough', 'subscript', 'superscript', 'separator', 'forecolor', 'backcolor', 'separator', 'alignleft', 'aligncenter', 'alignright', 'separator', 'orderedlist', 'unorderedlist', 'indent', 'outdent', 'separator', 'link', 'unlink', 'image', 'video', 'table', 'separator', 'blockquote', 'code', 'hr', 'separator', 'removeformat', 'selectall', 'print', 'separator', 'findreplace', 'fullscreen']
    };

    const DEFAULT_COLORS = [
        '#000000', '#434343', '#666666', '#999999', '#b7b7b7', '#cccccc', '#d9d9d9', '#efefef', '#f3f3f3', '#ffffff',
        '#980000', '#ff0000', '#ff9900', '#ffff00', '#00ff00', '#00ffff', '#4a86e8', '#0000ff', '#9900ff', '#ff00ff',
        '#e6b8af', '#f4cccc', '#fce5cd', '#fff2cc', '#d9ead3', '#d0e0e3', '#c9daf8', '#cfe2f3', '#d9d2e9', '#ead1dc'
    ];

    // 코드 블록 언어 목록 (data-language 속성 저장 + 구문 강조 대상)
    const CODE_LANGUAGES = ['text', 'html', 'css', 'javascript', 'php', 'sql', 'python', 'json', 'bash'];

    const instances = new Map();
    const plugins = new Map();

    // =========================================================
    // BlobInfo 클래스
    // =========================================================
    class BlobInfo {
        constructor(file, base64 = null) {
            this._file = file;
            this._base64 = base64;
            this._id = 'blobid' + Date.now() + Math.random().toString(36).substr(2, 9);
        }

        id() { return this._id; }
        name() { return this._file.name; }
        filename() { return this._file.name; }
        blob() { return this._file; }
        base64() { return this._base64; }
        blobUri() { return URL.createObjectURL(this._file); }
        uri() { return this.blobUri(); }
    }

    // =========================================================
    // 유틸리티 함수
    // =========================================================
    function generateId() {
        return 'mublo-editor-' + Math.random().toString(36).substr(2, 9);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function normalizeCodeText(html) {
        // 블록 태그와 <br>을 줄바꿈으로 변환 (innerText는 detached DOM에서 작동 안 함)
        let processed = html;
        processed = processed.replace(/<br\s*\/?>/gi, '\n');
        processed = processed.replace(/<\/(?:p|div|li|h[1-6]|pre|blockquote)>\s*<(?:p|div|li|h[1-6]|pre|blockquote)[^>]*>/gi, '\n');
        processed = processed.replace(/<\/?(?:p|div|li|h[1-6]|pre|blockquote)[^>]*>/gi, '\n');

        const temp = document.createElement('div');
        temp.innerHTML = processed;
        let text = temp.textContent || '';
        text = text.replace(/\r\n?/g, '\n').replace(/\u00a0/g, ' ');
        text = text.replace(/^\n+/, '').replace(/\n+$/, '');
        text = text.replace(/\n{3,}/g, '\n\n');
        return text;
    }

    function convertCodeShortcodesToHtml(html) {
        if (!html || html.indexOf('[code]') === -1) return html;

        return html.replace(/\[code\]([\s\S]*?)\[\/code\]/gi, (_, codeContent) => {
            const codeText = normalizeCodeText(codeContent);
            return `<pre><code>${escapeHtml(codeText)}</code></pre>`;
        });
    }

    function convertCodeHtmlToShortcodes(html) {
        if (!html || html.indexOf('<code>') === -1) return html;

        return html.replace(/<pre([^>]*)>\s*<code[^>]*>([\s\S]*?)<\/code>\s*<\/pre>/gi, (full, preAttrs, codeContent) => {
            // 언어가 지정된 향상된 코드 블록은 원본 HTML을 유지해 data-language 를 보존한다
            if (/mublo-code-block|data-language/i.test(preAttrs)) return full;
            // HTML 엔티티 디코딩
            const temp = document.createElement('textarea');
            temp.innerHTML = codeContent;
            return `[code]${temp.value}[/code]`;
        });
    }

    // =========================================================
    // 코드 구문 강조 (정규식 기반 · 외부 라이브러리/CDN 의존 없음)
    // ---------------------------------------------------------
    // 언어별 문법을 정규식 규칙 배열로 정의하고, 하나의 결합 정규식으로
    // 토큰을 스캔하여 <span class="mublo-tok-*"> 로 감싼다.
    // 규칙 패턴 내부에는 반드시 비캡처 그룹 (?:...) / 룩어헤드만 사용한다
    // (결합 정규식에서 각 규칙을 캡처 그룹으로 감싸 매칭 인덱스로 종류를 판별하므로).
    // =========================================================
    const CODE_KEYWORDS = {
        javascript: 'abstract arguments async await boolean break byte case catch char class const continue debugger default delete do double else enum eval export extends false final finally float for function goto if implements import in instanceof int interface let long native new null of package private protected public return short static super switch synchronized this throw throws transient true try typeof undefined var void volatile while with yield',
        php: 'abstract and array as break callable case catch class clone const continue declare default do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum extends final finally fn for foreach function global goto if implements include include_once instanceof insteadof interface isset list match namespace new or print private protected public readonly require require_once return static switch throw trait try unset use var while xor yield true false null',
        python: 'and as assert async await break class continue def del elif else except finally for from global if import in is lambda nonlocal not or pass raise return try while with yield True False None self',
        sql: 'select from where and or not insert into values update set delete create table drop alter add column primary key foreign references join inner left right outer full on as distinct group by order having limit offset union all index view null is in like between exists count sum avg min max case when then else end asc desc default constraint unique begin commit rollback transaction int integer varchar char text date datetime timestamp boolean decimal float',
        bash: 'if then else elif fi for while until do done case esac function in select return break continue exit echo export local readonly declare unset source alias cd pwd test eval exec trap set shift'
    };

    function _kwRe(lang) {
        const words = (CODE_KEYWORDS[lang] || '').trim().split(/\s+/).filter(Boolean);
        return new RegExp('\\b(?:' + words.join('|') + ')\\b');
    }

    const _grammarCache = {};

    function _getGrammar(lang) {
        if (lang in _grammarCache) return _grammarCache[lang];
        let rules = null;
        let flags = 'g';

        switch (lang) {
            case 'javascript':
                rules = [
                    { type: 'comment', re: /\/\/[^\n]*|\/\*[\s\S]*?\*\// },
                    { type: 'string', re: /`(?:\\[\s\S]|[^`\\])*`|"(?:\\.|[^"\\\n])*"|'(?:\\.|[^'\\\n])*'/ },
                    { type: 'number', re: /\b(?:0[xX][0-9a-fA-F]+|0[bB][01]+|\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)\b/ },
                    { type: 'keyword', re: _kwRe('javascript') },
                    { type: 'operator', re: /[+\-*/%=<>!&|^~?]+/ }
                ];
                break;
            case 'php':
                rules = [
                    { type: 'comment', re: /\/\/[^\n]*|#[^\n]*|\/\*[\s\S]*?\*\// },
                    { type: 'string', re: /"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'/ },
                    { type: 'variable', re: /\$[a-zA-Z_]\w*/ },
                    { type: 'keyword', re: /<\?php|<\?=|\?>/ },
                    { type: 'number', re: /\b(?:0[xX][0-9a-fA-F]+|\d+(?:\.\d+)?)\b/ },
                    { type: 'keyword', re: _kwRe('php') },
                    { type: 'operator', re: /[+\-*/%=<>!&|^~?:]+/ }
                ];
                break;
            case 'python':
                rules = [
                    { type: 'comment', re: /#[^\n]*/ },
                    { type: 'string', re: /"""[\s\S]*?"""|'''[\s\S]*?'''|"(?:\\.|[^"\\\n])*"|'(?:\\.|[^'\\\n])*'/ },
                    { type: 'number', re: /\b(?:0[xX][0-9a-fA-F]+|\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)\b/ },
                    { type: 'keyword', re: _kwRe('python') },
                    { type: 'operator', re: /[+\-*/%=<>!&|^~]+/ }
                ];
                break;
            case 'sql':
                rules = [
                    { type: 'comment', re: /--[^\n]*|\/\*[\s\S]*?\*\// },
                    { type: 'string', re: /'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"/ },
                    { type: 'number', re: /\b\d+(?:\.\d+)?\b/ },
                    { type: 'keyword', re: _kwRe('sql') },
                    { type: 'operator', re: /[+\-*/%=<>!]+|[,;()]/ }
                ];
                flags = 'gi';
                break;
            case 'json':
                rules = [
                    { type: 'string', re: /"(?:\\.|[^"\\])*"/ },
                    { type: 'keyword', re: /\b(?:true|false|null)\b/ },
                    { type: 'number', re: /-?\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/ },
                    { type: 'operator', re: /[:{}\[\],]/ }
                ];
                break;
            case 'css':
                rules = [
                    { type: 'comment', re: /\/\*[\s\S]*?\*\// },
                    { type: 'string', re: /"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'/ },
                    { type: 'keyword', re: /@[a-zA-Z-]+/ },
                    { type: 'number', re: /#[0-9a-fA-F]{3,8}\b|\b\d+(?:\.\d+)?(?:px|em|rem|ex|ch|vw|vh|vmin|vmax|%|s|ms|deg|fr|pt|pc|cm|mm|in)?\b/ },
                    { type: 'property', re: /[a-zA-Z-]+(?=\s*:)/ },
                    { type: 'operator', re: /[:;{}>~+*,]/ }
                ];
                break;
            case 'html':
                rules = [
                    { type: 'comment', re: /<!--[\s\S]*?-->/ },
                    { type: 'string', re: /"[^"]*"|'[^']*'/ },
                    { type: 'tag', re: /<\/?[a-zA-Z][a-zA-Z0-9-]*|\/?>/ },
                    { type: 'property', re: /[a-zA-Z-]+(?==)/ },
                    { type: 'keyword', re: /&[a-zA-Z#0-9]+;/ }
                ];
                flags = 'gi';
                break;
            case 'bash':
                rules = [
                    { type: 'comment', re: /#[^\n]*/ },
                    { type: 'string', re: /"(?:\\.|[^"\\])*"|'[^']*'/ },
                    { type: 'variable', re: /\$\{[^}]*\}|\$[a-zA-Z_]\w*|\$[0-9@*#?]/ },
                    { type: 'keyword', re: _kwRe('bash') },
                    { type: 'number', re: /\b\d+\b/ },
                    { type: 'operator', re: /[|&;<>()=]+/ }
                ];
                break;
        }

        const grammar = rules ? { rules, flags, combined: null } : null;
        _grammarCache[lang] = grammar;
        return grammar;
    }

    function _buildCombined(grammar) {
        const src = grammar.rules.map(r => '(' + r.re.source + ')').join('|');
        return new RegExp(src, grammar.flags);
    }

    function _escapeCode(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /**
     * 코드 텍스트를 구문 강조된 HTML로 변환한다.
     * 반환 HTML의 텍스트(태그 제외)는 입력 text와 문자 단위로 동일하다
     * (커서 오프셋 보존을 위해 문자 수가 절대 바뀌지 않도록 span만 삽입).
     */
    function highlightCodeToHtml(text, lang) {
        const grammar = _getGrammar(lang);
        if (!grammar) return _escapeCode(text);

        const combined = grammar.combined || (grammar.combined = _buildCombined(grammar));
        combined.lastIndex = 0;

        let out = '';
        let last = 0;
        let m;
        while ((m = combined.exec(text)) !== null) {
            if (m[0] === '') { combined.lastIndex++; continue; }
            if (m.index > last) out += _escapeCode(text.slice(last, m.index));

            let type = null;
            for (let i = 1; i < m.length; i++) {
                if (m[i] !== undefined) { type = grammar.rules[i - 1].type; break; }
            }
            out += '<span class="mublo-tok-' + type + '">' + _escapeCode(m[0]) + '</span>';
            last = m.index + m[0].length;
        }
        if (last < text.length) out += _escapeCode(text.slice(last));
        return out;
    }

    function sanitizeHtml(html) {
        if (!html) return '';

        // DOMParser를 이용한 자체 XSS 방어 로직
        // 외부 라이브러리 의존성 없이 브라우저 내장 파서를 사용하여 스크립트 실행을 방지합니다.
        try {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            // 1. 블랙리스트 태그 제거
            const forbiddenTags = ['script', 'meta', 'applet', 'object', 'embed', 'base', 'form', 'link'];
            
            forbiddenTags.forEach(tag => {
                const elements = doc.querySelectorAll(tag);
                elements.forEach(el => el.remove());
            });

            // 2. 모든 요소의 속성 전수 검사
            const allElements = doc.body.querySelectorAll('*');
            allElements.forEach(el => {
                const attributes = Array.from(el.attributes);
                
                attributes.forEach(attr => {
                    const name = attr.name.toLowerCase();
                    // 제어 문자 및 공백 제거 후 검사 (우회 공격 방지)
                    const value = attr.value.toLowerCase().replace(/[\s\x00-\x1f]+/g, '');

                    // 2-1. 이벤트 핸들러 제거 (onmouseover, onclick 등)
                    if (name.startsWith('on')) {
                        el.removeAttribute(attr.name);
                    }

                    // 2-2. 위험한 프로토콜 제거 (javascript:, vbscript:)
                    if (value.includes('javascript:') || value.includes('vbscript:')) {
                        el.removeAttribute(attr.name);
                    }
                    
                    // 2-3. data: 프로토콜은 이미지 외에는 차단
                    if (value.startsWith('data:') && !value.startsWith('data:image/')) {
                        el.removeAttribute(attr.name);
                    }

                    // 2-4. style 속성 내 위험 패턴 차단
                    if (name === 'style') {
                        const cleaned = attr.value
                            .replace(/expression\s*\(/gi, '')
                            .replace(/url\s*\(\s*['"]?\s*javascript:/gi, '')
                            .replace(/-moz-binding/gi, '')
                            .replace(/behavior\s*:/gi, '');
                        if (cleaned !== attr.value) {
                            el.setAttribute('style', cleaned);
                        }
                    }
                });
            });

            return doc.body.innerHTML;
        } catch (e) {
            console.error('[MubloEditor] Sanitization failed:', e);
            // 파싱 실패 시 텍스트만 반환하여 안전 확보
            const temp = document.createElement('div');
            temp.textContent = html;
            return temp.innerHTML;
        }
    }

    function fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result.split(',')[1]);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    // =========================================================
    // Editor 클래스
    // =========================================================
    class Editor {
        constructor(element, options = {}) {
            this.originalElement = element;
            this.id = element.id || generateId();
            this.options = this._mergeOptions(options);
            // 인스턴스별 locale (전역 fallback)
            this._locale = (this.options.locale && LOCALE[this.options.locale])
                ? this.options.locale : null;
            this.isFullscreen = false;
            this.isSourceMode = false;
            this.savedRange = null;
            
            // 이벤트 시스템
            this._eventListeners = new Map();
            
            // 이미지 업로드 핸들러 (플러그인에서 교체 가능)
            this._imageUploadHandler = null;

            // 자동 저장 타이머
            this._autosaveTimer = null;

            // 이미지 리사이저
            this._selectedImage = null;
            this._resizer = null;

            // 전역 이벤트 핸들러 참조 (제거용)
            this._handlers = {};

            // data-toolbar-*-mobile 옵션이 있을 때만 생성하는 반응형 툴바 쿼리
            this._toolbarMedia = null;

            this._withLocale(() => this._build());
            this._withLocale(() => this._bindEvents());
            this._initPlugins();
            this.setHTML(element.value || '');
            instances.set(this.id, this);
            
            // ready 이벤트 발생
            this.fire('ready', { editor: this });
        }

        _mergeOptions(options) {
            const dataOptions = {};
            const el = this.originalElement;
            if (el.dataset.toolbar) dataOptions.toolbar = el.dataset.toolbar;
            if (el.dataset.height) dataOptions.height = parseInt(el.dataset.height, 10);
            if (el.dataset.placeholder) dataOptions.placeholder = el.dataset.placeholder;
            if (el.dataset.uploadUrl) dataOptions.uploadUrl = el.dataset.uploadUrl;
            if (el.dataset.uploadCsrf) dataOptions.uploadCsrfToken = el.dataset.uploadCsrf;
            if (el.dataset.toolbarItems) dataOptions.toolbarItems = el.dataset.toolbarItems.split(',').map(s => s.trim());
            // 반응형 툴바: 모바일 개별 항목 > 모바일 프리셋 > 데스크톱 설정 순으로 적용한다.
            if (el.dataset.toolbarMobile) dataOptions.toolbarMobile = el.dataset.toolbarMobile;
            if (el.dataset.toolbarItemsMobile) dataOptions.toolbarItemsMobile = el.dataset.toolbarItemsMobile.split(',').map(s => s.trim());
            if (el.dataset.toolbarBreakpoint) dataOptions.toolbarBreakpoint = parseInt(el.dataset.toolbarBreakpoint, 10);
            if (el.dataset.showWordCount !== undefined) dataOptions.showWordCount = el.dataset.showWordCount === 'true';
            if (el.dataset.maxLength) dataOptions.maxLength = parseInt(el.dataset.maxLength, 10);
            if (el.dataset.autosave !== undefined) dataOptions.autosave = el.dataset.autosave === 'true';
            if (el.dataset.autosaveInterval) dataOptions.autosaveInterval = parseInt(el.dataset.autosaveInterval, 10);
            if (el.dataset.autosaveKey) dataOptions.autosaveKey = el.dataset.autosaveKey;
            if (el.dataset.locale) dataOptions.locale = el.dataset.locale;

            return {
                toolbar: 'full',
                toolbarMobile: null,
                toolbarItemsMobile: null,
                toolbarBreakpoint: 768,
                height: 300,
                minHeight: 150,
                placeholder: '',
                autofocus: false,
                readonly: false,
                colors: DEFAULT_COLORS,
                uploadUrl: null,
                uploadCsrfToken: null,
                allowedImageTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                sanitize: true,
                automatic_uploads: true,
                images_upload_credentials: false,
                // 콜백 (하위 호환성)
                onChange: null,
                onFocus: null,
                onBlur: null,
                onImageUpload: null,
                onReady: null,
                // 스타일 핸들러
                images_upload_handler: null,
                // 글자 수 카운터
                showWordCount: false,
                maxLength: 0,  // 0 = 제한 없음
                // 자동 저장
                autosave: false,
                autosaveInterval: 30000,  // 30초
                autosaveKey: null,  // localStorage 키 (null이면 에디터 ID 사용)
                autosaveRestore: true,  // 페이지 로드 시 자동 복원
                ...dataOptions,
                ...options
            };
        }

        // =========================================================
        // locale 컨텍스트 헬퍼
        // =========================================================
        _withLocale(fn) {
            const prev = _activeInstanceLocale;
            _activeInstanceLocale = this._locale;
            try { return fn(); } finally { _activeInstanceLocale = prev; }
        }

        // =========================================================
        // 이벤트 시스템
        // =========================================================
        on(event, callback) {
            if (!this._eventListeners.has(event)) {
                this._eventListeners.set(event, []);
            }
            this._eventListeners.get(event).push(callback);
            return this;
        }

        off(event, callback) {
            if (!this._eventListeners.has(event)) return this;
            if (!callback) {
                this._eventListeners.delete(event);
            } else {
                const listeners = this._eventListeners.get(event);
                const index = listeners.indexOf(callback);
                if (index > -1) listeners.splice(index, 1);
            }
            return this;
        }

        fire(event, data = {}) {
            const listeners = this._eventListeners.get(event) || [];
            listeners.forEach(callback => {
                try {
                    callback({ ...data, type: event, target: this });
                } catch (e) {
                    console.error(`[MubloEditor] Event "${event}" handler error:`, e);
                }
            });
            return this;
        }

        // =========================================================
        // 이미지 업로드 핸들러 설정 (플러그인용)
        // =========================================================
        setImageUploadHandler(handler) {
            if (typeof handler !== 'function') {
                console.error('[MubloEditor] Image upload handler must be a function');
                return this;
            }
            this._imageUploadHandler = handler;
            return this;
        }

        getImageUploadHandler() {
            return this._imageUploadHandler;
        }

        // =========================================================
        // 빌드
        // =========================================================
        _build() {
            this.wrapper = document.createElement('div');
            this.wrapper.className = EDITOR_WRAPPER_CLASS;
            this.wrapper.id = this.id + '-wrapper';

            this.toolbar = this._buildToolbar();
            this.wrapper.appendChild(this.toolbar);

            this.contentArea = document.createElement('div');
            this.contentArea.className = EDITOR_CONTENT_CLASS;
            this.contentArea.contentEditable = !this.options.readonly;
            this.contentArea.style.minHeight = this.options.minHeight + 'px';
            this.contentArea.style.height = this.options.height + 'px';
            if (this.options.placeholder) {
                this.contentArea.dataset.placeholder = this.options.placeholder;
            }
            this.wrapper.appendChild(this.contentArea);

            this.sourceArea = document.createElement('textarea');
            this.sourceArea.className = 'mublo-editor-source';
            this.sourceArea.style.display = 'none';
            this.sourceArea.style.height = this.options.height + 'px';
            this.wrapper.appendChild(this.sourceArea);

            // 업로드 진행률 표시 영역
            this.progressBar = document.createElement('div');
            this.progressBar.className = 'mublo-editor-progress';
            this.progressBar.style.display = 'none';
            this.progressBar.innerHTML = '<div class="mublo-editor-progress-bar"></div>';
            this.wrapper.appendChild(this.progressBar);

            // 이미지 리사이저 요소 생성 — 8방향 핸들(모서리 4 + 가운데 4) + 크기 표시 라벨
            this._resizer = document.createElement('div');
            this._resizer.className = 'mublo-editor-resizer';
            this._resizer.innerHTML = [
                'nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'
            ].map(dir => `<div class="mublo-editor-resizer-handle mublo-editor-resizer-${dir}" data-dir="${dir}"></div>`).join('')
                + '<div class="mublo-editor-resizer-size"></div>';
            this.wrapper.appendChild(this._resizer);

            // 코드 블록 언어 선택 바 (커서가 코드 블록 안에 있을 때 표시)
            this._codeLangBar = document.createElement('div');
            this._codeLangBar.className = 'mublo-editor-code-langbar';
            this._codeLangBar.style.display = 'none';
            const langSelect = document.createElement('select');
            langSelect.className = 'mublo-editor-code-lang-select';
            CODE_LANGUAGES.forEach(lang => {
                const opt = document.createElement('option');
                opt.value = lang;
                opt.textContent = lang;
                langSelect.appendChild(opt);
            });
            langSelect.addEventListener('change', () => {
                if (this._activeCodeBlock) {
                    this._activeCodeBlock.setAttribute('data-language', langSelect.value);
                    this._highlightCodeBlock(this._activeCodeBlock, true);
                    this._onChange();
                }
            });
            langSelect.addEventListener('mousedown', e => e.stopPropagation());
            this._codeLangBar.appendChild(langSelect);
            this.wrapper.appendChild(this._codeLangBar);

            // 글자 수 카운터
            if (this.options.showWordCount) {
                this.statusBar = document.createElement('div');
                this.statusBar.className = 'mublo-editor-statusbar';
                this.statusBar.innerHTML = '<span class="mublo-editor-wordcount"></span>';
                this.wrapper.appendChild(this.statusBar);
            }

            this.originalElement.style.display = 'none';
            this.originalElement.parentNode.insertBefore(this.wrapper, this.originalElement.nextSibling);

            // 엔터 키 입력 시 <div> 대신 <p> 태그가 생성되도록 설정
            this._ensureParagraphSeparator();
        }

        _buildToolbar() {
            const toolbar = document.createElement('div');
            toolbar.className = EDITOR_TOOLBAR_CLASS;
            const items = this._resolveToolbarItems();

            items.forEach(name => {
                if (name === 'separator') {
                    const sep = document.createElement('span');
                    sep.className = 'mublo-editor-separator';
                    toolbar.appendChild(sep);
                    return;
                }
                const def = _getToolbarItems()[name];
                if (!def) return;
                const btn = this._createButton(name, def);
                if (btn) toolbar.appendChild(btn);
            });
            return toolbar;
        }

        _hasResponsiveToolbar() {
            return this.options.toolbarMobile !== null
                || Array.isArray(this.options.toolbarItemsMobile);
        }

        _getToolbarMedia() {
            if (!this._hasResponsiveToolbar() || typeof window.matchMedia !== 'function') return null;
            if (this._toolbarMedia) return this._toolbarMedia;

            const configuredBreakpoint = Number(this.options.toolbarBreakpoint);
            const breakpoint = Number.isFinite(configuredBreakpoint) && configuredBreakpoint > 0
                ? configuredBreakpoint
                : 768;
            this._toolbarMedia = window.matchMedia(`(max-width: ${breakpoint}px)`);
            return this._toolbarMedia;
        }

        _resolveToolbarItems() {
            // data-toolbar-items 가 있으면 data-toolbar 프리셋보다 우선한다.
            const desktopItems = Array.isArray(this.options.toolbarItems)
                ? this.options.toolbarItems
                : (TOOLBAR_PRESETS[this.options.toolbar] || TOOLBAR_PRESETS.full);
            const media = this._getToolbarMedia();
            let items = desktopItems;

            if (media?.matches) {
                if (Array.isArray(this.options.toolbarItemsMobile)) {
                    items = this.options.toolbarItemsMobile;
                } else if (TOOLBAR_PRESETS[this.options.toolbarMobile]) {
                    items = TOOLBAR_PRESETS[this.options.toolbarMobile];
                }
            }

            // 화면 크기가 바뀌어도 활성 모드에서 빠져나올 버튼은 유지한다.
            items = [...items];
            if (this.isSourceMode && !items.includes('source')) items.push('source');
            if (this.isFullscreen && !items.includes('fullscreen')) items.push('fullscreen');
            return items;
        }

        _renderResponsiveToolbar() {
            if (!this.toolbar) return;

            const nextToolbar = this._buildToolbar();
            this.toolbar.replaceWith(nextToolbar);
            this.toolbar = nextToolbar;

            if (this.findReplaceBar
                && this.findReplaceBar.style.display !== 'none'
                && !this.toolbar.querySelector('[data-cmd="findreplace"]')) {
                this._closeFindReplace();
            }

            this._syncToolbarState();
        }

        _syncToolbarState() {
            this.toolbar.querySelectorAll('.mublo-editor-btn').forEach(btn => {
                const cmd = btn.dataset.cmd;
                btn.disabled = this.options.readonly
                    || (this.isSourceMode && cmd !== 'source' && cmd !== 'fullscreen');
            });

            const sourceBtn = this.toolbar.querySelector('[data-cmd="source"]');
            if (sourceBtn) sourceBtn.classList.toggle('active', this.isSourceMode);

            const fullscreenBtn = this.toolbar.querySelector('[data-cmd="fullscreen"]');
            if (fullscreenBtn) {
                fullscreenBtn.innerHTML = this.isFullscreen
                    ? TOOLBAR_ICONS.fullscreenExit
                    : TOOLBAR_ICONS.fullscreen;
            }
        }

        _bindResponsiveToolbar() {
            const media = this._getToolbarMedia();
            if (!media) return;

            this._handlers.toolbarMediaChange = () => this._renderResponsiveToolbar();
            if (typeof media.addEventListener === 'function') {
                media.addEventListener('change', this._handlers.toolbarMediaChange);
            } else if (typeof media.addListener === 'function') {
                media.addListener(this._handlers.toolbarMediaChange);
            }
        }

        _ensureParagraphSeparator() {
            try {
                document.execCommand('defaultParagraphSeparator', false, 'p');
            } catch (e) {
                // 브라우저 호환성 예외 처리
            }

            try {
                document.execCommand('styleWithCSS', false, true);
            } catch (e) {
                try {
                    document.execCommand('useCSS', false, false);
                } catch (ignored) {
                    // 브라우저 호환성 예외 처리
                }
            }
        }

        /**
         * Backspace로 전부 지웠을 때 빈 contentEditable 보정
         * 빈 상태에서 타이핑하면 브라우저마다 커서가 불안정 → <p><br></p> 삽입 + 커서 배치
         */
        _ensureNotEmpty() {
            const root = this.contentArea;
            if (!root || this.isSourceMode) return;

            // 내용이 비었거나 <br> 하나만 남은 상태
            const html = root.innerHTML;
            if (html === '' || html === '<br>' || html === '<br/>' || html.trim() === '') {
                root.innerHTML = '<p><br></p>';
                // 커서를 <p> 안에 배치
                const p = root.querySelector('p');
                if (p) {
                    const sel = window.getSelection();
                    const range = document.createRange();
                    range.setStart(p, 0);
                    range.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(range);
                }
            }
        }

        _createButton(name, def) {
            if (def.type === 'dropdown') return this._createDropdown(name, def);
            if (def.type === 'color') return this._createColorPicker(name, def);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mublo-editor-btn';
            btn.title = def.title;
            btn.innerHTML = def.icon;
            btn.dataset.cmd = name;
            btn.addEventListener('click', e => {
                e.preventDefault();
                this._handleCommand(name, def);
            });
            return btn;
        }

        _createDropdown(name, def) {
            const wrap = document.createElement('div');
            wrap.className = 'mublo-editor-dropdown';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mublo-editor-btn mublo-editor-dropdown-btn';
            btn.title = def.title;
            btn.innerHTML = def.icon + '<svg class="mublo-editor-caret" width="10" height="10" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9" fill="none" stroke="currentColor" stroke-width="2"/></svg>';

            const menu = document.createElement('div');
            menu.className = 'mublo-editor-dropdown-menu';
            def.items.forEach(item => {
                const mi = document.createElement('button');
                mi.type = 'button';
                mi.className = 'mublo-editor-dropdown-item';
                mi.textContent = item.label;
                mi.addEventListener('click', e => {
                    e.preventDefault();
                    this._exec(item.command, item.value);
                    menu.classList.remove('show');
                });
                menu.appendChild(mi);
            });

            btn.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                this._closeAllDropdowns();
                menu.classList.toggle('show');
            });

            wrap.appendChild(btn);
            wrap.appendChild(menu);
            return wrap;
        }

        _createColorPicker(name, def) {
            const wrap = document.createElement('div');
            wrap.className = 'mublo-editor-dropdown mublo-editor-colorpicker';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mublo-editor-btn';
            btn.title = def.title;
            btn.innerHTML = def.icon;

            const menu = document.createElement('div');
            menu.className = 'mublo-editor-dropdown-menu mublo-editor-color-menu';

            const palette = document.createElement('div');
            palette.className = 'mublo-editor-color-palette';
            this.options.colors.forEach(color => {
                const c = document.createElement('button');
                c.type = 'button';
                c.className = 'mublo-editor-color-btn';
                c.style.backgroundColor = color;
                c.title = color;
                c.addEventListener('click', e => {
                    e.preventDefault();
                    this._exec(def.command, color);
                    menu.classList.remove('show');
                });
                palette.appendChild(c);
            });
            menu.appendChild(palette);

            const custom = document.createElement('input');
            custom.type = 'color';
            custom.className = 'mublo-editor-color-custom';
            custom.title = def.title;
            custom.addEventListener('input', e => {
                this._exec(def.command, e.target.value);
            });
            custom.addEventListener('click', e => e.stopPropagation());
            menu.appendChild(custom);

            btn.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                this._closeAllDropdowns();
                menu.classList.toggle('show');
            });

            wrap.appendChild(btn);
            wrap.appendChild(menu);
            return wrap;
        }

        _closeAllDropdowns() {
            this.toolbar.querySelectorAll('.mublo-editor-dropdown-menu.show').forEach(m => m.classList.remove('show'));
        }

        _saveSelection() {
            const sel = window.getSelection();
            if (sel.rangeCount > 0) {
                const range = sel.getRangeAt(0);
                if (this.contentArea.contains(range.commonAncestorContainer)) {
                    this.savedRange = range.cloneRange();
                }
            }
        }

        _restoreSelection() {
            this.contentArea.focus();
            if (this.savedRange) {
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(this.savedRange);
            }
        }

        _handleCommand(name, def) {
            this.contentArea.focus();
            this._withLocale(() => { switch (def.type) {
                case 'link': this._insertLink(); break;
                case 'image': this._openImageDialog(); break;
                case 'video': this._insertVideo(); break;
                case 'table': this._insertTable(); break;
                case 'codeblock': this._insertCodeBlock(); break;
                case 'fullscreen': this._toggleFullscreen(); break;
                case 'source': this._toggleSource(); break;
                case 'print': this._print(); break;
                case 'findreplace': this._toggleFindReplace(); break;
                default: this._exec(def.command, def.value);
            } });
        }

        _exec(cmd, val = null) {
            this._restoreSelection();

            if (cmd === 'fontSize') {
                this._applyInlineStyle('fontSize', val);
                return;
            }

            if (cmd === 'foreColor') {
                this._applyInlineStyle('color', val);
                return;
            }

            if (cmd === 'hiliteColor') {
                this._applyInlineStyle('backgroundColor', val);
                return;
            }

            document.execCommand(cmd, false, val);
            this._normalizeFormattingMarkup();
            this._saveSelection();
            this._onChange();
        }

        /**
         * \uc120\ud0dd \uc601\uc5ed\uc5d0 \uc778\ub77c\uc778 \uc2a4\ud0c0\uc77c \uc801\uc6a9.
         *
         * \uc0c8 span \uc73c\ub85c \uac10\uc2f8\uae30\ub9cc \ud558\uba74 \uc138 \uac00\uc9c0\uac00 \ud55c\uaebc\ubc88\uc5d0 \uc5b4\uae0b\ub09c\ub2e4.
         * - \uc774\ubbf8 \uc0c9\uc774 \uc788\ub294 \uae00\uc790\ub97c \ub2e4\uc2dc \uce60\ud558\uba74 \uc0c8 span \uc774 \ubc14\uae65\uc744 \uac10\uc2f8\ub294\ub370,
         *   CSS \ub294 \uc548\ucabd\uc774 \uc774\uae30\ubbc0\ub85c \ud654\uba74\uc774 \ubc14\ub00c\uc9c0 \uc54a\ub294\ub2e4
         * - \uac10\uc2f8\uae30\ub9cc \ud558\ub2c8 \uc911\ucca9\uc774 \uacc4\uc18d \uc313\uc778\ub2e4
         * - \uc801\uc6a9 \ud6c4 \uce90\ub7ff\uc774 span \ub05d\uc73c\ub85c \ubaa8\uc774\uace0, \uadf8 \uc0c1\ud0dc\uc5d0\uc11c \ub2e4\uc2dc \uace0\ub974\uba74
         *   \uc81c\ub85c\ud3ed \ubb38\uc790\ub9cc \ub4e0 \ube48 span \uc774 \uc0c8\ub85c \uc0dd\uae34\ub2e4
         *
         * \uadf8\ub798\uc11c \uc785\ud788\uae30 \uc804\uc5d0 \uc120\ud0dd \uc548\uc758 \uac19\uc740 \uc18d\uc131 \uc120\uc5b8\uc744 \uba3c\uc800 \uac77\uc5b4\ub0b4\uace0,
         * \ub05d\ub09c \ub4a4 \ube48 span\u00b7\uc778\uc811 \uc911\ubcf5\uc744 \uc815\ub9ac\ud55c\ub2e4.
         */
        _applyInlineStyle(property, value) {
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0 || !value) {
                return;
            }

            const range = sel.getRangeAt(0);
            if (!this.contentArea.contains(range.commonAncestorContainer)) {
                return;
            }

            if (range.collapsed) {
                // \uc774\uc5b4\uc11c \uc785\ub825\ud560 \uc0c9\uc744 \uc608\uc57d\ud558\ub294 \uc790\ub9ac. \uc9c1\uc804\uc5d0 \ub9cc\ub4e4\uc5b4 \ub454 \ube48 span \uc548\uc5d0
                // \uce90\ub7ff\uc774 \uc788\uc73c\uba74 \uadf8\uac83\uc744 \ub2e4\uc2dc \uc4f4\ub2e4 \u2014 \uace0\ub97c \ub54c\ub9c8\ub2e4 \uc0c8\ub85c \ub9cc\ub4e4\uba74 \uc313\uc778\ub2e4.
                const pending = this._findPendingStyleSpan(range.startContainer);
                const span = pending || document.createElement('span');

                span.style[property] = value;

                if (!pending) {
                    span.appendChild(document.createTextNode(ZERO_WIDTH));
                    range.insertNode(span);
                }

                const textNode = span.firstChild;
                range.setStart(textNode, textNode.length);
                range.collapse(true);
            } else {
                const fragment = range.extractContents();
                this._stripInlineProperty(fragment, property);

                const span = document.createElement('span');
                span.style[property] = value;
                span.appendChild(fragment);
                range.insertNode(span);

                // 선택이 기존 span 의 내용 전체였다면 그 span 은 껍데기만 남고
                // 새 span 이 그 안에 들어간다. 바깥 선언이 살아 있으면 안쪽이 이겨
                // 화면은 바뀌지만 중첩이 계속 쌓인다 — 조상에서도 같은 속성을 걷어낸다.
                this._stripRedundantAncestors(span, property);

                const parent = span.parentNode;
                this._cleanupInlineSpans(parent);

                // \uc815\ub9ac \uacfc\uc815\uc5d0\uc11c \ubcd1\ud569\ub3fc \uc0ac\ub77c\uc84c\uc744 \uc218 \uc788\ub2e4
                if (span.isConnected) {
                    range.selectNodeContents(span);
                } else {
                    range.selectNodeContents(parent);
                }
                range.collapse(false);
            }

            sel.removeAllRanges();
            sel.addRange(range);
            this._saveSelection();
            this._onChange();
        }

        /**
         * \uce90\ub7ff\uc774 "\uc544\uc9c1 \uc785\ub825\ub418\uc9c0 \uc54a\uc740" \uc2a4\ud0c0\uc77c \uc608\uc57d span \uc548\uc5d0 \uc788\uc73c\uba74 \uadf8 span \uc744 \uc900\ub2e4.
         * \uc81c\ub85c\ud3ed \ubb38\uc790\ub9cc \ub4e4\uc5b4 \uc788\ub294 span \uc774 \uadf8 \ud45c\uc2dd\uc774\ub2e4.
         */
        _findPendingStyleSpan(node) {
            const el = node.nodeType === Node.TEXT_NODE ? node.parentNode : node;
            if (!el || el.tagName !== 'SPAN' || !this.contentArea.contains(el)) {
                return null;
            }

            return el.childNodes.length === 1
                && el.firstChild.nodeType === Node.TEXT_NODE
                && el.firstChild.data === ZERO_WIDTH
                ? el
                : null;
        }

        /**
         * \uc870\uac01 \uc548\uc758 \ud574\ub2f9 CSS \uc18d\uc131 \uc120\uc5b8\uc744 \ubaa8\ub450 \uc81c\uac70\ud55c\ub2e4.
         * \uc120\uc5b8\uc774 \uc0ac\ub77c\uc838 \uc544\ubb34 \uc5ed\ud560\uc774 \uc5c6\uc5b4\uc9c4 span \uc740 \uaecd\ub370\uae30\ub97c \ubc97\uae34\ub2e4.
         */
        _stripInlineProperty(root, property) {
            Array.from(root.querySelectorAll('*')).forEach(el => {
                if (!el.style || !el.style[property]) {
                    return;
                }

                el.style[property] = '';

                if (el.tagName !== 'SPAN') {
                    return;
                }
                if (el.getAttribute('style') === '') {
                    el.removeAttribute('style');
                }
                if (el.attributes.length === 0) {
                    el.replaceWith(...el.childNodes);
                }
            });
        }

        /**
         * \uc0c8\ub85c \ub9cc\ub4e0 span \uc744 \uac10\uc2f8\uace0 \uc788\ub294 \uc870\uc0c1 span \ub4e4\uc5d0\uc11c \uac19\uc740 \uc18d\uc131 \uc120\uc5b8\uc744 \uac77\uc5b4\ub0b8\ub2e4.
         * \uadf8 span \uc774 \uc774 \uc790\uc2dd \ub9d0\uace0 \uc2e4\uc9c8\uc801\uc778 \ub0b4\uc6a9\uc744 \ub354 \uac00\uc9c0\uace0 \uc788\uc73c\uba74 \uac74\ub4dc\ub9ac\uc9c0 \uc54a\ub294\ub2e4 \u2014
         * \uc120\ud0dd\ud558\uc9c0 \uc54a\uc740 \uae00\uc790\uc758 \uc11c\uc2dd\uae4c\uc9c0 \ubc14\uafb8\uac8c \ub41c\ub2e4.
         */
        _stripRedundantAncestors(span, property) {
            let parent = span.parentNode;

            while (parent
                && parent.nodeType === Node.ELEMENT_NODE
                && parent.tagName === 'SPAN'
                && parent !== this.contentArea
                && this.contentArea.contains(parent)
            ) {
                const onlyWrapsThis = parent.textContent === span.textContent;
                if (!onlyWrapsThis || !parent.style[property]) {
                    break;
                }

                parent.style[property] = '';
                if (parent.getAttribute('style') === '') {
                    parent.removeAttribute('style');
                }

                const next = parent.parentNode;
                if (parent.attributes.length === 0) {
                    parent.replaceWith(...parent.childNodes);
                }
                parent = next;
            }
        }

        /**
         * \ube48 span \uc81c\uac70 + \uc2a4\ud0c0\uc77c\uc774 \uac19\uc740 \uc778\uc811 span \ubcd1\ud569.
         * \ud3b8\uc9d1\uc744 \ubc18\ubcf5\ud560\uc218\ub85d \uc313\uc774\ub294 \uaecd\ub370\uae30\ub97c \uadf8\ub54c\uadf8\ub54c \uac77\uc5b4\ub0b8\ub2e4.
         *
         * getHTML() \uc740 \ub77c\uc774\ube0c DOM \uc774 \uc544\ub2c8\ub77c \ud074\ub860\uc5d0\uc11c \ubd80\ub974\ubbc0\ub85c, \ubb38\uc11c \uc5f0\uacb0 \uc5ec\ubd80\uac00 \uc544\ub2c8\ub77c
         * \uc804\ub2ec\ubc1b\uc740 root \uae30\uc900\uc73c\ub85c \ud310\ub2e8\ud574\uc57c \ud55c\ub2e4.
         */
        _cleanupInlineSpans(root) {
            if (!root || root.nodeType !== Node.ELEMENT_NODE) {
                return;
            }

            Array.from(root.querySelectorAll('span')).forEach(el => {
                if (!root.contains(el)) {
                    return;
                }
                // \ub0b4\uc6a9\uc774 \uc544\uc608 \uc5c6\uac70\ub098 \uc81c\ub85c\ud3ed \ubb38\uc790\ub9cc \ub0a8\uc740 \uaecd\ub370\uae30.
                // \uce90\ub7ff\uc774 \ub4e4\uc5b4 \uc788\ub294 \uc608\uc57d span \uc740 \ud3b8\uc9d1 \uc911\uc774\ubbc0\ub85c \ub0a8\uae34\ub2e4.
                const text = el.textContent;
                if (el.children.length === 0 && (text === '' || (text === ZERO_WIDTH && !this._containsSelection(el)))) {
                    el.remove();
                    return;
                }
                // \uc2a4\ud0c0\uc77c\uc774 \uc5c6\uc5b4\uc9c4 span \uc740 \uaecd\ub370\uae30\ub2e4
                if (el.attributes.length === 0) {
                    el.replaceWith(...el.childNodes);
                }
            });

            Array.from(root.querySelectorAll('span')).forEach(el => {
                const next = el.nextSibling;
                if (!root.contains(el) || !next || next.nodeType !== Node.ELEMENT_NODE) {
                    return;
                }
                if (next.tagName !== 'SPAN' || next.getAttribute('style') !== el.getAttribute('style')) {
                    return;
                }
                while (next.firstChild) {
                    el.appendChild(next.firstChild);
                }
                next.remove();
            });
        }

        _containsSelection(el) {
            const sel = window.getSelection();
            return sel && sel.rangeCount > 0 && el.contains(sel.getRangeAt(0).startContainer);
        }

        _normalizeFormattingMarkup() {
            this._normalizeFormattingMarkupOn(this.contentArea);
        }

        /**
         * font 태그 → span 변환 (지정된 root 요소에서 수행)
         * getHTML()에서는 클론에서 호출 → 라이브 DOM 보호
         */
        _normalizeFormattingMarkupOn(root) {
            if (!root) return;

            const fontNodes = Array.from(root.querySelectorAll('font'));
            fontNodes.forEach(node => {
                const span = document.createElement('span');
                const color = node.getAttribute('color');
                const face = node.getAttribute('face');
                const size = node.getAttribute('size');
                const inlineStyle = node.getAttribute('style');

                if (color) span.style.color = color;
                if (face) span.style.fontFamily = face;
                if (size) {
                    const mappedSize = this._mapLegacyFontSize(size);
                    if (mappedSize) span.style.fontSize = mappedSize;
                }
                if (inlineStyle) {
                    const currentStyle = span.getAttribute('style');
                    span.setAttribute('style', currentStyle ? `${currentStyle}; ${inlineStyle}` : inlineStyle);
                }

                Array.from(node.attributes).forEach(attr => {
                    const name = attr.name.toLowerCase();
                    if (name !== 'color' && name !== 'face' && name !== 'size' && name !== 'style') {
                        span.setAttribute(attr.name, attr.value);
                    }
                });

                while (node.firstChild) {
                    span.appendChild(node.firstChild);
                }
                node.replaceWith(span);
            });
        }

        _mapLegacyFontSize(size) {
            const sizeMap = {
                '1': '10px',
                '2': '13px',
                '3': '16px',
                '4': '18px',
                '5': '24px',
                '6': '32px',
                '7': '48px'
            };

            return sizeMap[String(size).trim()] || '';
        }

        // =========================================================
        // 모달 시스템
        // =========================================================
        _createModal(title, bodyHtml, primaryBtnText = null, onPrimaryClick = null) {
            if (primaryBtnText === null) primaryBtnText = _t('confirm');
            const existingModal = document.getElementById('mublo-editor-modal');
            if (existingModal) existingModal.remove();

            const modal = document.createElement('div');
            modal.id = 'mublo-editor-modal';
            modal.className = 'mublo-editor-modal';
            modal.innerHTML = `
                <div class="mublo-editor-modal-backdrop"></div>
                <div class="mublo-editor-modal-dialog">
                    <div class="mublo-editor-modal-header">
                        <h5>${title}</h5>
                        <button type="button" class="mublo-editor-modal-close">&times;</button>
                    </div>
                    <div class="mublo-editor-modal-body">${bodyHtml}</div>
                    <div class="mublo-editor-modal-footer">
                        <div></div>
                        <div>
                            <button type="button" class="mublo-editor-modal-btn mublo-editor-modal-btn-secondary" id="mublo-editor-modal-cancel">${_t('cancel')}</button>
                            <button type="button" class="mublo-editor-modal-btn mublo-editor-modal-btn-primary" id="mublo-editor-modal-confirm">${primaryBtnText}</button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            const closeBtn = modal.querySelector('.mublo-editor-modal-close');
            const cancelBtn = modal.querySelector('#mublo-editor-modal-cancel');
            const confirmBtn = modal.querySelector('#mublo-editor-modal-confirm');
            const backdrop = modal.querySelector('.mublo-editor-modal-backdrop');

            const closeModal = () => {
                modal.classList.add('mublo-editor-modal-closing');
                setTimeout(() => modal.remove(), 200);
                this._restoreSelection();
            };

            closeBtn.addEventListener('click', closeModal);
            cancelBtn.addEventListener('click', closeModal);
            backdrop.addEventListener('click', closeModal);

            if (onPrimaryClick) {
                confirmBtn.addEventListener('click', () => {
                    if (onPrimaryClick(modal) !== false) {
                        closeModal();
                    }
                });
            }

            // ESC 닫기
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);

            // 첫 번째 입력창 포커스
            const firstInput = modal.querySelector('input, select, textarea');
            if (firstInput) setTimeout(() => firstInput.focus(), 50);

            return modal;
        }

        _insertLink() {
            this._saveSelection();
            const sel = window.getSelection();
            const text = sel.toString();
            
            const body = `
                <div class="mublo-editor-modal-form-group">
                    <label class="mublo-editor-modal-label">${_t('linkUrl')}</label>
                    <input type="text" class="mublo-editor-modal-input" id="mublo-editor-link-url" value="https://" placeholder="https://example.com">
                </div>
                <div class="mublo-editor-modal-form-group">
                    <label class="mublo-editor-modal-label">${_t('linkText')}</label>
                    <input type="text" class="mublo-editor-modal-input" id="mublo-editor-link-text" value="${escapeHtml(text)}">
                </div>
                <div class="mublo-editor-modal-check">
                    <input type="checkbox" id="mublo-editor-link-target" checked>
                    <label for="mublo-editor-link-target">${_t('linkNewTab')}</label>
                </div>
            `;

            this._createModal(_t('linkInsert'), body, _t('insert'), (modal) => {
                const url = modal.querySelector('#mublo-editor-link-url').value.trim();
                const label = modal.querySelector('#mublo-editor-link-text').value.trim();
                const target = modal.querySelector('#mublo-editor-link-target').checked ? '_blank' : '_self';

                if (!url || url === 'https://') return false;

                const rel = target === '_blank' ? ' rel="noopener noreferrer"' : '';
                const html = `<a href="${escapeHtml(url)}" target="${target}"${rel}>${escapeHtml(label || url)}</a>`;
                this._exec('insertHTML', html);
            });
        }

        _openImageDialog() {
            // 현재 커서 위치 저장 (모달이 열리면 포커스 소실)
            this._saveSelection();
            this._openImageModal();
        }

        _openImageModal() {
            // 기존 모달이 있으면 제거
            const existingModal = document.getElementById('mublo-editor-modal');
            if (existingModal) existingModal.remove();

            // 모달 생성
            const modal = document.createElement('div');
            modal.id = 'mublo-editor-modal';
            modal.className = 'mublo-editor-modal';
            modal.innerHTML = `
                <div class="mublo-editor-modal-backdrop"></div>
                <div class="mublo-editor-modal-dialog">
                    <div class="mublo-editor-modal-header">
                        <h5>${_t('imageAdd')}</h5>
                        <button type="button" class="mublo-editor-modal-close">&times;</button>
                    </div>
                    <div class="mublo-editor-modal-body">
                        <div class="mublo-editor-image-upload-zone" id="mublo-editor-upload-zone">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="3" y="3" width="18" height="18" rx="2"/>
                                <circle cx="8.5" cy="8.5" r="1.5"/>
                                <path d="M21 15l-5-5L5 21"/>
                            </svg>
                            <p>${_t('imageDragOrClick')}</p>
                            <p class="mublo-editor-image-upload-hint">${_t('imageHint')}</p>
                            <input type="file" id="mublo-editor-image-input" accept="image/*" multiple hidden>
                        </div>
                        <div class="mublo-editor-image-url-input">
                            <input type="text" id="mublo-editor-image-url" placeholder="${_t('imageUrlPlaceholder')}">
                            <button type="button" id="mublo-editor-image-url-add">${_t('imageUrlAdd')}</button>
                        </div>
                        <div class="mublo-editor-image-preview-list" id="mublo-editor-preview-list">
                        </div>
                        <div class="mublo-editor-image-meta" id="mublo-editor-image-meta" style="display:none;">
                            <div class="mublo-editor-modal-form-group">
                                <label class="mublo-editor-modal-label">${_t('imageAlt')}</label>
                                <input type="text" class="mublo-editor-modal-input" id="mublo-editor-image-alt" placeholder="${_t('imageAltPlaceholder')}">
                            </div>
                            <div class="mublo-editor-modal-form-group">
                                <label class="mublo-editor-modal-label">${_t('imageCaption')}</label>
                                <input type="text" class="mublo-editor-modal-input" id="mublo-editor-image-caption" placeholder="${_t('imageCaptionPlaceholder')}">
                            </div>
                        </div>
                        <p class="mublo-editor-image-drag-hint" id="mublo-editor-drag-hint" style="display:none;">
                            ${_t('imageDragHint')}
                        </p>
                    </div>
                    <div class="mublo-editor-modal-footer">
                        <span class="mublo-editor-image-count">${_t('imageSelected')} <strong id="mublo-editor-image-count">0</strong>${_t('imageCount')}</span>
                        <div>
                            <button type="button" class="mublo-editor-modal-btn mublo-editor-modal-btn-secondary" id="mublo-editor-image-cancel">${_t('cancel')}</button>
                            <button type="button" class="mublo-editor-modal-btn mublo-editor-modal-btn-primary" id="mublo-editor-image-insert" disabled>${_t('insert')}</button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
            this._pendingImages = [];

            // 교체 모드일 때 UI 조정
            if (this._replacingImage) {
                modal.querySelector('.mublo-editor-modal-header h5').textContent = _t('imageReplace');
                modal.querySelector('#mublo-editor-image-insert').textContent = _t('imageUpdate');
                modal.querySelector('#mublo-editor-image-insert').disabled = false;
                modal.querySelector('#mublo-editor-image-input').removeAttribute('multiple');
                modal.querySelector('#mublo-editor-image-meta').style.display = 'block';
                modal.querySelector('#mublo-editor-image-alt').value = this._replacingImage.getAttribute('alt') || '';
                modal.querySelector('#mublo-editor-image-caption').value = this._getImageCaption(this._replacingImage);
            }

            // 외부 미디어 피커 확장 지점 (플러그인/패키지에서 탭 추가 가능)
            // 사용: editor.on('imageModalReady', function(e) { /* 탭 추가 */ })
            this.fire('imageModalReady', { modal: modal });

            this._setupImageModal(modal);
        }

        _setupImageModal(modal) {
            const uploadZone = modal.querySelector('#mublo-editor-upload-zone');
            const fileInput = modal.querySelector('#mublo-editor-image-input');
            const urlInput = modal.querySelector('#mublo-editor-image-url');
            const urlAddBtn = modal.querySelector('#mublo-editor-image-url-add');
            const previewList = modal.querySelector('#mublo-editor-preview-list');
            const insertBtn = modal.querySelector('#mublo-editor-image-insert');
            const cancelBtn = modal.querySelector('#mublo-editor-image-cancel');
            const closeBtn = modal.querySelector('.mublo-editor-modal-close');
            const backdrop = modal.querySelector('.mublo-editor-modal-backdrop');
            const countEl = modal.querySelector('#mublo-editor-image-count');
            const dragHint = modal.querySelector('#mublo-editor-drag-hint');

            // 파일 선택
            uploadZone.addEventListener('click', () => fileInput.click());

            fileInput.addEventListener('change', () => {
                this._addFilesToPreview(Array.from(fileInput.files), previewList, countEl, insertBtn, dragHint);
                fileInput.value = '';
            });

            // 드래그 앤 드롭
            uploadZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadZone.classList.add('mublo-editor-image-upload-zone-active');
            });
            uploadZone.addEventListener('dragleave', () => {
                uploadZone.classList.remove('mublo-editor-image-upload-zone-active');
            });
            uploadZone.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadZone.classList.remove('mublo-editor-image-upload-zone-active');
                const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
                this._addFilesToPreview(files, previewList, countEl, insertBtn, dragHint);
            });

            // URL로 추가
            urlAddBtn.addEventListener('click', () => {
                const url = urlInput.value.trim();
                if (url) {
                    this._addUrlToPreview(url, previewList, countEl, insertBtn, dragHint);
                    urlInput.value = '';
                }
            });
            urlInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    urlAddBtn.click();
                }
            });

            // 닫기
            const closeModal = () => {
                modal.classList.add('mublo-editor-modal-closing');
                setTimeout(() => modal.remove(), 200);
                this._pendingImages = [];
                this._replacingImage = null;
            };
            closeBtn.addEventListener('click', closeModal);
            cancelBtn.addEventListener('click', closeModal);
            backdrop.addEventListener('click', closeModal);

            // 삽입
            insertBtn.addEventListener('click', async () => {
                insertBtn.disabled = true;
                insertBtn.textContent = _t('uploading');
                const replaceMode = !!this._replacingImage;
                const targetImage = this._replacingImage;
                const altInput = modal.querySelector('#mublo-editor-image-alt');
                const captionInput = modal.querySelector('#mublo-editor-image-caption');
                const altText = altInput ? altInput.value.trim() : '';
                const captionText = captionInput ? captionInput.value.trim() : '';

                for (const item of this._pendingImages) {
                    if (item.type === 'file') {
                        await this._handleImageUpload(item.file);
                    } else if (item.type === 'url') {
                        this.insertImage(item.url, altText);
                    }
                    if (replaceMode) {
                        break;
                    }
                }

                if (replaceMode && targetImage) {
                    this._applyImageMetadata(targetImage, altText, captionText);
                    this._onChange();
                }

                closeModal();
            });

            // ESC로 닫기
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);

            // 드래그로 순서 변경
            this._setupPreviewDragSort(previewList);
        }

        _addFilesToPreview(files, previewList, countEl, insertBtn, dragHint) {
            if (this._replacingImage) {
                files = files.slice(0, 1);
                this._pendingImages = [];
                previewList.innerHTML = '';
            }
            files.forEach(file => {
                if (!file.type.startsWith('image/')) return;

                const reader = new FileReader();
                reader.onload = (e) => {
                    const id = 'img-' + Date.now() + '-' + Math.random().toString(36).substring(2, 11);
                    this._pendingImages.push({ id, type: 'file', file, preview: e.target.result });
                    this._renderPreviewItem(id, e.target.result, file.name, previewList, countEl, insertBtn, dragHint);
                };
                reader.readAsDataURL(file);
            });
        }

        _addUrlToPreview(url, previewList, countEl, insertBtn, dragHint) {
            if (this._replacingImage) {
                this._pendingImages = [];
                previewList.innerHTML = '';
            }
            const id = 'img-' + Date.now() + '-' + Math.random().toString(36).substring(2, 11);
            this._pendingImages.push({ id, type: 'url', url });
            this._renderPreviewItem(id, url, url.split('/').pop() || _t('urlImage'), previewList, countEl, insertBtn, dragHint);
        }

        _renderPreviewItem(id, src, name, previewList, countEl, insertBtn, dragHint) {
            const item = document.createElement('div');
            item.className = 'mublo-editor-image-preview-item';
            item.dataset.id = id;
            item.draggable = true;
            item.innerHTML = `
                <img src="${escapeHtml(src)}" alt="${escapeHtml(name)}">
                <span class="mublo-editor-image-preview-name" title="${escapeHtml(name)}">${escapeHtml(name.length > 20 ? name.substring(0, 17) + '...' : name)}</span>
                <button type="button" class="mublo-editor-image-preview-remove" title="${_t('imageRemove')}">&times;</button>
                <span class="mublo-editor-image-preview-order">${this._pendingImages.length}</span>
            `;

            // 제거 버튼
            item.querySelector('.mublo-editor-image-preview-remove').addEventListener('click', (e) => {
                e.stopPropagation();
                this._pendingImages = this._pendingImages.filter(img => img.id !== id);
                item.remove();
                this._updatePreviewOrder(previewList);
                this._updateImageCount(countEl, insertBtn, dragHint);
            });

            previewList.appendChild(item);
            this._updateImageCount(countEl, insertBtn, dragHint);
        }

        _updateImageCount(countEl, insertBtn, dragHint) {
            const count = this._pendingImages.length;
            countEl.textContent = count;
            insertBtn.disabled = count === 0;
            dragHint.style.display = count > 1 ? 'block' : 'none';
        }

        _updatePreviewOrder(previewList) {
            const items = previewList.querySelectorAll('.mublo-editor-image-preview-item');
            items.forEach((item, index) => {
                item.querySelector('.mublo-editor-image-preview-order').textContent = index + 1;
            });
        }

        _setupPreviewDragSort(previewList) {
            let draggedItem = null;

            previewList.addEventListener('dragstart', (e) => {
                if (e.target.classList.contains('mublo-editor-image-preview-item')) {
                    draggedItem = e.target;
                    e.target.classList.add('mublo-editor-image-preview-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                }
            });

            previewList.addEventListener('dragend', (e) => {
                if (e.target.classList.contains('mublo-editor-image-preview-item')) {
                    e.target.classList.remove('mublo-editor-image-preview-dragging');
                    draggedItem = null;
                }
            });

            previewList.addEventListener('dragover', (e) => {
                e.preventDefault();
                const afterElement = this._getDragAfterElement(previewList, e.clientY);
                if (draggedItem) {
                    if (afterElement == null) {
                        previewList.appendChild(draggedItem);
                    } else {
                        previewList.insertBefore(draggedItem, afterElement);
                    }
                }
            });

            previewList.addEventListener('drop', (e) => {
                e.preventDefault();
                // 순서 재정렬
                const newOrder = [];
                previewList.querySelectorAll('.mublo-editor-image-preview-item').forEach(item => {
                    const id = item.dataset.id;
                    const img = this._pendingImages.find(i => i.id === id);
                    if (img) newOrder.push(img);
                });
                this._pendingImages = newOrder;
                this._updatePreviewOrder(previewList);
            });
        }

        _getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.mublo-editor-image-preview-item:not(.mublo-editor-image-preview-dragging)')];

            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        // =========================================================
        // 이미지 업로드 처리 (플러그인 지원)
        // =========================================================
        async _handleImageUpload(file) {
            _activeInstanceLocale = this._locale;
            // 파일 타입 체크
            if (!this.options.allowedImageTypes.includes(file.type)) {
                this.fire('uploadError', { error: _t('invalidImageType'), file });
                this._showToast(_t('invalidImageType'), 'error');
                return;
            }

            // 파일 크기는 검사하지 않는다. 허용 크기는 업로드 엔드포인트와 php.ini 가 정하고
            // 에디터는 그 값을 모른다. 초과 시 서버가 실제 한도를 담은 메시지로 응답한다.

            // BlobInfo 생성
            const base64 = await fileToBase64(file);
            const blobInfo = new BlobInfo(file, base64);

            // 진행률 콜백
            const progress = (percent) => {
                this._showProgress(percent);
                this.fire('uploadProgress', { percent, blobInfo });
            };

            // 업로드 시작 이벤트
            this.fire('uploadStart', { blobInfo });

            try {
                let imageUrl;

                // 1. 플러그인에서 설정한 핸들러 (최우선)
                if (this._imageUploadHandler) {
                    imageUrl = await this._imageUploadHandler(blobInfo, progress);
                }
                // 2. 옵션으로 전달된 레거시 스타일 핸들러
                else if (this.options.images_upload_handler) {
                    imageUrl = await new Promise((resolve, reject) => {
                        this.options.images_upload_handler(blobInfo, resolve, reject, progress);
                    });
                }
                // 3. 옵션으로 전달된 콜백 (하위 호환성)
                else if (this.options.onImageUpload) {
                    const result = await this.options.onImageUpload(file, this);
                    imageUrl = result?.url;
                }
                // 4. uploadUrl 설정된 경우 기본 업로드
                else if (this.options.uploadUrl) {
                    imageUrl = await this._defaultUpload(blobInfo, progress);
                }
                // 5. 폴백: Base64 인라인 (권장하지 않음)
                else {
                    console.warn('[MubloEditor] No uploadUrl configured. Using Base64 inline embedding. This may cause storage issues. Set the uploadUrl option.');
                    imageUrl = `data:${file.type};base64,${base64}`;
                    this.fire('uploadWarning', {
                        message: 'Base64 fallback used. Consider setting uploadUrl option.',
                        blobInfo
                    });
                }

                if (imageUrl) {
                    this.insertImage(imageUrl, file.name);
                    this.fire('uploadSuccess', { url: imageUrl, blobInfo });
                }

            } catch (error) {
                console.error('[MubloEditor] Image upload failed:', error);
                this.fire('uploadError', { error: error.message || error, blobInfo });
                this._showToast(_t('uploadFailed'), 'error');
            } finally {
                this._hideProgress();
            }
        }

        async _defaultUpload(blobInfo, progress) {
            const formData = new FormData();
            formData.append('file', blobInfo.blob(), blobInfo.filename());

            const xhr = new XMLHttpRequest();
            
            return new Promise((resolve, reject) => {
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        progress(Math.round((e.loaded / e.total) * 100));
                    }
                });

                xhr.addEventListener('load', () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            resolve(response.url || response.location || response.data?.url);
                        } catch (e) {
                            reject(new Error('Invalid server response'));
                        }
                    } else {
                        reject(new Error(`Upload failed: ${xhr.status}`));
                    }
                });

                xhr.addEventListener('error', () => reject(new Error('Upload failed')));
                xhr.addEventListener('abort', () => reject(new Error('Upload aborted')));

                xhr.open('POST', this.options.uploadUrl);

                // 프레임워크 CSRF 토큰 전송 (있으면)
                if (this.options.uploadCsrfToken) {
                    xhr.setRequestHeader('X-CSRF-Token', this.options.uploadCsrfToken);
                }

                // CSRF 토큰이 있으면 세션 쿠키를 함께 보내야 프레임워크에서 검증 가능
                if (this.options.images_upload_credentials || this.options.uploadCsrfToken) {
                    xhr.withCredentials = true;
                }

                xhr.send(formData);
            });
        }

        _showProgress(percent) {
            this.progressBar.style.display = 'block';
            this.progressBar.querySelector('.mublo-editor-progress-bar').style.width = percent + '%';
        }

        _hideProgress() {
            this.progressBar.style.display = 'none';
            this.progressBar.querySelector('.mublo-editor-progress-bar').style.width = '0%';
        }

        _insertVideo() {
            this._saveSelection();
            const body = `
                <div class="mublo-editor-modal-form-group">
                    <label class="mublo-editor-modal-label">${_t('videoUrl')}</label>
                    <input type="text" class="mublo-editor-modal-input" id="mublo-editor-video-url" placeholder="${_t('videoUrlPlaceholder')}">
                </div>
            `;

            this._createModal(_t('videoInsert'), body, _t('insert'), (modal) => {
                const url = modal.querySelector('#mublo-editor-video-url').value.trim();
                const embedUrl = this._parseVideoUrl(url);
                if (!embedUrl) {
                    this._showToast(_t('unsupportedUrl'), 'error');
                    return false;
                }
                this.insertVideo(url);
            });
        }

        _parseVideoUrl(url) {
            let parsed;
            try {
                parsed = new URL(url);
            } catch (e) {
                return null;
            }

            const host = parsed.hostname.toLowerCase().replace(/^www\./, '');
            const path = parsed.pathname;

            if (host === 'youtu.be') {
                const id = path.split('/').filter(Boolean)[0];
                return /^[a-zA-Z0-9_-]{11}$/.test(id || '')
                    ? `https://www.youtube.com/embed/${id}`
                    : null;
            }

            if (host === 'youtube.com' || host === 'm.youtube.com') {
                let id = parsed.searchParams.get('v');

                const embedMatch = path.match(/^\/embed\/([a-zA-Z0-9_-]{11})$/);
                if (!id && embedMatch) {
                    id = embedMatch[1];
                }

                const shortsMatch = path.match(/^\/shorts\/([a-zA-Z0-9_-]{11})$/);
                if (!id && shortsMatch) {
                    id = shortsMatch[1];
                }

                return /^[a-zA-Z0-9_-]{11}$/.test(id || '')
                    ? `https://www.youtube.com/embed/${id}`
                    : null;
            }

            if (host === 'youtube-nocookie.com') {
                const match = path.match(/^\/embed\/([a-zA-Z0-9_-]{11})$/);
                return match ? `https://www.youtube-nocookie.com/embed/${match[1]}` : null;
            }

            if (host === 'vimeo.com') {
                const id = path.split('/').filter(Boolean)[0];
                return /^\d+$/.test(id || '')
                    ? `https://player.vimeo.com/video/${id}`
                    : null;
            }

            if (host === 'player.vimeo.com') {
                const match = path.match(/^\/video\/(\d+)$/);
                return match ? `https://player.vimeo.com/video/${match[1]}` : null;
            }

            return null;
        }

        _insertTable() {
            this._saveSelection();
            const body = `
                <div class="mublo-editor-table-picker">
                    <div class="mublo-editor-table-grid" id="mublo-editor-table-grid"></div>
                    <div class="mublo-editor-table-info" id="mublo-editor-table-info">0 x 0</div>
                </div>
            `;

            const modal = this._createModal(_t('tableInsert'), body, _t('insert'), () => {
                // 그리드 클릭 시 이미 삽입되므로 확인 버튼은 닫기 역할만 하거나 비활성화
                return true;
            });

            // 그리드 생성 (10x10)
            const grid = modal.querySelector('#mublo-editor-table-grid');
            const info = modal.querySelector('#mublo-editor-table-info');
            
            for (let i = 0; i < 100; i++) {
                const cell = document.createElement('div');
                cell.className = 'mublo-editor-table-cell';
                cell.dataset.idx = i;
                grid.appendChild(cell);
            }

            const cells = grid.querySelectorAll('.mublo-editor-table-cell');
            
            grid.addEventListener('mouseover', (e) => {
                if (!e.target.classList.contains('mublo-editor-table-cell')) return;
                const idx = parseInt(e.target.dataset.idx);
                const row = Math.floor(idx / 10) + 1;
                const col = (idx % 10) + 1;
                info.textContent = `${row} x ${col}`;
                
                cells.forEach((c, i) => {
                    const r = Math.floor(i / 10) + 1;
                    const cIdx = (i % 10) + 1;
                    c.classList.toggle('active', r <= row && cIdx <= col);
                });
            });

            grid.addEventListener('click', () => {
                const [rows, cols] = info.textContent.split(' x ').map(Number);
                if (rows > 0 && cols > 0) {
                    let html = '<table class="table table-bordered" style="width:100%; border-collapse:collapse;"><tbody>';
                    for (let r = 0; r < rows; r++) {
                        html += '<tr>';
                        for (let c = 0; c < cols; c++) html += '<td style="border:1px solid #dee2e6; padding:8px;">&nbsp;</td>';
                        html += '</tr>';
                    }
                    html += '</tbody></table>';
                    this._exec('insertHTML', html);
                    modal.querySelector('.mublo-editor-modal-close').click();
                }
            });
        }

        _print() {
            const content = this.getHTML();
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Print</title>
                    <style>body{font-family:sans-serif;padding:20px;line-height:1.6}img{max-width:100%}</style>
                </head>
                <body>
                    ${content}
                    <script>window.onload=function(){window.print();window.close();}<\/script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }

        _toggleFullscreen() {
            this.isFullscreen = !this.isFullscreen;
            this.wrapper.classList.toggle('mublo-editor-fullscreen', this.isFullscreen);
            document.body.classList.toggle('mublo-editor-noscroll', this.isFullscreen);
            const btn = this.toolbar.querySelector('[data-cmd="fullscreen"]');
            if (btn) btn.innerHTML = this.isFullscreen ? TOOLBAR_ICONS.fullscreenExit : TOOLBAR_ICONS.fullscreen;
            this.fire('fullscreenStateChanged', { state: this.isFullscreen });
            if (this._getToolbarMedia()?.matches) this._renderResponsiveToolbar();
        }

        _toggleSource() {
            this.isSourceMode = !this.isSourceMode;
            if (this.isSourceMode) {
                this.sourceArea.value = this._formatHTML(convertCodeHtmlToShortcodes(this.contentArea.innerHTML));
                this.contentArea.style.display = 'none';
                this.sourceArea.style.display = 'block';
            } else {
                const sourceValue = convertCodeShortcodesToHtml(this.sourceArea.value);
                this.contentArea.innerHTML = this.options.sanitize ? sanitizeHtml(sourceValue) : sourceValue;
                this.sourceArea.style.display = 'none';
                this.contentArea.style.display = 'block';
                // WYSIWYG 복귀 시 코드 블록 구문 강조 재적용
                this._highlightAllCodeBlocks();
            }

            // 툴바 버튼 활성/비활성화 처리 (소스 모드에서는 편집 도구 잠금)
            this.toolbar.querySelectorAll('.mublo-editor-btn').forEach(btn => {
                const cmd = btn.dataset.cmd;
                if (cmd !== 'source' && cmd !== 'fullscreen') {
                    btn.disabled = this.isSourceMode;
                }
            });

            const btn = this.toolbar.querySelector('[data-cmd="source"]');
            if (btn) btn.classList.toggle('active', this.isSourceMode);
            this._onChange();
            this.fire('sourceModeChanged', { state: this.isSourceMode });
            if (this._getToolbarMedia()?.matches) this._renderResponsiveToolbar();
        }

        _formatHTML(html) {
            // 블록 태그 주위에 줄바꿈을 추가하여 가독성을 높임 (내용은 건드리지 않음)
            const tokens = html.split(/(<[^>]+>)/g);
            let formatted = '';
            const blockTags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th', 'blockquote', 'pre', 'hr', 'header', 'footer', 'section', 'article', 'aside', 'nav', 'style'];
            
            for (let i = 0; i < tokens.length; i++) {
                const token = tokens[i];
                if (!token) continue;
                
                const isTag = token.startsWith('<') && token.endsWith('>');
                if (isTag) {
                    const tagNameMatch = token.match(/^<\/?([a-z0-9]+)/i);
                    const tagName = tagNameMatch ? tagNameMatch[1].toLowerCase() : '';
                    const isBlock = blockTags.includes(tagName);
                    
                    if (isBlock) {
                        const isClosing = token.startsWith('</');
                        if (!isClosing) {
                            // 여는 태그: 앞에 줄바꿈
                            if (formatted.length > 0 && !formatted.endsWith('\n')) formatted += '\n';
                            formatted += token;
                        } else {
                            // 닫는 태그: 뒤에 줄바꿈
                            formatted += token;
                            if (i < tokens.length - 1) formatted += '\n';
                        }
                    } else {
                        formatted += token;
                    }
                } else {
                    formatted += token;
                }
            }
            
            // 연속된 줄바꿈 제거 및 정리
            return formatted.replace(/\n\s*\n/g, '\n').trim();
        }

        // =========================================================
        // 찾기/바꾸기
        // =========================================================
        _toggleFindReplace() {
            if (this.findReplaceBar && this.findReplaceBar.style.display !== 'none') {
                this._closeFindReplace();
                return;
            }
            this._openFindReplace();
        }

        _openFindReplace() {
            if (!this.findReplaceBar) {
                this._buildFindReplaceBar();
            }
            this.findReplaceBar.style.display = 'flex';
            this.findReplaceBar.querySelector('.mublo-editor-find-input').focus();
            const btn = this.toolbar.querySelector('[data-cmd="findreplace"]');
            if (btn) btn.classList.add('active');
        }

        _closeFindReplace() {
            if (this.findReplaceBar) {
                this.findReplaceBar.style.display = 'none';
            }
            this._clearHighlights();
            const btn = this.toolbar.querySelector('[data-cmd="findreplace"]');
            if (btn) btn.classList.remove('active');
        }

        _buildFindReplaceBar() {
            this.findReplaceBar = document.createElement('div');
            this.findReplaceBar.className = 'mublo-editor-findreplace';
            this.findReplaceBar.innerHTML = `
                <input type="text" class="mublo-editor-find-input" placeholder="${_t('findPlaceholder')}">
                <input type="text" class="mublo-editor-replace-input" placeholder="${_t('replacePlaceholder')}">
                <span class="mublo-editor-find-count"></span>
                <button type="button" class="mublo-editor-btn mublo-editor-find-prev" title="${_t('findPrev')}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
                </button>
                <button type="button" class="mublo-editor-btn mublo-editor-find-next" title="${_t('findNext')}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <button type="button" class="mublo-editor-btn mublo-editor-replace-one" title="${_t('replaceOne')}">${_t('replaceOne')}</button>
                <button type="button" class="mublo-editor-btn mublo-editor-replace-all" title="${_t('replaceAll')}">${_t('replaceAll')}</button>
                <button type="button" class="mublo-editor-btn mublo-editor-find-close" title="${_t('findClose')}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            `;

            // 이벤트 바인딩
            const findInput = this.findReplaceBar.querySelector('.mublo-editor-find-input');
            const replaceInput = this.findReplaceBar.querySelector('.mublo-editor-replace-input');

            findInput.addEventListener('input', () => this._doFind(findInput.value));
            findInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this._findNext();
                }
                if (e.key === 'Escape') {
                    this._closeFindReplace();
                }
            });

            replaceInput.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    this._closeFindReplace();
                }
            });

            this.findReplaceBar.querySelector('.mublo-editor-find-prev').addEventListener('click', () => this._findPrev());
            this.findReplaceBar.querySelector('.mublo-editor-find-next').addEventListener('click', () => this._findNext());
            this.findReplaceBar.querySelector('.mublo-editor-replace-one').addEventListener('click', () => this._replaceOne());
            this.findReplaceBar.querySelector('.mublo-editor-replace-all').addEventListener('click', () => this._replaceAll());
            this.findReplaceBar.querySelector('.mublo-editor-find-close').addEventListener('click', () => this._closeFindReplace());

            this.toolbar.parentNode.insertBefore(this.findReplaceBar, this.toolbar.nextSibling);
            this._findMatches = [];
            this._currentMatchIndex = -1;
        }

        _collectMatches(query) {
            this._findMatches = [];
            if (!query) return;

            const walker = document.createTreeWalker(this.contentArea, NodeFilter.SHOW_TEXT, null, false);
            const textNodes = [];
            while (walker.nextNode()) textNodes.push(walker.currentNode);

            const regex = new RegExp(query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
            textNodes.forEach(node => {
                let m;
                while ((m = regex.exec(node.textContent)) !== null) {
                    this._findMatches.push({ node, index: m.index, length: m[0].length, text: m[0] });
                }
            });
        }

        _doFind(query) {
            this._clearHighlights();
            this._currentMatchIndex = -1;

            const countEl = this.findReplaceBar.querySelector('.mublo-editor-find-count');
            if (!query || query.length < 1) {
                this._findMatches = [];
                countEl.textContent = '';
                return;
            }

            this._collectMatches(query);
            countEl.textContent = this._findMatches.length > 0 ? _t('foundCount', { count: this._findMatches.length }) : _t('noResult');

            if (this._findMatches.length > 0) {
                this._currentMatchIndex = 0;
                this._highlightMatch(0);
            }
        }

        _highlightMatch(index) {
            this._clearHighlights();
            if (index < 0 || index >= this._findMatches.length) return;

            const match = this._findMatches[index];
            const range = document.createRange();
            range.setStart(match.node, match.index);
            range.setEnd(match.node, match.index + match.length);

            const highlight = document.createElement('span');
            highlight.className = 'mublo-editor-highlight';
            range.surroundContents(highlight);
            highlight.scrollIntoView({ behavior: 'smooth', block: 'center' });

            const countEl = this.findReplaceBar.querySelector('.mublo-editor-find-count');
            countEl.textContent = `${index + 1} / ${this._findMatches.length}`;
        }

        _clearHighlights() {
            this.contentArea.querySelectorAll('.mublo-editor-highlight').forEach(el => {
                const parent = el.parentNode;
                while (el.firstChild) parent.insertBefore(el.firstChild, el);
                parent.removeChild(el);
            });
            this.contentArea.normalize();
        }

        _findNext() {
            if (this._findMatches.length === 0) return;
            const query = this.findReplaceBar.querySelector('.mublo-editor-find-input').value;
            this._clearHighlights();
            this._collectMatches(query);
            this._currentMatchIndex = (this._currentMatchIndex + 1) % this._findMatches.length;
            this._highlightMatch(this._currentMatchIndex);
        }

        _findPrev() {
            if (this._findMatches.length === 0) return;
            const query = this.findReplaceBar.querySelector('.mublo-editor-find-input').value;
            this._clearHighlights();
            this._collectMatches(query);
            this._currentMatchIndex = (this._currentMatchIndex - 1 + this._findMatches.length) % this._findMatches.length;
            this._highlightMatch(this._currentMatchIndex);
        }

        _replaceOne() {
            const findInput = this.findReplaceBar.querySelector('.mublo-editor-find-input');
            const replaceInput = this.findReplaceBar.querySelector('.mublo-editor-replace-input');
            const query = findInput.value;
            const replacement = replaceInput.value;

            if (!query || this._findMatches.length === 0) return;

            this._clearHighlights();
            this._collectMatches(query);

            if (this._currentMatchIndex >= 0 && this._currentMatchIndex < this._findMatches.length) {
                const match = this._findMatches[this._currentMatchIndex];
                const range = document.createRange();
                range.setStart(match.node, match.index);
                range.setEnd(match.node, match.index + match.length);
                range.deleteContents();
                range.insertNode(document.createTextNode(replacement));
                this.contentArea.normalize();
                this._onChange();
            }

            this._doFind(query);
        }

        _replaceAll() {
            const findInput = this.findReplaceBar.querySelector('.mublo-editor-find-input');
            const replaceInput = this.findReplaceBar.querySelector('.mublo-editor-replace-input');
            const query = findInput.value;
            const replacement = replaceInput.value;

            if (!query) return;

            this._clearHighlights();

            // innerHTML에서 직접 치환 (텍스트만)
            const walker = document.createTreeWalker(this.contentArea, NodeFilter.SHOW_TEXT, null, false);
            const textNodes = [];
            while (walker.nextNode()) {
                textNodes.push(walker.currentNode);
            }

            const regex = new RegExp(query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
            let count = 0;

            textNodes.forEach(node => {
                const original = node.textContent;
                const replaced = original.replace(regex, () => {
                    count++;
                    return replacement;
                });
                if (original !== replaced) {
                    node.textContent = replaced;
                }
            });

            this._onChange();
            this._doFind(query);

            const countEl = this.findReplaceBar.querySelector('.mublo-editor-find-count');
            countEl.textContent = _t('replacedCount', { count });
        }

        _bindEvents() {
            // 한국어 IME 조합 상태 추적
            this._isComposing = false;
            this.contentArea.addEventListener('compositionstart', () => {
                this._isComposing = true;
            });
            this.contentArea.addEventListener('compositionend', () => {
                this._isComposing = false;
                this._enforceMaxLength();
                this._onChange(); // 조합 완료 후 반영
                this._maybeScheduleHighlight();
            });

            this.contentArea.addEventListener('input', () => {
                if (this._isComposing) return; // IME 조합 중에는 무시
                this._enforceMaxLength();
                this._onChange();
                this._maybeScheduleHighlight();
            });
            this.contentArea.addEventListener('focus', () => {
                this.wrapper.classList.add('focused');
                this._ensureParagraphSeparator();
                this.options.onFocus?.(this);
                this.fire('focus');
            });
            this.contentArea.addEventListener('blur', () => {
                this.wrapper.classList.remove('focused');
                this.sync();
                this.options.onBlur?.(this);
                this.fire('blur');
            });
            this.contentArea.addEventListener('keydown', e => this._onKeydown(e));
            this.contentArea.addEventListener('keyup', e => {
                // Backspace/Delete 후 빈 상태 보정 — keyup에서 처리해야 삭제가 완료된 후 체크
                if (e.key === 'Backspace' || e.key === 'Delete') {
                    this._ensureNotEmpty();
                }
                this._saveSelection();
            });
            this.contentArea.addEventListener('mouseup', () => this._saveSelection());
            this.contentArea.addEventListener('paste', e => this._onPaste(e));
            this.contentArea.addEventListener('drop', e => this._onDrop(e));
            this.contentArea.addEventListener('dragover', e => e.preventDefault());
            
            // 전역 클릭 핸들러 (드롭다운 닫기)
            this._handlers.docClick = (e) => {
                if (this.toolbar && !this.toolbar.contains(e.target)) this._closeAllDropdowns();
            };
            document.addEventListener('click', this._handlers.docClick);

            this._bindResponsiveToolbar();

            if (this.options.autofocus) setTimeout(() => this.focus(), 100);
            this._updateWordCount();
            this._initAutosave();
            this._initImageResizer();
            this._initTableEditing();
            this._initCodeBlockEditing();
            this.options.onReady?.(this);
        }

        // =========================================================
        // 자동 저장
        // =========================================================
        _getAutosaveKey() {
            return this.options.autosaveKey || `mublo-editor-autosave-${this.id}`;
        }

        _initAutosave() {
            if (!this.options.autosave) return;

            // 저장된 내용 복원 — confirm 대신 이벤트 + 배너 UI
            if (this.options.autosaveRestore) {
                const saved = this.getAutosavedContent();
                if (saved && saved.content && !this.originalElement.value) {
                    const eventData = { saved, editor: this, handled: false };
                    this.fire('autosaveRestoreAvailable', eventData);
                    if (!eventData.handled) {
                        this._showAutosaveRestoreBanner(saved);
                    }
                }
            }

            // 주기적 자동 저장 시작
            this._startAutosave();
        }

        _showAutosaveRestoreBanner(saved) {
            const dateStr = new Date(saved.timestamp).toLocaleString();
            const banner = document.createElement('div');
            banner.className = 'mublo-editor-autosave-banner';
            banner.innerHTML = `
                <span>${_t('autosaveRestore', { date: dateStr }).replace('\n', ' ')}</span>
                <button type="button" class="mublo-editor-modal-btn mublo-editor-modal-btn-primary mublo-editor-autosave-restore">${_t('autosaveRestoreBtn')}</button>
                <button type="button" class="mublo-editor-modal-btn mublo-editor-modal-btn-secondary mublo-editor-autosave-ignore">${_t('autosaveIgnoreBtn')}</button>
            `;
            this.wrapper.insertBefore(banner, this.contentArea);

            banner.querySelector('.mublo-editor-autosave-restore').addEventListener('click', () => {
                this.setHTML(saved.content);
                banner.remove();
            });
            banner.querySelector('.mublo-editor-autosave-ignore').addEventListener('click', () => {
                banner.remove();
            });
        }

        _startAutosave() {
            if (!this.options.autosave || this._autosaveTimer) return;

            this._autosaveTimer = setInterval(() => {
                this._doAutosave();
            }, this.options.autosaveInterval);
        }

        _stopAutosave() {
            if (this._autosaveTimer) {
                clearInterval(this._autosaveTimer);
                this._autosaveTimer = null;
            }
        }

        _doAutosave() {
            if (this.isEmpty()) return;

            const key = this._getAutosaveKey();
            const data = {
                content: this.getHTML(),
                timestamp: Date.now(),
                id: this.id
            };

            try {
                localStorage.setItem(key, JSON.stringify(data));
                this.fire('autosave', data);
            } catch (e) {
                console.error('[MubloEditor] Autosave failed:', e);
                this.fire('autosaveError', { error: e.message });
            }
        }

        getAutosavedContent() {
            const key = this._getAutosaveKey();
            try {
                const data = localStorage.getItem(key);
                return data ? JSON.parse(data) : null;
            } catch (e) {
                return null;
            }
        }

        clearAutosave() {
            const key = this._getAutosaveKey();
            localStorage.removeItem(key);
            this.fire('autosaveClear');
            return this;
        }

        saveNow() {
            this._doAutosave();
            return this;
        }

        _onKeydown(e) {
            // IME 조합 중에는 단축키 처리 안 함
            if (this._isComposing || e.isComposing) return;

            // 이미지 선택 상태에서 Delete/Backspace → 이미지 삭제
            if (this._selectedImage && (e.key === 'Delete' || e.key === 'Backspace')) {
                e.preventDefault();
                this._selectedImage.remove();
                this._hideResizer();
                this.fire('change');
                return;
            }

            // 마크다운 단축키 (Space / Enter 시점 검사)
            if (e.key === ' ' || e.key === 'Enter') {
                if (this._handleMarkdownKeydown(e)) {
                    this.fire('keydown', { originalEvent: e });
                    return;
                }
            }

            const isMac = /Mac|iPhone|iPad|iPod/.test(navigator.userAgent);
            const mod = isMac ? e.metaKey : e.ctrlKey;
            if (mod) {
                const key = e.key.toLowerCase();
                if (key === 'b') { e.preventDefault(); this._exec('bold'); }
                if (key === 'i') { e.preventDefault(); this._exec('italic'); }
                if (key === 'u') { e.preventDefault(); this._exec('underline'); }
                if (key === 'k') { e.preventDefault(); this._insertLink(); }
                if (key === 'f') { e.preventDefault(); this._openFindReplace(); }
                if (key === 'h') { e.preventDefault(); this._openFindReplace(); }
                if (key === 'z') { e.preventDefault(); this._exec(e.shiftKey ? 'redo' : 'undo'); }
                if (key === 'y') { e.preventDefault(); this._exec('redo'); }
            }
            if (e.key === 'Tab') {
                e.preventDefault();
                // 코드 블록 내부에서는 2스페이스 들여쓰기 / 내어쓰기
                if (this._getClosestCodeBlock()) {
                    if (e.shiftKey) this._codeOutdent();
                    else this._codeIndent();
                } else {
                    this._exec(e.shiftKey ? 'outdent' : 'indent');
                }
            }
            this.fire('keydown', { originalEvent: e });
        }

        // =========================================================
        // 커서/블록 위치 헬퍼 (마크다운 · 코드블록 공용)
        // =========================================================
        /** 현재 커서가 속한 블록 요소를 반환 (없으면 null) */
        _getCurrentBlock() {
            const sel = window.getSelection();
            if (!sel.rangeCount) return null;
            let node = sel.getRangeAt(0).startContainer;
            if (node.nodeType === 3) node = node.parentNode;
            const blockRe = /^(P|DIV|H[1-6]|LI|BLOCKQUOTE|PRE|TD|TH)$/;
            while (node && node !== this.contentArea) {
                if (node.tagName && blockRe.test(node.tagName)) return node;
                node = node.parentNode;
            }
            return null;
        }

        /** 현재 커서가 코드 블록(pre) 내부에 있으면 해당 pre 요소를 반환 */
        _getClosestCodeBlock() {
            const sel = window.getSelection();
            if (!sel.rangeCount) return null;
            let node = sel.getRangeAt(0).startContainer;
            if (node.nodeType === 3) node = node.parentNode;
            while (node && node !== this.contentArea) {
                if (node.tagName === 'PRE') return node;
                node = node.parentNode;
            }
            return null;
        }

        /** block 시작부터 (node, offset)까지의 순수 텍스트 */
        _textBeforeCaret(block, node, offset) {
            try {
                const range = document.createRange();
                range.setStart(block, 0);
                range.setEnd(node, offset);
                return range.toString();
            } catch (e) {
                return '';
            }
        }

        /** 마크다운 변환 성공 시 잠깐 시각적 피드백 */
        _flashMarkdown() {
            this.wrapper.classList.add('mublo-editor-md-flash');
            clearTimeout(this._mdFlashTimer);
            this._mdFlashTimer = setTimeout(() => {
                this.wrapper.classList.remove('mublo-editor-md-flash');
            }, 250);
        }

        // =========================================================
        // 마크다운 단축키 (Space / Enter 시점 검사)
        // =========================================================
        /** @returns {boolean} 처리 여부 (true면 호출부에서 기본 동작 취소됨) */
        _handleMarkdownKeydown(e) {
            if (this._isComposing || e.isComposing || this.isSourceMode) return false;

            const sel = window.getSelection();
            if (!sel.isCollapsed || !sel.rangeCount) return false;

            // 코드 블록 내부에서는 마크다운 변환하지 않음
            if (this._getClosestCodeBlock()) return false;

            const range = sel.getRangeAt(0);
            const node = range.startContainer;
            const offset = range.startOffset;

            if (e.key === ' ') {
                // 1) 블록 레벨 변환 — 토큰이 블록 맨 앞에 있을 때
                if (this._handleBlockMarkdown(e, node, offset)) return true;
                // 2) 인라인 변환 (**굵게** 등)
                if (this._handleInlineMarkdown(e, node, offset)) return true;
                return false;
            }

            if (e.key === 'Enter') {
                // --- → 수평선, ``` → 코드 블록
                if (this._handleEnterMarkdown(e)) return true;
                return false;
            }
            return false;
        }

        _handleBlockMarkdown(e, node, offset) {
            if (node.nodeType !== 3) return false;
            const block = this._getCurrentBlock();
            if (!block) return false;
            // 리스트 항목 안에서는 재변환하지 않음
            if (block.tagName === 'LI') return false;

            const before = this._textBeforeCaret(block, node, offset);
            const MAP = {
                '#': ['formatBlock', 'h1'],
                '##': ['formatBlock', 'h2'],
                '###': ['formatBlock', 'h3'],
                '-': ['insertUnorderedList', null],
                '*': ['insertUnorderedList', null],
                '1.': ['insertOrderedList', null],
                '>': ['formatBlock', 'blockquote'],
            };
            const rule = MAP[before];
            if (!rule) return false;

            e.preventDefault();
            // 토큰 텍스트 제거
            const del = document.createRange();
            del.setStart(block, 0);
            del.setEnd(node, offset);
            del.deleteContents();

            // 커서를 블록 앞으로 이동
            const sel = window.getSelection();
            const caret = document.createRange();
            caret.setStart(block, 0);
            caret.collapse(true);
            sel.removeAllRanges();
            sel.addRange(caret);

            this.contentArea.focus();
            document.execCommand(rule[0], false, rule[1]);
            this._normalizeFormattingMarkup();
            this._saveSelection();
            this._flashMarkdown();
            this._onChange();
            return true;
        }

        _handleInlineMarkdown(e, node, offset) {
            if (node.nodeType !== 3) return false;
            const before = node.textContent.slice(0, offset);
            const RULES = [
                { re: /\*\*([^*\s](?:[^*]*[^*\s])?)\*\*$/, tag: 'strong' },
                { re: /__([^_\s](?:[^_]*[^_\s])?)__$/, tag: 'strong' },
                { re: /\*([^*\s](?:[^*]*[^*\s])?)\*$/, tag: 'em' },
                { re: /`([^`\s](?:[^`]*[^`\s])?)`$/, tag: 'code' },
            ];
            for (const rule of RULES) {
                const m = before.match(rule.re);
                if (!m) continue;

                e.preventDefault();
                const start = offset - m[0].length;
                const range = document.createRange();
                range.setStart(node, start);
                range.setEnd(node, offset);
                range.deleteContents();

                const el = document.createElement(rule.tag);
                el.textContent = m[1];
                range.insertNode(el);

                // 서식 밖으로 커서를 빼내기 위해 공백 텍스트 노드 삽입
                const spacer = document.createTextNode(' ');
                if (el.nextSibling) {
                    el.parentNode.insertBefore(spacer, el.nextSibling);
                } else {
                    el.parentNode.appendChild(spacer);
                }
                const sel = window.getSelection();
                const caret = document.createRange();
                caret.setStart(spacer, 1);
                caret.collapse(true);
                sel.removeAllRanges();
                sel.addRange(caret);

                this._saveSelection();
                this._flashMarkdown();
                this._onChange();
                return true;
            }
            return false;
        }

        _handleEnterMarkdown(e) {
            const block = this._getCurrentBlock();
            if (!block || block.tagName === 'PRE' || block.tagName === 'LI') return false;

            const text = block.textContent.trim();

            // 수평선: ---, ***, ___ (3개 이상)
            if (/^(-{3,}|\*{3,}|_{3,})$/.test(text)) {
                e.preventDefault();
                const hr = document.createElement('hr');
                const p = this._makeEmptyParagraph();
                block.replaceWith(hr);
                hr.after(p);
                this._placeCaretIn(p);
                this._flashMarkdown();
                this._onChange();
                return true;
            }

            // 코드 블록: ``` 또는 ```언어
            const codeMatch = text.match(/^```([a-z0-9]*)$/i);
            if (codeMatch) {
                e.preventDefault();
                const lang = (codeMatch[1] || 'text').toLowerCase();
                this._convertBlockToCode(block, lang);
                this._flashMarkdown();
                return true;
            }
            return false;
        }

        _makeEmptyParagraph() {
            const p = document.createElement('p');
            p.appendChild(document.createElement('br'));
            return p;
        }

        _placeCaretIn(el) {
            const sel = window.getSelection();
            const range = document.createRange();
            range.setStart(el, 0);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
            this.contentArea.focus();
        }

        // =========================================================
        // 토스트 메시지
        // =========================================================
        _showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `mublo-editor-toast mublo-editor-toast-${type}`;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.3s'; }, 2500);
            setTimeout(() => toast.remove(), 3000);
        }

        // =========================================================
        // 이미지 hover 교체 안내 툴팁
        // =========================================================
        _showImageTooltip(img) {
            this._hideImageTooltip();
            const rect = img.getBoundingClientRect();

            const tip = document.createElement('div');
            tip.className = 'mublo-editor-img-tooltip';
            tip.innerHTML = _t('imageTooltip');
            document.body.appendChild(tip);
            this._imgTooltip = tip;

            const tipRect = tip.getBoundingClientRect();
            let top = rect.top + window.scrollY - tipRect.height - 8;
            let left = rect.left + window.scrollX + (rect.width / 2) - (tipRect.width / 2);

            if (left < 8) left = 8;
            if (left + tipRect.width > window.innerWidth - 8) left = window.innerWidth - tipRect.width - 8;
            if (top < window.scrollY + 8) top = rect.bottom + window.scrollY + 8;

            tip.style.top = top + 'px';
            tip.style.left = left + 'px';

            tip.addEventListener('mouseover', () => clearTimeout(this._imgTooltipTimer));
            tip.addEventListener('mouseout', () => {
                this._imgTooltipTimer = setTimeout(() => this._hideImageTooltip(), 1500);
            });
        }

        _hideImageTooltip() {
            if (this._imgTooltip) {
                this._imgTooltip.remove();
                this._imgTooltip = null;
            }
        }

        _getImageFigure(img) {
            const figure = img.closest('figure');
            return figure && this.contentArea.contains(figure) ? figure : null;
        }

        _ensureImageFigure(img) {
            let figure = this._getImageFigure(img);
            if (figure) return figure;

            figure = document.createElement('figure');
            figure.className = 'mublo-image';
            figure.style.margin = '1em 0';
            figure.style.textAlign = 'center';
            img.parentNode.insertBefore(figure, img);
            figure.appendChild(img);
            return figure;
        }

        _getImageCaption(img) {
            const figure = this._getImageFigure(img);
            const caption = figure?.querySelector('figcaption');
            return caption ? caption.textContent.trim() : '';
        }

        _applyImageMetadata(img, alt, caption) {
            img.setAttribute('alt', alt);

            const currentFigure = this._getImageFigure(img);
            const currentCaption = currentFigure?.querySelector('figcaption');

            if (!caption) {
                currentCaption?.remove();
                return;
            }

            const figure = currentFigure || this._ensureImageFigure(img);
            const figcaption = currentCaption || document.createElement('figcaption');
            figcaption.className = 'mublo-image-caption';
            figcaption.textContent = caption;

            if (!currentCaption) {
                figure.appendChild(figcaption);
            }
        }

        // =========================================================
        // 이미지 리사이저
        // =========================================================
        _initImageResizer() {
            // 이미지 클릭 시 리사이저 표시
            this.contentArea.addEventListener('click', (e) => {
                if (e.target.tagName === 'IMG') {
                    this._selectImage(e.target);
                } else {
                    this._hideResizer();
                }
            });

            // 이미지 hover 시 교체 안내 툴팁
            this._imgTooltip = null;
            this._imgTooltipTimer = null;

            this.contentArea.addEventListener('mouseover', (e) => {
                if (e.target.tagName !== 'IMG') return;
                clearTimeout(this._imgTooltipTimer);
                this._showImageTooltip(e.target);
            }, true);

            this.contentArea.addEventListener('mouseout', (e) => {
                if (e.target.tagName !== 'IMG') return;
                this._imgTooltipTimer = setTimeout(() => this._hideImageTooltip(), 1500);
            }, true);

            // 이미지 더블클릭 시 교체 다이얼로그 열기
            this.contentArea.addEventListener('dblclick', (e) => {
                if (e.target.tagName === 'IMG') {
                    e.preventDefault();
                    e.stopPropagation();
                    this._replacingImage = e.target;
                    // 모달 열기 전 잠깐 지연 — click 이벤트 처리 완료 후 실행
                    setTimeout(() => this._openImageModal(), 0);
                }
            });

            // 스크롤 시 리사이저 위치 업데이트
            this.contentArea.addEventListener('scroll', () => this._updateResizerPosition());
            
            // 윈도우 리사이즈 핸들러
            this._handlers.winResize = () => this._updateResizerPosition();
            window.addEventListener('resize', this._handlers.winResize);

            // 핸들 드래그 — 8방향 (모서리 4=비율 유지, 가운데 4=자유 크기)
            const MIN_SIZE = 50;
            const CORNERS = ['nw', 'ne', 'se', 'sw'];
            let startX, startY, startWidth, startHeight, activeDir, aspect;

            const onMouseMove = (e) => {
                if (!activeDir || !this._selectedImage) return;
                e.preventDefault();

                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                const isCorner = CORNERS.includes(activeDir);

                let newWidth = startWidth;
                let newHeight = startHeight;

                // 가로 변화량 (방향에 따라 부호 결정)
                if (activeDir.includes('e')) newWidth = startWidth + dx;
                else if (activeDir.includes('w')) newWidth = startWidth - dx;

                // 세로 변화량
                if (activeDir.includes('s')) newHeight = startHeight + dy;
                else if (activeDir.includes('n')) newHeight = startHeight - dy;

                if (isCorner) {
                    // 비율 유지 — 가로 기준으로 세로 계산
                    newWidth = Math.max(MIN_SIZE, newWidth);
                    newHeight = Math.round(newWidth / aspect);
                    if (newHeight < MIN_SIZE) {
                        newHeight = MIN_SIZE;
                        newWidth = Math.round(newHeight * aspect);
                    }
                    this._selectedImage.style.width = newWidth + 'px';
                    this._selectedImage.style.height = newHeight + 'px';
                } else if (activeDir === 'e' || activeDir === 'w') {
                    // 자유 — 가로만
                    newWidth = Math.max(MIN_SIZE, newWidth);
                    this._selectedImage.style.width = newWidth + 'px';
                } else {
                    // 자유 — 세로만 (n / s)
                    newHeight = Math.max(MIN_SIZE, newHeight);
                    this._selectedImage.style.height = newHeight + 'px';
                }

                this._updateResizerPosition();
                this._updateResizeSizeLabel();
            };

            const onMouseUp = () => {
                if (activeDir && this._selectedImage) {
                    // 최종 크기를 width/height 속성에 반영
                    const w = Math.round(this._selectedImage.offsetWidth);
                    const h = Math.round(this._selectedImage.offsetHeight);
                    this._selectedImage.setAttribute('width', w);
                    this._selectedImage.setAttribute('height', h);
                    this._onChange();
                }
                activeDir = null;
                this._resizer.classList.remove('mublo-editor-resizer-dragging');
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            };

            this._resizer.addEventListener('mousedown', (e) => {
                const handle = e.target.closest('.mublo-editor-resizer-handle');
                if (handle && this._selectedImage) {
                    e.preventDefault();
                    activeDir = handle.dataset.dir;
                    startX = e.clientX;
                    startY = e.clientY;
                    startWidth = this._selectedImage.offsetWidth;
                    startHeight = this._selectedImage.offsetHeight;
                    aspect = startHeight > 0 ? startWidth / startHeight : 1;
                    this._resizer.classList.add('mublo-editor-resizer-dragging');
                    this._updateResizeSizeLabel();
                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                }
            });
        }

        _updateResizeSizeLabel() {
            if (!this._selectedImage) return;
            const label = this._resizer.querySelector('.mublo-editor-resizer-size');
            if (label) {
                label.textContent = `${Math.round(this._selectedImage.offsetWidth)} × ${Math.round(this._selectedImage.offsetHeight)}`;
            }
        }

        _selectImage(img) {
            this._selectedImage = img;
            this._resizer.classList.add('active');
            this._updateResizerPosition();
            this._updateResizeSizeLabel();

            // contentArea에 포커스 확보 후 커서를 이미지 직후에 위치
            // selectNode 대신 setStartAfter 사용 — 브라우저 파란 selection 하이라이트 방지
            this.contentArea.focus();
            try {
                const range = document.createRange();
                range.setStartAfter(img);
                range.collapse(true);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            } catch (e) {
                // 범위 설정 실패 시 포커스만 유지
            }
        }

        _hideResizer() {
            this._selectedImage = null;
            this._resizer.classList.remove('active');
        }

        _updateResizerPosition() {
            if (!this._selectedImage) return;

            const imgRect = this._selectedImage.getBoundingClientRect();
            const wrapperRect = this.wrapper.getBoundingClientRect();

            // wrapper 기준 상대 좌표 계산
            const top = imgRect.top - wrapperRect.top;
            const left = imgRect.left - wrapperRect.left;

            this._resizer.style.top = top + 'px';
            this._resizer.style.left = left + 'px';
            this._resizer.style.width = imgRect.width + 'px';
            this._resizer.style.height = imgRect.height + 'px';
        }

        // =========================================================
        // 테이블 셀 편집 (컨텍스트 메뉴 · 행열 추가/삭제 · 병합/분할)
        // =========================================================
        _initTableEditing() {
            // 우클릭 → 셀 컨텍스트 메뉴
            this.contentArea.addEventListener('contextmenu', (e) => {
                const cell = e.target.closest && e.target.closest('td, th');
                if (cell && this.contentArea.contains(cell)) {
                    e.preventDefault();
                    this._showTableContextMenu(e.clientX, e.clientY, cell);
                }
            });

            // 셀 드래그로 다중 선택 (병합용)
            this._cellAnchor = null;
            this.contentArea.addEventListener('mousedown', (e) => {
                if (e.button !== 0) return;
                const cell = e.target.closest && e.target.closest('td, th');
                if (cell && this.contentArea.contains(cell)) {
                    this._cellAnchor = cell;
                    this._clearCellSelection();
                } else {
                    this._cellAnchor = null;
                    this._clearCellSelection();
                }
            });
            this.contentArea.addEventListener('mouseover', (e) => {
                if (!this._cellAnchor || !(e.buttons & 1)) return;
                const cell = e.target.closest && e.target.closest('td, th');
                if (cell && cell !== this._cellAnchor && this.contentArea.contains(cell)
                    && cell.closest('table') === this._cellAnchor.closest('table')) {
                    window.getSelection().removeAllRanges();
                    this._selectCellRange(this._cellAnchor, cell);
                }
            });

            // 전역 핸들러 등록 (destroy에서 제거)
            this._handlers.docMouseup = () => { this._cellAnchor = null; };
            this._handlers.docCloseTableMenu = (e) => {
                if (this._tableMenu && !this._tableMenu.contains(e.target)) {
                    this._hideTableContextMenu();
                }
            };
            this._handlers.docScrollCloseMenu = () => this._hideTableContextMenu();
            document.addEventListener('mouseup', this._handlers.docMouseup);
            document.addEventListener('mousedown', this._handlers.docCloseTableMenu);
            document.addEventListener('scroll', this._handlers.docScrollCloseMenu, true);
        }

        /** 표를 그리드(2차원 셀 참조 맵)로 전개 — colspan/rowspan 반영 */
        _buildTableGrid(table) {
            const grid = [];
            Array.from(table.rows).forEach((tr, r) => {
                if (!grid[r]) grid[r] = [];
                let c = 0;
                Array.from(tr.cells).forEach(cell => {
                    while (grid[r][c]) c++;
                    const rsp = cell.rowSpan || 1;
                    const csp = cell.colSpan || 1;
                    for (let dr = 0; dr < rsp; dr++) {
                        for (let dc = 0; dc < csp; dc++) {
                            if (!grid[r + dr]) grid[r + dr] = [];
                            grid[r + dr][c + dc] = cell;
                        }
                    }
                    c += csp;
                });
            });
            return grid;
        }

        _getCellCoord(grid, cell) {
            for (let r = 0; r < grid.length; r++) {
                const row = grid[r] || [];
                for (let c = 0; c < row.length; c++) {
                    if (row[c] === cell) return { r, c };
                }
            }
            return null;
        }

        _tableColumnCount(grid) {
            let max = 0;
            grid.forEach(row => { if (row && row.length > max) max = row.length; });
            return max;
        }

        _makeTableCell() {
            const td = document.createElement('td');
            td.style.cssText = 'border:1px solid #dee2e6; padding:8px;';
            td.innerHTML = '<br>';
            return td;
        }

        _selectCellRange(a, b) {
            const table = a.closest('table');
            if (!table) return;
            const grid = this._buildTableGrid(table);
            const ca = this._getCellCoord(grid, a);
            const cb = this._getCellCoord(grid, b);
            if (!ca || !cb) return;
            const minR = Math.min(ca.r, cb.r), maxR = Math.max(ca.r, cb.r);
            const minC = Math.min(ca.c, cb.c), maxC = Math.max(ca.c, cb.c);
            this._clearCellSelection();
            const set = new Set();
            for (let r = minR; r <= maxR; r++) {
                for (let c = minC; c <= maxC; c++) {
                    const cc = grid[r] && grid[r][c];
                    if (cc) set.add(cc);
                }
            }
            set.forEach(cc => cc.classList.add('mublo-editor-cell-selected'));
        }

        _getSelectedCells() {
            return Array.from(this.contentArea.querySelectorAll('.mublo-editor-cell-selected'));
        }

        _clearCellSelection() {
            this.contentArea.querySelectorAll('.mublo-editor-cell-selected')
                .forEach(c => c.classList.remove('mublo-editor-cell-selected'));
        }

        _showTableContextMenu(x, y, cell) {
            this._hideTableContextMenu();
            const canMerge = this._getSelectedCells().length >= 2;
            const canSplit = (cell.colSpan || 1) > 1 || (cell.rowSpan || 1) > 1;

            const items = [
                { label: _t('tableRowAbove'), fn: () => this._insertTableRow(cell, false) },
                { label: _t('tableRowBelow'), fn: () => this._insertTableRow(cell, true) },
                { sep: true },
                { label: _t('tableColLeft'), fn: () => this._insertTableColumn(cell, false) },
                { label: _t('tableColRight'), fn: () => this._insertTableColumn(cell, true) },
                { sep: true },
                { label: _t('tableRowDelete'), fn: () => this._deleteTableRow(cell), danger: true },
                { label: _t('tableColDelete'), fn: () => this._deleteTableColumn(cell), danger: true },
                { sep: true },
                { label: _t('tableMerge'), fn: () => this._mergeCells(), disabled: !canMerge },
                { label: _t('tableSplit'), fn: () => this._splitCell(cell), disabled: !canSplit },
                { sep: true },
                { label: _t('tableDelete'), fn: () => this._deleteTable(cell), danger: true },
            ];

            const menu = document.createElement('div');
            menu.className = 'mublo-editor-table-menu';
            items.forEach(it => {
                if (it.sep) {
                    const s = document.createElement('div');
                    s.className = 'mublo-editor-table-menu-sep';
                    menu.appendChild(s);
                    return;
                }
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mublo-editor-table-menu-item' + (it.danger ? ' danger' : '');
                btn.textContent = it.label;
                if (it.disabled) {
                    btn.disabled = true;
                } else {
                    btn.addEventListener('click', () => {
                        it.fn();
                        this._hideTableContextMenu();
                    });
                }
                menu.appendChild(btn);
            });

            document.body.appendChild(menu);
            this._tableMenu = menu;

            // 화면 밖으로 벗어나지 않게 위치 보정
            const rect = menu.getBoundingClientRect();
            let left = x, top = y;
            if (left + rect.width > window.innerWidth - 8) left = window.innerWidth - rect.width - 8;
            if (top + rect.height > window.innerHeight - 8) top = window.innerHeight - rect.height - 8;
            menu.style.left = Math.max(8, left) + 'px';
            menu.style.top = Math.max(8, top) + 'px';
        }

        _hideTableContextMenu() {
            if (this._tableMenu) {
                this._tableMenu.remove();
                this._tableMenu = null;
            }
        }

        _insertTableRow(cell, below) {
            const tr = cell.parentNode;
            const table = cell.closest('table');
            if (!tr || !table) return;
            const cols = this._tableColumnCount(this._buildTableGrid(table));
            const newTr = document.createElement('tr');
            for (let i = 0; i < cols; i++) newTr.appendChild(this._makeTableCell());
            if (below) tr.after(newTr); else tr.before(newTr);
            this._clearCellSelection();
            this._onChange();
        }

        _insertTableColumn(cell, right) {
            const table = cell.closest('table');
            if (!table) return;
            const grid = this._buildTableGrid(table);
            const coord = this._getCellCoord(grid, cell);
            if (!coord) return;
            const insertAt = right ? coord.c + (cell.colSpan || 1) : coord.c;

            Array.from(table.rows).forEach((tr, r) => {
                const rowGrid = grid[r] || [];
                const td = this._makeTableCell();
                const occupant = rowGrid[insertAt];
                if (occupant && occupant.parentNode === tr) {
                    tr.insertBefore(td, occupant);
                } else {
                    // 이 위치가 다른 행(rowspan)에서 내려온 셀이면, 같은 행의 다음 셀 앞에 삽입
                    let ref = null;
                    for (let c = insertAt; c < rowGrid.length; c++) {
                        if (rowGrid[c] && rowGrid[c].parentNode === tr) { ref = rowGrid[c]; break; }
                    }
                    if (ref) tr.insertBefore(td, ref); else tr.appendChild(td);
                }
            });
            this._clearCellSelection();
            this._onChange();
        }

        _deleteTableRow(cell) {
            const tr = cell.parentNode;
            const table = cell.closest('table');
            if (!tr || !table) return;
            if (table.rows.length <= 1) { this._deleteTable(cell); return; }
            tr.remove();
            this._clearCellSelection();
            this._onChange();
        }

        _deleteTableColumn(cell) {
            const table = cell.closest('table');
            if (!table) return;
            const grid = this._buildTableGrid(table);
            const coord = this._getCellCoord(grid, cell);
            if (!coord) return;
            if (this._tableColumnCount(grid) <= 1) { this._deleteTable(cell); return; }

            const removed = new Set();
            grid.forEach(row => {
                const cc = row && row[coord.c];
                if (cc && !removed.has(cc)) {
                    removed.add(cc);
                    if ((cc.colSpan || 1) > 1) {
                        cc.colSpan = cc.colSpan - 1;
                        if (cc.colSpan <= 1) cc.removeAttribute('colspan');
                    } else {
                        cc.remove();
                    }
                }
            });
            Array.from(table.rows).forEach(tr => { if (tr.cells.length === 0) tr.remove(); });
            this._clearCellSelection();
            this._onChange();
        }

        _deleteTable(cell) {
            const table = cell.closest('table');
            if (table) {
                const p = this._makeEmptyParagraph();
                table.replaceWith(p);
                this._placeCaretIn(p);
            }
            this._clearCellSelection();
            this._onChange();
        }

        _mergeCells() {
            const cells = this._getSelectedCells();
            if (cells.length < 2) return;
            const table = cells[0].closest('table');
            if (!table) return;
            const grid = this._buildTableGrid(table);
            const selected = new Set(cells);

            let minR = Infinity, maxR = -1, minC = Infinity, maxC = -1;
            for (let r = 0; r < grid.length; r++) {
                const row = grid[r] || [];
                for (let c = 0; c < row.length; c++) {
                    if (selected.has(row[c])) {
                        minR = Math.min(minR, r); maxR = Math.max(maxR, r);
                        minC = Math.min(minC, c); maxC = Math.max(maxC, c);
                    }
                }
            }
            if (maxR < 0) return;

            const inRect = new Set();
            for (let r = minR; r <= maxR; r++) {
                for (let c = minC; c <= maxC; c++) {
                    const cc = grid[r] && grid[r][c];
                    if (cc) inRect.add(cc);
                }
            }
            const topLeft = grid[minR][minC];
            if (!topLeft) return;

            const parts = [];
            inRect.forEach(cc => {
                if (cc === topLeft) return;
                const html = cc.innerHTML.trim();
                if (html && html !== '<br>' && html !== '&nbsp;') parts.push(html);
            });
            if (parts.length) {
                const base = topLeft.innerHTML.trim();
                topLeft.innerHTML = (base === '<br>' || base === '' ? '' : base + ' ') + parts.join(' ');
            }
            topLeft.colSpan = maxC - minC + 1;
            topLeft.rowSpan = maxR - minR + 1;
            if (topLeft.colSpan <= 1) topLeft.removeAttribute('colspan');
            if (topLeft.rowSpan <= 1) topLeft.removeAttribute('rowspan');
            inRect.forEach(cc => { if (cc !== topLeft) cc.remove(); });
            Array.from(table.rows).forEach(tr => { if (tr.cells.length === 0) tr.remove(); });
            this._clearCellSelection();
            this._onChange();
        }

        _splitCell(cell) {
            const table = cell.closest('table');
            if (!table) return;
            const cs = cell.colSpan || 1, rs = cell.rowSpan || 1;
            if (cs === 1 && rs === 1) return;
            const coord = this._getCellCoord(this._buildTableGrid(table), cell);
            if (!coord) return;

            cell.colSpan = 1; cell.rowSpan = 1;
            cell.removeAttribute('colspan'); cell.removeAttribute('rowspan');

            // 같은 행: cell 뒤에 (cs-1)개 추가
            let prev = cell;
            for (let i = 0; i < cs - 1; i++) {
                const td = this._makeTableCell();
                prev.after(td);
                prev = td;
            }
            // 아래 행들: 각 행의 원래 열 위치에 cs개씩 삽입
            const rows = Array.from(table.rows);
            for (let dr = 1; dr < rs; dr++) {
                const tr = rows[coord.r + dr];
                if (!tr) continue;
                const rowGrid = this._buildTableGrid(table)[coord.r + dr] || [];
                let ref = null;
                for (let c = coord.c; c < rowGrid.length; c++) {
                    if (rowGrid[c] && rowGrid[c].parentNode === tr) { ref = rowGrid[c]; break; }
                }
                for (let i = 0; i < cs; i++) {
                    const td = this._makeTableCell();
                    if (ref) tr.insertBefore(td, ref); else tr.appendChild(td);
                }
            }
            this._clearCellSelection();
            this._onChange();
        }

        // =========================================================
        // 코드 블록 보강 (언어 선택 · Tab 들여쓰기 · 다크 스타일)
        // =========================================================
        _initCodeBlockEditing() {
            const update = () => this._updateCodeBlockUI();
            this.contentArea.addEventListener('keyup', update);
            this.contentArea.addEventListener('mouseup', update);
            this.contentArea.addEventListener('click', update);
            this.contentArea.addEventListener('focus', update);
            this.contentArea.addEventListener('scroll', update);
            this.contentArea.addEventListener('blur', () => {
                setTimeout(() => {
                    if (!this._codeLangBar.contains(document.activeElement)) this._hideCodeLangBar();
                }, 150);
            });
        }

        _createCodeElement(language) {
            const pre = document.createElement('pre');
            pre.className = 'mublo-code-block';
            pre.setAttribute('data-language', language);
            const code = document.createElement('code');
            code.appendChild(document.createElement('br'));
            pre.appendChild(code);
            return pre;
        }

        _insertCodeBlock(lang) {
            this.contentArea.focus();
            this._restoreSelection();
            const language = (lang || 'text').toLowerCase();
            const block = this._getCurrentBlock();
            const pre = this._createCodeElement(language);

            if (block && this.contentArea.contains(block) && block.tagName !== 'PRE') {
                if (block.textContent.trim() === '') block.replaceWith(pre);
                else block.after(pre);
            } else {
                this.contentArea.appendChild(pre);
            }
            if (!pre.nextSibling) pre.after(this._makeEmptyParagraph());

            const code = pre.querySelector('code');
            this._placeCaretIn(code || pre);
            this._updateCodeBlockUI();
            this._onChange();
        }

        _convertBlockToCode(block, language) {
            const pre = this._createCodeElement(language);
            block.replaceWith(pre);
            if (!pre.nextSibling) pre.after(this._makeEmptyParagraph());
            const code = pre.querySelector('code');
            this._placeCaretIn(code || pre);
            this._updateCodeBlockUI();
            this._onChange();
        }

        _codeIndent() {
            document.execCommand('insertText', false, '  ');
            this._onChange();
            this._maybeScheduleHighlight();
        }

        _codeOutdent() {
            const sel = window.getSelection();
            if (!sel.rangeCount) return;
            const range = sel.getRangeAt(0);
            const node = range.startContainer;
            if (node.nodeType !== 3) return;
            const text = node.textContent;
            const off = range.startOffset;
            const lineStart = text.lastIndexOf('\n', off - 1) + 1;
            let remove = 0;
            while (remove < 2 && text[lineStart + remove] === ' ') remove++;
            if (remove > 0) {
                node.textContent = text.slice(0, lineStart) + text.slice(lineStart + remove);
                const newOff = Math.max(lineStart, off - remove);
                const r = document.createRange();
                r.setStart(node, Math.min(newOff, node.textContent.length));
                r.collapse(true);
                sel.removeAllRanges();
                sel.addRange(r);
                this._onChange();
                this._maybeScheduleHighlight();
            }
        }

        _updateCodeBlockUI() {
            if (!this._codeLangBar) return;
            if (this.isSourceMode) { this._hideCodeLangBar(); return; }
            const pre = this._getClosestCodeBlock();
            if (!pre) { this._hideCodeLangBar(); return; }

            this._activeCodeBlock = pre;
            const select = this._codeLangBar.querySelector('.mublo-editor-code-lang-select');
            const lang = (pre.getAttribute('data-language') || 'text').toLowerCase();
            if (select) select.value = CODE_LANGUAGES.includes(lang) ? lang : 'text';

            // pre 위 우측 모서리에 위치
            this._codeLangBar.style.display = 'flex';
            const preRect = pre.getBoundingClientRect();
            const wrapperRect = this.wrapper.getBoundingClientRect();
            const barRect = this._codeLangBar.getBoundingClientRect();
            const top = preRect.top - wrapperRect.top + 4;
            let left = preRect.right - wrapperRect.left - barRect.width - 4;
            if (left < 4) left = 4;
            this._codeLangBar.style.top = top + 'px';
            this._codeLangBar.style.left = left + 'px';
        }

        _hideCodeLangBar() {
            this._activeCodeBlock = null;
            if (this._codeLangBar) this._codeLangBar.style.display = 'none';
        }

        // =========================================================
        // 코드 구문 강조 적용 (커서 위치 보존)
        // =========================================================
        /**
         * 코드 요소의 텍스트를 문자 오프셋이 그대로 유지되도록 직렬화한다.
         * (텍스트 노드는 그대로, <br>·블록요소 경계는 '\n' 1글자로 카운트)
         * 전체 텍스트 추출과 커서 오프셋 계산이 같은 함수를 쓰므로 오프셋이 정확히 일치한다.
         */
        _serializeCodeText(node) {
            let out = '';
            node.childNodes.forEach(child => {
                if (child.nodeType === 3) {
                    out += child.data;
                } else if (child.nodeName === 'BR') {
                    out += '\n';
                } else if (child.nodeType === 1) {
                    if (/^(DIV|P)$/.test(child.nodeName) && out && !out.endsWith('\n')) out += '\n';
                    out += this._serializeCodeText(child);
                }
            });
            return out;
        }

        /** 코드 요소 시작부터 현재 커서까지의 문자 오프셋 (<br>=1) */
        _getCodeCaretOffset(code) {
            const sel = window.getSelection();
            if (!sel.rangeCount) return null;
            const range = sel.getRangeAt(0);
            if (range.startContainer !== code && !code.contains(range.startContainer)) return null;
            try {
                const pre = document.createRange();
                pre.setStart(code, 0);
                pre.setEnd(range.startContainer, range.startOffset);
                return this._serializeCodeText(pre.cloneContents()).length;
            } catch (e) {
                return null;
            }
        }

        /** 오프셋 위치에 커서 복원 (강조 후 결과에는 <br> 없이 '\n' 텍스트만 존재) */
        _setCodeCaretOffset(code, offset) {
            if (offset == null) return;
            let remaining = offset;
            const walker = document.createTreeWalker(code, NodeFilter.SHOW_TEXT, null, false);
            let node, target = null, targetOffset = 0;
            while ((node = walker.nextNode())) {
                const len = node.data.length;
                if (remaining <= len) { target = node; targetOffset = remaining; break; }
                remaining -= len;
            }
            const sel = window.getSelection();
            const range = document.createRange();
            if (target) {
                range.setStart(target, targetOffset);
                range.collapse(true);
            } else {
                range.selectNodeContents(code);
                range.collapse(false);
            }
            sel.removeAllRanges();
            sel.addRange(range);
        }

        /** 입력/언어변경 시 활성 코드 블록 강조를 300ms 디바운스로 예약 */
        _maybeScheduleHighlight() {
            const pre = this._getClosestCodeBlock();
            if (pre) this._scheduleCodeHighlight(pre);
        }

        _scheduleCodeHighlight(pre) {
            clearTimeout(this._highlightTimer);
            this._highlightTimer = setTimeout(() => {
                this._highlightCodeBlock(pre, true);
            }, 300);
        }

        /** 단일 코드 블록에 구문 강조 적용 */
        _highlightCodeBlock(pre, preserveCaret) {
            if (!pre || !pre.isConnected) return;
            const code = pre.querySelector('code') || pre;
            const text = this._serializeCodeText(code);
            if (text.trim() === '') return; // 빈 블록/공백만 → 손대지 않음

            const lang = (pre.getAttribute('data-language') || 'text').toLowerCase();
            let caretOffset = null;
            if (preserveCaret && this._getClosestCodeBlock() === pre) {
                caretOffset = this._getCodeCaretOffset(code);
            }

            const html = highlightCodeToHtml(text, lang);
            if (code.innerHTML === html) return; // 변화 없으면 DOM 갱신 생략 (idle 시 무한 재계산 방지)

            code.innerHTML = html;
            if (caretOffset != null) this._setCodeCaretOffset(code, caretOffset);
            this._syncLight();
        }

        /** 콘텐츠 로드 시 모든 향상된 코드 블록 강조 (커서 없음) */
        _highlightAllCodeBlocks() {
            this.contentArea.querySelectorAll('pre.mublo-code-block').forEach(pre => {
                this._highlightCodeBlock(pre, false);
            });
        }

        _onPaste(e) {
            const items = e.clipboardData?.items;
            if (items && this.options.automatic_uploads) {
                for (const item of items) {
                    if (item.type.startsWith('image/')) {
                        e.preventDefault();
                        this._handleImageUpload(item.getAsFile());
                        return;
                    }
                }
            }
            if (this.options.sanitize) {
                const html = e.clipboardData?.getData('text/html');
                if (html) {
                    e.preventDefault();
                    this._exec('insertHTML', sanitizeHtml(html));
                }
            }
            this.fire('paste', { originalEvent: e });
        }

        _onDrop(e) {
            const files = e.dataTransfer?.files;
            if (files && this.options.automatic_uploads) {
                for (const file of files) {
                    if (file.type.startsWith('image/')) {
                        e.preventDefault();
                        this._handleImageUpload(file);
                    }
                }
            }
            this.fire('drop', { originalEvent: e });
        }

        _enforceMaxLength() {
            const max = this.options.maxLength;
            if (!max || max <= 0) return;
            const text = this.getText();
            if (text.length > max) {
                // Selection API로 초과분 truncate — IME 완료 후에만 동작
                const sel = window.getSelection();
                const range = document.createRange();
                const walker = document.createTreeWalker(this.contentArea, NodeFilter.SHOW_TEXT, null, false);
                let charCount = 0;
                while (walker.nextNode()) {
                    const node = walker.currentNode;
                    const remaining = max - charCount;
                    if (charCount + node.textContent.length > max) {
                        node.textContent = node.textContent.substring(0, remaining);
                        // 이후 노드 제거
                        while (walker.nextNode()) walker.currentNode.textContent = '';
                        // 커서를 truncate 지점에 배치
                        range.setStart(node, node.textContent.length);
                        range.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(range);
                        break;
                    }
                    charCount += node.textContent.length;
                }
                this.contentArea.normalize();
                this.fire('maxLengthExceeded', { max, length: text.length });
            }
        }

        _onChange() {
            // 입력 중에는 DOM을 건드리지 않는 경량 동기화만 수행
            // (getHTML()은 _normalizeFormattingMarkup()으로 DOM을 수정해서 커서가 날아감)
            this._syncLight();
            this._updateWordCount();
            // 콜백은 debounce로 지연 실행 (입력 중 커서 보호)
            clearTimeout(this._changeDebounce);
            this._changeDebounce = setTimeout(() => {
                this.options.onChange?.(this.getHTML(), this);
                this.fire('change', { content: this.getHTML() });
            }, 300);
        }

        /** 경량 동기화 — DOM 수정 없이 innerHTML만 textarea에 반영 */
        _syncLight() {
            if (this.isSourceMode) {
                this.originalElement.value = this.sourceArea.value;
            } else {
                this.originalElement.value = this.contentArea.innerHTML;
            }
        }

        _updateWordCount() {
            if (!this.options.showWordCount || !this.statusBar) return;

            const text = this.getText();
            const chars = text.length;
            const charsNoSpace = text.replace(/\s/g, '').length;
            const words = text.trim() ? text.trim().split(/\s+/).length : 0;

            let html = `${_t('chars')}: ${chars}`;
            if (this.options.maxLength > 0) {
                html += ` / ${this.options.maxLength}`;
                if (chars > this.options.maxLength) {
                    html = `<span class="mublo-editor-over-limit">${html}</span>`;
                }
            }
            html += ` | ${_t('charsNoSpace')}: ${charsNoSpace} | ${_t('words')}: ${words}`;

            this.statusBar.querySelector('.mublo-editor-wordcount').innerHTML = html;
            this.fire('wordcount', { chars, charsNoSpace, words, maxLength: this.options.maxLength });
        }

        _initPlugins() {
            plugins.forEach((fn, name) => {
                try { fn(this); } catch (e) { console.error(`Plugin ${name} error:`, e); }
            });
        }

        // =========================================================
        // Public API
        // =========================================================
        getHTML() {
            let html = this.isSourceMode ? this.sourceArea.value : this.contentArea.innerHTML;
            if (!this.isSourceMode) {
                // 클론에서 normalize 수행 — 라이브 DOM을 건드리지 않아 커서 보호
                const clone = this.contentArea.cloneNode(true);
                this._normalizeFormattingMarkupOn(clone);
                // 서식 예약용 빈 span 과 인접 중복은 저장본에 남기지 않는다.
                // 클론이라 편집 중인 캐럿에는 영향이 없다.
                this._cleanupInlineSpans(clone);
                // 편집용 임시 상태 클래스 제거 (셀 선택 하이라이트)
                clone.querySelectorAll('.mublo-editor-cell-selected')
                    .forEach(el => el.classList.remove('mublo-editor-cell-selected'));
                clone.querySelectorAll('[class=""]').forEach(el => el.removeAttribute('class'));
                html = clone.innerHTML;
            }
            html = this._formatHTML(convertCodeShortcodesToHtml(html));
            // 앞뒤 빈 <p> 태그 제거 (<p><br></p>, <p>&nbsp;</p>, <p></p> 등)
            html = html.replace(/^(\s*<p[^>]*>\s*(<br\s*\/?>|&nbsp;)?\s*<\/p>\s*)+/i, '');
            html = html.replace(/(\s*<p[^>]*>\s*(<br\s*\/?>|&nbsp;)?\s*<\/p>\s*)+$/i, '');
            return html;
        }
        
        setHTML(html) {
            let safe = this.options.sanitize ? sanitizeHtml(html) : html;
            safe = convertCodeShortcodesToHtml(safe);
            
            // 1. 내용이 없으면 기본 P 태그 삽입 (첫 줄 div 방지)
            if (!safe && !this.options.readonly) {
                safe = '<p><br></p>';
            } else if (!this.options.readonly) {
                // 2. 내용이 블록 태그로 시작하지 않으면 <p>로 감싸기 (평문 초기화 대응)
                const trimmed = safe.trim();
                const blockTags = ['<p', '<div', '<h1', '<h2', '<h3', '<h4', '<h5', '<h6', '<ul', '<ol', '<table', '<blockquote', '<pre', '<hr', '<style', '<section', '<article', '<header', '<footer', '<nav', '<aside'];
                const startsWithBlock = blockTags.some(tag => trimmed.toLowerCase().startsWith(tag));
                
                if (!startsWithBlock) {
                    safe = `<p>${safe}</p>`;
                }
            }
            this.contentArea.innerHTML = safe;
            // 로드된 향상된 코드 블록에 구문 강조 적용 (텍스트 기반이라 저장된 span 유무와 무관하게 정규화)
            this._highlightAllCodeBlocks();
            this.sourceArea.value = this.contentArea.innerHTML;
            this.sync();
            return this;
        }
        
        getText() { return this.contentArea.textContent || ''; }
        isEmpty() { return !this.getText().trim(); }

        getWordCount() {
            const text = this.getText();
            return {
                chars: text.length,
                charsNoSpace: text.replace(/\s/g, '').length,
                words: text.trim() ? text.trim().split(/\s+/).length : 0
            };
        }
        focus() { (this.isSourceMode ? this.sourceArea : this.contentArea).focus(); return this; }
        blur() { (this.isSourceMode ? this.sourceArea : this.contentArea).blur(); return this; }
        sync() { this.originalElement.value = this.getHTML(); return this; }

        setReadonly(readonly) {
            this.options.readonly = readonly;
            this.contentArea.contentEditable = !readonly;
            this.sourceArea.readOnly = readonly;
            this.wrapper.classList.toggle('mublo-editor-readonly', readonly);

            // 툴바 버튼 비활성화
            this.toolbar.querySelectorAll('.mublo-editor-btn').forEach(btn => {
                btn.disabled = readonly;
            });

            this.fire('readonlyStateChanged', { state: readonly });
            return this;
        }

        isReadonly() {
            return this.options.readonly;
        }

        enable() { return this.setReadonly(false); }
        disable() { return this.setReadonly(true); }
        
        insertContent(html) {
            this._restoreSelection();
            const safe = this.options.sanitize ? sanitizeHtml(html) : html;
            this._exec('insertHTML', safe);
            return this;
        }

        insertTrustedContent(html) {
            this._restoreSelection();
            this._exec('insertHTML', html);
            return this;
        }

        insertImage(url, alt = '') {
            // 교체 모드: 기존 이미지의 src를 교체
            if (this._replacingImage) {
                this._replacingImage.src = url;
                if (alt) this._replacingImage.alt = alt;
                if (!this._replacingImage.hasAttribute('loading')) {
                    this._replacingImage.setAttribute('loading', 'lazy');
                }
                this._hideResizer();
                this._replacingImage = null;
                this.fire('change');
                return this;
            }
            const html = `<figure class="mublo-image" style="margin:1em 0; text-align:center;"><img src="${escapeHtml(url)}" alt="${escapeHtml(alt)}" loading="lazy" style="max-width:100%; height:auto; border-radius:4px;"></figure>`;
            return this.insertContent(html);
        }

        insertVideo(url) {
            const embedUrl = this._parseVideoUrl(url);
            if (!embedUrl) {
                console.error('[MubloEditor] Invalid video URL:', url);
                return this;
            }
            const html = `<div class="mublo-editor-video-wrapper" contenteditable="false">
                <iframe src="${escapeHtml(embedUrl)}" frameborder="0" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
            </div>`;
            return this.insertContent(html);
        }

        destroy() {
            this.fire('destroy');
            this._stopAutosave();
            clearTimeout(this._highlightTimer);
            clearTimeout(this._mdFlashTimer);
            this.originalElement.style.display = '';
            
            // 전역 리스너 제거
            if (this._handlers.docClick) document.removeEventListener('click', this._handlers.docClick);
            if (this._handlers.winResize) window.removeEventListener('resize', this._handlers.winResize);
            if (this._handlers.docMouseup) document.removeEventListener('mouseup', this._handlers.docMouseup);
            if (this._handlers.docCloseTableMenu) document.removeEventListener('mousedown', this._handlers.docCloseTableMenu);
            if (this._handlers.docScrollCloseMenu) document.removeEventListener('scroll', this._handlers.docScrollCloseMenu, true);
            if (this._toolbarMedia && this._handlers.toolbarMediaChange) {
                if (typeof this._toolbarMedia.removeEventListener === 'function') {
                    this._toolbarMedia.removeEventListener('change', this._handlers.toolbarMediaChange);
                } else if (typeof this._toolbarMedia.removeListener === 'function') {
                    this._toolbarMedia.removeListener(this._handlers.toolbarMediaChange);
                }
            }

            // 부유 UI 정리
            this._hideTableContextMenu();
            this._hideImageTooltip();

            this.wrapper.remove();
            instances.delete(this.id);
            if (this.isFullscreen) document.body.classList.remove('mublo-editor-noscroll');
            this._eventListeners.clear();
        }
        
        getElement() { return this.contentArea; }
        getWrapper() { return this.wrapper; }
        getToolbar() { return this.toolbar; }
    }

    // =========================================================
    // 자동 초기화
    // =========================================================
    function autoInit() {
        document.querySelectorAll(`.${EDITOR_CLASS}`).forEach(el => {
            if (!instances.has(el.id || el)) new Editor(el);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        autoInit();
    }

    // =========================================================
    // Public API
    // =========================================================
    return {
        VERSION,
        
        create(selector, options = {}) {
            const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
            if (!el) { console.error('[MubloEditor] Element not found:', selector); return null; }
            if (el.id && instances.has(el.id)) return instances.get(el.id);
            return new Editor(el, options);
        },
        
        get(id) { return instances.get(id) || null; },
        getAll() { return Array.from(instances.values()); },
        destroy(id) { instances.get(id)?.destroy(); },
        destroyAll() { instances.forEach(e => e.destroy()); },
        
        registerPlugin(name, fn) {
            if (typeof fn !== 'function') return false;
            plugins.set(name, fn);
            // 이미 생성된 에디터에도 적용
            instances.forEach(e => { try { fn(e); } catch (err) { console.error(err); } });
            return true;
        },
        
        syncAll() { instances.forEach(e => e.sync()); },

        setLocale(locale) {
            if (LOCALE[locale]) {
                _globalLocale = locale;
            } else {
                console.warn(`[MubloEditor] Unknown locale: ${locale}`);
            }
        },

        addLocale(name, translations) {
            LOCALE[name] = { ...LOCALE.en, ...translations };
        },

        getLocale() { return _globalLocale; },

        // 상수 노출
        TOOLBAR_ITEMS: _getToolbarItems,
        TOOLBAR_PRESETS,
        DEFAULT_COLORS,
        BlobInfo
    };
})();

if (typeof module !== 'undefined' && module.exports) module.exports = MubloEditor;
