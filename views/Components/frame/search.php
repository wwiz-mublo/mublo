<?php
/**
 * 통합 검색 폼 컴포넌트
 *
 * 파일 스킨(frame/{skin}/Header.php)과 프레임 템플릿 슬롯({{search}})이 공유한다.
 *
 * @var string $variant 'header'(PC 헤더, 기본) | 'panel'(모바일 패널)
 */
$variant = $variant ?? 'header';
?>
<?php if ($variant === 'panel'): ?>
            <div class="mublo-panel__search">
                <form action="/search" method="get" class="panel-search-form">
                    <input type="text" name="q" class="panel-search-input" placeholder="검색어 입력...">
                    <button type="submit" class="panel-search-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </button>
                </form>
            </div>
<?php else: ?>
            <div class="mublo-header__search">
                <form action="/search" method="get" class="header-search-form">
                    <input type="text" name="q" class="header-search-input"
                           placeholder="검색어 입력...">
                    <button type="submit" class="header-search-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </button>
                </form>
            </div>
<?php endif; ?>
