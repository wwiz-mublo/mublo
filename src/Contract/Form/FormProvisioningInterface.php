<?php

namespace Mublo\Contract\Form;

use Mublo\Core\Result\Result;

/**
 * FormProvisioningInterface
 *
 * 확장이 사이트를 프로그래밍으로 구축할 때 문의·신청 폼을 멱등 생성한다.
 * 코어가 정의하고 AutoForm 플러그인이 구현한다.
 */
interface FormProvisioningInterface
{
    /**
     * 폼을 멱등 보장
     *
     * `$provisioningKey` 는 `autoform_forms.form_code` 로 쓰인다 —
     * `UNIQUE(domain_id, form_code)` 가 동시 재시도를 막는다.
     *
     * 필드 프리셋은 **신규 생성 시에만** 적용한다. 기존 폼이 있으면 운영자가
     * 필드를 고쳤을 수 있으므로 덮지 않는다.
     *
     * @param array $preset form_name · fields[] 등
     * @return Result 성공 data: {form_id: int, form_code: string, created: bool}
     */
    public function ensureForm(int $domainId, string $provisioningKey, array $preset = []): Result;
}
