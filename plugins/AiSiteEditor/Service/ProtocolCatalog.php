<?php
declare(strict_types=1);

namespace Mublo\Plugin\AiSiteEditor\Service;

use JsonException;
use RuntimeException;

final class ProtocolCatalog
{
    public function __construct(private readonly string $protocolDirectory)
    {
    }

    /** @return array<string,mixed> */
    public function version(): array
    {
        return $this->read('version.json');
    }

    /** @return array<string,array<string,mixed>> */
    public function tools(): array
    {
        $tools = $this->read('tool-catalog.json')['tools'] ?? null;
        if (!is_array($tools)) {
            throw new RuntimeException('Invalid site editor tool catalog.');
        }
        return $tools;
    }

    /** @return list<string> */
    public function errors(): array
    {
        $errors = $this->read('errors.json')['errors'] ?? null;
        if (!is_array($errors)) {
            throw new RuntimeException('Invalid site editor error catalog.');
        }
        return array_values(array_map(
            static fn(array $error): string => (string) ($error['code'] ?? ''),
            array_filter($errors, 'is_array')
        ));
    }

    /** @return array<string,mixed> */
    private function read(string $name): array
    {
        $path = $this->protocolDirectory . DIRECTORY_SEPARATOR . $name;
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Missing site editor protocol file: $name");
        }
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException("Invalid site editor protocol file: $name", 0, $error);
        }
        if (!is_array($value)) {
            throw new RuntimeException("Invalid site editor protocol object: $name");
        }
        return $value;
    }
}
