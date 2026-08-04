<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Contract\Security\SensitiveValueCodecInterface;

final class SensitiveValueCodec implements SensitiveValueCodecInterface
{
    public function __construct(private FieldEncryptionService $encryption)
    {
    }

    public function encrypt(string $plainText): string
    {
        return $this->encryption->encrypt($plainText);
    }

    public function decrypt(string $encoded): ?string
    {
        return $this->encryption->decrypt($encoded);
    }

    public function createSearchIndex(string $value): string
    {
        return $this->encryption->createSearchIndex($value);
    }

    public function matchSearchIndex(string $storedIndex, string $searchValue): bool
    {
        return $this->encryption->matchSearchIndex($storedIndex, $searchValue);
    }

    public function processFieldValue(
        string $value,
        bool $encrypted,
        bool $searchable,
        ?string $searchIndexValue = null
    ): array {
        return $this->encryption->processFieldValue($value, $encrypted, $searchable, $searchIndexValue);
    }

    public function readFieldValue(?string $storedValue, bool $encrypted): ?string
    {
        return $this->encryption->readFieldValue($storedValue, $encrypted);
    }
}
