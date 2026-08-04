<?php
declare(strict_types=1);

namespace Mublo\Core\Report\Engine;

use Mublo\Core\Report\Contract\ReportDefinitionInterface;
use Mublo\Core\Extension\ExtensionRegistrationScope;

class ReportDefinitionRegistry
{
    /** @var array<string, ReportDefinitionInterface> */
    private array $definitions = [];

    /** @var array<string, string> */
    private array $definitionOwners = [];

    public function register(ReportDefinitionInterface $definition): void
    {
        $name = $definition->name();
        $previous = $this->definitions[$name] ?? null;
        $previousOwner = $this->definitionOwners[$name] ?? null;
        $owner = ExtensionRegistrationScope::currentOwner();
        $this->definitions[$name] = $definition;

        if ($owner !== null) {
            $this->definitionOwners[$name] = $owner;
            ExtensionRegistrationScope::recordCommit(function () use ($name, $owner): void {
                if (($this->definitionOwners[$name] ?? null) === $owner) {
                    unset($this->definitionOwners[$name]);
                }
            });
            ExtensionRegistrationScope::recordUndo(function () use (
                $name,
                $owner,
                $previous,
                $previousOwner
            ): void {
                if (($this->definitionOwners[$name] ?? null) !== $owner) {
                    return;
                }

                if ($previous === null) {
                    unset($this->definitions[$name], $this->definitionOwners[$name]);
                    return;
                }

                $this->definitions[$name] = $previous;
                if ($previousOwner === null || !ExtensionRegistrationScope::has($previousOwner)) {
                    unset($this->definitionOwners[$name]);
                } else {
                    $this->definitionOwners[$name] = $previousOwner;
                }
            });
        } else {
            unset($this->definitionOwners[$name]);
        }
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function get(string $name): ReportDefinitionInterface
    {
        if (!$this->has($name)) {
            throw new \RuntimeException("Report definition not found: {$name}");
        }

        return $this->definitions[$name];
    }
}
