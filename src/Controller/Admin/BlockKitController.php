<?php
namespace Mublo\Controller\Admin;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\HtmlResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Entity\Block\BlockKit;
use Mublo\Entity\Block\BlockKitApplication;
use Mublo\Enum\Block\BlockPosition;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Block\BlockKitApplier;
use Mublo\Service\Block\BlockKitLibrary;

/**
 * 블록 킷 관리 (설계 10)
 *
 * GET  /admin/block-kit              목록
 * GET  /admin/block-kit/show/{id}    상세
 * POST /admin/block-kit/upload       업로드 → 검증 → 보관
 * POST /admin/block-kit/preview      보관된 블록 킷 미리보기(dry-run)
 * POST /admin/block-kit/apply        보관된 블록 킷 적용
 * GET  /admin/block-kit/download/{id} 블록 킷 JSON 내려받기
 * POST /admin/block-kit/delete       소프트 삭제
 *
 * ## 권한
 *
 * 목록·상세는 일반 관리자도 본다. 업로드·적용·삭제만 도메인 운영자 이상이다(설계 9.4).
 * 메뉴를 눌렀는데 에러 화면이 뜨는 경험을 만들지 않고, 화면 안에서 버튼을 제한한다.
 *
 * ## 도메인 격리
 *
 * URL 의 kit_id 는 남의 도메인 것일 수 있다. 리포지토리가 모든 조회에서 domain_id 를
 * 함께 걸므로, 컨트롤러는 반환값이 null 인지만 보면 된다(설계 9.5).
 */
class BlockKitController
{
    use BlockKitControllerTrait;

    public function __construct(
        private BlockKitLibrary $library,
        private DependencyContainer $container
    ) {
    }

    /**
     * 목록
     *
     * GET /admin/block-kit
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        // 필터 — 허용값 밖은 전체('')로 뭉갠다. 쿼리스트링은 사용자가 만드는 값이다.
        $keyword = trim((string) $request->query('keyword', ''));
        $targetKind = (string) $request->query('target_kind', '');
        if (!in_array($targetKind, [BlockKit::TARGET_POSITION, BlockKit::TARGET_PAGE, BlockKit::TARGET_SCREEN], true)) {
            $targetKind = '';
        }
        $containsScript = (string) $request->query('script', '');
        if (!in_array($containsScript, ['1', '0'], true)) {
            $containsScript = '';
        }

        $filters = [
            'keyword' => $keyword,
            'target_kind' => $targetKind,
            'contains_script' => $containsScript,
        ];

        $kits = $this->library->list($domainId, $perPage, ($page - 1) * $perPage, $filters);
        $total = $this->library->count($domainId, $filters);

        /** @var AuthService $authService */
        $authService = $this->container->get(AuthService::class);

        return ViewResponse::view('blockkit/index')
            ->withData([
                'pageTitle' => '블록 킷 관리',
                'kits' => array_map(fn (BlockKit $kit) => $this->toListItem($kit), $kits),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
                // 목록은 보이되, 업로드·적용·삭제만 제한한다(설계 10).
                'canOperateKit' => $authService->canOperateDomain(),
                // "대상" 열의 위치 코드 → 한글 라벨 변환 (블록 행 목록의 출력 대상 열과 동일)
                'positions' => BlockPosition::options(),
                'currentKeyword' => $keyword,
                'currentTargetKind' => $targetKind,
                'currentScript' => $containsScript,
            ]);
    }

    /**
     * 상세
     *
     * GET /admin/block-kit/show/{id}
     */
    public function show(array $params, Context $context): ViewResponse|HtmlResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $kit = $this->library->find($domainId, (int) ($params[0] ?? 0));

        if ($kit === null) {
            return new HtmlResponse('블록 킷을 찾을 수 없습니다.', 404);
        }

        /** @var AuthService $authService */
        $authService = $this->container->get(AuthService::class);

        return ViewResponse::view('blockkit/show')
            ->withData([
                'pageTitle' => $kit->getKitName(),
                'kit' => $this->toListItem($kit),
                'history' => array_map($this->toHistoryItem(...), $this->library->history($domainId, $kit->getKitId())),
                'canOperateKit' => $authService->canOperateDomain(),
                // "대상" 행의 위치 코드 → 한글 라벨 변환 (목록과 동일)
                'positions' => BlockPosition::options(),
                // 목록 왕복 컨텍스트 — "목록" 버튼이 검색/필터/페이지 상태로 복귀한다
                'listContext' => $this->listContext($context),
            ]);
    }

    /**
     * 목록 복귀 컨텍스트 (검색어/필터/페이지)를 쿼리에서 추린다.
     *
     * 목록이 상세 링크에 실어 보낸 값이지만 URL 은 사용자가 만드는 값이므로,
     * 목록 필터와 같은 화이트리스트로 거른다.
     *
     * @return array<string, string|int>
     */
    private function listContext(Context $context): array
    {
        $request = $context->getRequest();
        $ctx = [];

        $keyword = trim((string) $request->query('keyword', ''));
        if ($keyword !== '') {
            $ctx['keyword'] = $keyword;
        }

        $targetKind = (string) $request->query('target_kind', '');
        if (in_array($targetKind, [BlockKit::TARGET_POSITION, BlockKit::TARGET_PAGE, BlockKit::TARGET_SCREEN], true)) {
            $ctx['target_kind'] = $targetKind;
        }

        $script = (string) $request->query('script', '');
        if (in_array($script, ['1', '0'], true)) {
            $ctx['script'] = $script;
        }

        $page = (int) $request->query('page', 1);
        if ($page > 1) {
            $ctx['page'] = $page;
        }

        return $ctx;
    }

    /**
     * 업로드 → 검증 → 보관
     *
     * POST /admin/block-kit/upload
     *
     * 검증에 실패한 블록 킷은 보관하지 않는다. 열리지 않는 블록 킷이 목록에 쌓이면 안 된다.
     */
    public function upload(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 킷 업로드는')) {
            return $denied;
        }

        $json = $this->readKitUpload();
        if ($json instanceof JsonResponse) {
            return $json;
        }

        $domainId = $context->getDomainId() ?? 1;
        $result = $this->library->store($domainId, $json);

        if (!$result['ok']) {
            return JsonResponse::error('블록 킷을 보관할 수 없습니다.', $result, 422);
        }

        return JsonResponse::success($result, '블록 킷이 보관되었습니다.');
    }

    /**
     * 보관된 블록 킷 미리보기 (dry-run)
     *
     * POST /admin/block-kit/preview
     *
     * 아무것도 쓰지 않으므로 일반 관리자도 볼 수 있다. 무엇이 적용될지 알아야
     * 운영자에게 적용을 요청할 수 있다.
     */
    public function preview(array $params, Context $context): JsonResponse
    {
        $kit = $this->loadKitJson($context);
        if ($kit instanceof JsonResponse) {
            return $kit;
        }

        /** @var BlockKitApplier $applier */
        $applier = $this->container->get(BlockKitApplier::class);

        return JsonResponse::success($applier->dryRunFromJson($kit));
    }

    /**
     * 보관된 블록 킷 적용
     *
     * POST /admin/block-kit/apply
     */
    public function apply(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 킷 적용은')) {
            return $denied;
        }

        $request = $context->getRequest();
        $mode = $request->input('mode') === BlockKitApplier::MODE_REPLACE
            ? BlockKitApplier::MODE_REPLACE
            : BlockKitApplier::MODE_APPEND;

        /** @var AuthService $authService */
        $authService = $this->container->get(AuthService::class);

        $result = $this->library->apply(
            $context->getDomainId() ?? 1,
            (int) $request->input('kit_id', 0),
            $mode,
            (bool) $request->input('apply_site_settings', false),
            $authService->id(),
            $authService->isSuper()
        );

        if (!$result['ok']) {
            return JsonResponse::error('블록 킷을 적용할 수 없습니다.', $result, 422);
        }

        return JsonResponse::success($result, '블록 킷이 적용되었습니다.');
    }

    /**
     * 적용 되돌리기
     *
     * POST /admin/block-kit/rollback
     *
     * 되돌리는 것은 블록 킷이 건드린 site_config 뿐이다. 블록 행은 되돌리지 않는다 —
     * 적용 후 운영자가 편집했을 수 있고, 그것까지 지우면 파괴다.
     */
    public function rollback(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 킷 되돌리기는')) {
            return $denied;
        }

        $result = $this->library->rollback(
            $context->getDomainId() ?? 1,
            (int) $context->getRequest()->input('application_id', 0)
        );

        if (!$result['ok']) {
            return JsonResponse::error($result['errors'][0] ?? '되돌릴 수 없습니다.', $result, 422);
        }

        return JsonResponse::success($result, '사이트 설정을 적용 이전으로 되돌렸습니다.');
    }

    /**
     * 블록 킷 JSON 내려받기
     *
     * GET /admin/block-kit/download/{id}
     */
    public function download(array $params, Context $context): HtmlResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $kit = $this->library->findWithJson($domainId, (int) ($params[0] ?? 0));

        if ($kit === null || !$kit->hasJson()) {
            return new HtmlResponse('블록 킷을 찾을 수 없습니다.', 404);
        }

        return (new HtmlResponse($kit->getKitJson()))
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Content-Disposition', $this->kitDownloadDisposition($kit->getKitName()));
    }

    /**
     * 소프트 삭제
     *
     * POST /admin/block-kit/delete
     *
     * 적용 이력이 FK 로 매달려 있으므로 행을 지우지 않는다.
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 킷 삭제는')) {
            return $denied;
        }

        $domainId = $context->getDomainId() ?? 1;
        $kitId = (int) $context->getRequest()->input('kit_id', 0);

        if (!$this->library->delete($domainId, $kitId)) {
            return JsonResponse::error('블록 킷을 찾을 수 없습니다.', null, 404);
        }

        return JsonResponse::success([], '블록 킷이 삭제되었습니다.');
    }

    /**
     * 목록 일괄 삭제
     *
     * POST /admin/block-kit/list-delete
     *
     * delete 와 같은 소프트 삭제를 반복할 뿐이다. 남의 도메인 kit_id 가 섞여 있어도
     * 리포지토리의 domain_id 조건이 걸러 실패로 셀 뿐, 오류가 되지 않는다(설계 9.5).
     */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 킷 삭제는')) {
            return $denied;
        }

        $domainId = $context->getDomainId() ?? 1;
        $chk = $context->getRequest()->input('chk') ?? [];

        if (!is_array($chk) || $chk === []) {
            return JsonResponse::error('삭제할 블록 킷을 선택해주세요.');
        }

        $deleted = 0;
        foreach ($chk as $kitId) {
            if ($this->library->delete($domainId, (int) $kitId)) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $failed = count($chk) - $deleted;
            $message = "{$deleted}개 블록 킷이 삭제되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개는 찾을 수 없어 건너뜀)";
            }
            return JsonResponse::success(['deleted' => $deleted], $message);
        }

        return JsonResponse::error('삭제할 수 있는 블록 킷이 없습니다.');
    }

    /**
     * 요청의 kit_id 로 보관된 블록 킷의 본문을 읽는다.
     *
     * @return string|JsonResponse
     */
    private function loadKitJson(Context $context): string|JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $kitId = (int) $context->getRequest()->input('kit_id', 0);

        $kit = $this->library->findWithJson($domainId, $kitId);
        if ($kit === null || !$kit->hasJson()) {
            return JsonResponse::error('블록 킷을 찾을 수 없습니다.', null, 404);
        }

        return $kit->getKitJson();
    }

    /**
     * 이력 한 줄. 스냅샷 원문은 화면에 흘리지 않는다 — 되돌릴 수 있는지만 알면 된다.
     *
     * @return array<string, mixed>
     */
    private function toHistoryItem(BlockKitApplication $application): array
    {
        $data = $application->toArray();
        unset($data['site_config_snapshot']);

        $data['can_rollback'] = $application->canRollback();

        return $data;
    }

    /**
     * 화면이 쓰는 형태로 바꾼다. kit_json 은 담지 않는다 — 목록이 무거워진다.
     *
     * @return array<string, mixed>
     */
    private function toListItem(BlockKit $kit): array
    {
        $data = $kit->toArray();
        unset($data['kit_json']);

        $data['target_label'] = match ($kit->getTargetKind()) {
            BlockKit::TARGET_PAGE => '페이지: ' . ($kit->getTargetPageCode() ?? '?'),
            BlockKit::TARGET_SCREEN => '화면: 메인화면',
            default => '위치: ' . ($kit->getTargetPosition() ?? '?')
                . ($kit->getTargetMenuCode() !== null ? ' / ' . $kit->getTargetMenuCode() : ''),
        };

        return $data;
    }
}
