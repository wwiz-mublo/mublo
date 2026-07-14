<?php

namespace Mublo\Core\Dashboard;

use Mublo\Core\Context\Context;

interface DashboardWidgetInterface
{
    /** 위젯 고유 ID (예: 'core.system_info', 'shop.sales_today') */
    public function id(): string;

    /** 표시 제목 (예: '시스템 정보', '오늘 매출') */
    public function title(): string;

    /** HTML 렌더링 */
    public function render(): string;

    /** 외부 CSS/JS 에셋 */
    public function assets(): array;

    /** 기본 슬롯 크기 (1~4). DB에서 오버라이드 가능 */
    public function defaultSlot(): int;

    /** 이 위젯을 볼 수 있는지 권한 체크 */
    public function canView(Context $context): bool;
}
