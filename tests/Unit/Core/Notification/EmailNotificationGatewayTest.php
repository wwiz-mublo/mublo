<?php

namespace Tests\Unit\Core\Notification;

use Mublo\Contract\Notification\EmailTemplateProviderInterface;
use Mublo\Core\Notification\EmailNotificationGateway;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Mail\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * 코어 이메일 게이트웨이 (회귀) — 이메일 역할 분담의 계약:
 * 발송(전송로)은 코어가 유일 통로, 템플릿(내용물)은
 * EmailTemplateProviderInterface 공급자가 담당한다.
 */
class EmailNotificationGatewayTest extends TestCase
{
    public function testTemplatelessSendUsesSubjectAndBodyFromFieldValues(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::once())->method('send')->willReturn(true);

        $gateway = new EmailNotificationGateway($mailer);
        $result = $gateway->send('email', '', 'user@example.com', [
            'domain_id' => 1,
            'subject' => '주문 알림',
            'body' => '<p>본문</p>',
        ]);

        self::assertTrue($result->success);
    }

    public function testSendFailsWithoutDomainContext(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('send');

        // domain_id 없음 + 리졸버 없음 — 도메인 1 추측 폴백 없이 거부
        $result = (new EmailNotificationGateway($mailer))
            ->send('email', '', 'user@example.com', ['subject' => 'x', 'body' => 'y']);

        self::assertFalse($result->success);
        self::assertStringContainsString('도메인', $result->message);
    }

    public function testSendBlockedWhenEmailChannelDisabled(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('send');

        // 도메인 이메일 전송 정책 off — 템플릿 없는 발송도 중단 (비활성화 우회 방지)
        $gateway = new EmailNotificationGateway($mailer, null, null, fn(int $domainId) => false);
        $result = $gateway->send('email', '', 'user@example.com', [
            'domain_id' => 1,
            'subject' => '주문 알림',
            'body' => '<p>본문</p>',
        ]);

        self::assertFalse($result->success);
        self::assertStringContainsString('비활성화', $result->message);
    }

    public function testPolicyLookupFailureRefusesToSend(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('send');

        // 리졸버가 있는 운영 환경에서 정책 조회 실패 — 허용이 아니라 거부
        $gateway = new EmailNotificationGateway($mailer, null, null, function (int $domainId): bool {
            throw new \RuntimeException('settings table missing');
        });
        $result = $gateway->send('email', '', 'user@example.com', [
            'domain_id' => 1,
            'subject' => 'x',
            'body' => 'y',
        ]);

        self::assertFalse($result->success);
    }

    public function testTemplatedSendDelegatesRenderToProviderAndNotifiesResult(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::once())->method('send')->willReturn(true);

        $sink = new \stdClass();
        $sink->onSent = null;

        $provider = new class($sink) implements EmailTemplateProviderInterface {
            public function __construct(private object $sink)
            {
            }

            public function getTemplates(int $domainId): array
            {
                return [['code' => 'welcome', 'name' => '환영 메일']];
            }

            public function render(int $domainId, string $templateCode, array $fieldValues): ?array
            {
                if ($templateCode !== 'welcome') {
                    return null;
                }
                return ['subject' => '환영합니다', 'body' => '<p>hi</p>', 'from_email' => 'noreply@shop.kr'];
            }

            public function onSent(int $domainId, string $templateCode, string $recipient, string $subject, bool $success, string $message): void
            {
                $this->sink->onSent = compact('domainId', 'templateCode', 'recipient', 'subject', 'success');
            }
        };

        $registry = new ContractRegistry();
        $registry->register(EmailTemplateProviderInterface::class, 'email_notify', $provider);

        $gateway = new EmailNotificationGateway($mailer, $registry);
        $result = $gateway->send('email', 'welcome', 'user@example.com', ['domain_id' => 7]);

        self::assertTrue($result->success);
        self::assertSame(7, $sink->onSent['domainId']);
        self::assertSame('welcome', $sink->onSent['templateCode']);
        self::assertSame('환영합니다', $sink->onSent['subject']);
        self::assertTrue($sink->onSent['success']);
    }

    public function testUnknownTemplateCodeFailsWithoutFallbackSend(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('send');

        $provider = $this->createMock(EmailTemplateProviderInterface::class);
        $provider->method('render')->willReturn(null);

        $registry = new ContractRegistry();
        $registry->register(EmailTemplateProviderInterface::class, 'email_notify', $provider);

        $gateway = new EmailNotificationGateway($mailer, $registry);
        $result = $gateway->send('email', 'no_such_code', 'user@example.com', ['domain_id' => 1]);

        self::assertFalse($result->success);
        self::assertStringContainsString('no_such_code', $result->message);
    }

    public function testProviderRenderFailureReturnsReasonAndSkipsTransport(): void
    {
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('send');

        $provider = $this->createMock(EmailTemplateProviderInterface::class);
        $provider->method('render')->willThrowException(new \RuntimeException('미치환 변수가 있습니다: #{이름}'));
        $provider->expects(self::once())->method('onSent')
            ->with(self::anything(), 'welcome', 'user@example.com', '', false, self::stringContains('미치환'));

        $registry = new ContractRegistry();
        $registry->register(EmailTemplateProviderInterface::class, 'email_notify', $provider);

        $gateway = new EmailNotificationGateway($mailer, $registry);
        $result = $gateway->send('email', 'welcome', 'user@example.com', ['domain_id' => 1]);

        self::assertFalse($result->success);
        self::assertStringContainsString('미치환', $result->message);
    }

    public function testChannelTreeDeduplicatesCodesAcrossProvidersFirstWins(): void
    {
        $first = $this->createMock(EmailTemplateProviderInterface::class);
        $first->method('getTemplates')->willReturn([
            ['code' => 'order_confirmed', 'name' => '먼저 등록된 템플릿'],
        ]);
        $second = $this->createMock(EmailTemplateProviderInterface::class);
        $second->method('getTemplates')->willReturn([
            ['code' => 'order_confirmed', 'name' => '나중 등록된 템플릿'],
            ['code' => 'shipping', 'name' => '배송 안내'],
        ]);

        $registry = new ContractRegistry();
        $registry->register(EmailTemplateProviderInterface::class, 'email_notify', $first);
        $registry->register(EmailTemplateProviderInterface::class, 'another_provider', $second);

        $tree = (new EmailNotificationGateway($this->createMock(Mailer::class), $registry))->getChannelTree(1);

        // 발송(sendTemplated)이 먼저 등록된 공급자 우선이므로 표시도 first-wins
        $templates = $tree['email']['channels'][0]['templates'];
        $byCode = array_column($templates, 'name', 'code');
        self::assertCount(2, $templates);
        self::assertSame('먼저 등록된 템플릿', $byCode['order_confirmed']);
        self::assertSame('배송 안내', $byCode['shipping']);
    }

    public function testChannelTreeAggregatesProviderTemplates(): void
    {
        $provider = $this->createMock(EmailTemplateProviderInterface::class);
        $provider->method('getTemplates')->willReturn([
            ['code' => 'welcome', 'name' => '환영 메일'],
        ]);

        $registry = new ContractRegistry();
        $registry->register(EmailTemplateProviderInterface::class, 'email_notify', $provider);

        $tree = (new EmailNotificationGateway($this->createMock(Mailer::class), $registry))->getChannelTree(1);

        self::assertSame('welcome', $tree['email']['channels'][0]['templates'][0]['code']);
    }

    public function testChannelTreeWithoutProvidersIsTemplateless(): void
    {
        $tree = (new EmailNotificationGateway($this->createMock(Mailer::class)))->getChannelTree(1);

        self::assertSame([], $tree['email']['channels']);
    }
}
