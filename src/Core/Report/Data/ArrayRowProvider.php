<?php

namespace Mublo\Core\Report\Data;

use Mublo\Core\Report\Contract\RowProviderInterface;

class ArrayRowProvider implements RowProviderInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
    }

    public function rows(): iterable
    {
        foreach ($this->rows as $row) {
            yield $row;
        }
    }

    public function totalCount(): ?int
    {
        return count($this->rows);
    }

    public function isRewindable(): bool
    {
        return true;
    }

    public function getChunk(int $offset, int $limit): array
    {
        if ($offset < 0) {
            $offset = 0;
        }
        if ($limit < 1) {
            $limit = 1;
        }

        return array_slice($this->rows, $offset, $limit);
    }
}

