<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

/**
 * 몰 밖의 AI(예: 사이트 편집 앱)가 HTML 블록을 만들 때 쓰는 코어 생성 규칙과 마무리 단계.
 *
 * 프롬프트는 관리자 블록 에디터 AI와 같고, 결과는 같은 HTML 정제기·CSS 범위 제한·반응형 검사를 거친다.
 * 이 계약은 스크립트를 만들지 않는다(정적 HTML·CSS만). 인터랙션이 필요한 블록은 관리자 블록 에디터에서 만든다.
 */
interface HtmlBlockDraftInterface
{
    /**
     * @param array{html?:string,css?:string}|null $current 수정할 기존 블록 내용. 새로 만들면 null
     * @return array{system:string,user:string,schema:array<string,mixed>} AI 입력과 응답 JSON 스키마
     */
    public function prompts(int $domainId, string $request, ?array $current = null): array;

    /**
     * AI 응답을 마무리해 블록에 저장할 내용으로 만든다.
     *
     * @param string $scopeKey 블록 스코프 클래스를 정하는 고유 키(같은 키면 같은 스코프)
     * @param array{html:string,css:string,notes?:string} $result AI 응답
     * @param array{html?:string,css?:string}|null $current 수정 대상 기존 내용
     * @return array{html:string,css:string,notes:string,audit:array<string,mixed>,warnings:list<string>}
     * @throws \InvalidArgumentException 응답 형식이 맞지 않음
     */
    public function finalize(int $domainId, string $scopeKey, string $request, array $result, ?array $current = null): array;
}
