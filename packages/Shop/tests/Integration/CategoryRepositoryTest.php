<?php

namespace Tests\Shop\Integration;

use Mublo\Packages\Shop\Repository\CategoryRepository;
use Tests\Integration\DatabaseTestCase;

/**
 * 카테고리 경로 갱신을 실 DB 로 검증한다.
 *
 * updateChildrenPathName() 은 raw SQL 로 CONCAT/SUBSTRING 을 쓴다. 자리수 계산이
 * 어긋나면 하위 카테고리의 경로가 잘리거나 접두가 겹쳐 붙는데, 둘 다 SQL 이
 * 결정하므로 mock 으로는 드러나지 않는다.
 *
 * 갱신 범위도 여기서만 확인된다 — 다른 도메인과, 접두만 같은 형제 카테고리는
 * 건드리지 않아야 한다.
 */
class CategoryRepositoryTest extends DatabaseTestCase
{
    private CategoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('shop_category_tree', '
            node_id INT AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            category_name VARCHAR(100) NOT NULL,
            path_name VARCHAR(500) NULL
        ');

        $this->repository = new CategoryRepository($this->db);
    }

    public function testChildPathsAreRewrittenUnderTheNewParentName(): void
    {
        $this->seed('shop_category_tree', [
            ['domain_id' => 1, 'category_name' => '의류',   'path_name' => '의류'],
            ['domain_id' => 1, 'category_name' => '상의',   'path_name' => '의류>상의'],
            ['domain_id' => 1, 'category_name' => '티셔츠', 'path_name' => '의류>상의>티셔츠'],
        ]);

        $this->repository->updateChildrenPathName(1, '의류', '패션');

        $paths = array_column(
            $this->fetchAll('SELECT path_name FROM shop_category_tree WHERE domain_id = 1 ORDER BY node_id'),
            'path_name'
        );

        // 자기 자신은 이 메서드의 대상이 아니다. 하위만 접두가 바뀐다.
        $this->assertSame(['의류', '패션>상의', '패션>상의>티셔츠'], $paths);
    }

    public function testSiblingsSharingThePrefixAreNotTouched(): void
    {
        $this->seed('shop_category_tree', [
            ['domain_id' => 1, 'category_name' => '의류',     'path_name' => '의류'],
            ['domain_id' => 1, 'category_name' => '상의',     'path_name' => '의류>상의'],
            // '의류세탁' 은 '의류' 로 시작하지만 다른 카테고리다.
            ['domain_id' => 1, 'category_name' => '의류세탁', 'path_name' => '의류세탁'],
            ['domain_id' => 1, 'category_name' => '세제',     'path_name' => '의류세탁>세제'],
        ]);

        $this->repository->updateChildrenPathName(1, '의류', '패션');

        $paths = array_column(
            $this->fetchAll('SELECT path_name FROM shop_category_tree WHERE domain_id = 1 ORDER BY node_id'),
            'path_name'
        );

        $this->assertSame(['의류', '패션>상의', '의류세탁', '의류세탁>세제'], $paths);
    }

    public function testOtherDomainsAreNotTouched(): void
    {
        $this->seed('shop_category_tree', [
            ['domain_id' => 1, 'category_name' => '상의', 'path_name' => '의류>상의'],
            ['domain_id' => 2, 'category_name' => '상의', 'path_name' => '의류>상의'],
        ]);

        $this->repository->updateChildrenPathName(1, '의류', '패션');

        $this->assertSame(
            [
                ['domain_id' => 1, 'path_name' => '패션>상의'],
                ['domain_id' => 2, 'path_name' => '의류>상의'],
            ],
            $this->fetchAll('SELECT domain_id, path_name FROM shop_category_tree ORDER BY domain_id')
        );
    }
}
