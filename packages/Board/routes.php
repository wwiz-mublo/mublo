<?php
declare(strict_types=1);
/**
 * Board Package Routes
 *
 * PrefixedRouteCollector를 통해 자동으로 접두사가 적용됩니다.
 *
 * URL 규칙:
 * - Front: /{prefix}/... → /board/...
 * - Admin: /admin/{prefix}/... → /admin/board/...
 */

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;

// Front Controllers
use Mublo\Packages\Board\Controller\Front\BoardController;
use Mublo\Packages\Board\Controller\Front\CommunityController;

// Admin Controllers
use Mublo\Packages\Board\Controller\Admin\BoardConfigController;
use Mublo\Packages\Board\Controller\Admin\BoardGroupController;
use Mublo\Packages\Board\Controller\Admin\BoardArticleController;
use Mublo\Packages\Board\Controller\Admin\BoardCategoryController;
use Mublo\Packages\Board\Controller\Admin\BoardPointController;
use Mublo\Packages\Board\Controller\Admin\DashboardController;

return function (PrefixedRouteCollector $r): void {

    // ================================================
    // Front Routes — 커뮤니티 (CommunityController)
    // prefix 없이 /community 경로 사용 (addRawRoute)
    // ================================================

    $r->addRawRoute('GET', '/community', [
        'controller' => CommunityController::class,
        'method'     => 'index',
    ]);

    $r->addRawRoute('GET', '/community/popular', [
        'controller' => CommunityController::class,
        'method'     => 'popular',
    ]);

    $r->addRawRoute('GET', '/community/group/{slug}', [
        'controller' => CommunityController::class,
        'method'     => 'group',
    ]);

    // ================================================
    // Front Routes — 게시판 (BoardController)
    // ================================================

    $r->addRoute('GET', '/{board_id}', [
        'controller' => BoardController::class,
        'method'     => 'list',
    ]);

    $r->addRoute('GET', '/{board_id}/list', [
        'controller' => BoardController::class,
        'method'     => 'list',
    ]);

    $r->addRoute('GET', '/{board_id}/view/{post_no:\d+}[/{slug}]', [
        'controller' => BoardController::class,
        'method'     => 'view',
    ]);

    $r->addRoute('GET', '/{board_id}/write', [
        'controller' => BoardController::class,
        'method'     => 'write',
    ]);

    $r->addRoute('POST', '/{board_id}/write', [
        'controller' => BoardController::class,
        'method'     => 'writeProcess',
    ]);

    $r->addRoute('GET', '/{board_id}/edit/{post_no:\d+}', [
        'controller' => BoardController::class,
        'method'     => 'edit',
    ]);

    $r->addRoute('POST', '/{board_id}/edit/{post_no:\d+}', [
        'controller' => BoardController::class,
        'method'     => 'editProcess',
    ]);

    $r->addRoute('POST', '/{board_id}/delete/{post_no:\d+}', [
        'controller' => BoardController::class,
        'method'     => 'delete',
    ]);

    $r->addRoute('POST', '/{board_id}/comment', [
        'controller' => BoardController::class,
        'method'     => 'commentCreate',
    ]);

    $r->addRoute('POST', '/{board_id}/comment/{comment_id:\d+}/update', [
        'controller' => BoardController::class,
        'method'     => 'commentUpdate',
    ]);

    $r->addRoute('POST', '/{board_id}/comment/{comment_id:\d+}/delete', [
        'controller' => BoardController::class,
        'method'     => 'commentDelete',
    ]);

    $r->addRoute('POST', '/{board_id}/reaction', [
        'controller' => BoardController::class,
        'method'     => 'reactionToggle',
    ]);

    // 비회원 비밀번호 확인
    $r->addRoute('POST', '/{board_id}/password-check', [
        'controller' => BoardController::class,
        'method'     => 'passwordCheck',
    ]);

    // 파일 첨부
    $r->addRoute('POST', '/{board_id}/file/upload', [
        'controller' => BoardController::class,
        'method'     => 'fileUpload',
    ]);

    // 공개 식별자(hex 22자)로만 받는다. 정수 attachment_id 는 URL 에 싣지 않는다.
    $r->addRoute('GET', '/{board_id}/file/download/{public_id:[0-9a-f]{22}}', [
        'controller' => BoardController::class,
        'method'     => 'fileDownload',
    ]);

    $r->addRoute('POST', '/{board_id}/file/delete', [
        'controller' => BoardController::class,
        'method'     => 'fileDelete',
    ]);

    // 링크
    $r->addRoute('POST', '/{board_id}/link/add', [
        'controller' => BoardController::class,
        'method'     => 'linkAdd',
    ]);

    $r->addRoute('POST', '/{board_id}/link/delete', [
        'controller' => BoardController::class,
        'method'     => 'linkDelete',
    ]);

    // ================================================
    // Admin Routes — 대시보드 (DashboardController)
    // ================================================

    $r->addRoute('GET', '/admin/dashboard', [
        'controller' => DashboardController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    // ================================================
    // Admin Routes — 게시판 설정 (BoardConfigController)
    // ================================================

    $r->addRoute('GET', '/admin/config', [
        'controller' => BoardConfigController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/install', [
        'controller' => BoardConfigController::class,
        'method'     => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/config/create', [
        'controller' => BoardConfigController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/config/edit/{id}', [
        'controller' => BoardConfigController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/config/edit', [
        'controller' => BoardConfigController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/config/store', [
        'controller' => BoardConfigController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/config/delete', [
        'controller' => BoardConfigController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/config/order-update', [
        'controller' => BoardConfigController::class,
        'method'     => 'orderUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/config/list-update', [
        'controller' => BoardConfigController::class,
        'method'     => 'listUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/config/list-delete', [
        'controller' => BoardConfigController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/config/check-slug', [
        'controller' => BoardConfigController::class,
        'method'     => 'checkSlug',
        'middleware' => [AdminMiddleware::class],
    ]);

    // ================================================
    // Admin Routes — 게시판 그룹 (BoardGroupController)
    // ================================================

    $r->addRoute('GET', '/admin/group', [
        'controller' => BoardGroupController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/group/create', [
        'controller' => BoardGroupController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/group/edit/{id}', [
        'controller' => BoardGroupController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/group/edit', [
        'controller' => BoardGroupController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/group/store', [
        'controller' => BoardGroupController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/group/delete', [
        'controller' => BoardGroupController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/group/order-update', [
        'controller' => BoardGroupController::class,
        'method'     => 'orderUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/group/check-slug', [
        'controller' => BoardGroupController::class,
        'method'     => 'checkSlug',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/group/list-update', [
        'controller' => BoardGroupController::class,
        'method'     => 'listUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/group/list-delete', [
        'controller' => BoardGroupController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/group/admin-add', [
        'controller' => BoardGroupController::class,
        'method'     => 'adminAdd',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/group/admin-remove', [
        'controller' => BoardGroupController::class,
        'method'     => 'adminRemove',
        'middleware' => [AdminMiddleware::class],
    ]);

    // ================================================
    // Admin Routes — 게시글 관리 (BoardArticleController)
    // ================================================

    $r->addRoute('GET', '/admin/article', [
        'controller' => BoardArticleController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/article/view/{id}', [
        'controller' => BoardArticleController::class,
        'method'     => 'view',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/article/view', [
        'controller' => BoardArticleController::class,
        'method'     => 'view',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/article/create', [
        'controller' => BoardArticleController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/article/edit/{id}', [
        'controller' => BoardArticleController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/article/edit', [
        'controller' => BoardArticleController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/article/store', [
        'controller' => BoardArticleController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/article/categories', [
        'controller' => BoardArticleController::class,
        'method'     => 'categories',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/article/status-update', [
        'controller' => BoardArticleController::class,
        'method'     => 'statusUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/article/delete', [
        'controller' => BoardArticleController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/article/bulk-delete', [
        'controller' => BoardArticleController::class,
        'method'     => 'bulkDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/article/bulk-status-update', [
        'controller' => BoardArticleController::class,
        'method'     => 'bulkStatusUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/article/comment-delete', [
        'controller' => BoardArticleController::class,
        'method'     => 'commentDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/article/attachment-delete', [
        'controller' => BoardArticleController::class,
        'method'     => 'attachmentDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/article/link-delete', [
        'controller' => BoardArticleController::class,
        'method'     => 'linkDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // ================================================
    // Admin Routes — 게시판 카테고리 (BoardCategoryController)
    // ================================================

    $r->addRoute('GET', '/admin/category', [
        'controller' => BoardCategoryController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/category/create', [
        'controller' => BoardCategoryController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/category/edit/{id}', [
        'controller' => BoardCategoryController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/category/edit', [
        'controller' => BoardCategoryController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/category/store', [
        'controller' => BoardCategoryController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/category/delete', [
        'controller' => BoardCategoryController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/category/order-update', [
        'controller' => BoardCategoryController::class,
        'method'     => 'orderUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/category/list-update', [
        'controller' => BoardCategoryController::class,
        'method'     => 'listUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/category/list-delete', [
        'controller' => BoardCategoryController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/category/check-slug', [
        'controller' => BoardCategoryController::class,
        'method'     => 'checkSlug',
        'middleware' => [AdminMiddleware::class],
    ]);

    // ================================================
    // Admin Routes — 포인트 설정 (BoardPointController)
    // ================================================

    $r->addRoute('GET', '/admin/point', [
        'controller' => BoardPointController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/point', [
        'controller' => BoardPointController::class,
        'method'     => 'save',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/point/{scopeType}/{scopeId}', [
        'controller' => BoardPointController::class,
        'method'     => 'scopeConfig',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/point/{scopeType}/{scopeId}', [
        'controller' => BoardPointController::class,
        'method'     => 'saveScopeConfig',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('DELETE', '/admin/point/{scopeType}/{scopeId}', [
        'controller' => BoardPointController::class,
        'method'     => 'deleteScopeConfig',
        'middleware' => [AdminMiddleware::class],
    ]);
};
