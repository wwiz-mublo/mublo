<?php

namespace Mublo\Core\Report\Contract;

interface RowProviderInterface
{
    /**
     * @return iterable<array<string, mixed>>
     */
    public function rows(): iterable;

    public function totalCount(): ?int;

    public function isRewindable(): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getChunk(int $offset, int $limit): array;
}

