<?php
declare(strict_types=1);

use Mublo\Contract\Member\MemberActionTargetTransport;
use Mublo\Contract\Member\MemberActionView;

/** @var list<MemberActionView> $actions */
$actions = $actions ?? [];
$targetPublicId = (string) ($targetPublicId ?? '');
$csrfToken = (string) ($csrfToken ?? '');
$placement = (string) ($placement ?? '');
$compact = (bool) ($compact ?? false);
$ariaLabel = (string) ($ariaLabel ?? '회원 액션');
$triggerLabel = trim((string) ($triggerLabel ?? ''));
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if ($actions === [] || preg_match('/\A[0-9a-f]{22}\z/', $targetPublicId) !== 1) {
    return;
}

$class = 'mublo-mam'
    . ($compact ? ' mublo-mam--compact' : '')
    . ($triggerLabel !== '' ? ' mublo-mam--text' : '');
?>
<details class="<?= $escape($class) ?>"
         data-mublo-member-action-menu<?= $placement !== '' ? ' data-placement="' . $escape($placement) . '"' : '' ?>>
    <summary class="mublo-mam__button" aria-label="<?= $escape($ariaLabel) ?>">
        <?php if ($triggerLabel !== ''): ?>
            <span class="mublo-mam__trigger-label"><?= $escape($triggerLabel) ?></span>
        <?php else: ?>
            <span aria-hidden="true">&#8942;</span>
        <?php endif; ?>
    </summary>
    <ul class="mublo-mam__list">
        <?php foreach ($actions as $action): ?>
            <?php
            if (!$action instanceof MemberActionView) {
                continue;
            }
            $transport = $action->getTargetTransport();
            $endpoint = $action->getEndpoint();
            $icon = $action->getIcon();
            $iconMarkup = $icon !== ''
                ? '<i class="' . $escape($icon) . '" aria-hidden="true"></i> '
                : '';
            ?>
            <li class="mublo-mam__entry">
                <?php if ($transport === MemberActionTargetTransport::PrivateBody): ?>
                    <form method="post" action="<?= $escape($endpoint) ?>" class="mublo-mam__form">
                        <input type="hidden" name="target_public_id" value="<?= $escape($targetPublicId) ?>">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <button type="submit" class="mublo-mam__item"
                                data-action-id="<?= $escape($action->getId()) ?>"><?= $iconMarkup ?><?= $escape($action->getLabel()) ?></button>
                    </form>
                <?php else: ?>
                    <?php
                    $href = $transport === MemberActionTargetTransport::PublicPath
                        ? $endpoint . '/' . rawurlencode($targetPublicId)
                        : $endpoint . '?' . http_build_query(['member' => $targetPublicId], '', '&', PHP_QUERY_RFC3986);
                    ?>
                    <a href="<?= $escape($href) ?>" class="mublo-mam__item"
                       data-action-id="<?= $escape($action->getId()) ?>"><?= $iconMarkup ?><?= $escape($action->getLabel()) ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</details>
