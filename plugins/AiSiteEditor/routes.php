<?php
declare(strict_types=1);

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Plugin\AiSiteEditor\Controller\CapabilitiesController;

return static function (PrefixedRouteCollector $routes): void {
    // 제품 중립 경로. 인증이 필요한 snapshot/apply API는 인증 어댑터가 추가된 뒤 개방한다.
    $routes->addRawRoute('GET', '/api/v1/site-editor/capabilities', [
        'controller' => CapabilitiesController::class,
        'method' => 'show',
    ]);
};
