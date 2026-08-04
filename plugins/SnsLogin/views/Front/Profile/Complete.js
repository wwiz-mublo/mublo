/**
 * SNS 로그인 — 프로필 완성 폼 동작
 * 의존: MubloRequest (data-callback=profileComplete)
 */
window.profileComplete = function (response) {
    if (response.result === 'success') {
        location.href = response.data?.redirect || '/';
    }
};

document.getElementById('profileForm').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        var btn = this.querySelector('.mublo-submit');
        if (btn) btn.click();
    }
});
