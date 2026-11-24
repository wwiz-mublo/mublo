<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

/**
 * 확장이 보존을 끝낸 블록 이미지를 코어 정리 규칙으로 지우게 하는 계약.
 *
 * 코어는 블록 행·칸·콘텐츠·revision 이력의 참조와 등록된 BlockImageRetentionInterface 보존을
 * 모두 확인한 뒤 어디서도 쓰지 않는 파일만 지운다. 해당 도메인 저장소 밖의 주소는 무시한다.
 */
interface BlockImageCleanupInterface
{
    /**
     * @param list<string> $imageUrls `/storage/D{domainId}/...` 공개 저장소 주소
     * @return int 실제로 지운 파일 수
     */
    public function cleanupUnreferenced(int $domainId, array $imageUrls): int;
}
