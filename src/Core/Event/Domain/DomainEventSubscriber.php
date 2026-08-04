<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Domain;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Block\BlockSeeder;
use Mublo\Contract\Block\BlockKitGatewayInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Service\Menu\MenuService;
use Mublo\Service\Member\PolicyService;
use Mublo\Service\Extension\ExtensionService;

/**
 * Core 도메인 이벤트 구독자
 *
 * 도메인 생성 시 기본 데이터 시딩 (메뉴, 약관, 확장 기능, 메인 블록, 시작 킷)
 * 기존 DomainsController에서 직접 호출하던 로직을 이벤트로 분리
 */
class DomainEventSubscriber implements EventSubscriberInterface
{
    private MenuService $menuService;
    private PolicyService $policyService;
    private ?ExtensionService $extensionService;
    private ?Database $database;
    private ?BlockKitGatewayInterface $blockKitGateway;

    public function __construct(
        MenuService $menuService,
        PolicyService $policyService,
        ?ExtensionService $extensionService = null,
        ?Database $database = null,
        ?BlockKitGatewayInterface $blockKitGateway = null
    ) {
        $this->menuService = $menuService;
        $this->policyService = $policyService;
        $this->extensionService = $extensionService;
        $this->database = $database;
        $this->blockKitGateway = $blockKitGateway;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DomainProvisioningEvent::class => 'onDomainProvisioning',
            DomainCreatedEvent::class => 'onDomainCreated',
        ];
    }

    /**
     * 도메인 생성 트랜잭션 안에서 필수 코어 데이터를 시딩한다.
     */
    public function onDomainProvisioning(DomainProvisioningEvent $event): void
    {
        $domainId = $event->getDomainId();

        // 기본 메뉴 시드
        $menuResult = $this->menuService->seedDefaultMenus($domainId);
        if ($menuResult->isFailure()) {
            throw new \RuntimeException($menuResult->getMessage());
        }

        // 기본 약관 시드
        $policyResult = $this->policyService->seedDefaultPolicies($domainId);
        if ($policyResult->isFailure()) {
            throw new \RuntimeException($policyResult->getMessage());
        }

        // 기본 메인화면 블록 시드 (최초 설치와 동일한 환영/안내 블록)
        if ($this->database !== null) {
            $seeder = new BlockSeeder($this->database->getPdo());
            $blockResult = $seeder->seed($domainId);
            if (!$blockResult['success']) {
                throw new \RuntimeException($blockResult['message']);
            }
        }
    }

    /**
     * 커밋 이후 선택 데이터를 best-effort로 시딩한다.
     */
    public function onDomainCreated(DomainCreatedEvent $event): void
    {
        $domainId = $event->getDomainId();

        // 기본 확장 기능(패키지) 시드
        $this->extensionService?->seedDefaultExtensions($domainId);

        // 번들 시작 킷 보관함 시드
        $this->seedStarterKits($domainId);
    }

    /**
     * 번들 시작 킷을 새 도메인의 블록 킷 보관함(block_kits)에 등록
     *
     * 설치 시더(003_seed_starter_kits.php)가 domain_id=1 에 하는 일을 새로 만든
     * 도메인에도 미러링한다. 설치기와 달리 런타임에는 서비스 체인이 조립돼 있으므로
     * 코어 게이트웨이(registerBundled)를 그대로 쓴다.
     *
     * - 멱등: 같은 이름의 번들 킷이 있으면 게이트웨이가 건너뛴다(재실행 안전)
     * - 미등록 확장 블록 타입은 dryRun 에서 경고로만 처리돼 등록을 막지 않는다
     *   (새 도메인 확장은 첫 부팅 reconcile 후 적용 가능해진다)
     * - 킷 하나가 손상돼도 도메인 생성을 막지 않고 해당 킷만 건너뛴다
     */
    private function seedStarterKits(int $domainId): void
    {
        if ($this->blockKitGateway === null) {
            return;
        }

        $kitDir = MUBLO_ROOT_PATH . '/database/seeders/starter-kits';
        $manifestPath = $kitDir . '/kits.php';
        if (!is_file($manifestPath)) {
            return;
        }

        $manifest = require $manifestPath;
        if (!is_array($manifest)) {
            return;
        }

        foreach ($manifest as $slug => $meta) {
            $file = (string) ($meta['file'] ?? '');
            if ($file === '') {
                continue;
            }

            try {
                $json = @file_get_contents($kitDir . '/' . $file);
                if ($json === false) {
                    throw new \RuntimeException("시작 킷 파일을 읽을 수 없습니다: {$file}");
                }
                $this->blockKitGateway->registerBundled($domainId, $json);
            } catch (\Throwable $e) {
                error_log("[Mublo] 시작 킷 시드 건너뜀 ({$slug}): " . $e->getMessage());
            }
        }
    }
}
