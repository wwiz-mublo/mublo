<?php
/**
 * 고객센터 컴포넌트 (대표 전화·운영시간·ict 인증 마크)
 *
 * 파일 스킨(frame/{skin}/Footer.php)과 프레임 템플릿 슬롯({{cs_info}})이 공유한다.
 * csInfo는 패키지가 siteOverrides로 덮어쓸 수 있는 값이므로(Context 경유),
 * 운영자 템플릿은 반드시 이 슬롯을 쓰고 값을 하드코딩하지 않는다.
 *
 * @var array $csInfo        고객센터 정보 (tel, time, email, ict_mark)
 * @var array $companyConfig 회사 정보 (tel, email — 폴백)
 */
$csInfo = $csInfo ?? [];
$companyConfig = $companyConfig ?? [];

$csTel   = $csInfo['tel'] ?? $companyConfig['tel'] ?? '';
$csTime  = $csInfo['time'] ?? '';

$hasCs = !empty($csTel) || !empty($csTime);
?>
            <?php if ($hasCs || !empty($csInfo['ict_mark'])): ?>
            <div class="footer-support">
                <?php if ($hasCs): ?>
                <div class="footer-cs">
                    <?php if (!empty($csTel)): ?>
                    <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9\-]/', '', $csTel)) ?>" class="footer-cs-tel">
                        <?= htmlspecialchars($csTel) ?>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($csTime)): ?>
                    <div class="footer-cs-time"><?= nl2br(htmlspecialchars($csTime)) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($csInfo['ict_mark'])): ?>
                <div class="footer-ict-mark"><?= $csInfo['ict_mark'] ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
