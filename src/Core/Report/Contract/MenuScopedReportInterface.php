<?php
declare(strict_types=1);

namespace Mublo\Core\Report\Contract;

/**
 * 다운로드 권한을 판정할 관리자 메뉴를 리포트가 스스로 정한다.
 *
 * 구현하지 않은 리포트는 요청이 보낸 menuCode 로 판정한다. 그 값은 브라우저가 정하고
 * 권한은 거부 목록 방식이라, 다운로드가 막히지 않은 다른 메뉴 코드(없는 코드 포함)를
 * 보내면 판정을 비켜 갈 수 있다. 개인정보가 담긴 리포트는 이 인터페이스로 메뉴를 고정한다.
 */
interface MenuScopedReportInterface extends ReportDefinitionInterface
{
    /**
     * 권한 판정에 쓸 관리자 메뉴 코드 (예: '003_001', 'K_Shop_005')
     */
    public function menuCode(): string;
}
