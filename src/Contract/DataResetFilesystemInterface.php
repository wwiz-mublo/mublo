<?php

namespace Mublo\Contract;

/**
 * DB 커밋 뒤에 수행해야 하는 비트랜잭션 파일 정리 계약입니다.
 *
 * DataResettableInterface::reset()에서는 DB 변경만 수행하고, 파일 삭제가 필요한
 * 구현체는 이 계약을 함께 구현합니다. DB 롤백 뒤 파일만 사라지는 상태를 막기 위해
 * DataResetService가 커밋 성공 후 호출합니다.
 */
interface DataResetFilesystemInterface
{
    public function resetFiles(string $category, int $domainId): int;
}
