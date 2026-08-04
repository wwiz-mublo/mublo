<?php
declare(strict_types=1);
/**
 * Manual Plugin Routes
 *
 * URL 규칙 (플러그인 접두사 자동 적용):
 * - Front: /manual/...
 * - Admin: /admin/manual/...
 */

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;

return function (PrefixedRouteCollector $r): void {

    // =========================================================
    // Admin Routes (관리자)
    // =========================================================

    // 관리자 진입점 — 매뉴얼 책 목록
    $r->addRoute('GET', '/admin', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 설치 (마이그레이션 실행)
    $r->addRoute('POST', '/admin/install', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 플러그인 설정 (프론트 스킨)
    $r->addRoute('PUT', '/admin/config', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'saveConfig',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/import/skin-development', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'importSkinDevelopment',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/import/board', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'importBoard',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/import/shop', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'importShop',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 책 (Book) ---
    $r->addRoute('GET', '/admin/book/create', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/book/edit/{id:\d+}', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/book', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('PUT', '/admin/book', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'update',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/book/toggle-active', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'toggleActive',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('DELETE', '/admin/book', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualBookController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // --- 페이지 (Page, 트리) ---
    $r->addRoute('GET', '/admin/pages/{bookId:\d+}', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualPageController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/page/tree/{bookId:\d+}', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualPageController::class,
        'method'     => 'tree',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/page/create/{bookId:\d+}', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualPageController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/page/edit/{id:\d+}', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualPageController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/page', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualPageController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('PUT', '/admin/page', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualPageController::class,
        'method'     => 'update',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('DELETE', '/admin/page', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualPageController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('PUT', '/admin/page/save-tree', [
        'controller' => \Mublo\Plugin\Manual\Controller\Admin\ManualPageController::class,
        'method'     => 'saveTree',
        'middleware' => [AdminMiddleware::class],
    ]);

    // =========================================================
    // Front Routes (프론트)
    // =========================================================

    // 매뉴얼 목록
    $r->addRoute('GET', '', [
        'controller' => \Mublo\Plugin\Manual\Controller\Front\ManualController::class,
        'method'     => 'index',
    ]);

    // 매뉴얼 열람
    $r->addRoute('GET', '/{bookSlug:[a-z0-9\-]+}', [
        'controller' => \Mublo\Plugin\Manual\Controller\Front\ManualController::class,
        'method'     => 'view',
    ]);

    // 매뉴얼 열람 (특정 페이지 딥링크)
    $r->addRoute('GET', '/{bookSlug:[a-z0-9\-]+}/{pageSlug:[a-z0-9\-]+}', [
        'controller' => \Mublo\Plugin\Manual\Controller\Front\ManualController::class,
        'method'     => 'view',
    ]);
};
