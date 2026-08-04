<?php
/**
 * Block Shared Title Partial
 *
 * 모든 블록 스킨에서 공유하는 타이틀 영역
 *
 * 스킨에서 include $titlePartial; 로 사용합니다.
 * 커스텀 타이틀이 필요하면 views/Block/{type}/{skin}/title.php를 만들면
 * 자동으로 해당 파일이 우선 적용됩니다.
 *
 * @var array $titleConfig 타이틀 설정
 */
$hasTitleImage = !empty($titleConfig['pc_image']);
$hasTitleText = !empty($titleConfig['show']) && !empty($titleConfig['text']);

if ($hasTitleImage || $hasTitleText):
    $titlePosition = $titleConfig['position'] ?? 'left';
    if (!in_array($titlePosition, ['left', 'center', 'right'], true)) $titlePosition = 'left';

    // 색상/크기는 CSS 변수로 내보낸다.
    // 스킨은 `--title-color` 등을 재정의해 기본값을 바꿀 수 있고,
    // 관리자가 값을 지정하면 인라인 변수가 스킨 기본값을 이긴다.
    $titleTextStyles = [];
    if (!empty($titleConfig['color'])) $titleTextStyles[] = '--title-color: ' . $titleConfig['color'];
    if (!empty($titleConfig['size_pc'])) $titleTextStyles[] = '--title-size-pc: ' . (int)$titleConfig['size_pc'] . 'px';
    if (!empty($titleConfig['size_mo'])) $titleTextStyles[] = '--title-size-mo: ' . (int)$titleConfig['size_mo'] . 'px';

    // 문구 스타일
    // 문구 위치: 빈 값이면 제목 정렬을 상속(제목과 동일), 값이 있으면 개별 오버라이드
    $copytextPosition = $titleConfig['copytext_position'] ?? '';
    $copytextStyles = [];
    if (!empty($titleConfig['copytext_color'])) $copytextStyles[] = '--copytext-color: ' . $titleConfig['copytext_color'];
    if (!empty($titleConfig['copytext_size_pc'])) $copytextStyles[] = '--copytext-size-pc: ' . (int)$titleConfig['copytext_size_pc'] . 'px';
    if (!empty($titleConfig['copytext_size_mo'])) $copytextStyles[] = '--copytext-size-mo: ' . (int)$titleConfig['copytext_size_mo'] . 'px';

    $titleTextStyleAttr = $titleTextStyles ? ' style="' . htmlspecialchars(implode('; ', $titleTextStyles)) . '"' : '';
    $copytextStyleAttr = $copytextStyles ? ' style="' . htmlspecialchars(implode('; ', $copytextStyles)) . '"' : '';

    // 더보기 문구: 미설정 시 기본값. (i18n 도입 후 __('block.title.more') 로 교체)
    $moreText = !empty($titleConfig['more_text']) ? $titleConfig['more_text'] : '더보기';

    // 문구 위치는 화이트리스트로 제한 (클래스명 주입 차단)
    $copytextPosition = in_array($copytextPosition, ['left', 'center', 'right'], true) ? $copytextPosition : '';
?>
<div class="block-title block-title--<?= $titlePosition ?>">
    <?php if ($hasTitleImage):
        $pcImage = htmlspecialchars($titleConfig['pc_image']);
        $moImage = !empty($titleConfig['mo_image']) ? htmlspecialchars($titleConfig['mo_image']) : '';
        $titleAlt = htmlspecialchars($titleConfig['text'] ?? '');
    ?>
    <div class="block-title__image">
        <img src="<?= $pcImage ?>" alt="<?= $titleAlt ?>" class="block-title__img block-title__img--pc">
        <?php if ($moImage && $moImage !== $pcImage): ?>
        <img src="<?= $moImage ?>" alt="<?= $titleAlt ?>" class="block-title__img block-title__img--mo">
        <?php endif; ?>
    </div>
    <?php elseif ($hasTitleText): ?>
    <h3 class="block-title__text"<?= $titleTextStyleAttr ?>><?= htmlspecialchars($titleConfig['text']) ?></h3>
    <?php endif; ?>
    <?php if (!empty($titleConfig['more_url'])): ?>
    <a href="<?= htmlspecialchars($titleConfig['more_url']) ?>" class="block-title__more"><?= htmlspecialchars($moreText) ?></a>
    <?php endif; ?>
    <?php if (!empty($titleConfig['copytext'])): ?>
    <p class="block-title__copytext<?= $copytextPosition ? ' block-title__copytext--' . $copytextPosition : '' ?>"<?= $copytextStyleAttr ?>><?= htmlspecialchars($titleConfig['copytext']) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>
