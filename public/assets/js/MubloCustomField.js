/**
 * MubloCustomField — 커스텀 필드 파일 업로드 모듈
 *
 * CustomFieldRenderer::renderFileScript()에서 로드.
 * .custom-field-file 입력에 자동 바인딩.
 */
var MubloCustomField = (function () {
    var uploadUrl = '';

    function setUploadUrl(url) {
        uploadUrl = url;
        initFileUploads();
    }

    function initFileUploads() {
        document.querySelectorAll('.custom-field-file').forEach(function (input) {
            if (input.dataset.cfInit) return;
            input.dataset.cfInit = '1';

            input.addEventListener('change', function () {
                var fieldId = this.dataset.fieldId;
                var prefix = this.dataset.idPrefix || 'field_';
                var maxSizeMb = parseInt(this.dataset.maxSize || '5', 10);
                var file = this.files[0];

                // 가상 트리거의 파일명 표시 갱신
                var chosenEl = this.closest('.custom-field-file-input-row');
                chosenEl = chosenEl && chosenEl.querySelector('.custom-field-file-chosen');
                if (chosenEl) chosenEl.textContent = file ? file.name : '선택된 파일 없음';

                if (!file) return;

                if (file.size > maxSizeMb * 1024 * 1024) {
                    MubloRequest.showAlert('파일 크기가 ' + maxSizeMb + 'MB를 초과했습니다.', 'warning');
                    this.value = '';
                    return;
                }

                // avatar: 업로드 전 즉시 미리보기 (client-side)
                if (this.classList.contains('custom-field-avatar')) {
                    showAvatarPreviewFromFile(prefix, fieldId, file);
                }

                var formData = new FormData();
                formData.append('file', file);
                formData.append('field_id', fieldId);

                MubloRequest.sendRequest({
                    method: 'POST',
                    url: uploadUrl,
                    payloadType: 'form',
                    data: formData,
                }).then(function (res) {
                    var metaInput = document.getElementById(prefix + fieldId + '_meta');
                    if (metaInput) metaInput.value = JSON.stringify(res.data);

                    var resultDiv = document.getElementById(prefix + fieldId + '_result');
                    if (resultDiv) {
                        resultDiv.querySelector('.custom-field-file-name').textContent = res.data.filename;
                        resultDiv.style.display = 'flex';
                    }

                    var currentDiv = document.getElementById(prefix + fieldId + '_current');
                    if (currentDiv) currentDiv.style.display = 'none';
                });
            });
        });
    }

    function removeFile(prefix, fieldId) {
        var metaInput = document.getElementById(prefix + fieldId + '_meta');
        if (metaInput) metaInput.value = '';

        var fileInput = document.getElementById(prefix + fieldId);
        if (fileInput) {
            fileInput.value = '';
            var chosenEl = fileInput.closest('.custom-field-file-input-row');
            chosenEl = chosenEl && chosenEl.querySelector('.custom-field-file-chosen');
            if (chosenEl) chosenEl.textContent = '선택된 파일 없음';
        }

        var resultDiv = document.getElementById(prefix + fieldId + '_result');
        if (resultDiv) resultDiv.style.display = 'none';

        var currentDiv = document.getElementById(prefix + fieldId + '_current');
        if (currentDiv) currentDiv.style.display = 'flex';

        // avatar: 미리보기를 기존 이미지로 되돌림(없으면 숨김)
        var preview = document.getElementById(prefix + fieldId + '_preview');
        if (preview) setAvatarPreviewSrc(prefix, fieldId, preview.dataset.existingUrl || '');
    }

    function deleteExisting(prefix, fieldId) {
        MubloRequest.showConfirm('파일을 삭제하시겠습니까?', function() {
            var metaInput = document.getElementById(prefix + fieldId + '_meta');
            if (metaInput) metaInput.value = '__delete__';

            var currentDiv = document.getElementById(prefix + fieldId + '_current');
            if (currentDiv) currentDiv.style.display = 'none';

            // avatar: 미리보기 숨기고 기존 URL 제거
            var preview = document.getElementById(prefix + fieldId + '_preview');
            if (preview) {
                preview.dataset.existingUrl = '';
                setAvatarPreviewSrc(prefix, fieldId, '');
            }
        }, { type: 'warning' });
    }

    // avatar 미리보기 — 선택한 파일을 즉시 표시(업로드 전)
    function showAvatarPreviewFromFile(prefix, fieldId, file) {
        var img = document.getElementById(prefix + fieldId + '_preview_img');
        var preview = document.getElementById(prefix + fieldId + '_preview');
        if (!img || !preview) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            img.src = e.target.result;
            preview.style.display = '';
        };
        reader.readAsDataURL(file);
    }

    // avatar 미리보기 src 설정(빈 값이면 숨김)
    function setAvatarPreviewSrc(prefix, fieldId, src) {
        var img = document.getElementById(prefix + fieldId + '_preview_img');
        var preview = document.getElementById(prefix + fieldId + '_preview');
        if (!img || !preview) return;
        if (src) {
            img.src = src;
            preview.style.display = '';
        } else {
            img.src = '';
            preview.style.display = 'none';
        }
    }

    // DOM 준비 시 자동 초기화
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFileUploads);
    } else {
        initFileUploads();
    }

    return {
        setUploadUrl: setUploadUrl,
        initFileUploads: initFileUploads,
        removeFile: removeFile,
        deleteExisting: deleteExisting,
    };
})();
