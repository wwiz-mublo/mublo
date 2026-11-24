<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

/**
 * 블록 콘텐츠 타입의 사이트 편집 규격.
 *
 * 콘텐츠 타입을 소유한 패키지·플러그인이 ContractRegistry에
 * `register(SiteEditorContentSchemaInterface::class, $key, $impl)`로 등록한다.
 * 사이트 편집기는 규격이 등록된 타입만 추가·설정 변경을 허용하고, 나머지 타입은 보존한 채 이동·표시 여부만 바꾼다.
 */
interface SiteEditorContentSchemaInterface
{
    public function supports(string $contentType): bool;

    /**
     * 현재 사이트에서 이 타입으로 쓸 수 있는 형식과 편집 필드. snapshot에 실어 AI 계획 입력으로 쓴다.
     *
     * @return array<string, mixed> 예: {"variants": {"products": {"title": "...", "fields": {"display_count": {"type": "integer", "min": 1, "max": 20}}}}}
     */
    public function describe(int $domainId, string $contentType): array;

    /**
     * 편집 요청을 규격으로 검증하고 저장할 content_config 전체를 돌려준다.
     *
     * @param array<string, mixed>|null $current 기존 content_config. 새 콘텐츠면 null
     * @param array<string, mixed> $patch 바꾸려는 값
     * @return array<string, mixed>
     * @throws SiteEditorContentRejectedException 규격에 없는 필드·범위 밖 값·현재 사이트에서 쓸 수 없는 형식
     */
    public function normalize(int $domainId, string $contentType, ?array $current, array $patch): array;
}
