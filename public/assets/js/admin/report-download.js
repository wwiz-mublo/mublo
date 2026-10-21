/**
 * 관리자 리포트(엑셀) 다운로드 — 코어 Report 프레임워크 공용 호출부
 *
 * MubloReportDownload.download({ reportName, filters, menuCode, filename, button })
 * MubloReportDownload.fromCurrentQuery(reportName, options) — 지금 목록의 검색 조건(쿼리스트링)을 그대로 넘긴다
 *
 * 메뉴를 스스로 정한 리포트(MenuScopedReportInterface)는 menuCode 를 보내지 않아도 된다.
 */
(function (window) {
    'use strict';

    var DEFAULT_ERROR = '엑셀 다운로드에 실패했습니다.';

    function showError(message) {
        if (window.MubloRequest && typeof window.MubloRequest.showAlert === 'function') {
            window.MubloRequest.showAlert(message, 'error');
            return;
        }
        window.alert(message);
    }

    function readError(response) {
        return response.json()
            .then(function (payload) {
                return payload.message || (payload.error && payload.error.message) || DEFAULT_ERROR;
            })
            .catch(function () {
                return DEFAULT_ERROR;
            });
    }

    function resolveFilename(response, fallback) {
        var disposition = response.headers.get('Content-Disposition') || '';
        var utf8 = disposition.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8) {
            try {
                return decodeURIComponent(utf8[1]);
            } catch (e) {
                return utf8[1];
            }
        }

        var quoted = disposition.match(/filename="([^"]+)"/i);
        return quoted ? quoted[1] : fallback;
    }

    function saveBlob(blob, filename) {
        var url = URL.createObjectURL(blob);
        var anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = filename;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
    }

    function download(options) {
        options = options || {};
        var reportName = options.reportName || '';
        var button = options.button || null;
        var fallback = options.filename || (reportName + '.xlsx');

        if (!reportName) {
            showError('출력 보고서를 지정해주세요.');
            return Promise.reject(new Error('reportName is required'));
        }

        if (button) {
            button.disabled = true;
        }

        return fetch('/api/v1/csrf/token')
            .then(function (response) { return response.json(); })
            .then(function (csrf) {
                var token = (csrf && csrf.data && csrf.data.token) || '';
                if (!token) {
                    throw new Error('보안 토큰을 확인할 수 없습니다.');
                }

                return fetch('/admin/report/' + encodeURIComponent(reportName) + '/download', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': token,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        menuCode: options.menuCode || '',
                        format: 'xlsx',
                        filters: options.filters || {}
                    })
                });
            })
            .then(function (response) {
                // 실패는 200 + JSON(success:false)로도 온다 — 형식으로 가른다
                var contentType = response.headers.get('Content-Type') || '';
                if (!response.ok || contentType.indexOf('application/json') !== -1) {
                    return readError(response).then(function (message) {
                        throw new Error(message);
                    });
                }

                var filename = resolveFilename(response, fallback);
                return response.blob().then(function (blob) {
                    saveBlob(blob, filename);
                });
            })
            .catch(function (error) {
                showError(error.message || DEFAULT_ERROR);
            })
            .finally(function () {
                if (button) {
                    button.disabled = false;
                }
            });
    }

    function fromCurrentQuery(reportName, options) {
        var filters = {};
        new URLSearchParams(window.location.search).forEach(function (value, key) {
            if (key !== 'page' && key !== 'activeCode') {
                filters[key] = value;
            }
        });

        return download(Object.assign({}, options || {}, { reportName: reportName, filters: filters }));
    }

    window.MubloReportDownload = { download: download, fromCurrentQuery: fromCurrentQuery };
})(window);
