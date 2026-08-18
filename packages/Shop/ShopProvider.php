<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop;

use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Member\PolicyQueryInterface;
use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Contract\Site\SiteProvisioningInterface;

use Mublo\Contract\DataResetFilesystemInterface;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\PluginHostInterface;
use Mublo\Core\Extension\PluginHostTrait;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Http\Request;
use Mublo\Contract\Payment\PaymentConsumerInterface;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Registry\CategoryProviderRegistry;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Enum\Block\BlockContentKind;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Packages\Shop\Block\ProductConfigForm;
use Mublo\Packages\Shop\Block\ProductRenderer;
use Mublo\Packages\Shop\Block\ProductAutoRenderer;
use Mublo\Packages\Shop\Block\ReviewAutoRenderer;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;
use Mublo\Packages\Shop\Sitemap\ShopSitemapProvider;
use Mublo\Packages\Shop\Contract\PriceCompare\PriceCompareChannelInterface;
use Mublo\Packages\Shop\Controller\Admin\PriceCompareController as AdminPriceCompareController;
use Mublo\Packages\Shop\Controller\Front\PriceCompareController;
use Mublo\Packages\Shop\Helper\PublicProductCriteria;
use Mublo\Packages\Shop\PriceCompare\BuiltinChannels;
use Mublo\Packages\Shop\PriceCompare\PriceCompareService;
use Mublo\Packages\Shop\PriceCompare\ProductFeedQuery;
use Mublo\Packages\Shop\PriceCompare\Writer\RssWriter;
use Mublo\Packages\Shop\PriceCompare\Writer\TsvWriter;

// Public Extension API
use Mublo\Packages\Shop\Api\ShopCommand;
use Mublo\Packages\Shop\Api\ShopExtensionApi;
use Mublo\Packages\Shop\Api\ShopOrderReader;
use Mublo\Packages\Shop\Api\ShopProductReader;
use Mublo\Packages\Shop\Contract\Extension\ShopCommandInterface;
use Mublo\Packages\Shop\Contract\Extension\ShopExtensionApiInterface;
use Mublo\Packages\Shop\Contract\Extension\ShopOrderReaderInterface;
use Mublo\Packages\Shop\Contract\Extension\ShopProductReaderInterface;

// Repository
use Mublo\Packages\Shop\Repository\PriceCompareChannelRepository;
use Mublo\Packages\Shop\Repository\ShopConfigRepository;
use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\OptionPresetRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\CartRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\ShippingRepository;
use Mublo\Packages\Shop\Repository\CouponRepository;
use Mublo\Packages\Shop\Repository\MemberAddressRepository;
use Mublo\Packages\Shop\Repository\OrderFieldRepository;
use Mublo\Packages\Shop\Repository\PaymentTransactionRepository;
use Mublo\Packages\Shop\Repository\PaymentCompletionRepository;
use Mublo\Packages\Shop\Repository\OrderMemoRepository;
use Mublo\Packages\Shop\Repository\ProductInfoTemplateRepository;
use Mublo\Packages\Shop\Repository\ReviewRepository;
use Mublo\Packages\Shop\Repository\InquiryRepository;
use Mublo\Packages\Shop\Repository\WishlistRepository;
use Mublo\Packages\Shop\Repository\PointLogRepository;
use Mublo\Packages\Shop\Repository\ExhibitionRepository;
use Mublo\Packages\Shop\Repository\ShipmentRepository;
use Mublo\Packages\Shop\Repository\ActionExecutionRepository;
use Mublo\Packages\Shop\Repository\ClaimRepository;

// Service
use Mublo\Packages\Shop\Service\MemberLevelResolver;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\CategoryService;
use Mublo\Packages\Shop\Service\ProductService;
use Mublo\Packages\Shop\Service\OptionPresetService;
use Mublo\Packages\Shop\Service\CartService;
use Mublo\Packages\Shop\Service\CartCheckoutService;
use Mublo\Packages\Shop\Service\DirectBuyService;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\OrderPointService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\ShippingService;
use Mublo\Packages\Shop\Service\ShippingFeeCalculator;
use Mublo\Packages\Shop\Service\CouponService;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\PaymentService;
use Mublo\Packages\Shop\Service\ShopPaymentConsumer;
use Mublo\Packages\Shop\Service\PaymentCompletionService;
use Mublo\Packages\Shop\Service\PaymentReceiptService;
use Mublo\Packages\Shop\Service\MemberAddressService;
use Mublo\Packages\Shop\Service\OrderFieldService;
use Mublo\Packages\Shop\Service\RefundService;
use Mublo\Packages\Shop\Service\OrderCancelService;
use Mublo\Packages\Shop\Service\OrderMemoService;
use Mublo\Packages\Shop\Service\ProductInfoTemplateService;
use Mublo\Packages\Shop\Service\ReviewService;
use Mublo\Packages\Shop\Service\InquiryService;
use Mublo\Packages\Shop\Service\WishlistService;
use Mublo\Packages\Shop\Service\PointLogService;
use Mublo\Packages\Shop\Service\DashboardService;
use Mublo\Packages\Shop\Service\ExhibitionService;
use Mublo\Packages\Shop\Service\ShipmentService;
use Mublo\Packages\Shop\Service\ShipmentGroupResolver;
use Mublo\Packages\Shop\Service\ActionExecutionService;
use Mublo\Packages\Shop\Service\ClaimStateMachine;
use Mublo\Packages\Shop\Service\ExchangeStockService;
use Mublo\Packages\Shop\Service\ExchangeService;
use Mublo\Packages\Shop\Service\ShopDataResetter;
use Mublo\Contract\CustomField\CustomFieldFileManagerInterface;
use Mublo\Contract\CustomField\CustomFieldValueValidatorInterface;
use Mublo\Contract\Security\SensitiveValueCodecInterface;

// Controller
use Mublo\Packages\Shop\Controller\Admin\ShopConfigController;
use Mublo\Packages\Shop\Controller\Admin\CategoryController;
use Mublo\Packages\Shop\Controller\Admin\ProductController as AdminProductController;
use Mublo\Packages\Shop\Controller\Admin\OptionPresetController;
use Mublo\Packages\Shop\Controller\Admin\OrderController as AdminOrderController;
use Mublo\Packages\Shop\Controller\Admin\CouponController;
use Mublo\Packages\Shop\Controller\Admin\ShippingTemplateController;
use Mublo\Packages\Shop\Controller\Admin\OrderStateController;
use Mublo\Packages\Shop\Controller\Admin\OrderFieldController;
use Mublo\Packages\Shop\Controller\Admin\ProductInfoTemplateController;
use Mublo\Packages\Shop\Controller\Admin\WishlistController as AdminWishlistController;
use Mublo\Packages\Shop\Controller\Admin\ReviewController as AdminReviewController;
use Mublo\Packages\Shop\Controller\Admin\InquiryController;
use Mublo\Packages\Shop\Controller\Admin\DashboardController;
use Mublo\Packages\Shop\Controller\Admin\ExhibitionController as AdminExhibitionController;
use Mublo\Packages\Shop\Controller\Admin\ExchangeController;
use Mublo\Packages\Shop\Controller\Front\ProductController as FrontProductController;
use Mublo\Packages\Shop\Controller\Front\ReviewController as FrontReviewController;
use Mublo\Packages\Shop\Controller\Front\InquiryController as FrontInquiryController;
use Mublo\Packages\Shop\Controller\Front\WishlistController;
use Mublo\Packages\Shop\Controller\Front\CartController;
use Mublo\Packages\Shop\Controller\Front\OrderController as FrontOrderController;
use Mublo\Packages\Shop\Controller\Front\AddressController;
use Mublo\Packages\Shop\Controller\Front\CouponController as FrontCouponController;
use Mublo\Packages\Shop\Controller\Front\ExhibitionController as FrontExhibitionController;

// Action
use Mublo\Packages\Shop\Service\ActionTypeRegistry;
use Mublo\Packages\Shop\Action\NotificationActionHandler;
use Mublo\Packages\Shop\Action\PointActionHandler;
use Mublo\Packages\Shop\Action\PointDeductActionHandler;
use Mublo\Packages\Shop\Action\OrderConfirmActionHandler;
use Mublo\Packages\Shop\Action\StockDeductActionHandler;
use Mublo\Packages\Shop\Action\StockRestoreActionHandler;
use Mublo\Packages\Shop\Action\WebhookActionHandler;
use Mublo\Contract\Balance\BalanceGatewayInterface;
use Mublo\Packages\Shop\EventSubscriber\ConfigurableActionSubscriber;
use Mublo\Packages\Shop\EventSubscriber\ConfigurableItemActionSubscriber;
use Mublo\Packages\Shop\EventSubscriber\ConfigurableClaimActionSubscriber;
use Mublo\Packages\Shop\EventSubscriber\ShipmentItemStatusSubscriber;
use Mublo\Packages\Shop\EventSubscriber\CouponRestoreSubscriber;
use Mublo\Packages\Shop\EventSubscriber\PaymentMismatchSubscriber;
use Mublo\Packages\Shop\EventSubscriber\PointPaymentSubscriber;
use Mublo\Packages\Shop\EventSubscriber\CouponAutoIssueSubscriber;
use Mublo\Packages\Shop\EventSubscriber\DomainEventSubscriber;
use Mublo\Packages\Shop\EventSubscriber\ExhibitionMenuSubscriber;
use Mublo\Packages\Shop\EventSubscriber\CategoryMenuSubscriber;
use Mublo\Packages\Shop\EventSubscriber\BlockCacheInvalidateSubscriber;
use Mublo\Packages\Shop\EventSubscriber\LoginFormSubscriber;
use Mublo\Packages\Shop\EventSubscriber\NotificationVariableSubscriber;
use Mublo\Packages\Shop\EventSubscriber\FrameTemplateSubscriber;
use Mublo\Packages\Shop\EventSubscriber\ShopSearchSubscriber;
use Mublo\Packages\Shop\EventSubscriber\MypageSectionSubscriber;

class ShopProvider implements ExtensionProviderInterface, InstallableExtensionInterface, PluginHostInterface, DataResettableInterface, DataResetFilesystemInterface
{
    // packages/Shop/Plugins/{Name} 표준 규약으로 Shop 종속 플러그인을 발견한다.
    use PluginHostTrait;

    private ShopDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(ShopDataResetter::class, fn(DependencyContainer $c) => new ShopDataResetter(
            $c->get(Database::class),
            $c->get(\Mublo\Contract\Balance\BalanceResetGatewayInterface::class)
        ));
        // ── Repository ──
        $container->singleton(ShopConfigRepository::class, fn(DependencyContainer $c) =>
            new ShopConfigRepository($c->get(Database::class))
        );
        $container->singleton(CategoryRepository::class, fn(DependencyContainer $c) =>
            new CategoryRepository($c->get(Database::class))
        );
        $container->singleton(ProductRepository::class, fn(DependencyContainer $c) =>
            new ProductRepository($c->get(Database::class))
        );
        $container->singleton(OptionPresetRepository::class, fn(DependencyContainer $c) =>
            new OptionPresetRepository($c->get(Database::class))
        );
        $container->singleton(ProductOptionRepository::class, fn(DependencyContainer $c) =>
            new ProductOptionRepository($c->get(Database::class))
        );
        $container->singleton(CartRepository::class, fn(DependencyContainer $c) =>
            new CartRepository($c->get(Database::class))
        );
        $container->singleton(OrderRepository::class, fn(DependencyContainer $c) =>
            new OrderRepository($c->get(Database::class))
        );
        $container->singleton(ActionExecutionRepository::class, fn(DependencyContainer $c) =>
            new ActionExecutionRepository($c->get(Database::class))
        );
        $container->singleton(ClaimRepository::class, fn(DependencyContainer $c) =>
            new ClaimRepository($c->get(Database::class))
        );
        $container->singleton(ShippingRepository::class, fn(DependencyContainer $c) =>
            new ShippingRepository($c->get(Database::class))
        );
        $container->singleton(CouponRepository::class, fn(DependencyContainer $c) =>
            new CouponRepository($c->get(Database::class))
        );
        $container->singleton(MemberAddressRepository::class, fn(DependencyContainer $c) =>
            new MemberAddressRepository($c->get(Database::class))
        );
        $container->singleton(OrderFieldRepository::class, fn(DependencyContainer $c) =>
            new OrderFieldRepository($c->get(Database::class))
        );
        $container->singleton(PaymentTransactionRepository::class, fn(DependencyContainer $c) =>
            new PaymentTransactionRepository($c->get(Database::class))
        );
        $container->singleton(PaymentCompletionRepository::class, fn(DependencyContainer $c) =>
            new PaymentCompletionRepository($c->get(Database::class))
        );
        $container->singleton(OrderMemoRepository::class, fn(DependencyContainer $c) =>
            new OrderMemoRepository($c->get(Database::class))
        );
        $container->singleton(ProductInfoTemplateRepository::class, fn(DependencyContainer $c) =>
            new ProductInfoTemplateRepository($c->get(Database::class))
        );
        $container->singleton(ReviewRepository::class, fn(DependencyContainer $c) =>
            new ReviewRepository($c->get(Database::class))
        );
        $container->singleton(InquiryRepository::class, fn(DependencyContainer $c) =>
            new InquiryRepository($c->get(Database::class))
        );
        $container->singleton(WishlistRepository::class, fn(DependencyContainer $c) =>
            new WishlistRepository($c->get(Database::class))
        );
        $container->singleton(PointLogRepository::class, fn(DependencyContainer $c) =>
            new PointLogRepository($c->get(Database::class))
        );
        $container->singleton(ExhibitionRepository::class, fn(DependencyContainer $c) =>
            new ExhibitionRepository($c->get(Database::class))
        );
        $container->singleton(ShipmentRepository::class, fn(DependencyContainer $c) =>
            new ShipmentRepository($c->get(Database::class))
        );

        // ── Service ──
        $container->singleton(PriceCalculator::class, fn() => new PriceCalculator());

        $container->singleton(ShopConfigService::class, fn(DependencyContainer $c) =>
            new ShopConfigService(
                $c->get(ShopConfigRepository::class)
            )
        );
        $container->singleton(ShippingFeeCalculator::class, fn(DependencyContainer $c) =>
            new ShippingFeeCalculator(
                $c->get(ShippingRepository::class),
                $c->get(ShopConfigService::class),
                $c->get(PriceCalculator::class)
            )
        );
        $container->singleton(OrderStateResolver::class, fn(DependencyContainer $c) =>
            new OrderStateResolver(
                $c->get(ShopConfigService::class)
            )
        );
        $container->singleton(ClaimStateMachine::class, fn() => new ClaimStateMachine());
        $container->singleton(ActionTypeRegistry::class, function (DependencyContainer $c) {
            $registry = new ActionTypeRegistry();
            $balanceManager = $c->get(BalanceGatewayInterface::class);
            $logger = $c->has(\Mublo\Infrastructure\Log\Logger::class) ? $c->get(\Mublo\Infrastructure\Log\Logger::class) : null;
            $uiHelper = $c->get(\Mublo\Contract\Notification\NotificationTemplateContextInterface::class);
            $registry->register(new NotificationActionHandler(
                $c->get(ContractRegistry::class),
                $logger,
                $c->get(ShipmentService::class),
                $uiHelper,
                fn() => $c->has(Context::class) ? $c->get(Context::class)->getDomainId() : null,
                $c->get(\Mublo\Contract\Notification\NotificationChannelTreeBuilderInterface::class)
            ));
            $registry->register(new PointActionHandler($balanceManager, $logger));
            $registry->register(new PointDeductActionHandler($balanceManager, $logger));
            $registry->register(new OrderConfirmActionHandler($c->get(OrderRepository::class), $logger));
            $orderRepo = $c->get(OrderRepository::class);
            $productRepo = $c->get(ProductRepository::class);
            $optionRepo = $c->get(ProductOptionRepository::class);
            $registry->register(new StockDeductActionHandler($orderRepo, $productRepo, $optionRepo));
            $registry->register(new StockRestoreActionHandler($orderRepo, $productRepo, $optionRepo));
            $registry->register(new WebhookActionHandler($logger));
            return $registry;
        });
        $container->singleton(ActionExecutionService::class, fn(DependencyContainer $c) =>
            new ActionExecutionService(
                $c->get(ActionExecutionRepository::class),
                $c->get(ActionTypeRegistry::class),
                $c->get(OrderRepository::class),
                $c->get(SensitiveValueCodecInterface::class),
                $c->get(\Mublo\Infrastructure\Cache\CacheInterface::class),
                $c->has(\Mublo\Infrastructure\Log\Logger::class) ? $c->get(\Mublo\Infrastructure\Log\Logger::class) : null,
            )
        );
        $container->singleton(CategoryService::class, fn(DependencyContainer $c) =>
            new CategoryService(
                $c->get(CategoryRepository::class),
                $c->get(EventDispatcher::class)
            )
        );
        $container->singleton(ProductService::class, fn(DependencyContainer $c) =>
            new ProductService(
                $c->get(ProductRepository::class),
                $c->get(ProductOptionRepository::class),
                $c->get(CategoryRepository::class),
                $c->get(PriceCalculator::class),
                $c->get(EventDispatcher::class)
            )
        );
        $container->singleton(OptionPresetService::class, fn(DependencyContainer $c) =>
            new OptionPresetService(
                $c->get(OptionPresetRepository::class),
                $c->get(ProductOptionRepository::class),
                $c->get(EventDispatcher::class),
                $c->get(ProductRepository::class)
            )
        );
        $container->singleton(MemberLevelResolver::class, fn(DependencyContainer $c) =>
            new MemberLevelResolver(
                $c->get(MemberQueryInterface::class),
                $c->get(MemberLevelCatalogInterface::class)
            )
        );
        $container->singleton(DirectBuyService::class, fn(DependencyContainer $c) =>
            new DirectBuyService(
                $c->get(ProductRepository::class),
                $c->get(PriceCalculator::class),
                $c->get(ShippingFeeCalculator::class),
                $c->get(\Mublo\Core\Session\SessionInterface::class),
                $c->get(ShopConfigService::class),
                $c->get(MemberLevelResolver::class)
            )
        );
        $container->singleton(CartCheckoutService::class, fn(DependencyContainer $c) =>
            new CartCheckoutService(
                $c->get(CartRepository::class),
                $c->get(ProductRepository::class),
                $c->get(PriceCalculator::class),
                $c->get(ShippingFeeCalculator::class),
                $c->get(ProductOptionRepository::class),
                $c->get(\Mublo\Core\Session\SessionInterface::class),
                $c->get(ShopConfigService::class),
                $c->get(MemberLevelResolver::class)
            )
        );
        $container->singleton(CartService::class, fn(DependencyContainer $c) =>
            new CartService(
                $c->get(CartRepository::class),
                $c->get(ProductRepository::class),
                $c->get(ProductOptionRepository::class),
                $c->get(PriceCalculator::class),
                $c->get(ShippingFeeCalculator::class),
                $c->get(DirectBuyService::class),
                $c->get(\Mublo\Core\Session\SessionInterface::class),
                $c->get(ShopConfigService::class),
                $c->get(MemberLevelResolver::class)
            )
        );
        $container->singleton(OrderService::class, fn(DependencyContainer $c) =>
            new OrderService(
                $c->get(OrderRepository::class),
                $c->get(CartRepository::class),
                $c->get(ProductRepository::class),
                $c->get(ProductOptionRepository::class),
                $c->get(PriceCalculator::class),
                $c->get(OrderStateResolver::class),
                $c->get(EventDispatcher::class),
                $c->get(SensitiveValueCodecInterface::class),
                $c->get(\Mublo\Infrastructure\Cache\CacheInterface::class),
                $c->get(ShopConfigService::class),
                $c->get(CouponService::class),
                $c->get(MemberLevelResolver::class)
            )
        );
        $container->singleton(OrderPointService::class, fn(DependencyContainer $c) =>
            new OrderPointService(
                $c->get(BalanceGatewayInterface::class),
                $c->get(ShopConfigService::class),
                $c->get(MemberLevelCatalogInterface::class),
                $c->has(\Mublo\Infrastructure\Log\Logger::class) ? $c->get(\Mublo\Infrastructure\Log\Logger::class) : null
            )
        );
        $container->singleton(ShippingService::class, fn(DependencyContainer $c) =>
            new ShippingService(
                $c->get(ShippingRepository::class)
            )
        );
        $container->singleton(CouponService::class, fn(DependencyContainer $c) =>
            new CouponService(
                $c->get(CouponRepository::class),
                $c->get(OrderRepository::class),
                $c->get(EventDispatcher::class)
            )
        );
        $container->singleton(PaymentCompletionService::class, fn(DependencyContainer $c) =>
            new PaymentCompletionService(
                $c->get(PaymentCompletionRepository::class),
                $c->get(PaymentTransactionRepository::class),
                $c->get(OrderRepository::class),
                $c->get(CartRepository::class),
                $c->get(CouponService::class),
                $c->get(OrderPointService::class),
                $c->get(EventDispatcher::class)
            )
        );
        $container->singleton(PaymentService::class, fn(DependencyContainer $c) =>
            new PaymentService(
                $c->get(ContractRegistry::class),
                $c->get(OrderRepository::class),
                $c->get(OrderService::class),
                $c->get(PriceCalculator::class),
                $c->get(PaymentCompletionService::class),
                $c->get(EventDispatcher::class)
            )
        );
        $container->singleton(PaymentReceiptService::class, fn(DependencyContainer $c) =>
            new PaymentReceiptService($c->get(PaymentTransactionRepository::class))
        );
        $container->singleton(MemberAddressService::class, fn(DependencyContainer $c) =>
            new MemberAddressService(
                $c->get(MemberAddressRepository::class),
                $c->get(SensitiveValueCodecInterface::class)
            )
        );
        $container->singleton(OrderFieldService::class, fn(DependencyContainer $c) =>
            new OrderFieldService(
                $c->get(OrderFieldRepository::class),
                $c->get(SensitiveValueCodecInterface::class),
                $c->get(CustomFieldValueValidatorInterface::class),
                $c->has(CustomFieldFileManagerInterface::class)
                    ? $c->get(CustomFieldFileManagerInterface::class)
                    : null
            )
        );
        $container->singleton(RefundService::class, fn(DependencyContainer $c) =>
            new RefundService(
                $c->get(PaymentService::class),
                $c->get(PaymentTransactionRepository::class),
                $c->get(OrderRepository::class),
                $c->get(OrderStateResolver::class),
                $c->get(EventDispatcher::class),
                $c->get(BalanceGatewayInterface::class)
            )
        );
        $container->singleton(OrderCancelService::class, fn(DependencyContainer $c) =>
            new OrderCancelService(
                $c->get(OrderRepository::class),
                $c->get(OrderService::class),
                $c->get(RefundService::class)
            )
        );
        $container->singleton(OrderMemoService::class, fn(DependencyContainer $c) =>
            new OrderMemoService(
                $c->get(OrderMemoRepository::class),
                $c->get(OrderRepository::class)
            )
        );
        $container->singleton(ProductInfoTemplateService::class, fn(DependencyContainer $c) =>
            new ProductInfoTemplateService(
                $c->get(ProductInfoTemplateRepository::class)
            )
        );
        $container->singleton(ReviewService::class, fn(DependencyContainer $c) =>
            new ReviewService(
                $c->get(ReviewRepository::class)
            )
        );
        $container->singleton(InquiryService::class, fn(DependencyContainer $c) =>
            new InquiryService(
                $c->get(InquiryRepository::class),
                $c->get(ProductRepository::class)
            )
        );
        $container->singleton(WishlistService::class, fn(DependencyContainer $c) =>
            new WishlistService(
                $c->get(WishlistRepository::class),
                $c->get(ProductRepository::class)
            )
        );
        $container->singleton(PointLogService::class, fn(DependencyContainer $c) =>
            new PointLogService(
                $c->get(PointLogRepository::class)
            )
        );
        $container->singleton(DashboardService::class, fn(DependencyContainer $c) =>
            new DashboardService(
                $c->get(OrderRepository::class),
                $c->get(ProductRepository::class)
            )
        );
        $container->singleton(ExhibitionService::class, fn(DependencyContainer $c) =>
            new ExhibitionService(
                $c->get(ExhibitionRepository::class),
                $c->get(ProductRepository::class),
                $c->get(CategoryRepository::class),
                $c->get(EventDispatcher::class)
            )
        );
        $container->singleton(ShipmentGroupResolver::class, fn() => new ShipmentGroupResolver());
        $container->singleton(ShipmentService::class, fn(DependencyContainer $c) =>
            new ShipmentService(
                $c->get(ShipmentRepository::class),
                $c->get(OrderRepository::class),
                $c->get(EventDispatcher::class),
                $c->get(ShipmentGroupResolver::class)
            )
        );
        $container->singleton(ExchangeStockService::class, fn(DependencyContainer $c) =>
            new ExchangeStockService(
                $c->get(ClaimRepository::class),
                $c->get(ProductRepository::class),
                $c->get(ProductOptionRepository::class)
            )
        );
        $container->singleton(ExchangeService::class, fn(DependencyContainer $c) =>
            new ExchangeService(
                $c->get(ClaimRepository::class),
                $c->get(OrderRepository::class),
                $c->get(ProductOptionRepository::class),
                $c->get(OrderStateResolver::class),
                $c->get(ClaimStateMachine::class),
                $c->get(ExchangeStockService::class),
                $c->get(ShipmentService::class),
                $c->get(SensitiveValueCodecInterface::class),
                $c->get(EventDispatcher::class)
            )
        );

        // ── Public Extension API ──
        // Shop 종속 Plugin은 내부 Service/Repository/Entity 대신 이 Contract만 사용한다.
        $container->singleton(ShopProductReaderInterface::class, fn(DependencyContainer $c) =>
            new ShopProductReader($c->get(ProductRepository::class))
        );
        $container->singleton(ShopOrderReaderInterface::class, fn(DependencyContainer $c) =>
            new ShopOrderReader($c->get(OrderRepository::class))
        );
        $container->singleton(ShopCommandInterface::class, fn(DependencyContainer $c) =>
            new ShopCommand(
                $c->get(ProductService::class),
                $c->get(OrderService::class),
                $c->get(OrderRepository::class)
            )
        );
        $container->singleton(ShopExtensionApiInterface::class, fn(DependencyContainer $c) =>
            new ShopExtensionApi(
                $c->get(ShopProductReaderInterface::class),
                $c->get(ShopOrderReaderInterface::class),
                $c->get(ShopCommandInterface::class)
            )
        );

        // ── Controller (Admin) ──
        $container->singleton(ShopConfigController::class, fn(DependencyContainer $c) =>
            new ShopConfigController(
                $c->get(ShopConfigService::class),
                $c->get(\Mublo\Core\Extension\MigrationRunner::class),
                $c->get(ContractRegistry::class),
                $c->get(ShippingService::class),
                $c->get(OrderFieldService::class),
                $c->get(PolicyQueryInterface::class),
                $c->get(ProductInfoTemplateService::class),
                $c->get(MemberLevelCatalogInterface::class)
            )
        );
        $container->singleton(CategoryController::class, fn(DependencyContainer $c) =>
            new CategoryController(
                $c->get(CategoryService::class),
                $c->get(MemberLevelCatalogInterface::class),
                $c->get(SiteProvisioningInterface::class),
                $c->get(MenuManagementInterface::class)
            )
        );
        $container->singleton(AdminProductController::class, fn(DependencyContainer $c) =>
            new AdminProductController(
                $c->get(ProductService::class),
                $c->get(CategoryService::class),
                $c->get(OptionPresetService::class),
                $c->get(ShippingService::class),
                $c->get(FileUploader::class),
                $c->get(ShopConfigService::class),
                $c->get(MemberLevelCatalogInterface::class)
            )
        );
        $container->singleton(OptionPresetController::class, fn(DependencyContainer $c) =>
            new OptionPresetController($c->get(OptionPresetService::class))
        );
        $container->singleton(AdminOrderController::class, fn(DependencyContainer $c) =>
            new AdminOrderController(
                $c->get(OrderService::class),
                $c->get(OrderFieldService::class),
                $c->get(OrderStateResolver::class),
                $c->get(RefundService::class),
                $c->get(OrderMemoService::class),
                $c->get(AuthContextInterface::class),
                $c->get(ShipmentService::class)
            )
        );
        $container->singleton(ExchangeController::class, fn(DependencyContainer $c) =>
            new ExchangeController(
                $c->get(ExchangeService::class),
                $c->get(ShipmentService::class),
                $c->get(AuthContextInterface::class),
                $c->get(ShopConfigService::class),
                $c->get(ActionTypeRegistry::class)
            )
        );
        $container->singleton(CouponController::class, fn(DependencyContainer $c) =>
            new CouponController($c->get(CouponService::class), $c->get(ProductService::class))
        );
        $container->singleton(ShippingTemplateController::class, fn(DependencyContainer $c) =>
            new ShippingTemplateController($c->get(ShippingService::class), $c->get(ShopConfigService::class))
        );
        $container->singleton(OrderStateController::class, fn(DependencyContainer $c) =>
            new OrderStateController(
                $c->get(ShopConfigService::class),
                $c->get(OrderStateResolver::class),
                $c->get(ActionTypeRegistry::class)
            )
        );
        $container->singleton(OrderFieldController::class, fn(DependencyContainer $c) =>
            new OrderFieldController($c->get(OrderFieldService::class))
        );
        $container->singleton(ProductInfoTemplateController::class, fn(DependencyContainer $c) =>
            new ProductInfoTemplateController(
                $c->get(ProductInfoTemplateService::class),
                $c->get(CategoryService::class)
            )
        );
        $container->singleton(AdminReviewController::class, fn(DependencyContainer $c) =>
            new AdminReviewController(
                $c->get(ReviewService::class),
                $c->get(FileUploader::class),
                $c->get(OrderService::class)
            )
        );
        $container->singleton(InquiryController::class, fn(DependencyContainer $c) =>
            new InquiryController(
                $c->get(AuthContextInterface::class),
                $c->get(InquiryService::class)
            )
        );
        $container->singleton(DashboardController::class, fn(DependencyContainer $c) =>
            new DashboardController(
                $c->get(DashboardService::class)
            )
        );
        $container->singleton(AdminExhibitionController::class, fn(DependencyContainer $c) =>
            new AdminExhibitionController(
                $c->get(ExhibitionService::class),
                $c->get(ProductService::class),
                $c->get(FileUploader::class),
                $c->get(SiteProvisioningInterface::class),
                $c->get(MenuManagementInterface::class)
            )
        );
        $container->singleton(AdminWishlistController::class, fn(DependencyContainer $c) =>
            new AdminWishlistController(
                $c->get(WishlistService::class)
            )
        );

        // ── Controller (Front) ──
        $container->singleton(FrontProductController::class, fn(DependencyContainer $c) =>
            new FrontProductController(
                $c->get(ProductService::class),
                $c->get(CategoryProviderRegistry::class),
                $c->get(ShopConfigService::class),
                $c->get(ReviewService::class),
                $c->get(InquiryService::class),
                $c->get(ProductInfoTemplateService::class),
                $c->get(WishlistService::class),
                $c->get(AuthContextInterface::class),
                $c->get(MemberLevelResolver::class)
            )
        );
        $container->singleton(CartController::class, fn(DependencyContainer $c) =>
            new CartController(
                $c->get(CartService::class),
                $c->get(CartCheckoutService::class),
                $c->get(DirectBuyService::class),
                $c->get(OrderService::class),
                $c->get(PaymentService::class),
                $c->get(AuthContextInterface::class),
                $c->get(MemberAddressService::class),
                $c->get(ShopConfigService::class),
                $c->get(OrderFieldService::class),
                $c->get(\Mublo\Core\Session\SessionInterface::class),
                $c->get(OrderPointService::class),
                $c->get(CouponService::class),
                $c->get(PolicyQueryInterface::class)
            )
        );
        $container->singleton(FrontOrderController::class, fn(DependencyContainer $c) =>
            new FrontOrderController(
                $c->get(OrderService::class),
                $c->get(AuthContextInterface::class),
                $c->get(OrderFieldService::class),
                $c->get(OrderStateResolver::class),
                $c->get(ReviewService::class),
                $c->get(\Mublo\Core\Session\SessionInterface::class),
                $c->get(ShopConfigService::class),
                $c->get(\Mublo\Infrastructure\Cache\CacheInterface::class),
                $c->get(PaymentReceiptService::class),
                $c->get(OrderCancelService::class),
                $c->get(ShipmentService::class),
                $c->get(ExchangeService::class)
            )
        );
        $container->singleton(AddressController::class, fn(DependencyContainer $c) =>
            new AddressController(
                $c->get(MemberAddressService::class),
                $c->get(AuthContextInterface::class)
            )
        );
        $container->singleton(FrontCouponController::class, fn(DependencyContainer $c) =>
            new FrontCouponController(
                $c->get(CouponService::class),
                $c->get(AuthContextInterface::class),
                $c->get(ShopConfigService::class)
            )
        );
        $container->singleton(WishlistController::class, fn(DependencyContainer $c) =>
            new WishlistController(
                $c->get(WishlistService::class),
                $c->get(AuthContextInterface::class),
                $c->get(ShopConfigService::class)
            )
        );
        $container->singleton(FrontReviewController::class, fn(DependencyContainer $c) =>
            new FrontReviewController(
                $c->get(ReviewService::class),
                $c->get(OrderService::class),
                $c->get(OrderStateResolver::class),
                $c->get(AuthContextInterface::class),
                $c->get(FileUploader::class),
                $c->get(ShopConfigService::class),
                $c->get(\Mublo\Core\Session\SessionInterface::class)
            )
        );
        $container->singleton(FrontInquiryController::class, fn(DependencyContainer $c) =>
            new FrontInquiryController(
                $c->get(InquiryService::class),
                $c->get(AuthContextInterface::class),
                $c->get(ShopConfigService::class)
            )
        );
        $container->singleton(FrontExhibitionController::class, fn(DependencyContainer $c) =>
            new FrontExhibitionController(
                $c->get(ExhibitionService::class),
                $c->get(ShopConfigService::class),
                $c->get(ReviewService::class),
                $c->get(WishlistService::class),
                $c->get(ProductService::class),
                $c->get(AuthContextInterface::class),
                $c->get(MemberLevelResolver::class)
            )
        );

        // ── 가격비교 ──
        //
        // 상품 조회는 사이트맵과 같은 공개 판정을 쓰고(PublicProductCriteria),
        // 채널 구현은 ContractRegistry 에서 온다. 여기서는 조립층만 배선한다.
        $container->singleton(PublicProductCriteria::class, fn(DependencyContainer $c) =>
            new PublicProductCriteria($c->get(Database::class))
        );
        $container->singleton(PriceCompareChannelRepository::class, fn(DependencyContainer $c) =>
            new PriceCompareChannelRepository($c->get(Database::class))
        );
        $container->singleton(TsvWriter::class, fn() => new TsvWriter());
        $container->singleton(RssWriter::class, fn() => new RssWriter());
        $container->singleton(ProductFeedQuery::class, fn(DependencyContainer $c) =>
            new ProductFeedQuery(
                $c->get(Database::class),
                $c->get(PublicProductCriteria::class)
            )
        );
        $container->singleton(PriceCompareService::class, fn(DependencyContainer $c) =>
            new PriceCompareService(
                $c->get(ContractRegistry::class),
                $c->get(ProductFeedQuery::class),
                $c->get(TsvWriter::class),
                $c->get(RssWriter::class),
                $c->get(PriceCompareChannelRepository::class)
            )
        );
        $container->singleton(PriceCompareController::class, fn(DependencyContainer $c) =>
            new PriceCompareController(
                $c->get(PriceCompareService::class)
            )
        );
        $container->singleton(AdminPriceCompareController::class, fn(DependencyContainer $c) =>
            new AdminPriceCompareController(
                $c->get(PriceCompareService::class)
            )
        );

        // ── Block ──
        $container->singleton(ProductRenderer::class, function (DependencyContainer $c) {
            $renderer = new ProductRenderer(
                $c->get(ProductRepository::class),
                $c->get(ShopConfigService::class),
                $c->get(AuthContextInterface::class),
                $c->get(MemberLevelResolver::class)
            );
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });
        $container->singleton(ProductConfigForm::class, fn() => new ProductConfigForm());

        $container->singleton(ProductAutoRenderer::class, function (DependencyContainer $c) {
            $renderer = new ProductAutoRenderer(
                $c->get(ProductRepository::class),
                $c->get(CategoryRepository::class),
                $c->get(ShopConfigService::class),
                $c->get(AuthContextInterface::class),
                $c->get(MemberLevelResolver::class)
            );
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });

        $container->singleton(ReviewAutoRenderer::class, function (DependencyContainer $c) {
            $renderer = new ReviewAutoRenderer(
                $c->get(ReviewRepository::class)
            );
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });

        // 결제 게이트웨이는 결제 플러그인(TestPay/TossPay 등)이 스스로
        // ContractRegistry에 등록한다. Shop 패키지는 게이트웨이를 직접 갖지 않는다.

        // 반대 방향 — PG 콜백이 결과를 되돌려줄 소비자 계약. 플러그인이 Shop 서비스를
        // 직접 붙잡지 않도록 계약 뒤에 둔다(PaymentConsumerInterface).
        $container->singleton(ShopPaymentConsumer::class, fn(DependencyContainer $c) =>
            new ShopPaymentConsumer(
                $c->get(PaymentService::class),
                $c->get(RefundService::class),
                $c->get(OrderRepository::class),
                $c->get(PriceCalculator::class)
            )
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        // PG 플러그인 콜백이 이 키('shop')로 조회해 결제 결과를 되돌려준다.
        // 키는 결제 준비 때 orderData['consumer'] 로 실려 나갔다가 콜백으로 되돌아온다.
        try {
            $container->get(ContractRegistry::class)->register(
                PaymentConsumerInterface::class,
                'shop',
                fn() => $container->get(ShopPaymentConsumer::class),
                ['label' => '쇼핑몰']
            );
        } catch (\Throwable $e) {
            // 등록 실패로 패키지 부팅 전체를 무너뜨리지 않는다(중복 키 등).
            // 결제 콜백만 소비자를 찾지 못하게 되며, 그때는 결과를 반영하지 않는다.
            error_log('[SHOP] 결제 소비자 계약 등록 실패: ' . $e->getMessage());
        }

        $this->dataResetter = $container->get(ShopDataResetter::class);
        // Context 속성 설정 (checkout 감지 등)
        $this->enrichContext($container, $context);

        // Shop 관리자 페이지 전용 CSS 전역 로드 (싱글턴 AssetManager → admin Head의 renderCss)
        if (str_starts_with($context->getRequest()->getPath(), '/admin/shop')) {
            $cssFile = __DIR__ . '/assets/css/admin.css';
            $ver = is_file($cssFile) ? '?v=' . filemtime($cssFile) : '';
            $container->get(AssetManager::class)->addCss('/serve/package/Shop/assets/css/admin.css' . $ver);
        }

        // 카테고리 Provider 등록
        $container->get(CategoryProviderRegistry::class)->register(
            'shop',
            fn() => new ShopCategoryProvider($container->get(CategoryService::class))
        );

        // 사이트맵 URL Provider 등록 (코어 SitemapController가 소비)
        // Closure = 지연 생성. 패키지가 비활성이면 boot 자체가 돌지 않아 URL도 사라진다.
        $container->get(ContractRegistry::class)->register(
            SitemapUrlProviderInterface::class,
            'shop',
            fn() => new ShopSitemapProvider(
                $container->get(Database::class)
            )
        );

        // 가격비교 채널 등록 (기본 제공)
        //
        // 키 이름 공간이 /shop/price-compare/ 아래로 좁혀져 있어 채널사 이름을 그대로
        // 쓴다. 다만 같은 키로 등록하면 ContractRegistry 가 중복 예외를 던져 부팅이
        // 깨지므로, 자체 구현이 이미 잡은 키에서는 기본 채널이 물러난다.
        //
        // 사용 여부는 여기서 보지 않는다. 부팅에서 설정을 읽으면 피드와 무관한 모든
        // 요청에 쿼리가 붙는다(shop_config 는 캐시되지 않는다). 꺼진 채널은 서빙
        // 시점에 걸러진다 — PriceCompareService::render.
        foreach (BuiltinChannels::all() as $channel) {
            $code = $channel->code();
            try {
                if ($container->get(ContractRegistry::class)
                        ->hasKey(PriceCompareChannelInterface::class, $code)) {
                    continue;
                }

                $container->get(ContractRegistry::class)->register(
                    PriceCompareChannelInterface::class,
                    $code,
                    fn() => $channel,
                    ['label' => $channel->label()]
                );
            } catch (\Throwable $e) {
                // 채널 하나가 등록되지 않아도 나머지 피드와 쇼핑몰은 계속 동작한다.
                error_log("[SHOP] 가격비교 채널 '{$code}' 등록 실패: " . $e->getMessage());
            }
        }

        // 주문 엑셀/CSV 리포트 정의 등록 (코어 Report 프레임워크)
        $container->get(\Mublo\Core\Report\Engine\ReportDefinitionRegistry::class)->register(
            new \Mublo\Packages\Shop\Report\ShopOrderReportDefinition(
                $container->get(OrderService::class),
                $container->get(OrderStateResolver::class)
            )
        );

        $eventDispatcher = $container->get(EventDispatcher::class);

        // 관리자 메뉴 등록
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        // 로그인 폼 확장 (비회원 주문 버튼)
        $eventDispatcher->addSubscriber(new LoginFormSubscriber(
            $container->get(\Mublo\Core\Rendering\FrontViewRuntime::class),
            $container->get(ShopConfigService::class),
            $container->get(\Mublo\Core\Rendering\AssetManager::class)
        ));

        // 알림 변수 등록 (어떤 알림 플러그인이든 Contract 이벤트로 수신)
        $eventDispatcher->addSubscriber(new NotificationVariableSubscriber());

        // 프레임 템플릿 변수 등록 (도메인 프레임 편집 — {{shop.cart_count}})
        $eventDispatcher->addSubscriber(new FrameTemplateSubscriber(
            $container->get(CartService::class),
            $container->get(AuthContextInterface::class)
        ));

        // 상태별 액션 실행 (Config 기반)
        $eventDispatcher->addSubscriber(new ConfigurableActionSubscriber(
            $container->get(ShopConfigService::class),
            $container->get(ActionTypeRegistry::class),
            $container->has(\Mublo\Infrastructure\Log\Logger::class) ? $container->get(\Mublo\Infrastructure\Log\Logger::class) : null,
            $container->get(ActionExecutionService::class),
        ));

        // 별도 데몬이 없는 FTP/공유호스팅에서도 다음 요청이 실패 Action을 조금씩 재처리한다.
        $domainId = $context->getDomainId();
        if ($domainId !== null) {
            try {
                $container->get(ActionExecutionService::class)->maybeRunDue($domainId);
            } catch (\Throwable $e) {
                if ($container->has(\Mublo\Infrastructure\Log\Logger::class)) {
                    $container->get(\Mublo\Infrastructure\Log\Logger::class)->warning(
                        'Shop Action 재시도 스윕 실패',
                        ['error' => $e->getMessage()]
                    );
                }
            }
        }

        $eventDispatcher->addSubscriber(new ConfigurableItemActionSubscriber(
            $container->get(ShopConfigService::class),
            $container->get(ActionTypeRegistry::class),
            $container->has(\Mublo\Infrastructure\Log\Logger::class) ? $container->get(\Mublo\Infrastructure\Log\Logger::class) : null
        ));

        // 송장이 주문상품 상태를 끌고 간다 (송장 등록 → 배송중, 배송완료 → 배송완료)
        $eventDispatcher->addSubscriber(new ShipmentItemStatusSubscriber(
            $container->get(OrderService::class),
            $container->get(OrderRepository::class),
            $container->get(ShipmentGroupResolver::class),
            $container->has(\Mublo\Infrastructure\Log\Logger::class)
                ? $container->get(\Mublo\Infrastructure\Log\Logger::class)
                : null,
        ));

        $eventDispatcher->addSubscriber(new ConfigurableClaimActionSubscriber(
            $container->get(ShopConfigService::class),
            $container->get(ActionTypeRegistry::class),
            $container->get(ActionExecutionService::class),
            $container->get(OrderRepository::class),
            $container->get(SensitiveValueCodecInterface::class),
            $container->has(\Mublo\Infrastructure\Log\Logger::class)
                ? $container->get(\Mublo\Infrastructure\Log\Logger::class)
                : null,
        ));

        // 주문 취소/환불 시 쿠폰 자동 복원
        $eventDispatcher->addSubscriber(new CouponRestoreSubscriber(
            $container->get(CouponService::class)
        ));

        // 주문 취소·반품 시 사용 포인트 복원
        $eventDispatcher->addSubscriber(new PointPaymentSubscriber(
            $container->get(OrderPointService::class)
        ));

        // 결제-상태 불일치(결제됨/전이실패) 시 관리자 메모 + critical 로그로 노출
        $eventDispatcher->addSubscriber(new PaymentMismatchSubscriber(
            $container->get(OrderMemoService::class),
            $container->has(\Mublo\Infrastructure\Log\Logger::class)
                ? $container->get(\Mublo\Infrastructure\Log\Logger::class)
                : null
        ));

        // 회원가입/등급변경 시 쿠폰 자동 발행
        $eventDispatcher->addSubscriber(new CouponAutoIssueSubscriber(
            $container->get(CouponService::class),
            $container->get(CouponRepository::class),
            $container->get(MemberQueryInterface::class)
        ));

        // 도메인 생성 시 프론트 메뉴 + 기본 배송 템플릿 자동 시딩
        $eventDispatcher->addSubscriber(new DomainEventSubscriber(
            $container->get(SiteProvisioningInterface::class),
            $container->get(MenuManagementInterface::class),
            $container->get(ShippingService::class),
            $container->get(ShopConfigService::class)
        ));

        // 기획전 생성/삭제 시 메뉴 아이템 자동 등록/삭제
        $eventDispatcher->addSubscriber(new ExhibitionMenuSubscriber(
            $container->get(SiteProvisioningInterface::class),
            $container->get(MenuManagementInterface::class)
        ));
        $eventDispatcher->addSubscriber(new CategoryMenuSubscriber(
            $container->get(MenuManagementInterface::class)
        ));

        // 상품 변경 시 진열 블록 캐시 무효화 (stale 가격/이미지 방지)
        $eventDispatcher->addSubscriber(new BlockCacheInvalidateSubscriber(
            $container->get(BlockContentCacheInvalidatorInterface::class)
        ));

        // 통합 검색에 상품 결과 포함
        $eventDispatcher->addSubscriber(new ShopSearchSubscriber(
            $container->get(ProductRepository::class)
        ));

        // 마이페이지 허브 등록 (/mypage/shop "마이쇼핑")
        $eventDispatcher->addSubscriber(new MypageSectionSubscriber(
            $container->get(OrderService::class),
            $container->get(OrderStateResolver::class),
            $container->get(CouponService::class),
            $container->get(WishlistService::class),
            $container->get(ReviewService::class),
            $container->get(InquiryService::class),
            $container->get(ShopConfigService::class)
        ));

        // 블록 콘텐츠 타입 등록
        BlockRegistry::registerContentType(
            type: 'product',
            kind: BlockContentKind::PACKAGE->value,
            title: '쇼핑몰 상품',
            rendererClass: ProductRenderer::class,
            configFormClass: ProductConfigForm::class,
            options: [
                'icon' => 'bi-bag',
                'capabilities' => BlockRegistry::capabilities(
                    skin: true, items: true, count: true, style: true, aos: true, customConfig: true,
                ),
                'skinBasePath' => MUBLO_PACKAGE_PATH . '/Shop/views/Block',
                'hasItems' => true,
                'hasStyle' => true,
                // 가격이 보는 회원의 등급에 따라 달라지므로 행 캐시에 담기면 안 된다.
                // 행 캐시 키는 rowId(+브랜드 variant)뿐이라 회원 차원이 없어서,
                // 먼저 들른 회원의 할인가 HTML 이 비회원에게까지 그대로 나간다.
                'noCache' => true,
                'adminScript' => '/serve/package/Shop/assets/js/block-product.js',
                'adminScriptInit' => 'MubloBlockProduct',
            ]
        );

        // 쇼핑몰 상품 자동 진열 (카테고리 + 정렬 기준 + 표시 개수로 자동 조회)
        BlockRegistry::registerContentType(
            type: 'product_auto',
            kind: BlockContentKind::PACKAGE->value,
            title: '쇼핑몰 상품 자동 진열',
            rendererClass: ProductAutoRenderer::class,
            options: [
                'icon' => 'bi-bag-check',
                'capabilities' => BlockRegistry::capabilities(
                    skin: true, items: false, count: false, style: false, aos: true, customConfig: true,
                ),
                'skinBasePath' => MUBLO_PACKAGE_PATH . '/Shop/views/Block',
                'hasItems' => true,
                'hasStyle' => true,
                'noCache' => true,
                'adminScript' => '/serve/package/Shop/assets/js/block-product-auto.js',
                'adminScriptInit' => 'MubloBlockProductAuto',
            ]
        );

        // 쇼핑몰 구매후기 (정렬 기준 + 표시 개수 + 포토/베스트 필터로 자동 조회)
        BlockRegistry::registerContentType(
            type: 'review_auto',
            kind: BlockContentKind::PACKAGE->value,
            title: '쇼핑몰 구매후기',
            rendererClass: ReviewAutoRenderer::class,
            options: [
                'icon' => 'bi-star',
                'capabilities' => BlockRegistry::capabilities(
                    skin: true, items: false, count: false, style: true, aos: true, customConfig: true,
                ),
                'skinBasePath' => MUBLO_PACKAGE_PATH . '/Shop/views/Block',
                'hasItems' => true,
                'hasStyle' => true,
                'noCache' => true,
                'adminScript' => '/serve/package/Shop/assets/js/block-review-auto.js',
                'adminScriptInit' => 'MubloBlockReviewAuto',
            ]
        );

    }

    // =========================================================================
    // InstallableExtensionInterface — 프론트 메뉴 등록/삭제
    // =========================================================================

    /**
     * 첫 설치 시 프론트 메뉴 "후보" 등록
     *
     * - provider_type='package', provider_name='Shop'으로 출처 추적
     * - 메뉴 아이템(후보)만 생성하고 배치(menu_tree/show_in_mypage)는 운영자가 수동
     * - 비활성화해도 메뉴를 지우지 않으므로(uninstall no-op), 재설치는 아래 guard로 중복 방지
     */
    public function install(DependencyContainer $container, Context $context): void
    {
        $menuItemRepo = $container->get(MenuManagementInterface::class);
        $domainId = $context->getDomainId();

        // 기본 배송 템플릿 시딩 (자체 멱등 — 메뉴 존재 여부와 무관하게 실행)
        DomainEventSubscriber::seedShippingTemplates(
            $container->get(ShippingService::class),
            $container->get(ShopConfigService::class),
            $domainId
        );

        // DomainCreatedEvent로 이미 시딩된 경우 중복 방지
        $existing = $menuItemRepo->findProviderMenus($domainId, 'package', 'Shop');
        if (!empty($existing)) {
            return;
        }

        DomainEventSubscriber::seedMenus(
            $container->get(SiteProvisioningInterface::class),
            $domainId
        );
    }

    /**
     * 비활성화 시 — 메뉴를 건드리지 않는다.
     *
     * 메뉴는 운영자가 관리하는 링크 모음집이며, 패키지 라이프사이클은 메뉴에
     * 관여하지 않는다(켜도 안 넣고, 꺼도 안 뺀다). 비활성 패키지의 잔여 메뉴
     * 링크 정리는 운영자 몫이다. DB 테이블/데이터도 보존한다.
     */
    public function uninstall(DependencyContainer $container, Context $context): void
    {
        // no-op
    }

    /**
     * Context 속성 설정
     *
     * 요청 URL/파라미터를 분석하여 Shop 관련 속성을 Context에 설정한다.
     * - active_package: Shop 영역이거나 checkout intent가 있을 때 'shop'
     * - shop.is_checkout: checkout 흐름일 때 true
     */
    public function getResetCategories(): array { return $this->dataResetter->getResetCategories(); }
    public function reset(string $category, int $domainId): DataResetResult { return $this->dataResetter->reset($category, $domainId); }
    public function resetFiles(string $category, int $domainId): int { return $this->dataResetter->resetFiles($category, $domainId); }

    private function enrichContext(DependencyContainer $container, Context $context): void
    {
        $request = $context->getRequest();
        $path = $request->getPath();

        $isShopArea = str_starts_with($path, '/shop/') || $path === '/shop';

        $intent = $request->get('intent', '');
        $redirect = $request->get('redirect', '');

        $isCheckout = (
            $intent === 'checkout'
            || str_contains($path, '/checkout')
            || str_contains($redirect, '/shop/checkout')
        );

        // 비회원 주문조회 흐름: 주문내역(/shop/orders)에서 로그인으로 튕긴 경우 등
        $isOrderLookup = (
            $intent === 'order_lookup'
            || str_contains($redirect, '/shop/orders')
        );

        if ($isShopArea || $isCheckout || $isOrderLookup) {
            $context->setAttribute('active_package', 'shop');
        }

        // 프레임 스킨 오버라이드 — shop 프론트 영역에만 적용
        if ($isShopArea) {
            $this->applyFrameOverride($context);
        }

        if ($isCheckout) {
            $context->setAttribute('shop.is_checkout', true);
        }

        // 체크아웃이 아닌 주문조회 의도일 때만 (체크아웃 CTA와 충돌 방지)
        if ($isOrderLookup && !$isCheckout) {
            $context->setAttribute('shop.is_order_lookup', true);
        }
    }

    /**
     * 프레임 스킨 오버라이드 적용.
     *
     * theme_config.frame_overrides.package.shop 에 선택된 스킨이 있고
     * Shop 프레임 디렉터리에 실제 존재하면 그 프레임으로 교체한다.
     * 없으면 코어 프레임 유지 (안전 폴백).
     */
    private function applyFrameOverride(Context $context): void
    {
        $themeConfig = $context->getDomainInfo()?->getThemeConfig() ?? [];
        $skin = \Mublo\Core\Theme\FrameOverride::resolve($themeConfig, 'shop');
        if ($skin === null) {
            return;
        }

        $skinDir = MUBLO_PACKAGE_PATH . '/Shop/views/Front/frame/' . $skin;
        if (!is_dir($skinDir)) {
            return; // 스킨 폴더 없으면 코어 프레임
        }

        // 코어 frameSkin 은 폴백용으로 보존, 베이스만 패키지 스킨 디렉터리로 지정.
        $context->setFrameBasePath($skinDir);
    }
}
