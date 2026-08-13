<?php
declare(strict_types=1);
namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Shop\Service\OptionPresetService;
use Mublo\Helper\Form\FormHelper;

/**
 * Admin OptionPresetController
 *
 * 옵션 프리셋 관리 컨트롤러
 *
 * 라우팅:
 * - GET  /admin/shop/options              → index (프리셋 목록)
 * - GET  /admin/shop/options/create       → create (생성 폼)
 * - GET  /admin/shop/options/{id}/edit    → edit (수정 폼)
 * - POST /admin/shop/options/store        → store (생성/수정)
 * - POST /admin/shop/options/{id}/delete  → delete (삭제)
 * - POST /admin/shop/options/detail       → detail (프리셋 상세 JSON)
 */
class OptionPresetController
{
    private OptionPresetService $optionPresetService;

    public function __construct(OptionPresetService $optionPresetService)
    {
        $this->optionPresetService = $optionPresetService;
    }

    /**
     * 옵션 프리셋 목록
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        $result = $this->optionPresetService->getList($domainId);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/OptionPreset/List')
            ->withData([
                'pageTitle' => '옵션 프리셋 관리',
                'presets' => $result->get('items', []),
            ]);
    }

    /**
     * 옵션 프리셋 생성 폼
     */
    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/OptionPreset/Form')
            ->withData([
                'pageTitle' => '옵션 프리셋 등록',
                'isEdit' => false,
                'preset' => null,
                'options' => [],
            ]);
    }

    /**
     * 옵션 프리셋 수정 폼
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();
        $presetId = (int) ($params['id'] ?? $params[0] ?? $request->query('id', 0));

        if ($presetId <= 0) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => '옵션 프리셋을 찾을 수 없습니다.']);
        }

        $result = $this->optionPresetService->getDetail($presetId, $context->getDomainId() ?? 1);

        if ($result->isFailure()) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => $result->getMessage()]);
        }

        $presetData = $result->get('preset', []);
        $options = $presetData['options'] ?? [];
        unset($presetData['options']);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/OptionPreset/Form')
            ->withData([
                'pageTitle' => '옵션 프리셋 수정',
                'isEdit' => true,
                'preset' => $presetData,
                'options' => $options,
            ]);
    }

    /**
     * 옵션 프리셋 저장 (생성/수정)
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // 옵션 데이터는 formData 밖에서 별도 전송
        $data['options'] = $request->input('options') ?? [];

        $presetId = (int) ($data['preset_id'] ?? 0);

        if ($presetId > 0) {
            $result = $this->optionPresetService->update($presetId, $data, $domainId);
        } else {
            $result = $this->optionPresetService->create($domainId, $data);
        }

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/shop/options'],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 옵션 프리셋 상세 조회 (JSON)
     *
     * 상품 등록/수정 시 프리셋 불러오기용
     */
    public function detail(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $presetId = (int) ($request->json('preset_id', 0));

        if ($presetId <= 0) {
            return JsonResponse::error('프리셋 ID가 필요합니다.');
        }

        $result = $this->optionPresetService->getDetail($presetId, $context->getDomainId() ?? 1);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success($result->getData());
    }

    /**
     * 옵션 프리셋 삭제
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $presetId = (int) ($params['id'] ?? $params[0] ?? $request->json('preset_id', 0));

        if ($presetId <= 0) {
            return JsonResponse::error('프리셋 ID가 필요합니다.');
        }

        $result = $this->optionPresetService->delete($presetId, $context->getDomainId() ?? 1);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 옵션 프리셋 선택 삭제 (벌크)
     */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $ids = array_values(array_filter(
            array_map('intval', (array) ($request->input('chk') ?? [])),
            fn($id) => $id > 0
        ));

        if (empty($ids)) {
            return JsonResponse::error('삭제할 프리셋을 선택하세요.');
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ($this->optionPresetService->delete($id, $domainId)->isSuccess()) {
                $deleted++;
            }
        }

        return JsonResponse::success(null, "{$deleted}건이 삭제되었습니다.");
    }

    /**
     * 폼 데이터 스키마
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => ['preset_id'],
            'required_string' => ['name'],
            'string' => ['description'],
            'enum' => [
                'option_mode' => ['values' => ['SINGLE', 'COMBINATION'], 'default' => 'SINGLE'],
            ],
        ];
    }
}
