<?php
namespace Mublo\Plugin\SnsLogin\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Plugin\SnsLogin\Service\SnsConnectionManager;

class AccountsController
{
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/SnsLogin/views/Admin/Accounts/';
    private const PER_PAGE  = 20;

    public function __construct(
        private SnsAccountRepository $accountRepository,
        private SnsConnectionManager $connectionManager,
    ) {}

    public function index(array $params, Context $context): ViewResponse
    {
        $request  = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;

        $provider    = $request->get('provider', '');
        $currentPage = max(1, (int)$request->get('page', 1));
        $offset      = ($currentPage - 1) * self::PER_PAGE;

        $totalItems = $this->accountRepository->countFiltered($domainId, $provider ?: null);
        $accounts   = $this->accountRepository->listPaginated($domainId, $provider ?: null, self::PER_PAGE, $offset);

        $totalPages = max(1, (int)ceil($totalItems / self::PER_PAGE));

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Index')
            ->withData([
                'pageTitle'   => 'SNS 연동 내역',
                'accounts'    => $accounts,
                'provider'    => $provider,
                'pagination'  => [
                    'totalItems'  => $totalItems,
                    'perPage'     => self::PER_PAGE,
                    'currentPage' => $currentPage,
                    'totalPages'  => $totalPages,
                ],
            ]);
    }

    public function destroy(array $params, Context $context): JsonResponse
    {
        $id = (int)($params['id'] ?? 0);

        if (!$id) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $domainId = $context->getDomainId() ?? 1;
        $result = $this->connectionManager->revokeAndDeleteById($id, $domainId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }
}
