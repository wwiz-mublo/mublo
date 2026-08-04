    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <!-- Sidebar Toggle Button (Desktop) -->
        <div class="sidebar-toggle" id="sidebarToggleBtn">
            <i class="bi bi-chevron-left"></i>
            <span class="visually-hidden">Toggle</span>
        </div>

        <!-- Sidebar Header -->
        <div class="sidebar-header">
            <a href="/admin" class="sidebar-brand">
                <div class="sidebar-brand-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-layout-dashboard-icon lucide-layout-dashboard"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                </div>
                <span class="sidebar-brand-text d-flex flex-column lh-sm">
                    Adminstrator
                    <small class="text-muted" style="font-size:.7rem">Mublo v<?= htmlspecialchars($mubloVersion ?? '') ?></small>
                </span>
            </a>
        </div>

        <!-- Sidebar Navigation -->
        <nav class="sidebar-nav">
            <?php foreach ($menu as $group => $groupData): ?>
            <?php $items = $groupData['items'] ?? []; ?>
            <?php if (is_array($items) && count($items) > 0): ?>
            <div class="sidebar-nav-group">
                <div class="group-title"><?= strtoupper($groupData['label'] ?? $group) ?></div>

                <ul class="nav">
                    <?php foreach ($items as $item): ?>
                    <?php
                    $hasSubmenu = isset($item['submenu']) && is_array($item['submenu']) && count($item['submenu']) > 0;
                    // 서브메뉴가 1개면 부모를 직접 링크로 처리 (접기/펼치기 불필요)
                    $isSingleSubmenu = $hasSubmenu && count($item['submenu']) === 1;
                    if ($isSingleSubmenu) {
                        $item['url'] = $item['submenu'][0]['url'];
                        $item['code'] = $item['submenu'][0]['code'];
                        $hasSubmenu = false;
                    }
                    ?>
                    <?php
                    $isActive = false;
                    if (isset($activeCode)) {
                        if (!empty($item['code'])) {
                            $isActive = ($item['code'] === $activeCode || str_starts_with($activeCode, $item['code'] . '_'));
                        } elseif ($hasSubmenu) {
                            // 부모 코드가 빈 경우 (Plugin/Package 메뉴) → 서브메뉴 코드로 판단
                            foreach ($item['submenu'] as $_sub) {
                                if (($_sub['code'] ?? '') === $activeCode) {
                                    $isActive = true;
                                    break;
                                }
                            }
                        }
                    }
                    ?>
                    <li class="nav-item">
                        <a
                            class="nav-link <?= $isActive ? 'active expanded' : '' ?>"
                            href="<?= $hasSubmenu ? '#' : $item['url'] . (strpos($item['url'], '?') === false ? '?' : '&') . 'activeCode=' . $item['code'] ?>"
                            <?php if ($hasSubmenu): ?>data-submenu="submenu-<?= $item['code'] ?>"<?php endif; ?>
                        >
                            <i class="<?= isset($item['icon']) && $item['icon'] ? $item['icon'] : 'bi bi-grid-fill' ?>"></i>
                            <span class="sidebar-text"><?= isset($item['label']) && $item['label'] ? $item['label'] : '서브 메뉴' ?></span>
                            <?php if ($hasSubmenu): ?><i class="bi bi-chevron-right chevron-icon"></i><?php endif; ?>
                        </a>
                        <?php if ($hasSubmenu): ?>
                        <ul class="nav submenu <?= $isActive ? 'show' : '' ?>" id="submenu-<?= $item['code'] ?>">
                            <?php foreach ($item['submenu'] as $subItem): ?>
                            <?php
                                $subSource = $subItem['source'] ?? 'core';
                                $subSourceName = $subItem['sourceName'] ?? '';
                            ?>
                            <li class="nav-item">
                                <a
                                    class="nav-link <?= isset($activeCode) && $subItem['code'] === $activeCode ? 'active' : '' ?>"
                                    href="<?= $subItem['url'] . (strpos($subItem['url'], '?') === false ? '?' : '&') . 'activeCode=' . $subItem['code'] ?>"
                                    <?= isset($subItem['target']) && $subItem['target'] ? 'target="'.$subItem['target'].'"' : '' ?>
                                >
                                    <span class="sidebar-text"><?= $subItem['label'] ?><?php if ($subSource === 'plugin' && $subSourceName): ?><small class="ms-1 opacity-50" style="font-size: 0.65em;">[P]</small><?php elseif ($subSource === 'package' && $subSourceName): ?><small class="ms-1 opacity-50" style="font-size: 0.65em;">[K]</small><?php endif; ?></span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>

            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <!-- User Profile -->
        <div class="sidebar-footer">
            <div class="user-profile dropdown dropup">
                <div class="user-outline" data-bs-toggle="dropdown">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="">
                        <?php else: ?>
                        <?= strtoupper(substr($user['user_id'] ?? 'A', 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?= htmlspecialchars($user['nickname'] ?? $user['user_id'] ?? '관리자') ?></div>
                        <div class="user-email"><?= htmlspecialchars($user['user_id'] ?? '') ?></div>
                    </div>
                    <i class="bi bi-three-dots-vertical chevron-icon"></i>
                </div>
                <ul class="dropdown-menu">
                    <li class="px-1">
                        <div class="d-flex align-items-center gap-2 px-2 py-1 rounded">
                            <span class="user-avatar">
                                <?php if (!empty($user['avatar'])): ?>
                                <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="" class="w-100 h-100">
                                <?php else: ?>
                                <?= strtoupper(substr($user['user_id'] ?? 'A', 0, 1)) ?>
                                <?php endif; ?>
                            </span>
                            <span class="d-flex flex-column small lh-sm">
                                <span class="text-truncate"><?= htmlspecialchars($user['nickname'] ?? $user['user_id'] ?? '관리자') ?></span>
                                <span class="text-truncate text-muted"><?= htmlspecialchars($user['user_id'] ?? '') ?></span>
                            </span>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li class="px-1">
                        <a href="/admin/profile" class="dropdown-item rounded" data-no-active-code>
                            <i class="bi bi-person-circle"></i>
                            <span>내 정보</span>
                        </a>
                    </li>
                    <li class="px-1">
                        <a href="/admin/settings?activeCode=002_001" class="dropdown-item rounded">
                            <i class="bi bi-gear"></i>
                            <span>설정</span>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li class="px-1">
                        <form action="/admin/logout" method="post" data-no-active-code>
                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                            <button type="submit" class="dropdown-item rounded">
                                <i class="bi bi-box-arrow-right"></i>
                                <span>로그아웃</span>
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </aside>
