<?php
/**
 * FAQ Plugin Routes
 *
 * URL 규칙:
 * - Front: /faq/...
 * - Admin: /admin/faq/...
 * - API:   /api/v1/faq/...
 */

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;

return function (PrefixedRouteCollector $r): void {

    // =========================================================
    // Admin Routes (관리자)
    // =========================================================

    // FAQ 관리 페이지 (통합)
    $r->addRoute('GET', '/admin', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqItemController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 설치 (마이그레이션 실행)
    $r->addRoute('POST', '/admin/install', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqItemController::class,
        'method'     => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 카테고리 CRUD ---
    $r->addRoute('POST', '/admin/category', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqCategoryController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('PUT', '/admin/category', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqCategoryController::class,
        'method'     => 'update',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('DELETE', '/admin/category', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqCategoryController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- FAQ 항목 CRUD ---
    $r->addRoute('GET', '/admin/items', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqItemController::class,
        'method'     => 'items',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/item', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqItemController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('PUT', '/admin/item', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqItemController::class,
        'method'     => 'update',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('DELETE', '/admin/item', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqItemController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('PUT', '/admin/sort', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqItemController::class,
        'method'     => 'sort',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 스킨 설정 저장
    $r->addRoute('PUT', '/admin/skin', [
        'controller' => \Mublo\Plugin\Faq\Controller\Admin\FaqItemController::class,
        'method'     => 'saveSkin',
        'middleware' => [AdminMiddleware::class],
    ]);

    // =========================================================
    // Front Routes (프론트)
    // =========================================================

    // 전체 FAQ
    $r->addRoute('GET', '', [
        'controller' => \Mublo\Plugin\Faq\Controller\Front\FaqController::class,
        'method'     => 'index',
    ]);

    // 카테고리별 FAQ
    $r->addRoute('GET', '/{slug:[a-z0-9\-]+}', [
        'controller' => \Mublo\Plugin\Faq\Controller\Front\FaqController::class,
        'method'     => 'category',
    ]);

    // =========================================================
    // API Routes (패키지 AJAX 호출용)
    // =========================================================

    $r->addRoute('GET', '/api/list', [
        'controller' => \Mublo\Plugin\Faq\Controller\Front\FaqController::class,
        'method'     => 'apiList',
    ]);
};
