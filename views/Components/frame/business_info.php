<?php
/**
 * 사업자 정보 컴포넌트 (상호·대표·사업자등록번호·통신판매업·주소 등)
 *
 * 파일 스킨(frame/{skin}/Footer.php)과 프레임 템플릿 슬롯({{business_info}})이 공유한다.
 * 빈 필드는 라벨째 생략하는 조건 로직을 이 컴포넌트가 소유한다.
 *
 * @var array $companyConfig 회사 정보 (name, owner, tel, email, business_number,
 *                           tongsin_number, zipcode, address, address_detail,
 *                           fax, privacy_officer, privacy_email)
 * @var array $csInfo        고객센터 정보 (email — 이메일 항목 폴백에 사용)
 */
$companyConfig = $companyConfig ?? [];
$csInfo = $csInfo ?? [];

$addressParts = array_filter([
    !empty($companyConfig['zipcode']) ? '(' . $companyConfig['zipcode'] . ')' : '',
    $companyConfig['address'] ?? '',
    $companyConfig['address_detail'] ?? '',
]);
$fullAddress = implode(' ', $addressParts);

$csEmail = $csInfo['email'] ?? $companyConfig['email'] ?? '';

$hasInfo = !empty($companyConfig['name'])
    || !empty($companyConfig['owner'])
    || !empty($companyConfig['privacy_officer'])
    || !empty($companyConfig['business_number'])
    || !empty($companyConfig['tongsin_number'])
    || !empty($fullAddress)
    || !empty($csEmail);
?>
                <?php if ($hasInfo): ?>
                <div class="footer-info">
                    <?php if (!empty($companyConfig['name'])): ?>
                    <span><em>상호</em> <?= htmlspecialchars($companyConfig['name']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($companyConfig['owner'])): ?>
                    <span><em>대표</em> <?= htmlspecialchars($companyConfig['owner']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($companyConfig['business_number'])): ?>
                    <span><em>사업자등록번호</em> <?= htmlspecialchars($companyConfig['business_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($companyConfig['tongsin_number'])): ?>
                    <span><em>통신판매업</em> <?= htmlspecialchars($companyConfig['tongsin_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($fullAddress)): ?>
                    <span><em>주소</em> <?= htmlspecialchars($fullAddress) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($csEmail)): ?>
                    <span><em>이메일</em> <a href="mailto:<?= htmlspecialchars($csEmail) ?>"><?= htmlspecialchars($csEmail) ?></a></span>
                    <?php endif; ?>
                    <?php if (!empty($companyConfig['tel'])): ?>
                    <span><em>전화</em> <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9\-]/', '', $companyConfig['tel'])) ?>"><?= htmlspecialchars($companyConfig['tel']) ?></a></span>
                    <?php endif; ?>
                    <?php if (!empty($companyConfig['fax'])): ?>
                    <span><em>팩스</em> <?= htmlspecialchars($companyConfig['fax']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($companyConfig['privacy_officer'])): ?>
                    <span><em>개인정보보호책임자</em> <?= htmlspecialchars($companyConfig['privacy_officer']) ?><?php if (!empty($companyConfig['privacy_email'])): ?> <a href="mailto:<?= htmlspecialchars($companyConfig['privacy_email']) ?>"><?= htmlspecialchars($companyConfig['privacy_email']) ?></a><?php endif; ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
