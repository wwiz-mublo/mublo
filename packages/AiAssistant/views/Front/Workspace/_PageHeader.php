<?php
/** @var string $pageTitle */
/** @var string $pageCopy */
?>
<header class="ai-workspace__page-head">
    <div><span><?= htmlspecialchars((string) $principal['company_name']) ?></span><h1><?= htmlspecialchars($pageTitle) ?></h1><p><?= htmlspecialchars($pageCopy) ?></p></div>
    <a href="/workspace"><i class="bi bi-arrow-left" aria-hidden="true"></i> 오늘의 업무</a>
</header>
