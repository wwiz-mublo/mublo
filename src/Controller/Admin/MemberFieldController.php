<?php
declare(strict_types=1);
namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Helper\Form\FormHelper;
use Mublo\Service\Member\MemberFieldService;
use Mublo\Service\Member\MemberService;

/**
 * Admin MemberFieldController
 *
 * 회원 추가 필드 관리 컨트롤러
 * - 필드 목록/추가/수정/삭제
 * - 정렬 순서 변경
 *
 * 자동 라우팅:
 * - GET  /admin/member-field            → index
 * - GET  /admin/member-field/create     → create
 * - GET  /admin/member-field/edit       → edit (쿼리: ?id=123)
 * - POST /admin/member-field/store      → store
 * - POST /admin/member-field/delete     → delete
 * - POST /admin/member-field/order-update → orderUpdate
 */
class MemberFieldController
{
    private MemberFieldService $fieldService;
    private MemberService $memberService;

    public function __construct(MemberFieldService $fieldService, MemberService $memberService)
    {
        $this->fieldService = $fieldService;
        $this->memberService = $memberService;
    }

    /**
     * 필드 목록
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $fields = $this->fieldService->getFields($domainId);

        return ViewResponse::view('memberfield/index')
            ->withData([
                'pageTitle' => '회원 추가 필드 관리',
                'fields' => $fields,
                'fieldTypeOptions' => $this->fieldService->getFieldTypeOptions(),
            ]);
    }

    /**
     * 필드 생성 폼
     */
    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::view('memberfield/form')
            ->withData([
                'pageTitle' => '필드 추가',
                'field' => null,
                'fieldTypeOptions' => $this->fieldService->getFieldTypeOptions(),
            ]);
    }

    /**
     * 필드 수정 폼
     * 쿼리스트링: ?id=123
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $fieldId = (int) $request->query('id', 0);
        $field = $this->fieldService->getField($fieldId, $domainId);

        if (!$field) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '필드를 찾을 수 없습니다.']);
        }

        // field_options JSON 디코딩
        if (!empty($field['field_options'])) {
            $field['field_options'] = json_decode($field['field_options'], true);
        }

        return ViewResponse::view('memberfield/form')
            ->withData([
                'pageTitle' => '필드 수정',
                'field' => $field,
                'fieldTypeOptions' => $this->fieldService->getFieldTypeOptions(),
            ]);
    }

    /**
     * 필드 저장 (생성/수정)
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->json('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // field_options는 JSON 배열이므로 별도 처리
        $data['field_options'] = $formData['field_options'] ?? [];

        $fieldId = (int) ($data['field_id'] ?? 0);
        unset($data['field_id']);

        if ($fieldId > 0) {
            // is_unique 토글 감지 — 켜기 전 기존 값 백필(중복 검출 시 거부), 끄면 키 해제
            $existing = $this->fieldService->getField($fieldId, $domainId);
            $wasUnique = !empty($existing['is_unique']);
            $willBeUnique = !empty($data['is_unique']);

            if ($existing && !$wasUnique && $willBeUnique) {
                $backfill = $this->memberService->backfillFieldUniqueKeys($fieldId, $existing);
                if ($backfill->isFailure()) {
                    return JsonResponse::error($backfill->getMessage());
                }
            }

            $result = $this->fieldService->updateField($fieldId, $data, $domainId);

            if ($result->isSuccess() && $wasUnique && !$willBeUnique) {
                $this->memberService->clearFieldUniqueKeys($fieldId);
            }
        } else {
            $result = $this->fieldService->createField($domainId, $data);
        }

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['field_id'],
            'required_string' => ['field_name', 'field_label'],
            'bool' => ['is_required', 'is_encrypted', 'is_searched', 'is_unique',
                        'is_visible_signup', 'is_visible_profile', 'is_visible_list', 'is_admin_only'],
            'enum' => [
                'field_type' => [
                    'values' => ['text', 'textarea', 'email', 'tel', 'number', 'date', 'select', 'radio', 'checkbox', 'address', 'file', 'avatar'],
                    'default' => 'text',
                ],
            ],
        ];
    }

    /**
     * 필드 삭제
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $fieldId = (int) $request->json('field_id', 0);

        if ($fieldId <= 0) {
            return JsonResponse::error('필드 ID가 필요합니다.');
        }

        $result = $this->fieldService->deleteField($fieldId, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 필드 정렬 순서 변경
     *
     * POST /admin/member-field/order-update
     */
    public function orderUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $fieldIds = $request->json('field_ids', []);

        if (empty($fieldIds) || !is_array($fieldIds)) {
            return JsonResponse::error('정렬할 필드 목록이 필요합니다.');
        }

        $result = $this->fieldService->updateOrder($domainId, $fieldIds);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }
}
