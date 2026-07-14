<?php

namespace Mublo\Core\Report\Document\Section;

use Mublo\Core\Report\Contract\RowProviderInterface;
use Mublo\Core\Report\Document\ColumnDefinition;

class TableSection implements SectionInterface
{
    /** @var array<int, ColumnDefinition> */
    private array $columns;
    private RowProviderInterface $rowProvider;
    private bool $withHeader;
    private bool $withFooter;

    /**
     * @param array<int, ColumnDefinition> $columns
     */
    public function __construct(
        array $columns,
        RowProviderInterface $rowProvider,
        bool $withHeader = true,
        bool $withFooter = false
    ) {
        $this->columns = $columns;
        $this->rowProvider = $rowProvider;
        $this->withHeader = $withHeader;
        $this->withFooter = $withFooter;
    }

    public function type(): string
    {
        return 'table';
    }

    /**
     * @return array<int, ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    public function rowProvider(): RowProviderInterface
    {
        return $this->rowProvider;
    }

    public function rows(): iterable
    {
        return $this->rowProvider->rows();
    }

    public function withHeader(): bool
    {
        return $this->withHeader;
    }

    public function withFooter(): bool
    {
        return $this->withFooter;
    }
}

