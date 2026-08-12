<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Security;

interface WorkerResultVerifierInterface
{
    /** @return array{ready: bool, signing_key_id: string} */
    public function readiness(): array;

    /** @param array<string, mixed> $result */
    public function verifyResultSignature(array $result): void;
}
