<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Security;

interface WorkerResultVerifierInterface
{
    /** @param array<string, mixed> $result */
    public function verifyResultSignature(array $result): void;
}
