<?php
declare(strict_types=1);

namespace Mublo\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\FileResponse;

/** 도메인 루트의 /ads.txt를 AdSense 승인 전부터 항상 제공한다. */
final class AdsTxtController
{
    private const GOOGLE_CERTIFICATION_AUTHORITY_ID = 'f08c47fec0942fa0';

    public function index(Context $context): FileResponse
    {
        $publisherId = trim((string) (
            $context->getDomainInfo()?->getSeoConfig()['adsense_publisher_id'] ?? ''
        ));

        $content = self::isValidPublisherId($publisherId)
            ? sprintf(
                "google.com, %s, DIRECT, %s\n",
                $publisherId,
                self::GOOGLE_CERTIFICATION_AUTHORITY_ID
            )
            : "# Google AdSense publisher ID is not configured yet.\n";

        return new FileResponse(
            filePath: null,
            statusCode: 200,
            headers: [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'public, max-age=300',
            ],
            content: $content
        );
    }

    public static function isValidPublisherId(string $publisherId): bool
    {
        return preg_match('/\Apub-[0-9]{16}\z/', $publisherId) === 1;
    }
}
