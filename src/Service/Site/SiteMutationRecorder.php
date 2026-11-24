<?php
declare(strict_types=1);

namespace Mublo\Service\Site;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Contract\Site\SiteMutationRecorderInterface;
use Mublo\Contract\Site\SiteMutationReceipt;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Site\SiteMutationRecordedEvent;
use Mublo\Infrastructure\Database\Database;

final class SiteMutationRecorder implements SiteMutationRecorderInterface
{
    public function __construct(
        private Database $db,
        private ?EventDispatcher $events = null
    ) {
    }

    public function record(SiteMutationContext $context): SiteMutationReceipt
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Site mutation revisions must be recorded inside an active transaction.');
        }
        if ($context->isEmpty()) {
            throw new \InvalidArgumentException('A site mutation must contain at least one changed resource.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO site_revision_counters (domain_id, revision, updated_at) VALUES (?, 1, ?) '
            . 'ON DUPLICATE KEY UPDATE revision = revision + 1, updated_at = VALUES(updated_at)',
            [$context->domainId, $now]
        );
        $siteCounter = $this->db->selectOne(
            'SELECT revision FROM site_revision_counters WHERE domain_id = ?',
            [$context->domainId]
        );
        if ($siteCounter === null) {
            throw new \RuntimeException('Site revision counter could not be read after update.');
        }
        $siteRevision = (int) $siteCounter['revision'];

        $resourceRevisions = [];
        $changedResources = [];
        foreach ($context->resources() as $resource) {
            $this->db->execute(
                'INSERT INTO site_resource_revisions '
                . '(domain_id, resource_id, resource_type, revision, fingerprint, updated_at) '
                . 'VALUES (?, ?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE '
                . 'resource_type = VALUES(resource_type), revision = revision + 1, '
                . 'fingerprint = VALUES(fingerprint), updated_at = VALUES(updated_at)',
                [
                    $context->domainId,
                    $resource->resourceId,
                    $resource->resourceType,
                    $resource->fingerprint,
                    $now,
                ]
            );
            $row = $this->db->selectOne(
                'SELECT resource_type, revision, fingerprint FROM site_resource_revisions '
                . 'WHERE domain_id = ? AND resource_id = ?',
                [$context->domainId, $resource->resourceId]
            );
            if ($row === null) {
                throw new \RuntimeException('Resource revision could not be read after update: ' . $resource->resourceId);
            }

            $resourceRevision = (int) $row['revision'];
            $resourceRevisions[$resource->resourceId] = $resourceRevision;
            $changedResources[] = [
                'resource_id' => $resource->resourceId,
                'resource_type' => (string) $row['resource_type'],
                'revision' => $resourceRevision,
                'fingerprint' => (string) $row['fingerprint'],
            ];
        }

        $eventId = $this->db->insert(
            'INSERT INTO site_revisions '
            . '(domain_id, revision, source, correlation_id, changed_resources_json, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [
                $context->domainId,
                $siteRevision,
                $context->source,
                $context->correlationId,
                json_encode($changedResources, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $now,
            ]
        );

        $receipt = new SiteMutationReceipt(
            $eventId,
            $context->domainId,
            $siteRevision,
            $context->source,
            $context->correlationId,
            $resourceRevisions
        );
        $this->db->afterCommit(function () use ($receipt): void {
            $this->events?->dispatch(new SiteMutationRecordedEvent($receipt));
        });

        return $receipt;
    }

    public function currentSiteRevision(int $domainId): int
    {
        $row = $this->db->selectOne(
            'SELECT revision FROM site_revision_counters WHERE domain_id = ?',
            [$domainId]
        );
        return (int) ($row['revision'] ?? 0);
    }

    public function resourceRevisions(int $domainId, array $resourceIds): array
    {
        $resourceIds = array_values(array_unique(array_filter(
            $resourceIds,
            static fn (mixed $id): bool => is_string($id) && $id !== ''
        )));
        if ($resourceIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($resourceIds), '?'));
        $rows = $this->db->select(
            'SELECT resource_id, resource_type, revision, fingerprint FROM site_resource_revisions '
            . "WHERE domain_id = ? AND resource_id IN ({$placeholders})",
            [$domainId, ...$resourceIds]
        );

        $revisions = [];
        foreach ($rows as $row) {
            $revisions[(string) $row['resource_id']] = [
                'resource_type' => (string) $row['resource_type'],
                'revision' => (int) $row['revision'],
                'fingerprint' => (string) $row['fingerprint'],
            ];
        }
        ksort($revisions, SORT_STRING);
        return $revisions;
    }
}
