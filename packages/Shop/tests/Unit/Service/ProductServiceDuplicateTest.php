<?php
/**
 * packages/Shop/tests/Unit/Service/ProductServiceDuplicateTest.php
 *
 * ProductService::duplicate() — 상품 복사
 *
 * 실제 Repository 를 SQLite 메모리 DB 위에서 돌려, 하위 데이터가 새 상품으로
 * 옮겨지고 옵션 값이 새 옵션에 다시 이어지는지 확인한다.
 */

namespace Tests\Shop\Unit\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Shop\Event\ProductChangedEvent;
use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ProductService;
use PDO;
use PHPUnit\Framework\TestCase;

class ProductServiceDuplicateTest extends TestCase
{
    private PDO $pdo;
    private ProductService $service;
    /** @var list<ProductChangedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();

        $db = new Database($this->pdo);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ProductChangedEvent::class, function (ProductChangedEvent $e): void {
            $this->events[] = $e;
        });

        $this->service = new ProductService(
            new ProductRepository($db),
            new ProductOptionRepository($db),
            $this->createMock(CategoryRepository::class),
            $this->createMock(PriceCalculator::class),
            $dispatcher
        );
    }

    public function testDuplicatesProductAndChildGraph(): void
    {
        $this->seedSource();

        $result = $this->service->duplicate(1, 10);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $newId = (int) $result->get('goods_id');
        $this->assertGreaterThan(1, $newId);

        // 본체
        $copy = $this->row('SELECT * FROM shop_products WHERE goods_id = ?', [$newId]);
        $this->assertSame(10, (int) $copy['domain_id']);
        $this->assertSame('티셔츠 (복사)', $copy['goods_name']);
        $this->assertSame('티셔츠-복사', $copy['goods_slug']);
        $this->assertNotSame('ITEM-SRC', $copy['item_code']);
        $this->assertMatchesRegularExpression('/^G-\d{8}-\d{4}$/', $copy['item_code']);
        $this->assertSame(0, (int) $copy['hit']);
        $this->assertSame(0, (int) $copy['is_active']);
        $this->assertSame(15000, (int) $copy['display_price']);
        $this->assertSame('CAT01', $copy['category_code']);

        // 이미지 — 같은 파일 경로를 가리킨다
        $images = $this->rows('SELECT image_url, is_main, sort_order FROM shop_product_images WHERE goods_id = ? ORDER BY sort_order', [$newId]);
        $this->assertSame(['/storage/D10/shop/a.jpg', '/storage/D10/shop/b.jpg'], array_column($images, 'image_url'));
        $this->assertSame(1, (int) $images[0]['is_main']);

        // 옵션 → 값: 새 옵션 ID 로 다시 이어진다
        $options = $this->rows('SELECT * FROM shop_product_options WHERE goods_id = ? ORDER BY sort_order', [$newId]);
        $this->assertSame(['색상', '사이즈'], array_column($options, 'option_name'));
        $this->assertNotContains(100, array_map('intval', array_column($options, 'option_id')));

        $colorValues = $this->rows('SELECT value_name, extra_price FROM shop_product_option_values WHERE option_id = ? ORDER BY sort_order', [(int) $options[0]['option_id']]);
        $this->assertSame(['빨강', '파랑'], array_column($colorValues, 'value_name'));
        $this->assertSame(500, (int) $colorValues[1]['extra_price']);
        $sizeValues = $this->rows('SELECT value_name FROM shop_product_option_values WHERE option_id = ?', [(int) $options[1]['option_id']]);
        $this->assertSame(['L'], array_column($sizeValues, 'value_name'));

        // 원본 옵션 값은 그대로 (원본 옵션에 남아 있다)
        $this->assertSame(3, (int) $this->row('SELECT COUNT(*) c FROM shop_product_option_values WHERE option_id IN (100, 101)')['c']);

        // 조합
        $combos = $this->rows('SELECT combination_key, stock_quantity FROM shop_product_option_combos WHERE goods_id = ?', [$newId]);
        $this->assertSame([['combination_key' => '빨강/L', 'stock_quantity' => 3]], array_map(
            fn($r) => ['combination_key' => $r['combination_key'], 'stock_quantity' => (int) $r['stock_quantity']],
            $combos
        ));

        // 상세
        $details = $this->rows('SELECT detail_value FROM shop_product_details WHERE goods_id = ?', [$newId]);
        $this->assertSame(['<p>상세 <img src="/storage/D10/shop/editor/x.jpg"></p>'], array_column($details, 'detail_value'));

        // 상품정보제공고시 — 같은 양식 버전 + 값
        $notice = $this->row('SELECT * FROM shop_product_notices WHERE goods_id = ?', [$newId]);
        $this->assertSame(5, (int) $notice['template_id']);
        $values = $this->rows('SELECT field_code, field_value FROM shop_product_notice_values WHERE product_notice_id = ?', [(int) $notice['product_notice_id']]);
        $this->assertSame([['field_code' => 'material', 'field_value' => '면 100%']], $values);

        // 원본은 그대로
        $this->assertSame(2, (int) $this->row('SELECT COUNT(*) c FROM shop_product_images WHERE goods_id = 1')['c']);

        // 생성 이벤트
        $this->assertCount(1, $this->events);
        $this->assertSame(ProductChangedEvent::CREATED, $this->events[0]->getChangeType());
        $this->assertSame([$newId], $this->events[0]->getGoodsIds());
        $this->assertSame(10, $this->events[0]->getDomainId());
    }

    public function testOtherDomainProductCannotBeDuplicated(): void
    {
        $this->seedSource();

        $result = $this->service->duplicate(1, 99);

        $this->assertTrue($result->isFailure());
        $this->assertSame(1, (int) $this->row('SELECT COUNT(*) c FROM shop_products')['c']);
        $this->assertSame([], $this->events);
    }

    public function testFailureRollsBackEverything(): void
    {
        $this->seedSource();
        // 상세 테이블을 없애 중간 단계에서 실패시킨다
        $this->pdo->exec('DROP TABLE shop_product_details');

        $result = $this->service->duplicate(1, 10);

        $this->assertTrue($result->isFailure());
        $this->assertSame(1, (int) $this->row('SELECT COUNT(*) c FROM shop_products')['c']);
        $this->assertSame(2, (int) $this->row('SELECT COUNT(*) c FROM shop_product_images')['c']);
        $this->assertSame(2, (int) $this->row('SELECT COUNT(*) c FROM shop_product_options')['c']);
        $this->assertSame([], $this->events);
    }

    public function testLongNameIsCutToFitSuffix(): void
    {
        $this->seedSource(str_repeat('가', 255));

        $result = $this->service->duplicate(1, 10);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $name = $this->row('SELECT goods_name FROM shop_products WHERE goods_id = ?', [(int) $result->get('goods_id')])['goods_name'];
        $this->assertSame(255, mb_strlen($name));
        $this->assertStringEndsWith(' (복사)', $name);
    }

    // ─── 픽스처 ───

    private function seedSource(string $name = '티셔츠'): void
    {
        $this->pdo->prepare(
            "INSERT INTO shop_products (goods_id, domain_id, category_code, item_code, goods_name, goods_slug,
                display_price, hit, is_active, created_at)
             VALUES (1, 10, 'CAT01', 'ITEM-SRC', ?, 'tshirt', 15000, 42, 1, '2026-01-01 00:00:00')"
        )->execute([$name]);

        $this->pdo->exec(
            "INSERT INTO shop_product_images (image_id, goods_id, image_url, is_main, sort_order, created_at) VALUES
                (1, 1, '/storage/D10/shop/a.jpg', 1, 0, '2026-01-01'),
                (2, 1, '/storage/D10/shop/b.jpg', 0, 1, '2026-01-01')"
        );
        $this->pdo->exec(
            "INSERT INTO shop_product_options (option_id, goods_id, option_name, option_type, is_required, sort_order, created_at) VALUES
                (100, 1, '색상', 'BASIC', 1, 0, '2026-01-01'),
                (101, 1, '사이즈', 'BASIC', 1, 1, '2026-01-01')"
        );
        $this->pdo->exec(
            "INSERT INTO shop_product_option_values (value_id, option_id, value_name, extra_price, stock_quantity, is_active, sort_order, created_at) VALUES
                (1000, 100, '빨강', 0, NULL, 1, 0, '2026-01-01'),
                (1001, 100, '파랑', 500, 7, 1, 1, '2026-01-01'),
                (1002, 101, 'L', 0, NULL, 1, 0, '2026-01-01')"
        );
        $this->pdo->exec(
            "INSERT INTO shop_product_option_combos (combo_id, goods_id, combination_key, extra_price, stock_quantity, is_active, created_at) VALUES
                (1, 1, '빨강/L', 0, 3, 1, '2026-01-01')"
        );
        $this->pdo->exec(
            "INSERT INTO shop_product_details (detail_id, goods_id, detail_type, detail_value, lang_code, sort_order, created_at) VALUES
                (1, 1, 'description', '<p>상세 <img src=\"/storage/D10/shop/editor/x.jpg\"></p>', 'ko', 0, '2026-01-01')"
        );
        $this->pdo->exec("INSERT INTO shop_product_notice_templates (template_id, type_code, revision) VALUES (5, 'CLOTHES', 1)");
        $this->pdo->exec("INSERT INTO shop_product_notice_template_fields (field_id, template_id, field_code, sort_order) VALUES (1, 5, 'material', 0)");
        $this->pdo->exec("INSERT INTO shop_product_notices (product_notice_id, domain_id, goods_id, template_id, created_at) VALUES (1, 10, 1, 5, '2026-01-01')");
        $this->pdo->exec("INSERT INTO shop_product_notice_values (value_id, product_notice_id, field_code, field_value, created_at) VALUES (1, 1, 'material', '면 100%', '2026-01-01')");
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE shop_products (
                goods_id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_id INTEGER NOT NULL,
                category_code TEXT NULL,
                item_code TEXT NOT NULL UNIQUE,
                goods_name TEXT NOT NULL,
                goods_slug TEXT NULL,
                display_price INTEGER NOT NULL DEFAULT 0,
                hit INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE shop_product_images (
                image_id INTEGER PRIMARY KEY AUTOINCREMENT,
                goods_id INTEGER NOT NULL,
                image_url TEXT NOT NULL,
                is_main INTEGER NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE shop_product_options (
                option_id INTEGER PRIMARY KEY AUTOINCREMENT,
                goods_id INTEGER NOT NULL,
                option_name TEXT NOT NULL,
                option_type TEXT NOT NULL,
                is_required INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE shop_product_option_values (
                value_id INTEGER PRIMARY KEY AUTOINCREMENT,
                option_id INTEGER NOT NULL,
                value_name TEXT NOT NULL,
                extra_price INTEGER NOT NULL DEFAULT 0,
                stock_quantity INTEGER NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE shop_product_option_combos (
                combo_id INTEGER PRIMARY KEY AUTOINCREMENT,
                goods_id INTEGER NOT NULL,
                combination_key TEXT NOT NULL,
                extra_price INTEGER NOT NULL DEFAULT 0,
                stock_quantity INTEGER NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                UNIQUE (goods_id, combination_key)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE shop_product_details (
                detail_id INTEGER PRIMARY KEY AUTOINCREMENT,
                goods_id INTEGER NOT NULL,
                detail_type TEXT NOT NULL,
                detail_value TEXT NOT NULL,
                lang_code TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE shop_product_notice_templates (
                template_id INTEGER PRIMARY KEY AUTOINCREMENT,
                type_code TEXT NOT NULL,
                revision INTEGER NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE shop_product_notice_template_fields (
                field_id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_id INTEGER NOT NULL,
                field_code TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE shop_product_notices (
                product_notice_id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_id INTEGER NOT NULL,
                goods_id INTEGER NOT NULL,
                template_id INTEGER NOT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                UNIQUE (domain_id, goods_id)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE shop_product_notice_values (
                value_id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_notice_id INTEGER NOT NULL,
                field_code TEXT NOT NULL,
                field_value TEXT NOT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
    }

    private function row(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
