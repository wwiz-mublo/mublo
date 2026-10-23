<?php
/**
 * 배송지 관리 (마이쇼핑 → 배송지)
 *
 * 목록은 서버에서 그리고, 추가·수정·삭제·기본 설정은 /shop/address/* JSON API 를 부른 뒤 새로 고친다.
 *
 * @var array $addresses    배송지 목록 (기본 배송지 먼저, 복호화됨)
 * @var int   $maxAddresses 저장 가능한 최대 개수
 */
$addresses    = $addresses ?? [];
$maxAddresses = (int) ($maxAddresses ?? 10);
$isFull       = count($addresses) >= $maxAddresses;

$this->assets->addCss('/serve/package/Shop/views/Front/Address/basic/_assets/css/address-index.css');
?>

<div class="shop-address">
    <div class="shop-address__head">
        <div>
            <h2 class="shop-address__title">배송지 관리</h2>
            <p class="shop-address__count">저장된 배송지 <?= count($addresses) ?>개 · 최대 <?= $maxAddresses ?>개</p>
        </div>
        <button type="button" class="shop-address__add" id="addressAddBtn" <?= $isFull ? 'disabled title="배송지는 최대 ' . $maxAddresses . '개까지 저장할 수 있습니다."' : '' ?>>
            <i class="bi bi-plus-lg"></i> 새 배송지
        </button>
    </div>

    <form class="shop-address__form" id="addressForm" hidden novalidate>
        <h3 class="shop-address__form-title" id="addressFormTitle">새 배송지</h3>
        <input type="hidden" id="aAddressId" value="0">
        <div class="shop-address__field">
            <label for="aAddressName">배송지명</label>
            <input type="text" id="aAddressName" maxlength="50" placeholder="자택, 직장 등">
        </div>
        <div class="shop-address__field">
            <label for="aRecipient">받는 분 <span class="shop-address__req">*</span></label>
            <input type="text" id="aRecipient" maxlength="50" autocomplete="name">
        </div>
        <div class="shop-address__field">
            <label for="aPhone">연락처</label>
            <input type="tel" id="aPhone" class="mask-hp" maxlength="20" placeholder="010-0000-0000" autocomplete="tel">
        </div>
        <div class="shop-address__field">
            <label for="aZip">주소 <span class="shop-address__req">*</span></label>
            <div class="shop-address__zip">
                <input type="text" id="aZip" readonly placeholder="우편번호">
                <button type="button" id="aZipBtn">주소 검색</button>
            </div>
            <input type="text" id="aAddress1" readonly placeholder="기본 주소">
            <input type="text" id="aAddress2" maxlength="100" placeholder="상세 주소">
        </div>
        <label class="shop-address__check" id="aDefaultWrap">
            <input type="checkbox" id="aIsDefault"> 기본 배송지로 저장
        </label>
        <div class="shop-address__form-btns">
            <button type="button" class="shop-address__cancel" id="addressCancelBtn">취소</button>
            <button type="submit" class="shop-address__save">저장</button>
        </div>
    </form>

    <?php if (empty($addresses)): ?>
    <div class="shop-address__empty">
        <p>저장된 배송지가 없습니다.</p>
        <p class="shop-address__empty-sub">배송지를 저장해 두면 주문서에서 바로 불러올 수 있습니다.</p>
    </div>
    <?php else: ?>
    <ul class="shop-address__list" id="addressList">
        <?php foreach ($addresses as $a):
            $isDefault = !empty($a['is_default']);
            $title = ($a['address_name'] ?? '') !== '' ? $a['address_name'] : ($a['recipient_name'] ?? '');
        ?>
        <li class="shop-address__card<?= $isDefault ? ' shop-address__card--default' : '' ?>">
            <div class="shop-address__card-top">
                <span class="shop-address__card-name"><?= e($title) ?></span>
                <?php if ($isDefault): ?><span class="shop-address__badge">기본 배송지</span><?php endif; ?>
            </div>
            <div class="shop-address__card-info">
                <?= e($a['recipient_name'] ?? '') ?><?php if (!empty($a['recipient_phone'])): ?> · <?= e($a['recipient_phone']) ?><?php endif; ?><br>
                [<?= e($a['zip_code'] ?? '') ?>] <?= e($a['address1'] ?? '') ?> <?= e($a['address2'] ?? '') ?>
            </div>
            <div class="shop-address__card-btns">
                <button type="button" data-action="edit" data-address="<?= e(json_encode($a, JSON_UNESCAPED_UNICODE)) ?>">수정</button>
                <?php if (!$isDefault): ?>
                <button type="button" data-action="default" data-id="<?= (int) $a['address_id'] ?>">기본 배송지로</button>
                <?php endif; ?>
                <button type="button" class="shop-address__danger" data-action="delete" data-id="<?= (int) $a['address_id'] ?>">삭제</button>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
<script src="<?= asset('/serve/package/Shop/views/Front/Address/basic/_assets/js/address-index.js') ?>"></script>
