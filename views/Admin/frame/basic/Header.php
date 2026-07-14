    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Header -->
        <header class="top-header">
            <button type="button" class="btn btn-link text-body mobile-toggle p-0" id="mobileToggle">
                <i class="bi bi-list fs-4"></i>
                <span class="visually-hidden">Toggle</span>
            </button>

            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/admin">Admin</a></li>
                    <?php if (!empty($breadcrumb)): ?>
                        <?php $lastIdx = count($breadcrumb) - 1; ?>
                        <?php foreach ($breadcrumb as $i => $item): ?>
                            <?php if ($i === $lastIdx): ?>
                    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($item['label']) ?></li>
                            <?php elseif (!empty($item['url']) && $item['url'] !== '#'): ?>
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($item['url']) ?>"><?= htmlspecialchars($item['label']) ?></a></li>
                            <?php else: ?>
                    <li class="breadcrumb-item"><?= htmlspecialchars($item['label']) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></li>
                    <?php endif; ?>
                </ol>
            </nav>

            <div class="ms-auto d-flex align-items-center gap-2">
                <?php if (!empty($proxyLogin)): ?>
                <span class="proxy-login-badge">
                    <i class="bi bi-person-badge"></i>
                    <strong><?= htmlspecialchars($proxyLogin['admin_nickname'] ?? '관리자') ?></strong>님이
                    <strong><?= htmlspecialchars($proxyLogin['site_name'] ?? '') ?></strong>에 대리접속 중
                </span>
                <?php endif; ?>
                <a href="/" target="_blank" class="header-icon-link" aria-label="홈페이지로 이동">
                    <svg fill="none" shape-rendering="geometricPrecision" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="32" height="32" style="color: currentcolor;"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    <span class="visually-hidden">홈페이지</span>
                </a>
                <div class="theme-toggle">
                    <button type="button" class="theme-toggle-button position-absolute d-none" data-bs-theme-value="light" aria-pressed="false">
                        <svg fill="none" shape-rendering="geometricPrecision" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="32" height="32" style="color: currentcolor;"><circle cx="12" cy="12" r="5"></circle><path d="M12 1v2"></path><path d="M12 21v2"></path><path d="M4.22 4.22l1.42 1.42"></path><path d="M18.36 18.36l1.42 1.42"></path><path d="M1 12h2"></path><path d="M21 12h2"></path><path d="M4.22 19.78l1.42-1.42"></path><path d="M18.36 5.64l1.42-1.42"></path></svg>
                        <span class="visually-hidden">Light</span>
                    </button>
                    <button type="button" class="theme-toggle-button position-relative invisible z-n1" data-bs-theme-value="auto" aria-pressed="false">
                        <svg fill="none" shape-rendering="geometricPrecision" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="32" height="32" style="color: currentcolor;"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><path d="M8 21h8"></path><path d="M12 17v4"></path></svg>
                        <span class="visually-hidden">Auto</span>
                    </button>
                    <button type="button" class="theme-toggle-button position-absolute d-none" data-bs-theme-value="dark" aria-pressed="false">
                        <svg fill="none" shape-rendering="geometricPrecision" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="32" height="32" style="color: currentcolor;"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path></svg>
                        <span class="visually-hidden">Dark</span>
                    </button>
                </div>
            </div>
        </header>

        <!-- Content Area -->
        <main class="content-area">
