<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Contract\Balance\BalanceResetGatewayInterface;
use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetFilesystemInterface;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

final class ShopDataResetter implements DataResettableInterface, DataResetFilesystemInterface
{
    public function __construct(
        private Database $db,
        private BalanceResetGatewayInterface $balanceResetGateway
    ) {}

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('shop_transactions', '쇼핑몰 거래 데이터', '주문·결제·장바구니·회원 활동과 Shop 포인트 원장을 삭제합니다. (상품·설정 보존)', 'bi-receipt', false),
            new DataResetCategory('shop_all', '쇼핑몰 전체 데이터', '거래 데이터와 상품·카테고리·쿠폰 데이터를 삭제합니다. (쇼핑몰 설정·배송/상품정보 템플릿 보존)', 'bi-shop'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        return match ($category) {
            'shop_transactions' => $this->resetTransactions($domainId),
            'shop_all' => $this->resetAllData($domainId),
            default => new DataResetResult(details: '알 수 없는 카테고리'),
        };
    }

    public function resetFiles(string $category, int $domainId): int
    {
        if ($category !== 'shop_all' || !defined('MUBLO_PUBLIC_STORAGE_PATH')) return 0;
        return $this->deleteDirectory(MUBLO_PUBLIC_STORAGE_PATH . '/D' . $domainId . '/shop');
    }

    private function resetTransactions(int $domainId): DataResetResult
    {
        $cleared = 0;
        $direct = [
            'shop_claim_logs', 'shop_action_executions', 'shop_payment_completions',
            'shop_payment_transactions', 'shop_reviews', 'shop_inquiries',
            'shop_cart_fees', 'shop_carts', 'shop_member_addresses', 'shop_point_logs',
        ];
        foreach ($direct as $table) $cleared += $this->deleteDomainTable($table, $domainId);

        $cleared += $this->deleteJoined('shop_exchange_items', 'DELETE ei FROM shop_exchange_items ei INNER JOIN shop_returns r ON r.return_id = ei.return_id INNER JOIN shop_orders o ON o.order_no = r.order_no WHERE o.domain_id = ?', $domainId);
        foreach (['shop_returns', 'shop_shipments'] as $table) {
            $alias = $table === 'shop_returns' ? 'r' : 's';
            $cleared += $this->deleteJoined($table, "DELETE {$alias} FROM {$table} {$alias} INNER JOIN shop_orders o ON o.order_no = {$alias}.order_no WHERE o.domain_id = ?", $domainId);
        }
        $cleared += $this->deleteJoined('shop_order_field_values', 'DELETE v FROM shop_order_field_values v INNER JOIN shop_orders o ON o.order_no = v.order_no WHERE o.domain_id = ?', $domainId);
        foreach (['shop_order_memos', 'shop_order_logs', 'shop_order_details'] as $table) {
            $alias = match ($table) { 'shop_order_memos' => 'm', 'shop_order_logs' => 'l', default => 'd' };
            $cleared += $this->deleteJoined($table, "DELETE {$alias} FROM {$table} {$alias} INNER JOIN shop_orders o ON o.order_no = {$alias}.order_no WHERE o.domain_id = ?", $domainId);
        }
        $cleared += $this->deleteJoined('shop_coupon_issue', 'DELETE i FROM shop_coupon_issue i INNER JOIN shop_coupon_group g ON g.coupon_group_id = i.coupon_group_id WHERE g.domain_id = ?', $domainId);
        $cleared += $this->deleteJoined('shop_wishlist', 'DELETE w FROM shop_wishlist w INNER JOIN shop_products p ON p.goods_id = w.goods_id WHERE p.domain_id = ?', $domainId);
        $cleared += $this->deleteDomainTable('shop_orders', $domainId);

        $logs = $this->balanceResetGateway->resetSource($domainId, 'package', 'Shop');
        return new DataResetResult($cleared + ($logs > 0 ? 1 : 0), details: "Shop 거래 데이터 및 포인트 원장 {$logs}건 삭제 (상품·설정 보존)");
    }

    private function resetAllData(int $domainId): DataResetResult
    {
        $transactions = $this->resetTransactions($domainId);
        $cleared = $transactions->tablesCleared;
        $cleared += $this->deleteJoined('shop_exhibition_items', 'DELETE i FROM shop_exhibition_items i INNER JOIN shop_exhibitions e ON e.exhibition_id = i.exhibition_id WHERE e.domain_id = ?', $domainId);
        $cleared += $this->deleteDomainTable('shop_exhibitions', $domainId);
        $cleared += $this->deleteJoined('shop_product_option_values', 'DELETE v FROM shop_product_option_values v INNER JOIN shop_product_options o ON o.option_id = v.option_id INNER JOIN shop_products p ON p.goods_id = o.goods_id WHERE p.domain_id = ?', $domainId);
        foreach (['shop_product_option_combos', 'shop_product_options', 'shop_product_images', 'shop_product_details'] as $table) {
            $alias = match ($table) { 'shop_product_option_combos' => 'c', 'shop_product_options' => 'o', 'shop_product_images' => 'i', default => 'd' };
            $cleared += $this->deleteJoined($table, "DELETE {$alias} FROM {$table} {$alias} INNER JOIN shop_products p ON p.goods_id = {$alias}.goods_id WHERE p.domain_id = ?", $domainId);
        }
        foreach (['shop_product_icons', 'shop_product_templates', 'shop_level_pricing', 'shop_products', 'shop_category_tree', 'shop_category_items'] as $table) {
            $cleared += $this->deleteDomainTable($table, $domainId);
        }
        $cleared += $this->deleteJoined('shop_option_preset_values', 'DELETE v FROM shop_option_preset_values v INNER JOIN shop_option_preset_options o ON o.preset_option_id = v.preset_option_id INNER JOIN shop_option_presets p ON p.preset_id = o.preset_id WHERE p.domain_id = ?', $domainId);
        $cleared += $this->deleteJoined('shop_option_preset_options', 'DELETE o FROM shop_option_preset_options o INNER JOIN shop_option_presets p ON p.preset_id = o.preset_id WHERE p.domain_id = ?', $domainId);
        $cleared += $this->deleteDomainTable('shop_option_presets', $domainId);
        $cleared += $this->deleteDomainTable('shop_coupon_group', $domainId);
        return new DataResetResult($cleared, details: 'Shop 거래·상품·카테고리·쿠폰 데이터 삭제 (설정·배송/상품정보 템플릿 보존)');
    }

    private function deleteDomainTable(string $table, int $domainId): int
    {
        if (!$this->db->tableExists($table)) return 0;
        $this->db->execute("DELETE FROM {$table} WHERE domain_id = ?", [$domainId]);
        return 1;
    }
    private function deleteJoined(string $table, string $sql, int $domainId): int
    {
        if (!$this->db->tableExists($table)) return 0;
        $this->db->execute($sql, [$domainId]);
        return 1;
    }
    private function deleteDirectory(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $count = 0;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
            else { unlink($item->getPathname()); $count++; }
        }
        rmdir($dir);
        return $count;
    }
}
