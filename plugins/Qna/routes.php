<?php
declare(strict_types=1);
/**
 * QnA Plugin Routes
 *
 * URL 규칙 (prefix = qna):
 * - Front: /qna, /qna/write, /qna/{id}
 * - Admin: /admin/qna/...
 */

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;

return function (PrefixedRouteCollector $r): void {

    // =========================================================
    // Admin Routes (관리자)
    // =========================================================

    $r->addRoute('GET', '/admin', [
        'controller' => \Mublo\Plugin\Qna\Controller\Admin\QnaAdminController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/install', [
        'controller' => \Mublo\Plugin\Qna\Controller\Admin\QnaAdminController::class,
        'method'     => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('PUT', '/admin/config', [
        'controller' => \Mublo\Plugin\Qna\Controller\Admin\QnaAdminController::class,
        'method'     => 'saveConfig',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 첨부 임시 업로드 (관리자 답변 첨부용)
    $r->addRoute('POST', '/admin/upload', [
        'controller' => \Mublo\Plugin\Qna\Controller\Admin\QnaAdminController::class,
        'method'     => 'upload',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 목록 일괄 처리 (정적 경로 → {id} 보다 먼저)
    $r->addRoute('POST', '/admin/list-modify', [
        'controller' => \Mublo\Plugin\Qna\Controller\Admin\QnaAdminController::class,
        'method'     => 'listModify',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/list-delete', [
        'controller' => \Mublo\Plugin\Qna\Controller\Admin\QnaAdminController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 문의 상세/답변 화면 (Form.php) — 목록 컨벤션(List/Form)에 맞춰 edit 라우트로 노출.
    $r->addRoute('GET', '/admin/{id:\d+}/edit', [
        'controller' => \Mublo\Plugin\Qna\Controller\Admin\QnaAdminController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/{id:\d+}/reply', [
        'controller' => \Mublo\Plugin\Qna\Controller\Admin\QnaAdminController::class,
        'method'     => 'reply',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('PUT', '/admin/{id:\d+}/status', [
        'controller' => \Mublo\Plugin\Qna\Controller\Admin\QnaAdminController::class,
        'method'     => 'status',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('DELETE', '/admin/{id:\d+}', [
        'controller' => \Mublo\Plugin\Qna\Controller\Admin\QnaAdminController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // =========================================================
    // Front Routes (문의자 — 회원 전용)
    // =========================================================

    // 내 문의 목록
    $r->addRoute('GET', '', [
        'controller' => \Mublo\Plugin\Qna\Controller\Front\QnaController::class,
        'method'     => 'index',
    ]);

    // 문의 작성 폼 (정적 경로 → {id} 보다 먼저)
    $r->addRoute('GET', '/write', [
        'controller' => \Mublo\Plugin\Qna\Controller\Front\QnaController::class,
        'method'     => 'create',
    ]);

    // 첨부 임시 업로드 (정적 경로 → {id} 보다 먼저)
    $r->addRoute('POST', '/upload', [
        'controller' => \Mublo\Plugin\Qna\Controller\Front\QnaController::class,
        'method'     => 'upload',
    ]);

    // 문의 등록
    $r->addRoute('POST', '', [
        'controller' => \Mublo\Plugin\Qna\Controller\Front\QnaController::class,
        'method'     => 'store',
    ]);

    // 문의 상세(스레드)
    $r->addRoute('GET', '/{id:\d+}', [
        'controller' => \Mublo\Plugin\Qna\Controller\Front\QnaController::class,
        'method'     => 'show',
    ]);

    // 추가 문의(스레드 글)
    $r->addRoute('POST', '/{id:\d+}/reply', [
        'controller' => \Mublo\Plugin\Qna\Controller\Front\QnaController::class,
        'method'     => 'reply',
    ]);

    // 본인 문의 삭제
    $r->addRoute('DELETE', '/{id:\d+}', [
        'controller' => \Mublo\Plugin\Qna\Controller\Front\QnaController::class,
        'method'     => 'destroy',
    ]);
};
