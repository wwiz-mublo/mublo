<?php
/**
 * Widget Left Skin - basic
 *
 * @var array  $items    위젯 항목 배열
 * @var string $position 위치 (left)
 * @var array  $config   위젯 설정 (left_width 등)
 */
if (empty($items)) return;
$itemSize = (int) ($config['left_width'] ?? 50);
$this->assets->addCss('/serve/plugin/Widget/views/Front/skins/left/basic/style.css');
?>
<div id="mublo-widget-left" style="--widget-item-size:<?= $itemSize ?>px">
    <?php foreach ($items as $item):
        $type = $item['item_type'] ?? 'link';
        $url = $item['link_url'] ?? '';
        $target = $item['link_target'] ?? '_blank';
        $image = htmlspecialchars($item['icon_image'] ?? '');
        $alt = htmlspecialchars($item['title'] ?? '');
    ?>
    <div class="widget-item">
        <?php if ($type === 'tel'): ?>
        <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+\-]/', '', $url)) ?>"><img src="<?= $image ?>" alt="<?= $alt ?>"></a>
        <?php else: ?>
        <a href="<?= htmlspecialchars($url) ?>" target="<?= htmlspecialchars($target) ?>"><img src="<?= $image ?>" alt="<?= $alt ?>"></a>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
