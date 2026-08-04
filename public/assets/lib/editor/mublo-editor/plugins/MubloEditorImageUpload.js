/**
 * ============================================================
 * MubloEditor Image Upload Plugin Example
 * ============================================================
 *
 * MubloEditor의 이미지 업로드 기능을 커스터마이징하는 플러그인 예제
 *
 * 사용법:
 * 1. 이 파일을 MubloEditor.js 다음에 로드
 * 2. 필요에 맞게 업로드 로직 수정
 *
 * ------------------------------------------------------------
 * 플러그인 구조
 * ------------------------------------------------------------
 *
 * MubloEditor.registerPlugin('pluginName', (editor) => {
 *     // editor: MubloEditor 인스턴스
 *     // 여기서 에디터를 확장/수정
 * });
 *
 * ============================================================
 */

// ============================================================
// 예제 1: 기본 서버 업로드 플러그인
// ============================================================
MubloEditor.registerPlugin('simpleImageUploader', (editor) => {
    editor.setImageUploadHandler(async (blobInfo, progress) => {
        const formData = new FormData();
        formData.append('file', blobInfo.blob(), blobInfo.filename());

        // XMLHttpRequest로 진행률 추적
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    progress(Math.round((e.loaded / e.total) * 100));
                }
            });

            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        // 서버 응답에서 URL 추출 (서버 구현에 맞게 수정)
                        resolve(response.url || response.data?.url || response.location);
                    } catch (e) {
                        reject(new Error('서버 응답 파싱 실패'));
                    }
                } else {
                    reject(new Error(`업로드 실패: ${xhr.status}`));
                }
            });

            xhr.addEventListener('error', () => reject(new Error('네트워크 오류')));
            xhr.open('POST', '/api/v1/upload/image');
            xhr.send(formData);
        });
    });
});


// ============================================================
// 예제 2: MubloRequest 통합 업로드 플러그인
// ============================================================
/*
MubloEditor.registerPlugin('MubloCoreImageUploader', (editor) => {
    editor.setImageUploadHandler(async (blobInfo, progress) => {
        // MubloRequest가 있는 경우 사용
        if (typeof MubloRequest === 'undefined') {
            throw new Error('MubloRequest가 필요합니다.');
        }

        const formData = new FormData();
        formData.append('file', blobInfo.blob(), blobInfo.filename());

        const result = await MubloRequest.sendRequest({
            method: 'POST',
            url: '/api/v1/upload/image',
            payloadType: MubloRequest.PayloadType.FORM,
            data: formData,
            loading: false  // 에디터 자체 프로그레스 사용
        });

        if (result.result === 'success' && result.data?.url) {
            return result.data.url;
        }

        throw new Error(result.message || '업로드 실패');
    });

    // 업로드 이벤트 로깅
    editor.on('uploadStart', (e) => {
        console.log('[MubloEditor] 업로드 시작:', e.blobInfo.filename());
    });

    editor.on('uploadSuccess', (e) => {
        console.log('[MubloEditor] 업로드 성공:', e.url);
    });

    editor.on('uploadError', (e) => {
        console.error('[MubloEditor] 업로드 실패:', e.error);
    });
});
*/


// ============================================================
// 예제 3: 이미지 리사이징 후 업로드 플러그인
// ============================================================
/*
MubloEditor.registerPlugin('resizeImageUploader', (editor) => {
    const MAX_WIDTH = 1200;
    const MAX_HEIGHT = 1200;
    const QUALITY = 0.85;

    // 이미지 리사이즈 함수
    function resizeImage(file, maxWidth, maxHeight) {
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => {
                let { width, height } = img;

                // 리사이즈 필요 여부 확인
                if (width <= maxWidth && height <= maxHeight) {
                    resolve(file);
                    return;
                }

                // 비율 유지하며 리사이즈
                if (width > height) {
                    if (width > maxWidth) {
                        height = Math.round(height * maxWidth / width);
                        width = maxWidth;
                    }
                } else {
                    if (height > maxHeight) {
                        width = Math.round(width * maxHeight / height);
                        height = maxHeight;
                    }
                }

                // Canvas로 리사이즈
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);

                canvas.toBlob((blob) => {
                    resolve(new File([blob], file.name, { type: file.type }));
                }, file.type, QUALITY);
            };
            img.src = URL.createObjectURL(file);
        });
    }

    editor.setImageUploadHandler(async (blobInfo, progress) => {
        progress(10);

        // 이미지 리사이즈
        const resizedFile = await resizeImage(blobInfo.blob(), MAX_WIDTH, MAX_HEIGHT);
        progress(30);

        // 업로드
        const formData = new FormData();
        formData.append('file', resizedFile, blobInfo.filename());

        const response = await fetch('/api/v1/upload/image', {
            method: 'POST',
            body: formData
        });

        progress(90);

        if (!response.ok) {
            throw new Error('업로드 실패');
        }

        const data = await response.json();
        progress(100);

        return data.url;
    });
});
*/


// ============================================================
// 예제 4: AWS S3 직접 업로드 플러그인 (Presigned URL)
// ============================================================
/*
MubloEditor.registerPlugin('s3DirectUploader', (editor) => {
    editor.setImageUploadHandler(async (blobInfo, progress) => {
        // 1. 서버에서 Presigned URL 요청
        progress(10);
        const presignResponse = await fetch('/api/v1/upload/presign', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                filename: blobInfo.filename(),
                contentType: blobInfo.blob().type
            })
        });

        if (!presignResponse.ok) {
            throw new Error('Presigned URL 요청 실패');
        }

        const { uploadUrl, publicUrl } = await presignResponse.json();
        progress(30);

        // 2. S3에 직접 업로드
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = 30 + Math.round((e.loaded / e.total) * 70);
                    progress(percent);
                }
            });

            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(publicUrl);
                } else {
                    reject(new Error('S3 업로드 실패'));
                }
            });

            xhr.addEventListener('error', () => reject(new Error('네트워크 오류')));

            xhr.open('PUT', uploadUrl);
            xhr.setRequestHeader('Content-Type', blobInfo.blob().type);
            xhr.send(blobInfo.blob());
        });
    });
});
*/


// ============================================================
// 예제 5: 이미지 검증 플러그인 (업로드 전 체크)
// ============================================================
/*
MubloEditor.registerPlugin('imageValidator', (editor) => {
    const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const MAX_SIZE = 10 * 1024 * 1024; // 10MB
    const MIN_WIDTH = 100;
    const MIN_HEIGHT = 100;
    const MAX_WIDTH = 4000;
    const MAX_HEIGHT = 4000;

    // 이미지 크기 체크
    function checkImageDimensions(file) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => {
                URL.revokeObjectURL(img.src);
                
                if (img.width < MIN_WIDTH || img.height < MIN_HEIGHT) {
                    reject(new Error(`이미지가 너무 작습니다. 최소 ${MIN_WIDTH}x${MIN_HEIGHT}px 이상이어야 합니다.`));
                    return;
                }
                
                if (img.width > MAX_WIDTH || img.height > MAX_HEIGHT) {
                    reject(new Error(`이미지가 너무 큽니다. 최대 ${MAX_WIDTH}x${MAX_HEIGHT}px 이하여야 합니다.`));
                    return;
                }
                
                resolve({ width: img.width, height: img.height });
            };
            img.onerror = () => reject(new Error('이미지를 로드할 수 없습니다.'));
            img.src = URL.createObjectURL(file);
        });
    }

    editor.setImageUploadHandler(async (blobInfo, progress) => {
        const file = blobInfo.blob();

        // 타입 체크
        if (!ALLOWED_TYPES.includes(file.type)) {
            throw new Error(`허용되지 않는 파일 형식입니다. (${ALLOWED_TYPES.join(', ')})`);
        }

        // 크기 체크
        if (file.size > MAX_SIZE) {
            throw new Error(`파일이 너무 큽니다. 최대 ${MAX_SIZE / 1024 / 1024}MB까지 업로드 가능합니다.`);
        }

        progress(10);

        // 이미지 dimensions 체크
        await checkImageDimensions(file);
        progress(20);

        // 실제 업로드
        const formData = new FormData();
        formData.append('file', file, blobInfo.filename());

        const response = await fetch('/api/v1/upload/image', {
            method: 'POST',
            body: formData
        });

        progress(90);

        if (!response.ok) {
            throw new Error('업로드 실패');
        }

        const data = await response.json();
        progress(100);

        return data.url;
    });
});
*/


// ============================================================
// 예제 6: 업로드 이벤트 로깅/분석 플러그인
// ============================================================
/*
MubloEditor.registerPlugin('uploadAnalytics', (editor) => {
    // 업로드 시작
    editor.on('uploadStart', (e) => {
        console.log('[Analytics] Upload started:', {
            filename: e.blobInfo.filename(),
            size: e.blobInfo.blob().size,
            type: e.blobInfo.blob().type,
            timestamp: new Date().toISOString()
        });
    });

    // 업로드 진행
    editor.on('uploadProgress', (e) => {
        console.log('[Analytics] Upload progress:', e.percent + '%');
    });

    // 업로드 성공
    editor.on('uploadSuccess', (e) => {
        console.log('[Analytics] Upload success:', {
            url: e.url,
            filename: e.blobInfo.filename(),
            timestamp: new Date().toISOString()
        });

        // Google Analytics 등에 이벤트 전송
        // gtag('event', 'image_upload', { ... });
    });

    // 업로드 실패
    editor.on('uploadError', (e) => {
        console.error('[Analytics] Upload error:', {
            error: e.error,
            filename: e.blobInfo?.filename(),
            timestamp: new Date().toISOString()
        });

        // 에러 리포팅 서비스에 전송
        // Sentry.captureException(e.error);
    });
});
*/
