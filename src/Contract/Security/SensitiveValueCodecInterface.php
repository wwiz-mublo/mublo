<?php
declare(strict_types=1);

namespace Mublo\Contract\Security;

interface SensitiveValueCodecInterface
{
    public function encrypt(string $plainText): string;

    public function decrypt(string $encoded): ?string;

    public function createSearchIndex(string $value): string;

    public function matchSearchIndex(string $storedIndex, string $searchValue): bool;

    /** @return array{field_value:string, search_index:?string} */
    public function processFieldValue(
        string $value,
        bool $encrypted,
        bool $searchable,
        ?string $searchIndexValue = null
    ): array;

    public function readFieldValue(?string $storedValue, bool $encrypted): ?string;
}
