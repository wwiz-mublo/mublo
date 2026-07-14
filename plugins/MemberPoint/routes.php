<?php
/**
 * MemberPoint Plugin Routes
 *
 * URL 규칙 (접두사는 디렉토리명 소문자화 — Router::buildRoutePrefix):
 * - Front: /memberpoint/...
 * - Admin: /admin/memberpoint/...
 */

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;

return function (PrefixedRouteCollector $r): void {

    // =========================================================
    // Admin Routes (관리자)
    //
    // 포인트 내역/수동조정/무결성검증은 코어 "포인트 지갑"(/admin/point)으로 일원화됨.
    // 이 플러그인은 회원 생애주기(가입·레벨업) 자동적립 설정만 담당한다.
    // =========================================================

    // 포인트 설정 페이지 (구 URL → 회원포인트 설정으로 이동)
    $r->addRoute('GET', '/admin/settings', [
        'controller' => \Mublo\Plugin\MemberPoint\Controller\Admin\SettingsController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 회원포인트 설정
    $r->addRoute('GET', '/admin/member-settings', [
        'controller' => \Mublo\Plugin\MemberPoint\Controller\Admin\SettingsController::class,
        'method'     => 'memberSettings',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/member-settings', [
        'controller' => \Mublo\Plugin\MemberPoint\Controller\Admin\SettingsController::class,
        'method'     => 'saveMemberSettings',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 플러그인 설치 (마이그레이션 실행)
    $r->addRoute('POST', '/admin/install', [
        'controller' => \Mublo\Plugin\MemberPoint\Controller\Admin\SettingsController::class,
        'method'     => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);

    // =========================================================
    // Front Routes (사용자)
    // =========================================================

    // 내 포인트 내역
    $r->addRoute('GET', '/my', [
        'controller' => \Mublo\Plugin\MemberPoint\Controller\Front\PointController::class,
        'method'     => 'my',
    ]);
};
