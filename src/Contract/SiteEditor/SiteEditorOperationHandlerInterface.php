<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

/** 공통 실행기 또는 제품 패키지가 제공하는 단일 operation 처리 경계. */
interface SiteEditorOperationHandlerInterface
{
    /** management_mode를 포함한 현재 snapshot을 보고 이 handler가 operation을 전담할지 결정한다. */
    public function supports(string $tool, array $operation, SiteEditContext $context): bool;

    /** 같은 tool을 여러 handler가 지원할 때 큰 값이 우선한다. 같은 우선순위 중복은 실행기가 거절한다. */
    public function priority(): int;

    /** @return array<string, mixed> handler 정책을 반영해 정규화된 operation */
    public function validate(array $operation, SiteEditContext $context): array;

    /** 운영 DB를 쓰지 않고 최종 상태와 resource_changes를 계산한다. */
    public function preview(array $operation, SiteEditContext $context): SiteEditorOperationResult;

    /** 최상위 transaction과 SiteMutationContext 안에서 실제 변경을 수행한다. */
    public function apply(array $operation, SiteEditContext $context): SiteEditorOperationResult;
}
