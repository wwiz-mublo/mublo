<?php

namespace Mublo\Core\Report\Document;

class ColumnDefinition
{
    public string $key;
    public string $label;
    public string $type;
    public string $align;
    /** @var callable|null */
    public $formatter;

    public function __construct(
        string $key,
        string $label,
        string $type = 'string',
        string $align = 'left',
        ?callable $formatter = null
    ) {
        $this->key = $key;
        $this->label = $label;
        $this->type = $type;
        $this->align = $align;
        $this->formatter = $formatter;
    }
}

