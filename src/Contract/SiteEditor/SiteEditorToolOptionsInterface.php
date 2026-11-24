<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

/**
 * operation handler가 도구 입력의 선택지(예: theme.apply의 설치된 스킨)를 알려 주는 선택 계약.
 *
 * 실행기는 snapshot의 tool_options로 앱에 전달하고, 앱 AI는 이 목록 밖의 값을 지어내지 않는다.
 * 선택지는 몰마다 다를 수 있으므로 도메인별로 계산한다.
 */
interface SiteEditorToolOptionsInterface
{
    /**
     * @return array<string, list<array{value:string,label:string,description?:string,current?:bool}>> 입력 필드 → 선택지
     */
    public function toolOptions(string $tool, int $domainId): array;
}
