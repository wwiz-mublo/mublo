/**
 * ============================================================
 * MubloRequest.js
 * (c) 2025 Mublo
 * Author: Mublo
 * ============================================================
 *
 * MubloRequest 는
 * Mublo 프레임워크 전반(Front / Admin 공용)에서 사용하는
 * **클라이언트 사이드 공통 코어 모듈**이다.
 *
 * 이 파일은
 * "AJAX 요청 + CSRF 관리 + 로딩 UX + 콜백 + 렌더러"
 * 를 하나의 일관된 규칙으로 통합한다.
 *
 * ------------------------------------------------------------
 * 핵심 설계 철학
 * ------------------------------------------------------------
 *
 * 1. HTML 은 선언만 한다
 *    - data-* 속성으로 의도만 표현
 *    - JS 로직을 HTML에 직접 쓰지 않는다
 *
 * 2. JS 는 해석만 한다
 *    - 버튼 클래스 / data-* 규칙을 해석하여 동작 수행
 *    - 개별 페이지 로직에 의존하지 않는다
 *
 * 3. 요청 방식은 명확히 구분한다
 *    - JSON      : application/json
 *    - FORM      : FormData
 *    - QUERY     : GET QueryString
 *
 * ------------------------------------------------------------
 * 주요 기능 요약
 * ------------------------------------------------------------
 *
 * [1] 공통 Ajax 요청 엔진
 *  - sendRequest()
 *  - PayloadType(JSON / FORM / QUERY) 기반 전송
 *  - CSRF 자동 첨부 (상태 변경 요청만 — GET/HEAD/OPTIONS 는 서버도 검증하지 않음)
 *  - 재시도 / 타임아웃 / AbortController 지원
 *
 * [2] 폼 자동 제출 처리
 *  - .mublo-submit 클래스를 가진 버튼 자동 감지
 *  - <form> + FormData 기반 전송
 *  - 에디터(MubloEditor / CKEditor / TinyMCE) 자동 동기화
 *
 * [3] 전역 로딩 UX 관리
 *  - 다중 요청 대응
 *  - Progress Overlay 자동 표시/해제
 *
 * [4] 콜백 & 렌더러 시스템
 *  - registerCallback / executeCallback
 *  - registerRenderer / render
 *  - 서버 응답(JSON)과 화면 렌더링 로직 분리
 *
 * ------------------------------------------------------------
 * 자동 초기화 동작
 * ------------------------------------------------------------
 *
 * DOMContentLoaded 시 자동 실행:
 *  - Progress 엘리먼트 생성
 *    (스타일은 /assets/css/components/mublo-request.css 를 Head에서 로드)
 *  - 버튼 이벤트 위임 등록
 *
 * 별도의 init 호출 없이도 기본 동작한다.
 *
 * ------------------------------------------------------------
 * HTML 사용 예시 (폼 제출)
 * ------------------------------------------------------------
 *
 * <button
 *   class="mublo-submit"
 *   data-target="/api/v1/board/write"
 *   data-callback="afterWrite"
 *   data-container="list-area"
 *   data-loading="true">
 *   저장
 * </button>
 *
 * ------------------------------------------------------------
 * JS 직접 호출 예시 (JSON)
 * ------------------------------------------------------------
 *
 * MubloRequest.requestJson('/api/v1/goods/getList', {
 *   page: 1
 * }, {
 *   loading: true
 * });
 *
 * ------------------------------------------------------------
 * 서버 응답 기본 형식 (권장)
 * ------------------------------------------------------------
 *
 * {
 *   result  : "success" | "error",
 *   message : "처리 결과 메시지",
 *   data    : {
 *     // 렌더러에서 사용할 실제 데이터
 *   }
 * }
 *
 * ※ data 구조는 렌더러별로 자유롭게 정의 가능
 *
 * ============================================================
 
 */

const MubloRequest = (() => {
    /* =========================================================
     * Payload Type 정의
     * ========================================================= */
    const PayloadType = {
        JSON: 'json',   // application/json
        FORM: 'form',   // FormData
        QUERY: 'query', // GET query string
    };

    let cachedCsrfToken = null;
    let csrfPromise = null;
    let activeRequestCount = 0;
    let scrollLockCount = 0;
    const callbacks = {};
    const renderers = {};
    const pendingRequests = new Map(); // requestKey → Set<AbortController>

    const config = {
        apiBaseUrl: window.API_BASE_URL || '/api/v1',
        csrfTokenEndpoint: '/csrf/token',
        timeout: 30000,
        maxRetries: 3,
        retryableStatuses: [419, 503],
        strictResponseFormat: false,
        preventDuplicateRequests: false,
        debug: false,
        errorHandler: null,
        responseInterceptor: null,
        validationErrorDisplay: false,
        progressElement: null,
        showProgress: null,
        formValidator: null,
        onRequestStart: null,
        onRequestComplete: null,

        log(...args) {
            if (this.debug) {
                console.log('[MubloRequest]', ...args);
            }
        }
    };

    // -----------------------------------
    // 유틸리티 함수
    // -----------------------------------

    const debounce = (func, wait) => {
        let timeout;
        const debounced = function executedFunction(...args) {
            const later = () => {
                timeout = null;
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };

        debounced.cancel = () => {
            clearTimeout(timeout);
            timeout = null;
        };

        return debounced;
    };

    const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    const throttle = (func, limit) => {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    };

    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') return '';
        const div = document.createElement('div');
        div.textContent = unsafe;
        return div.innerHTML;
    }

    // -----------------------------------
    // 초기화
    // -----------------------------------
    function init() {
        addProgressElement();
        document.addEventListener('click', handleButtonClick);
        config.log('Initialized');
    }
    // progress 스타일은 /assets/css/components/mublo-request.css 로 분리(Head에서 <link> 로드)

    // destroy()에서 자신이 만든 #progress만 제거하기 위한 소유 플래그.
    // 서버 HTML이나 다른 스크립트가 만든 동일 id 요소는 건드리지 않는다.
    let ownsProgressElement = false;

    function addProgressElement() {
        if (!document.getElementById('progress') && !config.progressElement) {
            const el = document.createElement('div');
            el.id = 'progress';
            document.body.appendChild(el);
            ownsProgressElement = true;
        }
    }

    // -----------------------------------
    // 공용 배경 스크롤 잠금 (progress / alert / confirm 공유, ref-count)
    // 스크롤바 폭만큼 padding-right로 보정해 레이아웃 흔들림·스크롤바 노출을 막는다.
    // -----------------------------------
    // 잠그기 직전의 body inline 스타일을 보존한다. 부트스트랩 모달 백드랍 등
    // 외부 스크롤락 위에 alert가 겹칠 때, 우리 unlock이 남의 락을 지우지 않도록
    // '' 하드코딩 대신 원래 값으로 복원한다.
    let savedBodyOverflow = '';
    let savedBodyPaddingRight = '';

    function lockScroll() {
        if (scrollLockCount === 0) {
            savedBodyOverflow = document.body.style.overflow;
            savedBodyPaddingRight = document.body.style.paddingRight;
            const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
            document.body.style.overflow = 'hidden';
            if (scrollbarWidth > 0) document.body.style.paddingRight = scrollbarWidth + 'px';
        }
        scrollLockCount++;
    }

    function unlockScroll() {
        scrollLockCount = Math.max(0, scrollLockCount - 1);
        if (scrollLockCount === 0) {
            document.body.style.overflow = savedBodyOverflow;
            document.body.style.paddingRight = savedBodyPaddingRight;
        }
    }

    // 기존 alert/confirm 오버레이를 즉시 제거한다.
    // 오버레이가 등록해 둔 폐기 함수(_mubloDiscard)가 keydown 리스너·스크롤락까지
    // 함께 정리한다. 폐기 함수가 없는(외부가 만든 동일 id) 요소는 우리가 잠근 락이
    // 없으므로 요소만 제거한다.
    function discardExistingAlert() {
        const existing = document.getElementById('mublo-alert-overlay');
        if (!existing) return;
        if (typeof existing._mubloDiscard === 'function') {
            existing._mubloDiscard();
        } else {
            existing.remove();
        }
    }

    function showProgressElement() {
        if (config.showProgress && typeof config.showProgress === 'function') {
            config.showProgress(true);
            return;
        }
        const el = config.progressElement || document.getElementById('progress');
        if (el) el.style.display = 'block';
        lockScroll();
    }

    function hideProgressElement() {
        if (config.showProgress && typeof config.showProgress === 'function') {
            config.showProgress(false);
            return;
        }
        const el = config.progressElement || document.getElementById('progress');
        if (el) el.style.display = 'none';
        unlockScroll();
    }

    function toggleProgress(show = true) {
        if (show) {
            activeRequestCount++;
            if (activeRequestCount === 1) {
                showProgressElement();
            }
        } else {
            activeRequestCount = Math.max(0, activeRequestCount - 1);
            if (activeRequestCount === 0) {
                hideProgressElement();
            }
        }
    }

    // -----------------------------------
    // CSRF 토큰 관리
    // -----------------------------------
    async function getCsrfToken() {
        // 1. 이미 캐시된 토큰이 있으면 즉시 반환
        if (cachedCsrfToken) {
            config.log('Using cached CSRF token');
            return cachedCsrfToken;
        }
        
        // 2. 현재 토큰을 가져오는 중이라면 진행 중인 Promise를 반환
        if (csrfPromise) {
            config.log('Waiting for pending CSRF fetch...');
            return csrfPromise;
        }

        // 3. 토큰도 없고 진행 중인 요청도 없다면 새로 요청 시작
        csrfPromise = (async () => {
            try {
                const url = `${config.apiBaseUrl}${config.csrfTokenEndpoint}`;
                config.log('Fetching CSRF token from:', url);

                // 엔드포인트가 행 걸리면 이 promise를 기다리는 모든 요청이 영구 미결이 된다.
                // 자체 타임아웃으로 반드시 settle 시킨다.
                let csrfDidTimeout = false;
                const csrfController = new AbortController();
                const csrfTimeoutId = setTimeout(() => {
                    csrfDidTimeout = true;
                    csrfController.abort();
                }, config.timeout);

                let res;
                try {
                    res = await fetch(url, { signal: csrfController.signal });
                } catch (fetchErr) {
                    // AbortError를 그대로 흘리면 sendRequest catch가 자기 타이머 기준
                    // (didTimeout=false)으로 '외부 abort'로 오분류해 알림 없이 조용히
                    // 실패한다(특히 이 promise를 공유하는 다른 요청). isTimeout 에러로
                    // 변환하면 일반 catch 경로의 handleError가 시간 초과로 처리한다.
                    if (fetchErr.name === 'AbortError' && csrfDidTimeout) {
                        const timeoutError = new Error('CSRF request timeout');
                        timeoutError.isTimeout = true;
                        timeoutError.url = url;
                        throw timeoutError;
                    }
                    throw fetchErr;
                } finally {
                    clearTimeout(csrfTimeoutId);
                }
                if (!res.ok) throw new Error(`CSRF fetch failed: ${res.status}`);
                
                const json = await res.json();
                if (!json || !json.data?.token) throw new Error('Invalid CSRF response');

                cachedCsrfToken = json.data.token;
                config.log('CSRF token cached');
                return cachedCsrfToken;
            } catch (err) {
                console.error('[MubloRequest] CSRF Token Fetch Error:', err);
                throw err;
            } finally {
                // 요청이 성공하든 실패하든 대기열(Promise)은 비워줌
                csrfPromise = null;
            }
        })();

        return csrfPromise;
    }

    function resetCsrfToken() {
        config.log('Resetting CSRF token');
        cachedCsrfToken = null;
        csrfPromise = null;
    }

    // -----------------------------------
    // 에디터 내용 동기화
    // -----------------------------------

    function syncAllEditors() {
        // MubloEditor 동기화 (우선 처리)
        if (typeof MubloEditor !== 'undefined' && MubloEditor.syncAll) {
            try {
                MubloEditor.syncAll();
                config.log('Synced all MubloEditor instances');
            } catch (e) {
                console.warn('[MubloEditor Sync Error]:', e);
            }
        }

        // 기존 에디터 동기화 (SmartEditor2, CKEditor, TinyMCE)
        const editors = document.querySelectorAll('.editor-form, textarea[id^="wr_content"], textarea.smarteditor2');

        const syncStrategies = [
            {
                check: (id) => window.oEditors?.getById?.[id],
                sync: (id) => window.oEditors.getById[id].exec("UPDATE_CONTENTS_FIELD", []),
                name: 'SmartEditor2'
            },
            {
                check: (id) => window.CKEDITOR?.instances?.[id],
                sync: (id) => window.CKEDITOR.instances[id].updateElement(),
                name: 'CKEditor'
            },
            {
                check: (id) => window.tinymce?.get(id),
                sync: (id) => window.tinymce.get(id).save(),
                name: 'TinyMCE'
            }
        ];

        editors.forEach(ed => {
            const editorId = ed.id;
            if (!editorId) return;

            for (const strategy of syncStrategies) {
                try {
                    if (strategy.check(editorId)) {
                        strategy.sync(editorId);
                        config.log(`Synced ${strategy.name} editor:`, editorId);
                        break;
                    }
                } catch (e) {
                    console.warn(`[${strategy.name} Sync Error] ${editorId}:`, e);
                }
            }
        });
    }

    // -----------------------------------
    // FormData 검증
    // -----------------------------------

    // 파일 크기는 여기서 막지 않는다. 허용 크기는 게시판 설정, php.ini(upload_max_filesize,
    // post_max_size), 업로드 정책이 함께 정하는 값이고 클라이언트는 그중 어느 것도 알지 못한다.
    // 임의의 상한을 두면 서버가 허용하는 파일까지 전송 전에 차단된다. 판정은 서버에 맡긴다.
    function validateFormData(formData) {
        if (!(formData instanceof FormData)) {
            throw new Error('FORM payload requires FormData');
        }

        for (let [key, value] of formData.entries()) {
            if (value instanceof File) {
                config.log(`File detected: ${key} = ${value.name} (${value.size} bytes)`);
            }
        }
    }

    // -----------------------------------
    // 응답 데이터 유효성 검사
    // -----------------------------------
    
    function validateResponse(json) {
        // 1. 기본 객체 여부 확인
        if (!json || typeof json !== 'object') {
            throw new Error('서버 응답이 올바른 객체 형식이 아닙니다.');
        }

        // 2. 서버에서 정의한 공통 응답 규격 확인 (result: success/error)
        // 머블로 프레임워크의 표준 규격을 강제하거나 권장합니다.
        const hasResult = 'result' in json;
        const isSuccess = json.result === 'success';

        if (config.strictResponseFormat) {
            if (!hasResult) {
                throw new Error('응답 규격 위반: "result" 필드가 누락되었습니다.');
            }
            if (!('message' in json)) {
                console.warn('[MubloRequest] "message" 필드가 누락되었습니다.');
            }
        }

        // 3. 비즈니스 로직 에러 처리
        // HTTP 상태 코드는 200(OK)이지만, 결과가 'error'인 경우를 처리합니다.
        if (hasResult && !isSuccess) {
            const businessError = new Error(json.message || '요청 처리 중 오류가 발생했습니다.');
            businessError.status = json.status || 200; // 응답 내 별도 상태코드가 있다면 활용
            businessError.response = json;
            throw businessError;
        }

        // 4. 데이터 필드 보장 (Optional)
        // 렌더러에서 data.items 등을 쓸 때 undefined 에러 방지를 위해 기본값 할당
        if (isSuccess && !json.data) {
            json.data = {};
        }

        return json;
    }

    // -----------------------------------
    // 공통 Ajax 요청 처리
    // -----------------------------------

    // 같은 키(method:url)의 동시 요청이 서로의 controller를 덮어쓰지 않도록
    // 키별 Set으로 보관한다. 하나가 끝나도 나머지는 destroy()의 abort 대상으로 남는다.
    function addPendingRequest(key, controller) {
        if (!pendingRequests.has(key)) {
            pendingRequests.set(key, new Set());
        }
        pendingRequests.get(key).add(controller);
    }

    function removePendingRequest(key, controller) {
        const controllers = pendingRequests.get(key);
        if (!controllers) return;

        controllers.delete(controller);

        if (controllers.size === 0) {
            pendingRequests.delete(key);
        }
    }

    async function sendRequest({
        method = 'GET',
        url,
        payloadType = PayloadType.JSON,
        data = null,
        loading = false,
        retryCount = 0,
        timeout = null, // 요청별 타임아웃(ms). 미지정 시 config.timeout 적용
    }) {
        // 저수준 공개 API라 'post' 같은 소문자 입력도 들어온다. 아래 분기가 전부
        // 대문자 비교라, 정규화 없이는 소문자 'get'의 쿼리가 조용히 유실된다.
        method = String(method).toUpperCase();

        // GET 쿼리는 requestKey 계산 전에 URL에 확정한다. 파라미터가 다른 요청이
        // 같은 키로 묶이지 않고, data를 비워 재시도 시 쿼리 이중 부착도 막는다.
        if (method === 'GET' && payloadType === PayloadType.QUERY && data) {
            const params = new URLSearchParams(data);
            url += (url.includes('?') ? '&' : '?') + params.toString();
            config.log('Request payload (Query):', data);
            data = null;
        }

        const requestKey = `${method}:${url}`;

        // 재시도(retryCount > 0)는 진행 중 요청의 연속이라 중복 검사에서 제외한다.
        // 검사하면 자신이 등록해 둔 pending 항목에 걸려 재시도가 무산된다.
        if (config.preventDuplicateRequests && retryCount === 0 && pendingRequests.has(requestKey)) {
            const error = new Error('Duplicate request prevented');
            error.isDuplicate = true;
            throw error;
        }

        const controller = new AbortController();
        addPendingRequest(requestKey, controller);

        const timeoutMs = Number(timeout) > 0 ? Number(timeout) : config.timeout;
        let didTimeout = false;
        const timeoutId = setTimeout(() => {
            didTimeout = true;
            config.log('Request timeout:', url);
            controller.abort();
        }, timeoutMs);

        let responseStatus = null;
        let didRetry = false;

        try {
            // finally의 toggleProgress(false)와 항상 짝이 맞도록 try 진입 직후에 켠다.
            // (CSRF 획득·FormData 검증 단계에서 throw해도 카운트가 틀어지지 않는다)
            if (loading) toggleProgress(true);

            // 훅은 논리 요청 기준 1회(재시도 프레임 제외). try 진입 직후에 불러야
            // CSRF 획득·FormData 검증 실패 시에도 finally의 onRequestComplete와
            // 1:1 쌍이 보장된다.
            if (retryCount === 0 && config.onRequestStart && typeof config.onRequestStart === 'function') {
                config.onRequestStart({ method, url, payloadType });
            }

            const options = {
                method,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            };

            // 상태 변경 요청에만 CSRF 토큰을 첨부한다. 서버 CsrfMiddleware도
            // GET/HEAD/OPTIONS는 검증을 스킵하므로 조회 요청이 /csrf/token에 의존할 필요가 없다.
            if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
                options.headers['X-CSRF-Token'] = await getCsrfToken();
            }

            if (method !== 'GET') {
                if (payloadType === PayloadType.JSON) {
                    options.headers['Content-Type'] = 'application/json';
                    options.body = JSON.stringify(data ?? {});
                    config.log('Request payload (JSON):', data);
                }

                if (payloadType === PayloadType.FORM) {
                    validateFormData(data);
                    options.body = data;
                    config.log('Request payload (FormData)');
                }
            }

            config.log('Sending request:', { method, url, payloadType, retryCount });

            if (config.validationErrorDisplay) clearValidationErrors();

            const response = await fetch(url, options);
            responseStatus = response.status;

            let json = null;
            const contentType = response.headers.get('content-type') || '';


            if (contentType.includes('application/json')) {
                try {
                    json = await response.json();
                } catch (e) {
                    json = {
                        result: 'error',
                        message: 'Invalid JSON response',
                    };
                }
            } else {
                // JSON이 아닌 응답 (nginx 413/502 등 프록시 HTML 에러 페이지).
                // 앱(JsonResponse)까지 못 온 경우라 상태코드로 사람이 읽을 메시지를 만든다.
                const text = await response.text();
                let message = '알 수 없는 오류가 발생했습니다. 잠시 후 다시 시도해주세요.';
                if (response.status === 413) {
                    message = '업로드 파일 용량이 서버 허용치를 초과했습니다.';
                } else if (response.status === 502 || response.status === 504) {
                    message = '서버 응답이 지연되고 있습니다. 잠시 후 다시 시도해주세요.';
                }
                json = {
                    result: 'error',
                    message: message,
                    raw: text,
                };
            }

            if (!response.ok) {
                if (
                    config.retryableStatuses.includes(response.status) &&
                    retryCount < config.maxRetries
                ) {
                    config.log(`Retrying request (${retryCount + 1}/${config.maxRetries}):`, url);

                    if (response.status === 419) resetCsrfToken();

                    const backoffDelay = Math.pow(2, retryCount) * 1000;
                    await delay(backoffDelay);

                    didRetry = true;

                    // await 필수: 없으면 재시도가 끝나기 전에 이 호출의 finally가 실행되어
                    // pending 항목 조기 정리·progress 깜빡임이 발생한다.
                    return await sendRequest({
                        method,
                        url,
                        payloadType,
                        data,
                        loading,
                        retryCount: retryCount + 1,
                        timeout,
                    });
                }

                const error = new Error(json.message || 'Request failed');
                error.status = response.status;
                error.url = url;
                error.response = json;
                throw error;
            }

            // Response Interceptor 적용
            if (config.responseInterceptor && typeof config.responseInterceptor === 'function') {
                json = await config.responseInterceptor(json, response);
                config.log('Response interceptor applied');
            }

            return validateResponse(json);

        } catch (e) {
            if (e.name === 'AbortError') {
                // 타임아웃 타이머가 직접 중단시킨 경우에만 '시간 초과'다.
                // destroy() 등 외부 abort 까지 시간초과로 오인해 알림을 띄우지 않는다.
                if (!didTimeout) {
                    config.log('Request aborted (external):', url);
                    const aborted = new Error('Request aborted');
                    aborted.isAborted = true;
                    aborted.url = url;
                    throw aborted;
                }
                const error = new Error('Request timeout');
                error.isTimeout = true;
                error.url = url;
                handleError(error);
                throw error;
            }

            if (!e.isDuplicate) {
                handleError(e);
            }
            throw e;
        } finally {
            clearTimeout(timeoutId);
            removePendingRequest(requestKey, controller);
            if (loading) toggleProgress(false);

            // 성공·실패·타임아웃·abort 모두에서 호출하되 논리 요청당 1회.
            // 재시도로 넘어간 프레임(didRetry)은 생략한다 — 안 그러면 프레임별 finally가
            // 안쪽부터 실행되어 마지막 훅이 재시도 이전의 실패 status를 보고하게 된다.
            // 최종 시도 프레임이 최종 status(응답 미수신 실패는 null)를 보고한다.
            if (!didRetry && config.onRequestComplete && typeof config.onRequestComplete === 'function') {
                try {
                    config.onRequestComplete({ method, url, payloadType, status: responseStatus });
                } catch (hookErr) {
                    // 훅의 예외가 원래 요청 에러를 삼키지 않도록 격리
                    console.error('[MubloRequest] onRequestComplete hook error:', hookErr);
                }
            }
        }
    }

    // -----------------------------------
    // 폼 기반 Ajax 요청
    // -----------------------------------

    // 폼 단위 in-flight 잠금. 전역 debounce는 300ms 안에 서로 다른 폼의 제출이
    // 겹치면 앞 제출이 조용히 취소되는 문제가 있어 진행 중 상태 잠금으로 대체한다.
    // (이중 제출 방지 의미로도 지연이 아니라 잠금이 정확하다)
    const submittingForms = new WeakSet();

    async function submitForm(button) {
        const form = button.closest('form');
        const url = button.dataset.target;
        const callback = button.dataset.callback;
        const containerId = button.dataset.container;
        const loading = button.dataset.loading === 'true';

        if (!form || !url) {
            console.warn('[MubloRequest] Form or target URL not found');
            return;
        }

        if (submittingForms.has(form)) {
            config.log('Form submission already in progress');
            return;
        }

        config.log('Submitting form:', { url, callback, containerId, loading });

        syncAllEditors();

        if (config.formValidator && !config.formValidator(form)) {
            config.log('Form validation failed');
            return;
        }

        submittingForms.add(form);
        button.disabled = true;

        try {
            const formData = new FormData(form);

            const result = await sendRequest({
                method: 'POST',
                url,
                payloadType: PayloadType.FORM,
                data: formData,
                loading,
            });

            if (callback) {
                await executeCallback(callback, result, containerId);
            }
        } catch (e) {
            config.log('Form submission error:', e);
        } finally {
            submittingForms.delete(form);
            button.disabled = false;
        }
    }

    function requestJson(url, data = {}, options = {}) {
        return sendRequest({
            method: 'POST',
            url,
            payloadType: PayloadType.JSON,
            data,
            ...options,
        });
    }

    function requestQuery(url, params = {}, options = {}) {
        return sendRequest({
            method: 'GET',
            url,
            payloadType: PayloadType.QUERY,
            data: params,
            ...options,
        });
    }

    // -----------------------------------
    // 공통 에러 핸들러
    // -----------------------------------

    function createErrorInfo(error) {
        return {
            message: error?.message || '요청 처리 중 오류가 발생했습니다.',
            status: error?.status,
            url: error?.url,
            isTimeout: error?.isTimeout || false,
            isDuplicate: error?.isDuplicate || false,
            timestamp: new Date().toISOString(),
            response: error?.response,
        };
    }

    function handleError(error) {
        const errorInfo = createErrorInfo(error);

        console.error('[MubloRequest Error]', errorInfo);

        if (config.errorHandler && typeof config.errorHandler === 'function') {
            config.errorHandler(errorInfo);
            return;
        }

        if (error?.isDuplicate) {
            return;
        }

        if (error?.isTimeout) {
            showAlert('요청 시간이 초과되었습니다. 다시 시도해주세요.', 'warning');
            return;
        }

        switch (error?.status) {
            case 401:
                showConfirm('로그인이 필요합니다.', function() { location.href = '/login'; }, {
                    type: 'warning',
                    confirmText: '로그인',
                    cancelText: '닫기'
                });
                break;

            case 403:
                showAlert('접근 권한이 없습니다.', 'error');
                break;

            case 404:
                showAlert('요청한 리소스를 찾을 수 없습니다.', 'error');
                break;

            case 419:
                showConfirm('세션이 만료되었습니다.', function() { location.reload(); }, {
                    type: 'warning',
                    confirmText: '새로고침',
                    cancelText: '닫기'
                });
                break;

            case 422:
                if (config.validationErrorDisplay && error?.response?.data?.errors) {
                    displayValidationErrors(error.response.data.errors);
                } else {
                    showAlert(errorInfo.message || '입력 데이터가 올바르지 않습니다.', 'warning');
                }
                break;

            case 413:
                showAlert('업로드 파일 용량이 서버 허용치를 초과했습니다.', 'error');
                break;

            case 500:
            case 503:
                showAlert('서버 오류가 발생했습니다. 잠시 후 다시 시도해주세요.', 'error');
                break;

            default:
                showAlert(errorInfo.message, 'error');
        }
    }

    // -----------------------------------
    // 422 유효성 오류 자동 매핑
    // -----------------------------------

    function clearValidationErrors() {
        document.querySelectorAll('.is-invalid').forEach(el => {
            el.classList.remove('is-invalid');
        });
        document.querySelectorAll('.invalid-feedback[data-mublo-validation]').forEach(el => {
            el.remove();
        });
    }

    function displayValidationErrors(errors) {
        clearValidationErrors();

        if (!errors || typeof errors !== 'object') return;

        let firstErrorElement = null;

        Object.entries(errors).forEach(([field, messages]) => {
            const message = Array.isArray(messages) ? messages[0] : messages;

            // formData[field] 또는 field 형태로 매칭
            const selectors = [
                `[name="${field}"]`,
                `[name="formData[${field}]"]`,
                `[name="${field}[]"]`,
                `[name="formData[${field}][]"]`,
            ];

            let input = null;
            for (const sel of selectors) {
                input = document.querySelector(sel);
                if (input) break;
            }

            if (!input) return;

            input.classList.add('is-invalid');

            // 이미 피드백이 있으면 스킵
            if (input.parentElement.querySelector('.invalid-feedback[data-mublo-validation]')) return;

            const feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            feedback.setAttribute('data-mublo-validation', '1');
            feedback.style.display = 'block';
            feedback.textContent = message;
            input.parentElement.appendChild(feedback);

            if (!firstErrorElement) firstErrorElement = input;
        });

        if (firstErrorElement) {
            firstErrorElement.focus();
            firstErrorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    // -----------------------------------
    // 콜백 & 렌더러 시스템
    // -----------------------------------

    function registerCallback(name, fn, options = { override: true, exposeGlobally: true }) {
        if (typeof fn !== 'function') {
            console.error(`[MubloRequest] Invalid callback function: ${name}`);
            return false;
        }

        if (callbacks[name] && !options.override) {
            console.warn(`[MubloRequest] Callback already exists: ${name}`);
            return false;
        }

        callbacks[name] = fn;
        config.log(`Callback registered: ${name}`);

        if (options.exposeGlobally && (!window[name] || options.override)) {
            window[name] = fn;
        }

        return true;
    }

    async function executeCallback(name, data, containerId) {
        config.log(`Executing callback: ${name}`, { data, containerId });

        if (callbacks[name]) return await callbacks[name](data, containerId);
        if (typeof window[name] === 'function') return await window[name](data, containerId);

        console.warn(`콜백 [${name}]를 찾을 수 없습니다.`);
    }

    function registerRenderer(name, fn) {
        if (typeof fn !== 'function') {
            console.error('[MubloRequest] Invalid renderer function');
            return false;
        }

        renderers[name] = fn;
        config.log(`Renderer registered: ${name}`);

        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.info(`[MubloRequest] Renderer '${name}' registered. Remember to sanitize HTML!`);
        }

        return true;
    }

    function render(name, container, data, ...args) {
        config.log(`Rendering: ${name}`, { container, data });

        if (renderers[name]) return renderers[name](container, data, ...args);
        console.warn(`렌더러 [${name}]를 찾을 수 없습니다.`);
    }

    // -----------------------------------
    // 이벤트 위임
    // -----------------------------------

    function handleButtonClick(e) {
        // 폼 컨트롤 요소 클릭은 무시 (파일 선택, 셀렉트 등 정상 동작 보장)
        const tag = e.target.tagName;
        if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || tag === 'LABEL' || tag === 'OPTION') {
            return;
        }

        const btn = e.target.closest('.mublo-submit');
        if (btn) {
            e.preventDefault();
            config.log('Submit button clicked:', btn.dataset.target);

            // data-confirm 처리: 커스텀 모달 사용
            const confirmMessage = btn.dataset.confirm;
            if (confirmMessage) {
                showConfirm(confirmMessage.replace(/\\n/g, '\n'), function() {
                    submitForm(btn);
                }, { type: 'warning' });
                return;
            }

            submitForm(btn);
        }
    }

    // -----------------------------------
    // 메모리 정리 함수
    // -----------------------------------

    function destroy() {
        config.log('Destroying MubloRequest');

        document.removeEventListener('click', handleButtonClick);

        pendingRequests.forEach((controllers) => {
            controllers.forEach((controller) => controller.abort());
        });
        pendingRequests.clear();

        // 열린 알럿(문서에 걸린 keydown 리스너 포함) 정리
        discardExistingAlert();

        // 자신이 만든 요소만 제거한다. 서버 HTML 등 외부가 만든 #progress는 보존.
        if (ownsProgressElement) {
            const progressEl = document.getElementById('progress');
            if (progressEl) progressEl.remove();
            ownsProgressElement = false;
        }

        if (_toastContainer) {
            _toastContainer.remove();
            _toastContainer = null;
        }

        cachedCsrfToken = null;
        activeRequestCount = 0;

        // 우리 락이 남았을 때만 저장해 둔 원래 값으로 복원한다.
        // ''로 밀면 부트스트랩 모달 등 외부 컴포넌트의 스크롤락까지 지워버린다.
        if (scrollLockCount > 0) {
            scrollLockCount = 0;
            document.body.style.overflow = savedBodyOverflow;
            document.body.style.paddingRight = savedBodyPaddingRight;
        }

        config.log('Destroyed');
    }

    // -----------------------------------
    // 설정 변경 함수
    // -----------------------------------

    function configure(options) {
        Object.assign(config, options);
        config.log('Configuration updated:', options);
    }

    function getConfig() {
        return { ...config };
    }

    // -----------------------------------
    // Toast 알림
    // -----------------------------------

    let _toastContainer = null;

    function _ensureToastContainer() {
        if (_toastContainer && document.body.contains(_toastContainer)) return _toastContainer;
        _toastContainer = document.createElement('div');
        _toastContainer.id = 'mublo-toast-container';
        _toastContainer.setAttribute('aria-live', 'polite');
        document.body.appendChild(_toastContainer);
        // 토스트 스타일은 /assets/css/components/mublo-request.css 로 분리
        return _toastContainer;
    }

    const _toastIcons = {
        success: '<i class="bi bi-check-circle-fill"></i>',
        error: '<i class="bi bi-x-circle-fill"></i>',
        info: '<i class="bi bi-info-circle-fill"></i>',
        warning: '<i class="bi bi-exclamation-triangle-fill"></i>',
    };

    /**
     * 토스트 알림 표시
     * @param {string} message
     * @param {'success'|'error'|'info'|'warning'} type
     * @param {number} duration ms (기본 3000)
     */
    function showToast(message, type, duration) {
        type = type || 'info';
        duration = duration || 3000;
        const container = _ensureToastContainer();

        const toast = document.createElement('div');
        toast.className = 'mublo-toast mublo-toast--' + type;
        toast.innerHTML =
            '<span class="mublo-toast__icon">' + (_toastIcons[type] || '') + '</span>' +
            '<span>' + escapeHtml(message) + '</span>' +
            '<button class="mublo-toast__close" type="button" aria-label="닫기"><i class="bi bi-x-lg"></i></button>';

        toast.querySelector('.mublo-toast__close').addEventListener('click', function() { removeToast(toast); });
        container.appendChild(toast);

        requestAnimationFrame(function() {
            requestAnimationFrame(function() { toast.classList.add('mublo-toast--visible'); });
        });

        setTimeout(function() { removeToast(toast); }, duration);
    }

    function removeToast(toast) {
        if (!toast || !toast.parentNode) return;
        toast.classList.remove('mublo-toast--visible');
        setTimeout(function() { toast.remove(); }, 300);
    }

    // -----------------------------------
    // 모달 알림 (alert 대체)
    // -----------------------------------

    /**
     * 중앙 모달 알림
     * @param {string} message
     * @param {'error'|'warning'|'info'|'success'} type
     * @param {object} options  { title, buttonText, onClose }
     */
    // 얼럿 스타일은 /assets/css/components/mublo-request.css 로 분리

    function getModalFocusableElements(container) {
        return Array.from(container.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), ' +
            'textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(function(el) {
            return el.offsetParent !== null || el === document.activeElement;
        });
    }

    function restoreFocus(element) {
        if (!element || typeof element.focus !== 'function' || !document.contains(element)) return;
        try {
            element.focus({ preventScroll: true });
        } catch (e) {
            element.focus();
        }
    }

    function trapModalTab(e, overlay) {
        if (e.key !== 'Tab') return;

        var focusable = getModalFocusableElements(overlay);
        if (!focusable.length) {
            e.preventDefault();
            overlay.focus();
            return;
        }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    function showAlert(message, type, options) {
        type = type || 'info';
        options = options || {};
        var previousFocus = document.activeElement;

        // 기존 모달 제거 (이전 오버레이의 스크롤락도 함께 해제)
        discardExistingAlert();

        var icons = {
            error: '<i class="bi bi-x-circle-fill"></i>',
            warning: '<i class="bi bi-exclamation-triangle-fill"></i>',
            info: '<i class="bi bi-info-circle-fill"></i>',
            success: '<i class="bi bi-check-circle-fill"></i>',
        };
        var titles = { error: '오류', warning: '알림', info: '안내', success: '완료' };

        var overlay = document.createElement('div');
        overlay.id = 'mublo-alert-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('tabindex', '-1');
        overlay.innerHTML =
            '<div class="mublo-alert">' +
                '<div class="mublo-alert__icon mublo-alert__icon--' + type + '">' + (icons[type] || '') + '</div>' +
                '<div class="mublo-alert__title">' + escapeHtml(options.title || titles[type] || '') + '</div>' +
                '<div class="mublo-alert__msg">' + escapeHtml(message) + '</div>' +
                '<button class="mublo-alert__btn mublo-alert__btn--' + type + '">' + escapeHtml(options.buttonText || '확인') + '</button>' +
            '</div>';

        var closed = false;
        // 어떤 경로(정상 닫기 타이머·강제 폐기)로 와도 이 오버레이의 락 해제는 1회만.
        var lockReleased = false;
        var releaseScrollLock = function() {
            if (lockReleased) return;
            lockReleased = true;
            unlockScroll();
        };

        var closeAlert = function() {
            if (closed) return;
            closed = true;
            document.removeEventListener('keydown', keyHandler);
            overlay.classList.remove('--visible');
            // 스크롤 언락을 페이드아웃 종료 후로 미룬다. 즉시 풀면 스크롤바가 되돌아오며
            // 페이드아웃 중인 중앙정렬 창이 스크롤바 폭만큼 왼쪽으로 튄다.
            setTimeout(function() {
                overlay.remove();
                releaseScrollLock();
                // 그 사이 새 오버레이가 떴으면 포커스를 되돌리지 않는다(새 모달 포커스 유지)
                if (!document.getElementById('mublo-alert-overlay')) restoreFocus(previousFocus);
            }, 200);
            if (typeof options.onClose === 'function') options.onClose();
        };

        var alertButton = overlay.querySelector('.mublo-alert__btn');
        alertButton.addEventListener('click', closeAlert);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) closeAlert(); });
        var keyHandler = function(e) {
            if (e.key === 'Escape') {
                closeAlert();
                return;
            }
            trapModalTab(e, overlay);
        };

        // 강제 폐기(discardExistingAlert) 경로: 페이드아웃·onClose 없이 리스너와
        // 스크롤락을 즉시 정리한다. 리스너를 남기면 다음 Escape에서 죽은 알럿의
        // onClose가 발동하고 락이 이중 해제된다.
        overlay._mubloDiscard = function() {
            closed = true;
            document.removeEventListener('keydown', keyHandler);
            overlay.remove();
            releaseScrollLock();
        };

        lockScroll();
        document.body.appendChild(overlay);
        alertButton.focus();
        requestAnimationFrame(function() {
            requestAnimationFrame(function() { overlay.classList.add('--visible'); });
        });

        document.addEventListener('keydown', keyHandler);
    }

    /**
     * 중앙 확인/취소 모달 (confirm 대체)
     * @param {string} message
     * @param {function} onConfirm 확인 시 콜백
     * @param {object} options { title, confirmText, cancelText, type }
     */
    function showConfirm(message, onConfirm, options) {
        options = options || {};
        var type = options.type || 'info';
        var previousFocus = document.activeElement;

        discardExistingAlert();

        // confirm은 '정말?' 성격이라 상태셋과 갈라 고정 아이콘(question-circle) 사용
        // (FAQ 플러그인이 patch-question을 쓰므로 아이콘 충돌 회피)
        var titles = { error: '확인', warning: '확인', info: '확인', success: '확인' };

        var overlay = document.createElement('div');
        overlay.id = 'mublo-alert-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('tabindex', '-1');
        overlay.innerHTML =
            '<div class="mublo-alert">' +
                '<div class="mublo-alert__icon mublo-alert__icon--' + type + '"><i class="bi bi-question-circle"></i></div>' +
                '<div class="mublo-alert__title">' + escapeHtml(options.title || titles[type]) + '</div>' +
                '<div class="mublo-alert__msg">' + escapeHtml(message) + '</div>' +
                '<div style="display:flex;gap:8px;justify-content:center">' +
                    '<button class="mublo-alert__btn" style="background:var(--secondary,#6c757d);color:var(--secondary-foreground,#fff)" data-role="cancel">' + escapeHtml(options.cancelText || '취소') + '</button>' +
                    '<button class="mublo-alert__btn mublo-alert__btn--' + type + '" data-role="confirm">' + escapeHtml(options.confirmText || '확인') + '</button>' +
                '</div>' +
            '</div>';

        var closed = false;
        // 어떤 경로(정상 닫기 타이머·강제 폐기)로 와도 이 오버레이의 락 해제는 1회만.
        var lockReleased = false;
        var releaseScrollLock = function() {
            if (lockReleased) return;
            lockReleased = true;
            unlockScroll();
        };

        var close = function() {
            if (closed) return;
            closed = true;
            document.removeEventListener('keydown', keyHandler);
            overlay.classList.remove('--visible');
            // 스크롤 언락을 페이드아웃 종료 후로 미룬다(닫힐 때 창이 왼쪽으로 튀는 현상 방지).
            setTimeout(function() {
                overlay.remove();
                releaseScrollLock();
                // 그 사이 새 오버레이가 떴으면 포커스를 되돌리지 않는다(새 모달 포커스 유지)
                if (!document.getElementById('mublo-alert-overlay')) restoreFocus(previousFocus);
            }, 200);
        };

        overlay.querySelector('[data-role="cancel"]').addEventListener('click', close);
        var confirmButton = overlay.querySelector('[data-role="confirm"]');
        confirmButton.addEventListener('click', function() {
            close();
            if (typeof onConfirm === 'function') onConfirm();
        });
        overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });

        var keyHandler = function(e) {
            if (e.key === 'Escape') {
                close();
                return;
            }
            trapModalTab(e, overlay);
        };
        document.addEventListener('keydown', keyHandler);

        // 강제 폐기(discardExistingAlert) 경로: 페이드아웃 없이 리스너·스크롤락 즉시 정리
        overlay._mubloDiscard = function() {
            closed = true;
            document.removeEventListener('keydown', keyHandler);
            overlay.remove();
            releaseScrollLock();
        };

        lockScroll();
        document.body.appendChild(overlay);
        confirmButton.focus();
        requestAnimationFrame(function() {
            requestAnimationFrame(function() { overlay.classList.add('--visible'); });
        });
    }

    // -----------------------------------
    // 외부 노출 API
    // -----------------------------------
    return {
        init,
        sendRequest,
        submitForm,
        requestJson,
        requestQuery,

        registerCallback,
        executeCallback,
        registerRenderer,
        render,

        debounce,
        throttle,
        syncAllEditors,
        escapeHtml,

        configure,
        getConfig,
        destroy,

        getCsrfToken,
        resetCsrfToken,
        clearValidationErrors,

        showToast,
        showAlert,
        showConfirm,

        PayloadType,
    };
})();

document.addEventListener('DOMContentLoaded', () => MubloRequest.init());
