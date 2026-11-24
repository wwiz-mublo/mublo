<?php
declare(strict_types=1);

namespace Mublo\Plugin\AiSiteEditor\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Plugin\AiSiteEditor\Service\CapabilityService;

final class CapabilitiesController
{
    public function __construct(private readonly CapabilityService $capabilities)
    {
    }

    public function show(Request $request, Context $context): JsonResponse
    {
        return JsonResponse::success([
            ...$this->capabilities->describe(),
            'domain_available' => $context->getDomainId() !== null,
        ]);
    }
}
