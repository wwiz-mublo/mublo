<?php
/**
 * SNS 채널 링크 컴포넌트
 *
 * 파일 스킨(frame/{skin}/Footer.php)과 프레임 템플릿 슬롯({{sns}})이 공유한다.
 * 아이콘 SVG·라벨·브랜드 색상은 SnsHelper(코어)가 소유한다.
 *
 * @var array $seoConfig SEO/SNS 설정 (sns_channels: [{type, url}])
 */

use Mublo\Helper\Sns\SnsHelper;

$activeChannels = SnsHelper::filterActiveChannels($seoConfig['sns_channels'] ?? []);
?>
                    <?php if (!empty($activeChannels)): ?>
                    <div class="footer-sns">
                        <?php foreach ($activeChannels as $ch):
                            $type  = $ch['type'] ?? '';
                            $url   = $ch['url'] ?? '';
                            if (!$type || !$url) continue;
                            $svg   = SnsHelper::getSvg($type);
                            $label = SnsHelper::getLabel($type);
                            $color = SnsHelper::getColor($type);
                        ?>
                        <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener noreferrer"
                           class="footer-sns-btn" aria-label="<?= htmlspecialchars($label) ?>"
                           style="--sns-color:<?= htmlspecialchars($color) ?>">
                            <?= $svg ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
