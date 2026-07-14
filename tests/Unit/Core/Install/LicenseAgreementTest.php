<?php

namespace Tests\Unit\Core\Install;

use Mublo\Core\Install\LicenseAgreement;
use PHPUnit\Framework\TestCase;

class LicenseAgreementTest extends TestCase
{
    public function testIssueTokenStoresAndReusesValidToken(): void
    {
        $session = [];
        $agreement = new LicenseAgreement(__FILE__);

        $first = $agreement->issueToken($session);
        $second = $agreement->issueToken($session);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $session[LicenseAgreement::TOKEN_SESSION_KEY]);
    }

    public function testAcceptRequiresAgreementAndMatchingToken(): void
    {
        $agreement = new LicenseAgreement(__FILE__);

        $notAgreedSession = [];
        $notAgreedToken = $agreement->issueToken($notAgreedSession);
        $this->assertFalse($agreement->accept($notAgreedSession, $notAgreedToken, false));
        $this->assertFalse($agreement->isAccepted($notAgreedSession));

        $invalidSession = [];
        $agreement->issueToken($invalidSession);
        $this->assertFalse($agreement->accept($invalidSession, str_repeat('0', 64), true));
        $this->assertFalse($agreement->isAccepted($invalidSession));
    }

    public function testSuccessfulAcceptanceSetsGateAndConsumesToken(): void
    {
        $session = [];
        $agreement = new LicenseAgreement(__FILE__);
        $token = $agreement->issueToken($session);

        $this->assertTrue($agreement->accept($session, $token, true));
        $this->assertTrue($agreement->isAccepted($session));
        $this->assertArrayNotHasKey(LicenseAgreement::TOKEN_SESSION_KEY, $session);
        $this->assertFalse($agreement->accept($session, $token, true));
    }

    public function testLicenseTextUsesConfiguredSourceFile(): void
    {
        $agreement = new LicenseAgreement(MUBLO_ROOT_PATH . '/LICENSE');

        $text = $agreement->licenseText();

        $this->assertNotNull($text);
        $this->assertStringStartsWith('MIT License', $text);
        $this->assertStringContainsString('Copyright (c) 2026 Mublo', $text);
    }

    public function testMissingLicenseFileFailsClosed(): void
    {
        $agreement = new LicenseAgreement(MUBLO_ROOT_PATH . '/missing-license-file');

        $this->assertNull($agreement->licenseText());
    }

    public function testLicenseViewEscapesLicenseAndErrorContent(): void
    {
        $licenseText = '<script>alert("license")</script>';
        $licenseToken = str_repeat('a', 64);
        $licenseError = '<b>invalid</b>';

        ob_start();
        include MUBLO_ROOT_PATH . '/public/install/steps/license.php';
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert', $html);
        $this->assertStringNotContainsString('<b>invalid</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;invalid&lt;/b&gt;', $html);
        $this->assertStringContainsString('name="license_token" value="' . $licenseToken . '"', $html);
        $this->assertStringContainsString('name="license_agree" value="1" required', $html);
        $this->assertStringContainsString('<textarea class="license-document"', $html);
        $this->assertStringContainsString('readonly', $html);
    }
}
