<?php
declare(strict_types=1);

namespace Mublo\Packages\Board;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\PluginHostInterface;
use Mublo\Core\Extension\PluginHostTrait;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\DataResetFilesystemInterface;
use Mublo\Contract\Notification\MemberNotificationPublisherInterface;
use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Contract\Site\SiteProvisioningInterface;
use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Core\Dashboard\DashboardWidgetRegistry;
use Mublo\Contract\Board\BoardProvisioningInterface;
use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Packages\Board\Api\BoardProvisioningGateway;
use Mublo\Enum\Block\BlockContentKind;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Image\ImageProcessor;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Balance\BalanceGatewayInterface;

// Public Extension API
use Mublo\Packages\Board\Api\BoardArticleCommand;
use Mublo\Packages\Board\Api\BoardArticleReader;
use Mublo\Packages\Board\Api\BoardExtensionApi;
use Mublo\Packages\Board\Contract\Extension\BoardArticleCommandInterface;
use Mublo\Packages\Board\Contract\Extension\BoardArticleReaderInterface;
use Mublo\Packages\Board\Contract\Extension\BoardExtensionApiInterface;

// Repository
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardCategoryRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Repository\BoardAttachmentRepository;
use Mublo\Packages\Board\Repository\BoardPermissionRepository;
use Mublo\Packages\Board\Repository\BoardReactionRepository;
use Mublo\Packages\Board\Repository\BoardLinkRepository;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Repository\BoardGroupAdminRepository;
use Mublo\Packages\Board\Repository\BoardPointConfigRepository;

// Service
use Mublo\Packages\Board\Service\BoardArticleService;
use Mublo\Packages\Board\Service\BoardCommentService;
use Mublo\Packages\Board\Service\BoardConfigService;
use Mublo\Packages\Board\Service\BoardCategoryService;
use Mublo\Packages\Board\Service\BoardGroupService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Packages\Board\Service\BoardReactionService;
use Mublo\Packages\Board\Service\BoardFileService;
use Mublo\Packages\Board\Service\CommunityService;
use Mublo\Packages\Board\Service\BoardPointConfigService;
use Mublo\Packages\Board\Service\BoardPointService;
use Mublo\Packages\Board\Service\BoardDashboardService;
use Mublo\Packages\Board\Service\BoardDataResetter;

// Block
use Mublo\Packages\Board\Block\BoardRenderer;
use Mublo\Packages\Board\Block\BoardGroupRenderer;
use Mublo\Packages\Board\Block\BoardCommentRenderer;

// Subscriber
use Mublo\Packages\Board\Subscriber\AdminMenuSubscriber;
use Mublo\Packages\Board\Subscriber\MenuAutoRegistrationSubscriber;
use Mublo\Packages\Board\Subscriber\BoardSearchSubscriber;
use Mublo\Packages\Board\Subscriber\BoardBlockCacheSubscriber;
use Mublo\Packages\Board\Subscriber\BoardPointSubscriber;
use Mublo\Packages\Board\Subscriber\BoardNotificationSubscriber;
use Mublo\Packages\Board\Subscriber\DomainEventSubscriber;
use Mublo\Packages\Board\Subscriber\ReactionPointSubscriber;
use Mublo\Packages\Board\Subscriber\MypageSectionSubscriber;
use Mublo\Packages\Board\Subscriber\BlockContentItemsSubscriber;
use Mublo\Packages\Board\Subscriber\PageTypeSubscriber;

// Widget
use Mublo\Packages\Board\Widget\RecentNoticesWidget;

// Sitemap
use Mublo\Packages\Board\Sitemap\BoardSitemapProvider;

class BoardProvider implements ExtensionProviderInterface, InstallableExtensionInterface, DataResettableInterface, DataResetFilesystemInterface, PluginHostInterface
{
    // 플러그인 수용 패키지 선언 — 다른 개발자가 Board 종속 플러그인
    // (packages/Board/Plugins/{Name})을 배포할 수 있다. 발견은 표준 규약
    // (PluginHostTrait)을 쓴다.
    use PluginHostTrait;

    private BoardDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(BoardDataResetter::class, fn(DependencyContainer $c) =>
            new BoardDataResetter(
                $c->get(Database::class),
                $c->get(\Mublo\Contract\Balance\BalanceResetGatewayInterface::class)
            )
        );
        // ── Repository ──
        $container->singleton(BoardArticleRepository::class, fn(DependencyContainer $c) =>
            new BoardArticleRepository($c->get(Database::class))
        );
        $container->singleton(BoardCommentRepository::class, fn(DependencyContainer $c) =>
            new BoardCommentRepository($c->get(Database::class))
        );
        $container->singleton(BoardConfigRepository::class, fn(DependencyContainer $c) =>
            new BoardConfigRepository($c->get(Database::class))
        );
        $container->singleton(BoardCategoryRepository::class, fn(DependencyContainer $c) =>
            new BoardCategoryRepository($c->get(Database::class))
        );
        $container->singleton(BoardGroupRepository::class, fn(DependencyContainer $c) =>
            new BoardGroupRepository($c->get(Database::class))
        );
        $container->singleton(BoardAttachmentRepository::class, fn(DependencyContainer $c) =>
            new BoardAttachmentRepository($c->get(Database::class))
        );
        $container->singleton(BoardPermissionRepository::class, fn(DependencyContainer $c) =>
            new BoardPermissionRepository($c->get(Database::class))
        );
        $container->singleton(BoardReactionRepository::class, fn(DependencyContainer $c) =>
            new BoardReactionRepository($c->get(Database::class))
        );
        $container->singleton(BoardLinkRepository::class, fn(DependencyContainer $c) =>
            new BoardLinkRepository($c->get(Database::class))
        );
        $container->singleton(BoardCategoryMappingRepository::class, fn(DependencyContainer $c) =>
            new BoardCategoryMappingRepository($c->get(Database::class))
        );
        $container->singleton(BoardGroupAdminRepository::class, fn(DependencyContainer $c) =>
            new BoardGroupAdminRepository($c->get(Database::class))
        );
        $container->singleton(BoardPointConfigRepository::class, fn(DependencyContainer $c) =>
            new BoardPointConfigRepository($c->get(Database::class))
        );

        // ── Service ──
        $container->singleton(BoardPermissionService::class, fn(DependencyContainer $c) =>
            new BoardPermissionService(
                $c->get(BoardGroupRepository::class),
                $c->get(BoardCategoryMappingRepository::class),
                $c->get(BoardPermissionRepository::class),
                $c->get(AuthContextInterface::class)
            )
        );
        $container->singleton(BoardArticleService::class, fn(DependencyContainer $c) =>
            new BoardArticleService(
                $c->get(BoardArticleRepository::class),
                $c->get(BoardConfigRepository::class),
                $c->get(MemberQueryInterface::class),
                $c->get(BoardPermissionService::class),
                $c->get(EventDispatcher::class),
                $c->get(AuthContextInterface::class)
            )
        );

        // ── Public Extension API ──
        // 내부 Service/Entity를 종속 Plugin에 직접 노출하지 않는다.
        $container->singleton(BoardArticleReaderInterface::class, fn(DependencyContainer $c) =>
            new BoardArticleReader(
                $c->get(BoardArticleService::class),
                $c->get(BoardConfigService::class)
            )
        );
        $container->singleton(BoardArticleCommandInterface::class, fn(DependencyContainer $c) =>
            new BoardArticleCommand($c->get(BoardArticleService::class))
        );
        $container->singleton(BoardExtensionApiInterface::class, fn(DependencyContainer $c) =>
            new BoardExtensionApi(
                $c->get(BoardArticleReaderInterface::class),
                $c->get(BoardArticleCommandInterface::class)
            )
        );
        $container->singleton(BoardCommentService::class, fn(DependencyContainer $c) =>
            new BoardCommentService(
                $c->get(BoardCommentRepository::class),
                $c->get(BoardArticleRepository::class),
                $c->get(BoardConfigRepository::class),
                $c->get(MemberQueryInterface::class),
                $c->get(BoardPermissionService::class),
                $c->get(EventDispatcher::class),
                $c->get(AuthContextInterface::class)
            )
        );
        $container->singleton(BoardConfigService::class, fn(DependencyContainer $c) =>
            new BoardConfigService(
                $c->get(BoardConfigRepository::class),
                $c->get(BoardGroupRepository::class),
                $c->get(BoardCategoryMappingRepository::class),
                $c->get(BoardArticleRepository::class),
                $c->get(EventDispatcher::class),
                $c->get(AuthContextInterface::class)
            )
        );
        $container->singleton(BoardCategoryService::class, fn(DependencyContainer $c) =>
            new BoardCategoryService(
                $c->get(BoardCategoryRepository::class),
                $c->get(BoardCategoryMappingRepository::class)
            )
        );
        $container->singleton(BoardGroupService::class, fn(DependencyContainer $c) =>
            new BoardGroupService(
                $c->get(BoardGroupRepository::class),
                $c->get(BoardPermissionRepository::class)
            )
        );
        $container->singleton(BoardReactionService::class, fn(DependencyContainer $c) =>
            new BoardReactionService(
                $c->get(BoardReactionRepository::class),
                $c->get(BoardArticleRepository::class),
                $c->get(BoardCommentRepository::class),
                $c->get(BoardConfigRepository::class),
                $c->get(BoardPermissionService::class),
                $c->get(EventDispatcher::class),
                $c->get(AuthContextInterface::class)
            )
        );
        $container->singleton(BoardFileService::class, fn(DependencyContainer $c) =>
            new BoardFileService(
                $c->get(BoardAttachmentRepository::class),
                $c->get(BoardLinkRepository::class),
                $c->get(BoardArticleRepository::class),
                $c->get(BoardConfigRepository::class),
                $c->get(MemberQueryInterface::class),
                $c->get(BoardPermissionService::class),
                $c->get(EventDispatcher::class),
                $c->get(AuthContextInterface::class),
                $c->get(FileUploader::class),
                $c->get(ImageProcessor::class)
            )
        );
        $container->singleton(CommunityService::class, fn(DependencyContainer $c) =>
            new CommunityService(
                $c->get(BoardArticleRepository::class),
                $c->get(BoardConfigRepository::class),
                $c->get(BoardGroupRepository::class),
                $c->get(BoardPermissionService::class)
            )
        );
        $container->singleton(BoardPointConfigService::class, fn(DependencyContainer $c) =>
            new BoardPointConfigService(
                $c->get(BoardPointConfigRepository::class),
                $c->get(BoardConfigRepository::class),
                $c->get(BoardGroupRepository::class)
            )
        );
        $container->singleton(BoardPointService::class, fn(DependencyContainer $c) =>
            new BoardPointService(
                $c->get(BalanceGatewayInterface::class),
                $c->get(BoardPointConfigService::class)
            )
        );
        $container->singleton(BoardDashboardService::class, fn(DependencyContainer $c) =>
            new BoardDashboardService(
                $c->get(BoardConfigRepository::class),
                $c->get(BoardGroupRepository::class),
                $c->get(BoardArticleRepository::class),
                $c->get(BoardCommentRepository::class)
            )
        );

        // ── Block ──
        $container->singleton(BoardRenderer::class, function (DependencyContainer $c) {
            $renderer = new BoardRenderer(
                $c->get(BoardArticleRepository::class),
                $c->get(BoardConfigRepository::class)
            );
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });
        $container->singleton(BoardGroupRenderer::class, function (DependencyContainer $c) {
            $renderer = new BoardGroupRenderer(
                $c->get(BoardArticleRepository::class),
                $c->get(BoardConfigRepository::class),
                $c->get(BoardGroupRepository::class)
            );
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });
        $container->singleton(BoardCommentRenderer::class, function (DependencyContainer $c) {
            $renderer = new BoardCommentRenderer(
                $c->get(BoardCommentRepository::class),
                $c->get(BoardConfigRepository::class)
            );
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(BoardDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);

        // 블록 콘텐츠 타입 등록
        BlockRegistry::registerContentType(
            type: 'board',
            kind: BlockContentKind::PACKAGE->value,
            title: '게시판 최신글',
            rendererClass: BoardRenderer::class,
            options: [
                'icon' => 'bi-card-text',
                'capabilities' => BlockRegistry::capabilities(
                    skin: true, items: true, count: true, style: true, aos: true, customConfig: false,
                ),
                // 아이템 = 글을 가져올 게시판(소스). 출력갯수는 "글 몇 개"라 축이 다르므로
                // 출력갯수 연동을 끊고 1개로 고정한다. 렌더러도 items[0]만 소비한다.
                'hasItems' => true,
                'maxItems' => 1,
                'hasStyle' => true,
                'skinBasePath' => MUBLO_PACKAGE_PATH . '/Board/views/Block/',
            ]
        );
        BlockRegistry::registerContentType(
            type: 'boardcomment',
            kind: BlockContentKind::PACKAGE->value,
            title: '게시판 최신댓글',
            rendererClass: BoardCommentRenderer::class,
            options: [
                'icon' => 'bi-chat-dots',
                'capabilities' => BlockRegistry::capabilities(
                    skin: true, items: true, count: true, style: false, aos: true, customConfig: false,
                ),
                // 게시판 지정. 미지정/삭제/비활성이면 댓글 없는 것처럼 동작(게시판 최신글과 동일).
                'hasItems' => true,
                'maxItems' => 1,
                'hasStyle' => true,
                'skinBasePath' => MUBLO_PACKAGE_PATH . '/Board/views/Block/',
            ]
        );
        BlockRegistry::registerContentType(
            type: 'boardgroup',
            kind: BlockContentKind::PACKAGE->value,
            title: '게시판 그룹',
            rendererClass: BoardGroupRenderer::class,
            options: [
                'icon' => 'bi-collection',
                'capabilities' => BlockRegistry::capabilities(
                    skin: true, items: true, count: true, style: true, aos: true, customConfig: false,
                ),
                // 아이템 = 게시판을 가져올 그룹(소스). 출력갯수는 "게시판당 글 몇 개"라
                // 축이 다르다. 게시판:그룹 = N:1(board_configs.group_id) 이라 그룹을 여러 개
                // 고를 실익이 적어 board/boardcomment 와 같은 단일 규칙으로 맞춘다.
                'hasItems' => true,
                'maxItems' => 1,
                'hasStyle' => true,
                'skinBasePath' => MUBLO_PACKAGE_PATH . '/Board/views/Block/',
            ]
        );

        // 이벤트 구독자 등록
        $eventDispatcher->addSubscriber(new MenuAutoRegistrationSubscriber(
            $container->get(SiteProvisioningInterface::class),
            $container->get(MenuManagementInterface::class)
        ));
        $eventDispatcher->addSubscriber(new BoardSearchSubscriber($container->get(BoardArticleRepository::class)));
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        // 게시글 변경 시 진열 블록(board/boardgroup) 캐시 무효화 (stale 목록 방지)
        $eventDispatcher->addSubscriber(new BoardBlockCacheSubscriber(
            $container->get(BoardConfigRepository::class),
            $container->get(BlockContentCacheInvalidatorInterface::class)
        ));

        // 도메인 생성 시 기본 게시판 자동 시딩
        $eventDispatcher->addSubscriber(new DomainEventSubscriber(
            $container->get(Database::class)
        ));

        // 포인트 이벤트 구독자 등록
        $eventDispatcher->addSubscriber(new BoardPointSubscriber(
            $container->get(BoardPointService::class),
            $container->get(BoardConfigRepository::class)
        ));
        $eventDispatcher->addSubscriber(new ReactionPointSubscriber(
            $container->get(BoardPointService::class)
        ));

        // Core 회원 알림 계약 실증: 글 댓글과 댓글 답글을 수신자 도메인에 저장한다.
        $eventDispatcher->addSubscriber(new BoardNotificationSubscriber(
            $container->get(MemberNotificationPublisherInterface::class),
            $container->get(BoardConfigRepository::class),
            $container->get(BoardCommentRepository::class),
            $container->get(MemberQueryInterface::class),
        ));

        // Core 디커플링 구독자 (Mypage, Block, PageType)
        // 마이페이지 허브/섹션 등록 (/mypage/board 대시보드, /mypage/board/articles·comments)
        $eventDispatcher->addSubscriber(new MypageSectionSubscriber(
            $container->get(BoardArticleRepository::class),
            $container->get(BoardCommentRepository::class),
            $container->get(BoardReactionRepository::class),
        ));
        $eventDispatcher->addSubscriber(new BlockContentItemsSubscriber(
            $container->get(BoardConfigRepository::class),
            $container->get(BoardGroupRepository::class),
        ));
        $eventDispatcher->addSubscriber(new PageTypeSubscriber());

        // 확장이 게시판을 프로그래밍으로 만들 때 쓰는 안정 계약 (코어 정의, Board 구현)
        // ContractRegistry 를 쓰는 이유: Board 가 비활성인 도메인에서는 바인딩이
        // 없어야 하고, 소비자가 has() 로 판정해 폴백할 수 있어야 한다.
        // Closure = 지연 생성. 프로비저닝은 사이트 구축 때만 쓰므로
        // 일반 요청에서는 서비스 네 개를 만들지 않는다. has() 는 그대로 참이다.
        $container->get(ContractRegistry::class)->bind(
            BoardProvisioningInterface::class,
            fn(): BoardProvisioningGateway => new BoardProvisioningGateway(
                $container->get(BoardGroupService::class),
                $container->get(BoardGroupRepository::class),
                $container->get(BoardConfigService::class),
                $container->get(BoardConfigRepository::class),
                $container->get(\Mublo\Infrastructure\Database\Database::class)
            )
        );

        // 사이트맵 URL 제공자 등록 (코어 SitemapController가 소비)
        // Closure = 지연 생성. 사이트맵 요청이 아닐 때는 만들어지지 않는다.
        $contractRegistry = $container->get(ContractRegistry::class);
        $contractRegistry->register(
            SitemapUrlProviderInterface::class,
            'board',
            fn() => new BoardSitemapProvider($container->get(Database::class)),
        );

        // 대시보드 위젯 등록
        $registry = $container->get(DashboardWidgetRegistry::class);
        $domainIdResolver = fn() => $container->has(Context::class) ? $container->get(Context::class)->getDomainId() : null;
        $registry->register(
            'board.recent_notices',
            new RecentNoticesWidget($container->get(BoardArticleRepository::class), $domainIdResolver),
            10
        );
    }

    /**
     * 관리자 패널에서 첫 활성화 시 실행
     *
     * 기본 게시판 그룹 + 공지사항/자유게시판 생성 (없을 경우에만)
     */
    public function install(DependencyContainer $container, Context $context): void
    {
        DomainEventSubscriber::seedBoards(
            $container->get(Database::class),
            $context->getDomainId()
        );
    }

    /**
     * 관리자 패널에서 비활성화 시 실행
     *
     * DB 데이터는 보존 (테이블/게시글 유지)
     */
    public function uninstall(DependencyContainer $container, Context $context): void
    {
        // 게시판 데이터는 보존 — 별도 삭제 없음
    }

    // ── DataResettableInterface ──

    public function getResetCategories(): array
    {
        return $this->dataResetter->getResetCategories();
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        return $this->dataResetter->reset($category, $domainId);
    }

    public function resetFiles(string $category, int $domainId): int
    {
        return $this->dataResetter->resetFiles($category, $domainId);
    }
}
