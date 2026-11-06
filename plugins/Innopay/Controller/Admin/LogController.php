<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\ViewResponse;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Innopay\Repository\InnopayAttemptRepository;
use Mublo\Plugin\Innopay\Repository\InnopayLogRepository;

class LogController
{
    private const PER_PAGE = 30;

    /** 단계 필터 목록 — 화면 표시용 설명 */
    public const STEPS = [
        'PREPARE' => '결제 준비',
        'CLIENT_OPEN' => '결제창 열림',
        'CLIENT_CLOSE' => '결제창 닫힘',
        'CLIENT_ERROR' => '결제창 오류',
        'RETURN' => '인증 복귀',
        'RETURN_FAIL' => '복귀 처리 실패',
        'APPROVE_REQ' => '승인 요청',
        'APPROVE_RES' => '승인 응답',
        'APPROVE_RECOVER' => '승인 결과 복구',
        'QUERY_TID_REQ' => '거래조회 요청',
        'QUERY_TID_RES' => '거래조회 응답',
        'CONSUMER_RESULT' => '주문 반영',
        'CANCEL_REQ' => '취소 요청',
        'CANCEL_RES' => '취소 응답',
        'AUTO_CANCEL' => '자동 취소',
        'NEEDS_ACTION' => '확인 필요',
    ];

    private MigrationRunner $migrationRunner;

    public function __construct(
        private InnopayLogRepository $logs,
        private InnopayAttemptRepository $attempts,
        Database $db,
    ) {
        $this->migrationRunner = new MigrationRunner($db);
    }

    public function index(Request $request, Context $context): ViewResponse
    {
        $installView = SettingsController::installViewIfNeeded($this->migrationRunner, '이노페이 결제 로그');
        if ($installView !== null) {
            return $installView;
        }

        $domainId = $context->getDomainId() ?? 1;
        $filters = [
            'keyword' => trim((string) $request->get('keyword', '')),
            'step' => trim((string) $request->get('step', '')),
            'level' => trim((string) $request->get('level', '')),
            'date' => trim((string) $request->get('date', '')),
        ];
        $page = max(1, (int) $request->get('page', 1));

        $total = $this->logs->count($domainId, $filters);

        return ViewResponse::absoluteView(MUBLO_PLUGIN_PATH . '/Innopay/views/Admin/Logs/Index')
            ->withData([
                'pageTitle' => '이노페이 결제 로그',
                'logs' => $this->logs->search($domainId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
                'filters' => $filters,
                'steps' => self::STEPS,
                'needsAction' => $this->attempts->findNeedsAction($domainId),
                'pagination' => [
                    'totalItems' => $total,
                    'perPage' => self::PER_PAGE,
                    'currentPage' => $page,
                    'totalPages' => max(1, (int) ceil($total / self::PER_PAGE)),
                ],
            ]);
    }
}
