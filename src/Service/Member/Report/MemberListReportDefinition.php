<?php
declare(strict_types=1);

namespace Mublo\Service\Member\Report;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Report\Contract\MenuScopedReportInterface;
use Mublo\Core\Report\Document\ColumnDefinition;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Service\Member\MemberAdminService;
use Mublo\Service\Member\MemberFieldService;
use Mublo\Service\Member\MemberLevelService;

/**
 * 관리자 회원 목록 엑셀/CSV 내보내기 정의 (코어 Report 프레임워크)
 *
 * 목록 화면과 같은 검색·등급·상태 조건으로 같은 컬럼을 내보낸다. 추가 필드는 목록에
 * 노출하도록 설정한 것만 담는다 — 화면에서 보이지 않는 개인정보가 파일로 새지 않도록.
 * 파일·아바타 필드는 셀에 담을 수 없어 뺀다.
 *
 * 범위는 이 사이트 회원뿐이다. 슈퍼관리자 목록의 하위 사이트 계층 조회는 따르지 않는다.
 *
 * 레지스트리는 패키지 부팅 때마다 만들어지므로, 회원 서비스는 내보낼 때(build) 꺼낸다.
 */
class MemberListReportDefinition implements MenuScopedReportInterface
{
    public function __construct(
        private DependencyContainer $container,
    ) {
    }

    public function name(): string
    {
        return 'members';
    }

    /** 회원 관리 메뉴 — 회원 개인정보가 담기므로 요청 menuCode 를 믿지 않는다 */
    public function menuCode(): string
    {
        return '003_001';
    }

    public function build(array $filters): ReportDocument
    {
        $domainId = (int) ($filters['domain_id'] ?? 0);
        $memberAdminService = $this->container->get(MemberAdminService::class);
        $levelService = $this->container->get(MemberLevelService::class);
        $fieldService = $this->container->get(MemberFieldService::class);

        $columns = [
            new ColumnDefinition('user_id', '아이디', 'string', 'left'),
            new ColumnDefinition('nickname', '닉네임', 'string', 'left'),
            new ColumnDefinition('level_name', '등급', 'string', 'left'),
            new ColumnDefinition('status_label', '상태', 'string', 'center'),
        ];

        $listFields = array_values(array_filter(
            $fieldService->getListVisibleFields($domainId),
            fn(array $field) => !in_array($field['field_type'] ?? '', ['file', 'avatar'], true)
        ));
        foreach ($listFields as $field) {
            $columns[] = new ColumnDefinition('field_' . $field['field_name'], (string) $field['field_label'], 'string', 'left');
        }

        $columns[] = new ColumnDefinition('point', '포인트', 'number', 'right');
        $columns[] = new ColumnDefinition('created_at', '가입일시', 'string', 'left');
        $columns[] = new ColumnDefinition('last_login_at', '최종 로그인', 'string', 'left');

        $provider = new MemberListReportRowProvider(
            $memberAdminService,
            $levelService->getOptionsForSelect(),
            $domainId,
            $filters
        );

        return ReportDocument::create('회원 목록')
            ->addSection(new TableSection($columns, $provider));
    }
}
