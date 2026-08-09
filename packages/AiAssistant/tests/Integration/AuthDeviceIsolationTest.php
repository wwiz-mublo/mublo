<?php
declare(strict_types=1);

namespace Tests\AiAssistant\Integration;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Tests\AiAssistant\DatabaseTestCase;

final class AuthDeviceIsolationTest extends DatabaseTestCase
{
    public function testLoginRefreshRotationAndCompanyIsolation(): void
    {
        $principalA = $this->principal('company-a', 101, 'company-a-secret');
        $principalB = $this->principal('company-b', 102, 'company-b-secret');
        self::assertNotSame($principalA['company_id'], $principalB['company_id']);

        $login = $this->auth->login('company-a', null, 'owner', 'company-a-secret');
        $refresh = (string) $login['tokens']['refresh_token'];
        $rotated = $this->auth->refresh($refresh);
        self::assertNotSame($refresh, $rotated['tokens']['refresh_token']);

        try {
            $this->auth->refresh($refresh);
            self::fail('Reused refresh token must fail');
        } catch (ApiException $exception) {
            self::assertSame('AUTH_REFRESH_REUSED', $exception->errorCode);
            self::assertSame(409, $exception->statusCode);
        }

        $device = $this->enroll($principalA, 'installation-company-a-0001');
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('기기를 찾을 수 없습니다.');
        $this->devices->heartbeat($principalB, (string) $device['device_id'], [
            'app_version' => '0.1.0',
            'os_version' => '14',
            'permissions' => [],
            'battery_optimization_exempt' => true,
        ]);
    }
}
