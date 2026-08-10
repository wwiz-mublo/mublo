/**
 * MubloEditor TypeScript 타입 정의
 * (c) 2025 Mublo
 * Licensed under the MIT License
 */

declare module 'mublo-editor' {
    export = MubloEditor;
}

declare const MubloEditor: MubloEditorStatic;

interface MubloEditorStatic {
    /** 에디터 버전 */
    readonly VERSION: string;

    /** 툴바 아이템 정의 */
    readonly TOOLBAR_ITEMS: Record<string, ToolbarItem>;

    /** 툴바 프리셋 */
    readonly TOOLBAR_PRESETS: {
        minimal: string[];
        basic: string[];
        full: string[];
    };

    /** 기본 색상 팔레트 */
    readonly DEFAULT_COLORS: string[];

    /** BlobInfo 클래스 */
    readonly BlobInfo: typeof BlobInfo;

    /**
     * 에디터 인스턴스 생성
     * @param selector CSS 선택자 또는 DOM 요소
     * @param options 에디터 옵션
     */
    create(selector: string | HTMLElement, options?: EditorOptions): Editor | null;

    /**
     * ID로 에디터 인스턴스 가져오기
     * @param id 에디터 ID
     */
    get(id: string): Editor | null;

    /**
     * 모든 에디터 인스턴스 반환
     */
    getAll(): Editor[];

    /**
     * 에디터 인스턴스 제거
     * @param id 에디터 ID
     */
    destroy(id: string): void;

    /**
     * 모든 에디터 인스턴스 제거
     */
    destroyAll(): void;

    /**
     * 플러그인 등록
     * @param name 플러그인 이름
     * @param fn 플러그인 초기화 함수
     */
    registerPlugin(name: string, fn: PluginFunction): boolean;

    /**
     * 모든 에디터 동기화
     */
    syncAll(): void;
}

interface Editor {
    /** 에디터 ID */
    readonly id: string;

    /** 원본 textarea 요소 */
    readonly originalElement: HTMLTextAreaElement;

    /** 에디터 옵션 */
    readonly options: EditorOptions;

    /** 전체화면 모드 여부 */
    isFullscreen: boolean;

    /** 소스 모드 여부 */
    isSourceMode: boolean;

    /**
     * HTML 콘텐츠 반환
     */
    getHTML(): string;

    /**
     * HTML 콘텐츠 설정
     * @param html HTML 문자열
     */
    setHTML(html: string): this;

    /**
     * 텍스트만 반환 (태그 제외)
     */
    getText(): string;

    /**
     * 에디터가 비어있는지 확인
     */
    isEmpty(): boolean;

    /**
     * 에디터에 포커스
     */
    focus(): this;

    /**
     * 포커스 해제
     */
    blur(): this;

    /**
     * textarea와 동기화
     */
    sync(): this;

    /**
     * HTML 콘텐츠 삽입 (sanitize 옵션이 켜져 있으면 정화 후 삽입)
     * @param html 삽입할 HTML
     */
    insertContent(html: string): this;

    /**
     * 신뢰된 HTML 콘텐츠 삽입 (sanitize 우회)
     * @param html 삽입할 HTML
     */
    insertTrustedContent(html: string): this;

    /**
     * 이미지 삽입
     * @param url 이미지 URL
     * @param alt 대체 텍스트
     */
    insertImage(url: string, alt?: string): this;

    /**
     * 이미지 업로드 핸들러 설정
     * @param handler 업로드 핸들러 함수
     */
    setImageUploadHandler(handler: ImageUploadHandler): this;

    /**
     * 이미지 업로드 핸들러 반환
     */
    getImageUploadHandler(): ImageUploadHandler | null;

    /**
     * 이벤트 리스너 등록
     * @param event 이벤트 이름
     * @param callback 콜백 함수
     */
    on<K extends keyof EditorEventMap>(event: K, callback: (e: EditorEventMap[K]) => void): this;
    on(event: string, callback: (e: EditorEvent) => void): this;

    /**
     * 이벤트 리스너 제거
     * @param event 이벤트 이름
     * @param callback 콜백 함수 (생략시 해당 이벤트의 모든 리스너 제거)
     */
    off<K extends keyof EditorEventMap>(event: K, callback?: (e: EditorEventMap[K]) => void): this;
    off(event: string, callback?: (e: EditorEvent) => void): this;

    /**
     * 이벤트 발생
     * @param event 이벤트 이름
     * @param data 이벤트 데이터
     */
    fire(event: string, data?: Record<string, any>): this;

    /**
     * 에디터 제거
     */
    destroy(): void;

    /**
     * 콘텐츠 영역 요소 반환
     */
    getElement(): HTMLDivElement;

    /**
     * 래퍼 요소 반환
     */
    getWrapper(): HTMLDivElement;

    /**
     * 툴바 요소 반환
     */
    getToolbar(): HTMLDivElement;
}

interface EditorOptions {
    /** 툴바 프리셋: 'minimal'(3개), 'compact'(7개), 'basic'(20개), 'full'(35개) */
    toolbar?: 'minimal' | 'compact' | 'basic' | 'full';

    /** 커스텀 툴바 아이템 */
    toolbarItems?: string[];

    /** 모바일 툴바 프리셋 (data-toolbar-mobile) */
    toolbarMobile?: 'minimal' | 'compact' | 'basic' | 'full' | null;

    /** 모바일 커스텀 툴바 아이템 (data-toolbar-items-mobile) */
    toolbarItemsMobile?: string[] | null;

    /** 모바일 툴바 전환 기준 (data-toolbar-breakpoint, px, 기본 768) */
    toolbarBreakpoint?: number;

    /** 에디터 높이 (px) */
    height?: number;

    /** 최소 높이 (px) */
    minHeight?: number;

    /** 플레이스홀더 텍스트 */
    placeholder?: string;

    /** 자동 포커스 */
    autofocus?: boolean;

    /** 읽기 전용 */
    readonly?: boolean;

    /** 색상 팔레트 */
    colors?: string[];

    /** 이미지 업로드 URL */
    uploadUrl?: string | null;

    /** 허용 이미지 타입 */
    allowedImageTypes?: string[];

    /** HTML 새니타이즈 */
    sanitize?: boolean;

    /** 자동 이미지 업로드 */
    automatic_uploads?: boolean;

    /** 업로드 시 credentials 포함 */
    images_upload_credentials?: boolean;

    /** 레거시 스타일 이미지 업로드 핸들러 (resolve/reject 콜백 방식) */
    images_upload_handler?: LegacyStyleUploadHandler;

    /** 콘텐츠 변경 콜백 */
    onChange?: (html: string, editor: Editor) => void;

    /** 포커스 콜백 */
    onFocus?: (editor: Editor) => void;

    /** 블러 콜백 */
    onBlur?: (editor: Editor) => void;

    /** 이미지 업로드 콜백 (레거시) */
    onImageUpload?: (file: File, editor: Editor) => Promise<{ url: string } | void>;

    /** 에디터 준비 완료 콜백 */
    onReady?: (editor: Editor) => void;
}

interface ToolbarItem {
    icon: string;
    iconExit?: string;
    title: string;
    type?: 'separator' | 'dropdown' | 'color' | 'link' | 'image' | 'video' | 'table' | 'fullscreen' | 'source' | 'print' | 'findreplace';
    command?: string;
    value?: string;
    items?: DropdownItem[];
}

interface DropdownItem {
    label: string;
    command: string;
    value: string;
}

declare class BlobInfo {
    constructor(file: File, base64?: string | null);

    /** 고유 ID */
    id(): string;

    /** 파일명 */
    name(): string;

    /** 파일명 (별칭) */
    filename(): string;

    /** File/Blob 객체 */
    blob(): File;

    /** Base64 문자열 */
    base64(): string | null;

    /** Blob URI */
    blobUri(): string;

    /** URI (별칭) */
    uri(): string;
}

/** 진행률 콜백 */
type ProgressCallback = (percent: number) => void;

/** 이미지 업로드 핸들러 */
type ImageUploadHandler = (blobInfo: BlobInfo, progress: ProgressCallback) => Promise<string>;

/** 레거시 스타일 업로드 핸들러 (resolve/reject 콜백 방식) */
type LegacyStyleUploadHandler = (
    blobInfo: BlobInfo,
    success: (url: string) => void,
    failure: (err: string) => void,
    progress: ProgressCallback
) => void;

/** 플러그인 함수 */
type PluginFunction = (editor: Editor) => void;

/** 기본 이벤트 */
interface EditorEvent {
    type: string;
    target: Editor;
    [key: string]: any;
}

/** 이벤트 맵 */
interface EditorEventMap {
    ready: EditorEvent & { editor: Editor };
    focus: EditorEvent;
    blur: EditorEvent;
    change: EditorEvent & { content: string };
    keydown: EditorEvent & { originalEvent: KeyboardEvent };
    paste: EditorEvent & { originalEvent: ClipboardEvent };
    drop: EditorEvent & { originalEvent: DragEvent };
    uploadStart: EditorEvent & { blobInfo: BlobInfo };
    uploadProgress: EditorEvent & { percent: number; blobInfo: BlobInfo };
    uploadSuccess: EditorEvent & { url: string; blobInfo: BlobInfo };
    uploadError: EditorEvent & { error: string; blobInfo?: BlobInfo; file?: File };
    fullscreenStateChanged: EditorEvent & { state: boolean };
    sourceModeChanged: EditorEvent & { state: boolean };
    destroy: EditorEvent;
}
