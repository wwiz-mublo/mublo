<?php

namespace Tests\EmailNotify\Unit;

use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Contract\Notification\NotificationSendResult;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Mail\Mailer;
use Mublo\Plugin\EmailNotify\Repository\EmailConfigRepository;
use Mublo\Plugin\EmailNotify\Repository\EmailLogRepository;
use Mublo\Plugin\EmailNotify\Repository\EmailTemplateRepository;
use Mublo\Plugin\EmailNotify\Service\EmailNotifyService;
use Mublo\Contract\Site\DomainQueryInterface;
use PHPUnit\Framework\TestCase;

/**
 * EmailNotify 발송 정책 (회귀) — 이메일 역할 분담의 경계:
 * 관리자 테스트 발송도 코어 게이트웨이(유일 전송로)를 경유해야 하며,
 * 레지스트리가 주입된 환경에서 게이트웨이 조회 실패는 직접 전송으로
 * 우회하지 않고 fail-closed 로 실패해야 한다.
 */
class EmailNotifySendPolicyTest extends TestCase
{
    private function service(
        ?ContractRegistry $registry,
        ?Mailer $mailer = null,
        array $config = ['is_active' => 1],
        ?array $template = null,
    ): EmailNotifyService {
        $configRepo = $this->createMock(EmailConfigRepository::class);
        $configRepo->method('findByDomainId')->willReturn($config);

        $templateRepo = $this->createMock(EmailTemplateRepository::class);
        $templateRepo->method('findByCode')->willReturn(
            $template ?? ['subject' => '환영합니다', 'body' => '<p>hi</p>', 'is_active' => 1]
        );
        $templateRepo->method('getList')->willReturn([
            ['template_code' => 'welcome', 'template_name' => '환영 메일', 'is_active' => 1],
        ]);

        $domainRepo = $this->createMock(DomainQueryInterface::class);
        $domainRepo->method('find')->willReturn(null);

        return new EmailNotifyService(
            $configRepo,
            $templateRepo,
            $this->createMock(EmailLogRepository::class),
            $mailer ?? $this->createMock(Mailer::class),
            $domainRepo,
            $registry
        );
    }

    public function testRegistryAbsentFallsBackToDirectTransport(): void
    {
        // 레지스트리 미주입(테스트·부분 구성 환경)만 직접 전송 폴백 허용
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::once())->method('send')->willReturn(true);

        $result = $this->service(null, $mailer)->send(1, 'welcome', 'user@example.com', []);

        self::assertTrue($result->isSuccess());
    }

    public function testRegistryPresentDelegatesToCoreGatewayByExactKey(): void
    {
        $sink = new \stdClass();
        $gateway = new class($sink) implements NotificationGatewayInterface {
            public function __construct(private object $sink)
            {
            }

            public function send(string $channel, string $templateCode, string $recipient, array $fieldValues): NotificationSendResult
            {
                $this->sink->fields = $fieldValues;
                return new NotificationSendResult(true);
            }

            public function getSupportedChannels(): array
            {
                return ['email' => '이메일'];
            }

            public function getChannelTree(int $domainId): array
            {
                return [];
            }
        };

        $registry = $this->createMock(ContractRegistry::class);
        // 메타 채널 매칭이 아니라 정확히 'core_email' 키로 조회해야 한다
        $registry->expects(self::once())
            ->method('get')
            ->with(NotificationGatewayInterface::class, EmailNotifyService::CORE_EMAIL_GATEWAY_KEY)
            ->willReturn($gateway);

        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('send');

        $result = $this->service($registry, $mailer)->send(7, 'welcome', 'user@example.com', []);

        self::assertTrue($result->isSuccess());
        self::assertSame(7, $sink->fields['domain_id']);
    }

    public function testRegistryPresentGatewayLookupFailureDoesNotFallBackToDirectSend(): void
    {
        // fail-closed: core_email 등록 누락·생성 예외 시 직접 Mailer 로 우회하면
        // 알림 이메일 정책이 뚫린다 — 명시적 실패여야 한다
        $registry = $this->createMock(ContractRegistry::class);
        $registry->method('get')->willThrowException(new \RuntimeException('no registration'));

        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('send');

        $result = $this->service($registry, $mailer)->send(1, 'welcome', 'user@example.com', []);

        self::assertFalse($result->isSuccess());
    }

    public function testGatewayFailureIsPropagatedAsFailure(): void
    {
        // 글로벌 알림 이메일 비활성 등 게이트웨이 거부가 테스트 발송에도 그대로 반영
        $gateway = $this->createMock(NotificationGatewayInterface::class);
        $gateway->method('send')->willReturn(new NotificationSendResult(false, '이메일 발송이 비활성화되어 있습니다 (도메인 설정).'));

        $registry = $this->createMock(ContractRegistry::class);
        $registry->method('get')->willReturn($gateway);

        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('send');

        $result = $this->service($registry, $mailer)->send(1, 'welcome', 'user@example.com', []);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('비활성화', $result->getMessage());
    }

    public function testInactivePluginYieldsNoTemplateOptions(): void
    {
        // 플러그인 전체 비활성 — 렌더가 어차피 실패하므로 채널 트리에서 제외
        $service = $this->service(null, null, ['is_active' => 0]);

        self::assertSame([], $service->getActiveTemplateOptions(1));
    }
}
