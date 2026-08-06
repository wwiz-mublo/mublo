<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

final readonly class MemberActionVariant
{
    public const DEFAULT = 'default';
    public const HIDDEN = '@hidden';

    public function __construct(
        public string $label,
        public string $endpoint,
        public string $icon = '',
    ) {
    }
}
