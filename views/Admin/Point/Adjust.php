<?php
/**
 * Admin Point - Adjust
 *
 * 포인트 수동 조정
 *
 * @var string $pageTitle
 * @var array|null $member 회원 정보 (미리 선택된 경우)
 * @var int $currentBalance 현재 잔액
 */
?>
<form id="adjustForm">
<?php // 폼 로드 시 1회용 nonce — 더블서브밋(재클릭·새로고침) 이중 지급/차감을 원장 멱등키로 차단 ?>
<input type="hidden" name="formData[idempotency_nonce]" value="<?= bin2hex(random_bytes(16)) ?>">
<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '포인트 수동 조정') ?></h3>
            <p>회원에게 포인트를 지급하거나 차감합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/point" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button" class="btn btn-sm btn-primary mublo-submit"
                    data-target="/admin/point/adjust-store"
                    data-callback="adjustFormSuccess">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </div>

    <div class="page-block">
        <div class="card">
            <div class="card-body">
                <!-- 회원 선택 -->
                <div class="mb-4 position-relative">
                    <label for="memberSearch" class="form-label fw-bold">회원 선택 <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="memberSearch"
                            placeholder="회원 아이디를 입력하세요"
                            value="<?= htmlspecialchars($member['user_id'] ?? '') ?>"
                            autocomplete="off">
                        <input type="hidden" name="formData[member_id]" id="memberId"
                            value="<?= $member['member_id'] ?? '' ?>">
                        <button type="button" class="btn btn-outline-secondary" id="searchMemberBtn">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                    <div id="memberSearchResults" class="list-group position-absolute w-100 start-0" style="z-index:1000; max-height:200px; overflow-y:auto; display:none;"></div>
                    <div id="memberInfo" class="mt-2 <?= $member ? '' : 'd-none' ?>">
                        <?php if ($member): ?>
                        <div class="alert alert-info mb-0 py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    <strong><?= htmlspecialchars($member['user_id']) ?></strong>
                                    (ID: <?= $member['member_id'] ?>)
                                </span>
                                <span>현재 잔액: <strong id="currentBalanceDisplay"><?= number_format($currentBalance) ?></strong> P</span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 조정 타입 -->
                <div class="mb-4">
                    <label class="form-label fw-bold">조정 유형 <span class="text-danger">*</span></label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="formData[adjust_type]" id="typeAdd" value="add" checked>
                        <label class="btn btn-outline-primary" for="typeAdd">
                            <i class="bi bi-plus-circle"></i> 지급
                        </label>

                        <input type="radio" class="btn-check" name="formData[adjust_type]" id="typeSubtract" value="subtract">
                        <label class="btn btn-outline-danger" for="typeSubtract">
                            <i class="bi bi-dash-circle"></i> 차감
                        </label>
                    </div>
                </div>

                <!-- 포인트 금액 -->
                <div class="mb-4">
                    <label for="amount" class="form-label fw-bold">포인트 <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="amount" name="formData[amount]"
                            min="1" placeholder="0" required>
                        <span class="input-group-text">P</span>
                    </div>
                    <div id="previewBalance" class="form-text"></div>
                </div>

                <!-- 조정 사유 -->
                <div class="mb-4">
                    <label for="message" class="form-label fw-bold">조정 사유 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="message" name="formData[message]"
                        placeholder="예: 이벤트 당첨, 관리자 보정, 잘못된 지급 취소 등" required>
                    <div class="form-text">회원에게 표시되는 메시지입니다.</div>
                </div>

                <!-- 관리자 메모 -->
                <div class="mb-4">
                    <label for="memo" class="form-label fw-bold">관리자 메모</label>
                    <textarea class="form-control" id="memo" name="formData[memo]" rows="3"
                            placeholder="내부 관리용 메모 (선택사항)"></textarea>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const memberSearch = document.getElementById('memberSearch');
    const memberIdInput = document.getElementById('memberId');
    const searchResults = document.getElementById('memberSearchResults');
    const memberInfo = document.getElementById('memberInfo');
    const amountInput = document.getElementById('amount');
    const previewBalance = document.getElementById('previewBalance');
    let currentBalance = <?= $currentBalance ?>;
    let searchTimeout = null;

    // Enter 키로 폼 제출 방지 (검색 필드에서)
    memberSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            // Enter 키 입력 시 검색 실행
            const keyword = this.value.trim();
            if (keyword.length >= 2) {
                MubloRequest.requestJson('/admin/point/search-member?keyword=' + encodeURIComponent(keyword))
                    .then(function(response) {
                        if (response.result === 'success' && response.data.members.length > 0) {
                            showSearchResults(response.data.members);
                        } else {
                            searchResults.innerHTML = '<div class="list-group-item text-muted">검색 결과가 없습니다.</div>';
                            searchResults.style.display = 'block';
                        }
                    });
            }
        }
    });

    // 검색 버튼 클릭
    document.getElementById('searchMemberBtn').addEventListener('click', function(e) {
        e.preventDefault();
        const keyword = memberSearch.value.trim();
        if (keyword.length >= 2) {
            MubloRequest.requestJson('/admin/point/search-member?keyword=' + encodeURIComponent(keyword))
                .then(function(response) {
                    if (response.result === 'success' && response.data.members.length > 0) {
                        showSearchResults(response.data.members);
                    } else {
                        searchResults.innerHTML = '<div class="list-group-item text-muted">검색 결과가 없습니다.</div>';
                        searchResults.style.display = 'block';
                    }
                });
        }
    });

    // 회원 검색 (자동완성)
    memberSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const keyword = this.value.trim();

        if (keyword.length < 2) {
            searchResults.style.display = 'none';
            return;
        }

        searchTimeout = setTimeout(function() {
            MubloRequest.requestJson('/admin/point/search-member?keyword=' + encodeURIComponent(keyword))
                .then(function(response) {
                    if (response.result === 'success' && response.data.members.length > 0) {
                        showSearchResults(response.data.members);
                    } else {
                        searchResults.innerHTML = '<div class="list-group-item text-muted">검색 결과가 없습니다.</div>';
                        searchResults.style.display = 'block';
                    }
                });
        }, 300);
    });

    // 검색 결과 표시
    function showSearchResults(members) {
        searchResults.innerHTML = members.map(function(m) {
            return '<a href="#" class="list-group-item list-group-item-action" data-member-id="' + m.member_id + '" data-user-id="' + m.user_id + '" data-balance="' + m.balance + '">' +
                '<div class="d-flex justify-content-between">' +
                '<span><strong>' + m.user_id + '</strong></span>' +
                '<span class="text-muted">' + Number(m.balance).toLocaleString() + ' P</span>' +
                '</div>' +
                '</a>';
        }).join('');
        searchResults.style.display = 'block';
    }

    // 회원 선택
    searchResults.addEventListener('click', function(e) {
        e.preventDefault();
        const item = e.target.closest('.list-group-item');
        if (!item || !item.dataset.memberId) return;

        memberIdInput.value = item.dataset.memberId;
        memberSearch.value = item.dataset.userId;
        currentBalance = parseInt(item.dataset.balance) || 0;

        memberInfo.innerHTML = '<div class="alert alert-info mb-0 py-2">' +
            '<div class="d-flex justify-content-between align-items-center">' +
            '<span><strong>' + item.dataset.userId + '</strong> (ID: ' + item.dataset.memberId + ')</span>' +
            '<span>현재 잔액: <strong id="currentBalanceDisplay">' + currentBalance.toLocaleString() + '</strong> P</span>' +
            '</div></div>';
        memberInfo.classList.remove('d-none');

        searchResults.style.display = 'none';
        updatePreview();
    });

    // 외부 클릭 시 검색 결과 닫기
    document.addEventListener('click', function(e) {
        if (!searchResults.contains(e.target) && e.target !== memberSearch) {
            searchResults.style.display = 'none';
        }
    });

    // 금액 입력 시 미리보기
    amountInput.addEventListener('input', updatePreview);
    document.querySelectorAll('input[name="formData[adjust_type]"]').forEach(function(radio) {
        radio.addEventListener('change', updatePreview);
    });

    function updatePreview() {
        if (!memberIdInput.value || !amountInput.value) {
            previewBalance.textContent = '';
            return;
        }

        const amount = parseInt(amountInput.value) || 0;
        const isAdd = document.getElementById('typeAdd').checked;
        const newBalance = isAdd ? (currentBalance + amount) : (currentBalance - amount);

        if (!isAdd && newBalance < 0) {
            previewBalance.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> 잔액이 부족합니다. (변경 후: ' + newBalance.toLocaleString() + ' P)</span>';
        } else {
            const changeText = isAdd ? '+' + amount.toLocaleString() : '-' + amount.toLocaleString();
            previewBalance.innerHTML = '<span class="text-' + (isAdd ? 'primary' : 'danger') + '">' + changeText + '</span> &rarr; ' +
                '변경 후 잔액: <strong>' + newBalance.toLocaleString() + ' P</strong>';
        }
    }

    // 폼 제출 성공 콜백
    window.adjustFormSuccess = function(response) {
        if (response.data && response.data.redirect) {
            window.location.href = response.data.redirect;
        }
    };
});
</script>
