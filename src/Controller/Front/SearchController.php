<?php

namespace Mublo\Controller\Front;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Context\Context;
use Mublo\Service\Search\SearchService;
use Mublo\Service\Domain\DomainSettingsService;

/**
 * 전체 검색 컨트롤러
 *
 * GET /search?q=키워드
 */
class SearchController
{
    private SearchService $searchService;
    private DomainSettingsService $settingsService;

    public function __construct(
        SearchService $searchService,
        DomainSettingsService $settingsService
    ) {
        $this->searchService = $searchService;
        $this->settingsService = $settingsService;
    }

    /**
     * 검색 결과 페이지
     *
     * GET /search?q=키워드
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $keyword  = trim($context->getRequest()->get('q', ''));
        $domainId = $context->getDomainId();

        $siteConfig = $this->settingsService->getSiteConfig($domainId);

        $extras = [];
        $rentalBrandCode = $context->getSiteOverride('rental.brand_code', '');
        if ($rentalBrandCode !== '') {
            $extras['rental_brand_code'] = $rentalBrandCode;
        }

        $result = $this->searchService->search($keyword, $domainId, $siteConfig, $extras);

        return ViewResponse::view('search/index')->withData([
            'pageTitle' => $keyword !== '' ? "'{$keyword}' 검색 결과" : '검색',
            'keyword'   => $result['keyword'],
            'groups'    => $result['groups'],
        ]);
    }
}
