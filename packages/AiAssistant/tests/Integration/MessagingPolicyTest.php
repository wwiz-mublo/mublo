<?php
declare(strict_types=1);

namespace Tests\AiAssistant\Integration;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;
use Tests\AiAssistant\DatabaseTestCase;

final class MessagingPolicyTest extends DatabaseTestCase
{
    public function testCustomerRegistrationDoesNotGrantMarketingConsent(): void
    {
        [$principal, $customerId, $phoneId] = $this->registeredPhone('policy-company', 501);
        $request = $this->eligibilityRequest($customerId, $phoneId, 'MARKETING');

        $beforeConsent = $this->messaging->eligibility($principal, $request);
        self::assertFalse($beforeConsent['eligible']);
        self::assertSame(['PERMISSION_REQUIRED'], $beforeConsent['reasons']);

        $permission = $this->permission($phoneId, 'MARKETING', 'CONSENTED', 1);
        $saved = $this->messaging->putPermission($principal, $phoneId, 'SMS', 'MARKETING', $permission);
        self::assertSame('CONSENTED', $saved['status']);

        $afterConsent = $this->messaging->eligibility($principal, $request);
        self::assertTrue($afterConsent['eligible']);
        self::assertSame([], $afterConsent['reasons']);
        self::assertSame(1, $afterConsent['permission_version']);
    }

    public function testSuppressionOverridesValidConsentAndUnregisteredPhoneIsRejected(): void
    {
        [$principal, $customerId, $phoneId] = $this->registeredPhone('suppression-company', 502);
        $this->messaging->putPermission(
            $principal,
            $phoneId,
            'SMS',
            'MARKETING',
            $this->permission($phoneId, 'MARKETING', 'CONSENTED', 1)
        );
        $event = $this->messaging->appendSuppressionEvent(
            $principal,
            $this->suppressionEvent($phoneId, 'SUPPRESS', 1)
        );
        self::assertTrue($event['suppressed']);
        self::assertSame(1, (int) $this->db->selectOne(
            'SELECT COUNT(*) AS count_value FROM ai_suppression_events WHERE customer_phone_id = ?',
            [$phoneId]
        )['count_value']);

        $suppressed = $this->messaging->eligibility(
            $principal,
            $this->eligibilityRequest($customerId, $phoneId, 'MARKETING')
        );
        self::assertFalse($suppressed['eligible']);
        self::assertSame(['SUPPRESSED'], $suppressed['reasons']);
        self::assertSame(1, $suppressed['suppression_version']);

        try {
            $this->messaging->appendSuppressionEvent(
                $principal,
                $this->suppressionEvent($phoneId, 'LIFT', 1)
            );
            self::fail('A stale suppression version must fail');
        } catch (ApiException $exception) {
            self::assertSame('SUPPRESSION_VERSION_CONFLICT', $exception->errorCode);
            self::assertSame(409, $exception->statusCode);
        }

        $this->messaging->putPermission(
            $principal,
            $phoneId,
            'SMS',
            'MARKETING',
            $this->permission($phoneId, 'MARKETING', 'REVOKED', 2)
        );
        $lifted = $this->messaging->appendSuppressionEvent(
            $principal,
            $this->suppressionEvent($phoneId, 'LIFT', 2)
        );
        self::assertFalse($lifted['suppressed']);
        $afterLift = $this->messaging->eligibility(
            $principal,
            $this->eligibilityRequest($customerId, $phoneId, 'MARKETING')
        );
        self::assertFalse($afterLift['eligible']);
        self::assertSame(['PERMISSION_REVOKED'], $afterLift['reasons']);
        self::assertSame(2, $afterLift['suppression_version']);

        try {
            $this->messaging->eligibility(
                $principal,
                $this->eligibilityRequest($customerId, Uuid::v4(), 'MARKETING')
            );
            self::fail('Unregistered phone must be rejected');
        } catch (ApiException $exception) {
            self::assertSame('CUSTOMER_PHONE_NOT_REGISTERED', $exception->errorCode);
            self::assertSame(422, $exception->statusCode);
        }
    }

    public function testCampaignSnapshotIsImmutableAndRejectsUnknownPhonesBeforePersistence(): void
    {
        [$principal, $customerId, $phoneId] = $this->registeredPhone('campaign-company', 503);
        $this->messaging->putPermission(
            $principal,
            $phoneId,
            'SMS',
            'MARKETING',
            $this->permission($phoneId, 'MARKETING', 'CONSENTED', 1)
        );
        $campaignId = Uuid::v4();
        $input = $this->campaignSnapshot($campaignId, $customerId, $phoneId);
        $snapshot = $this->messaging->createCampaignSnapshot($principal, $campaignId, $input);
        self::assertSame(1, $snapshot['recipient_count']);
        self::assertSame(1, $snapshot['eligible_count']);
        self::assertSame(0, $snapshot['excluded_count']);
        self::assertTrue($snapshot['recipients'][0]['eligible']);

        $stored = $this->db->selectOne(
            'SELECT content_version, eligible, reason_codes_json
               FROM ai_campaign_recipient_snapshots WHERE campaign_id = ?',
            [$campaignId]
        );
        self::assertNotNull($stored);
        self::assertSame(1, (int) $stored['content_version']);
        self::assertSame(1, (int) $stored['eligible']);
        self::assertSame('[]', $stored['reason_codes_json']);

        try {
            $this->messaging->createCampaignSnapshot($principal, $campaignId, $input);
            self::fail('Campaign snapshot must be immutable');
        } catch (ApiException $exception) {
            self::assertSame('CAMPAIGN_SNAPSHOT_IMMUTABLE', $exception->errorCode);
        }

        $unknownCampaignId = Uuid::v4();
        try {
            $this->messaging->createCampaignSnapshot(
                $principal,
                $unknownCampaignId,
                $this->campaignSnapshot($unknownCampaignId, $customerId, Uuid::v4())
            );
            self::fail('Unknown campaign phone must reject the complete snapshot');
        } catch (ApiException $exception) {
            self::assertSame('CUSTOMER_PHONE_NOT_REGISTERED', $exception->errorCode);
        }
        self::assertSame(0, (int) $this->db->selectOne(
            'SELECT COUNT(*) AS count_value FROM ai_campaign_recipient_snapshots WHERE campaign_id = ?',
            [$unknownCampaignId]
        )['count_value']);
    }

    public function testDispatchPreflightCreatesReadyReservationAndAppliesDailyFrequencyLimit(): void
    {
        [$principal, $customerId, $phoneId] = $this->registeredPhone('dispatch-company', 504);
        $this->messaging->putPermission(
            $principal,
            $phoneId,
            'SMS',
            'MARKETING',
            $this->permission($phoneId, 'MARKETING', 'CONSENTED', 1)
        );

        $campaignId = Uuid::v4();
        $snapshot = $this->messaging->createCampaignSnapshot(
            $principal,
            $campaignId,
            $this->campaignSnapshot($campaignId, $customerId, $phoneId)
        );
        $this->messaging->putCampaignPolicy(
            $principal,
            $campaignId,
            $this->campaignPolicy($campaignId, 1)
        );
        $preflight = $this->messaging->createDispatchPreflight(
            $principal,
            $campaignId,
            $this->dispatchPreflight($campaignId, (string) $snapshot['snapshot_id'])
        );
        self::assertSame(1, $preflight['ready_count']);
        self::assertSame(0, $preflight['blocked_count']);
        self::assertSame('READY', $preflight['reservations'][0]['status']);

        try {
            $this->messaging->createDispatchPreflight(
                $principal,
                $campaignId,
                $this->dispatchPreflight($campaignId, (string) $snapshot['snapshot_id'])
            );
            self::fail('The same campaign/content preflight must not be recreated');
        } catch (ApiException $exception) {
            self::assertSame('DISPATCH_PREFLIGHT_ALREADY_EXISTS', $exception->errorCode);
        }

        $secondCampaignId = Uuid::v4();
        $secondSnapshot = $this->messaging->createCampaignSnapshot(
            $principal,
            $secondCampaignId,
            $this->campaignSnapshot($secondCampaignId, $customerId, $phoneId)
        );
        $this->messaging->putCampaignPolicy(
            $principal,
            $secondCampaignId,
            $this->campaignPolicy($secondCampaignId, 1)
        );
        $limited = $this->messaging->createDispatchPreflight(
            $principal,
            $secondCampaignId,
            $this->dispatchPreflight($secondCampaignId, (string) $secondSnapshot['snapshot_id'])
        );
        self::assertSame(0, $limited['ready_count']);
        self::assertSame(['DAILY_FREQUENCY_LIMIT'], $limited['reservations'][0]['reasons']);
    }

    public function testDispatchPreflightBlocksQuietHoursStaleInteractionAndUnapprovedContent(): void
    {
        [$principal, $customerId, $phoneId] = $this->registeredPhone('dispatch-gates-company', 505);
        $this->messaging->putPermission(
            $principal,
            $phoneId,
            'SMS',
            'MARKETING',
            $this->permission($phoneId, 'MARKETING', 'CONSENTED', 1)
        );

        $quietCampaignId = Uuid::v4();
        $quietSnapshot = $this->messaging->createCampaignSnapshot(
            $principal,
            $quietCampaignId,
            $this->campaignSnapshot($quietCampaignId, $customerId, $phoneId)
        );
        $quietPolicy = $this->campaignPolicy($quietCampaignId, 10);
        $quietPolicy['quiet_hours_start'] = gmdate('H:i');
        $quietPolicy['quiet_hours_end'] = gmdate('H:i', time() + 120);
        $this->messaging->putCampaignPolicy($principal, $quietCampaignId, $quietPolicy);
        $quiet = $this->messaging->createDispatchPreflight(
            $principal,
            $quietCampaignId,
            $this->dispatchPreflight($quietCampaignId, (string) $quietSnapshot['snapshot_id'])
        );
        self::assertSame(['QUIET_HOURS'], $quiet['reservations'][0]['reasons']);

        $staleCampaignId = Uuid::v4();
        $staleSnapshot = $this->messaging->createCampaignSnapshot(
            $principal,
            $staleCampaignId,
            $this->campaignSnapshot($staleCampaignId, $customerId, $phoneId)
        );
        $device = $this->db->selectOne(
            'SELECT device_id FROM ai_devices WHERE company_id = ? LIMIT 1',
            [$principal['company_id']]
        );
        self::assertNotNull($device);
        $this->db->insert(
            'INSERT INTO ai_interactions
                (interaction_id, company_id, customer_id, customer_phone_id, device_id, channel,
                 occurred_at, envelope_json, envelope_sha256, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Uuid::v4(), $principal['company_id'], $customerId, $phoneId, $device['device_id'],
                'CALL_TRANSCRIPT', Time::database(time() + 60), '{}', str_repeat('0', 64),
                'COMPLETED', Time::database(), Time::database(),
            ]
        );
        $this->messaging->putCampaignPolicy(
            $principal,
            $staleCampaignId,
            $this->campaignPolicy($staleCampaignId, 10)
        );
        $stale = $this->messaging->createDispatchPreflight(
            $principal,
            $staleCampaignId,
            $this->dispatchPreflight($staleCampaignId, (string) $staleSnapshot['snapshot_id'])
        );
        self::assertContains('STALE_AFTER_INTERACTION', $stale['reservations'][0]['reasons']);

        [$unapprovedPrincipal, $unapprovedCustomerId, $unapprovedPhoneId] =
            $this->registeredPhone('unapproved-company', 506);
        $this->messaging->putPermission(
            $unapprovedPrincipal,
            $unapprovedPhoneId,
            'SMS',
            'MARKETING',
            $this->permission($unapprovedPhoneId, 'MARKETING', 'CONSENTED', 1)
        );
        $unapprovedCampaignId = Uuid::v4();
        $unapprovedSnapshot = $this->messaging->createCampaignSnapshot(
            $unapprovedPrincipal,
            $unapprovedCampaignId,
            $this->campaignSnapshot($unapprovedCampaignId, $unapprovedCustomerId, $unapprovedPhoneId)
        );
        $unapprovedPolicy = $this->campaignPolicy($unapprovedCampaignId, 10);
        $unapprovedPolicy['approved_content_version'] = null;
        $this->messaging->putCampaignPolicy($unapprovedPrincipal, $unapprovedCampaignId, $unapprovedPolicy);
        $unapproved = $this->messaging->createDispatchPreflight(
            $unapprovedPrincipal,
            $unapprovedCampaignId,
            $this->dispatchPreflight($unapprovedCampaignId, (string) $unapprovedSnapshot['snapshot_id'])
        );
        self::assertSame(['CONTENT_VERSION_NOT_APPROVED'], $unapproved['reservations'][0]['reasons']);

        [$revokedPrincipal, $revokedCustomerId, $revokedPhoneId] =
            $this->registeredPhone('preflight-revoked-company', 507);
        $this->messaging->putPermission(
            $revokedPrincipal,
            $revokedPhoneId,
            'SMS',
            'MARKETING',
            $this->permission($revokedPhoneId, 'MARKETING', 'CONSENTED', 1)
        );
        $revokedCampaignId = Uuid::v4();
        $revokedSnapshot = $this->messaging->createCampaignSnapshot(
            $revokedPrincipal,
            $revokedCampaignId,
            $this->campaignSnapshot($revokedCampaignId, $revokedCustomerId, $revokedPhoneId)
        );
        $this->messaging->appendSuppressionEvent(
            $revokedPrincipal,
            $this->suppressionEvent($revokedPhoneId, 'SUPPRESS', 1)
        );
        $this->messaging->putCampaignPolicy(
            $revokedPrincipal,
            $revokedCampaignId,
            $this->campaignPolicy($revokedCampaignId, 10)
        );
        $revoked = $this->messaging->createDispatchPreflight(
            $revokedPrincipal,
            $revokedCampaignId,
            $this->dispatchPreflight($revokedCampaignId, (string) $revokedSnapshot['snapshot_id'])
        );
        self::assertSame(['SUPPRESSED'], $revoked['reservations'][0]['reasons']);
    }

    /** @return array{array<string, mixed>, string, string} */
    private function registeredPhone(string $slug, int $domainId): array
    {
        $principal = $this->principal($slug, $domainId, $slug . '-secret');
        $device = $this->enroll($principal, 'installation-' . $slug);
        $customerId = Uuid::v4();
        $phoneId = Uuid::v4();
        $companyId = (string) $principal['company_id'];
        $this->sync->push($principal, (string) $device['device_id'], [
            [
                'schema_version' => 'sync-record-v1', 'company_id' => $companyId,
                'object_type' => 'customer', 'object_id' => $customerId, 'operation' => 'UPSERT',
                'version' => 1, 'updated_at' => '2026-08-08T00:00:00Z', 'deleted_at' => null,
                'payload' => ['display_name' => '정책 고객', 'management_status' => 'MANAGED'],
            ],
            [
                'schema_version' => 'sync-record-v1', 'company_id' => $companyId,
                'object_type' => 'customer_phone', 'object_id' => $phoneId, 'operation' => 'UPSERT',
                'version' => 1, 'updated_at' => '2026-08-08T00:00:01Z', 'deleted_at' => null,
                'payload' => [
                    'customer_id' => $customerId, 'normalized_phone' => '+82105551234',
                    'management_status' => 'MANAGED', 'is_primary' => true,
                ],
            ],
        ]);
        return [$principal, $customerId, $phoneId];
    }

    /** @return array<string, mixed> */
    private function eligibilityRequest(string $customerId, string $phoneId, string $class): array
    {
        return [
            'schema_version' => 'messaging-eligibility-v1',
            'customer_id' => $customerId,
            'customer_phone_id' => $phoneId,
            'channel' => 'SMS',
            'message_class' => $class,
        ];
    }

    /** @return array<string, mixed> */
    private function permission(string $phoneId, string $purpose, string $status, int $version): array
    {
        return [
            'schema_version' => 'contact-permission-v1',
            'customer_phone_id' => $phoneId,
            'channel' => 'SMS',
            'purpose' => $purpose,
            'status' => $status,
            'legal_basis' => 'EXPLICIT_CONSENT',
            'captured_at' => '2026-08-08T00:10:00Z',
            'source' => 'APP_MANUAL',
            'expires_at' => null,
            'version' => $version,
        ];
    }

    /** @return array<string, mixed> */
    private function suppressionEvent(string $phoneId, string $action, int $version): array
    {
        return [
            'schema_version' => 'suppression-event-v1',
            'event_id' => Uuid::v4(),
            'customer_phone_id' => $phoneId,
            'channel' => 'SMS',
            'action' => $action,
            'reason' => $action === 'SUPPRESS' ? 'USER_OPT_OUT' : 'REVIEWED_RELEASE',
            'occurred_at' => '2026-08-08T00:20:0' . $version . 'Z',
            'source' => 'APP_MANUAL',
            'version' => $version,
        ];
    }

    /** @return array<string, mixed> */
    private function campaignSnapshot(
        string $campaignId,
        string $customerId,
        string $phoneId
    ): array {
        return [
            'schema_version' => 'campaign-recipient-snapshot-v1',
            'campaign_id' => $campaignId,
            'channel' => 'SMS',
            'message_class' => 'MARKETING',
            'content_version' => 1,
            'recipients' => [[
                'customer_id' => $customerId,
                'customer_phone_id' => $phoneId,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function campaignPolicy(string $campaignId, int $dailyLimit): array
    {
        return [
            'schema_version' => 'campaign-dispatch-policy-v1',
            'campaign_id' => $campaignId,
            'channel' => 'SMS',
            'message_class' => 'MARKETING',
            'content_version' => 1,
            'approved_content_version' => 1,
            'timezone' => 'UTC',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'per_recipient_daily_limit' => $dailyLimit,
            'version' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function dispatchPreflight(string $campaignId, string $snapshotId): array
    {
        return [
            'schema_version' => 'campaign-dispatch-preflight-v1',
            'preflight_id' => Uuid::v4(),
            'campaign_id' => $campaignId,
            'snapshot_id' => $snapshotId,
            'content_version' => 1,
        ];
    }
}
