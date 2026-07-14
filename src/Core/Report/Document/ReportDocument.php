<?php

namespace Mublo\Core\Report\Document;

use Mublo\Core\Report\Document\Section\SectionInterface;

class ReportDocument
{
    private string $title;
    private ReportMetadata $metadata;

    /** @var array<int, SectionInterface> */
    private array $sections;

    /**
     * @param array<int, SectionInterface> $sections
     */
    public function __construct(string $title, ?ReportMetadata $metadata = null, array $sections = [])
    {
        $this->title = $title;
        $this->metadata = $metadata ?? new ReportMetadata();
        $this->sections = $sections;
    }

    public static function create(string $title): self
    {
        return new self($title);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function metadata(): ReportMetadata
    {
        return $this->metadata;
    }

    public function withMetadata(ReportMetadata $metadata): self
    {
        $clone = clone $this;
        $clone->metadata = $metadata;
        return $clone;
    }

    public function addSection(SectionInterface $section): self
    {
        $clone = clone $this;
        $clone->sections[] = $section;
        return $clone;
    }

    /**
     * @return array<int, SectionInterface>
     */
    public function sections(): array
    {
        return $this->sections;
    }
}

