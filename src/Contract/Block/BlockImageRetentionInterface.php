<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

/**
 * 확장이 아직 지우면 안 되는 블록 이미지 파일을 알려 주는 계약.
 *
 * 블록 저장·revision 정리가 더 이상 쓰지 않는 이미지를 지우기 전에, ContractRegistry에
 * `register(BlockImageRetentionInterface::class, $key, $impl)`로 등록된 모든 구현에게 묻는다.
 * 하나라도 보존 중이라고 답하거나 확인 중 예외가 나면 파일을 남긴다.
 * 예: 사이트 편집 change set의 복원 지점이 가리키는 이미지(보존 기간 동안).
 */
interface BlockImageRetentionInterface
{
    /** @param string $imageUrl `/storage/D{domain}/...` 형식의 공개 저장소 주소 */
    public function isRetained(string $imageUrl): bool;
}
