<?php

namespace Mublo\Plugin\Faq\Api;

use Mublo\Contract\Faq\FaqProvisioningInterface;
use Mublo\Core\Result\Result;
use Mublo\Plugin\Faq\Repository\FaqRepository;

/**
 * FaqProvisioningGateway
 *
 * `Contract\Faq\FaqProvisioningInterface` 의 Faq 플러그인 구현.
 *
 * `FaqService::createCategory()` 는 슬러그를 자동 생성하므로 프로비저닝에는
 * 쓸 수 없다 — 결정적 키가 필요하다. 그래서 리포지토리를 직접 쓴다
 * (이 어댑터는 Faq 플러그인 내부 코드이므로 자기 저장소를 다뤄도 된다).
 *
 * `$provisioningKey` 가 `category_slug` 이고 `UNIQUE(domain_id, category_slug)`
 * 가 동시 재시도를 막는다. 기존 카테고리의 이름은 **덮지 않는다.**
 */
class FaqProvisioningGateway implements FaqProvisioningInterface
{
    public function __construct(private FaqRepository $repository)
    {
    }

    public function ensureCategory(int $domainId, string $provisioningKey, array $preset = []): Result
    {
        $slug = trim($provisioningKey);
        if ($slug === '') {
            return Result::failure('프로비저닝 키는 필수입니다.');
        }

        $existing = $this->repository->findCategoryBySlug($domainId, $slug);
        if ($existing !== null) {
            return $this->result((int) $existing['category_id'], $slug, false);
        }

        $name = trim((string) ($preset['category_name'] ?? ''));

        try {
            $categoryId = $this->repository->insertCategory([
                'domain_id' => $domainId,
                'category_name' => $name !== '' ? $name : $slug,
                'category_slug' => $slug,
                'sort_order' => (int) ($preset['sort_order'] ?? 0),
                'is_active' => (int) ($preset['is_active'] ?? 1),
            ]);
        } catch (\Throwable $e) {
            // 동시 호출이 먼저 넣었으면 UNIQUE 로 막힌다.
            $raced = $this->repository->findCategoryBySlug($domainId, $slug);
            if ($raced === null) {
                throw $e;
            }

            return $this->result((int) $raced['category_id'], $slug, false);
        }

        if (!$categoryId) {
            return Result::failure('FAQ 카테고리 생성에 실패했습니다.');
        }

        return $this->result($categoryId, $slug, true);
    }

    private function result(int $categoryId, string $slug, bool $created): Result
    {
        return Result::success(
            $created ? 'FAQ 카테고리를 생성했습니다.' : '기존 FAQ 카테고리를 사용합니다.',
            ['category_id' => $categoryId, 'category_slug' => $slug, 'created' => $created]
        );
    }
}
