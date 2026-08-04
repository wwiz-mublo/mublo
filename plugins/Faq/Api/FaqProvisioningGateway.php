<?php
declare(strict_types=1);

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

        $this->seedQuestions($domainId, (int) $categoryId, $preset);

        return $this->result($categoryId, $slug, true);
    }

    /**
     * 첫 질문을 넣는다 — **신규 생성 경로에서만.**
     *
     * 빈 FAQ 는 방문자에게 "등록된 글이 없습니다" 로 보인다. 갓 게시한 사이트의
     * 첫인상이 그것이면 안 된다(MubloCatalog 가 같은 이유로 샘플 항목을 넣는다).
     *
     * 재시도에서 다시 넣지 않는 이유는 **운영자가 지운 것을 되살리면 안 되기**
     * 때문이다. 그건 프로비저닝이 아니라 되돌리기다.
     *
     * 문구는 **어느 업종에서도 참인 것**으로 고른다. 방문자에게도 보이는
     * 글이라, 지어낸 실적이나 없는 혜택을 적으면 거짓말이 된다.
     */
    private function seedQuestions(int $domainId, int $categoryId, array $preset): void
    {
        $items = $preset['placeholder_items'] ?? null;

        if (!is_array($items) || $items === []) {
            $items = [
                [
                    'question' => '상담은 어떻게 신청하나요?',
                    'answer' => '문의 양식을 남겨 주시면 담당자가 확인 후 연락드립니다. '
                        . '전화로도 문의하실 수 있습니다.',
                ],
                [
                    'question' => '방문 상담도 가능한가요?',
                    'answer' => '가능합니다. 미리 연락 주시면 일정에 맞춰 안내해 드립니다.',
                ],
                [
                    'question' => '운영 시간이 어떻게 되나요?',
                    'answer' => '운영 시간과 휴무일은 오시는 길 안내를 참고해 주세요.',
                ],
            ];
        }

        $order = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }

            try {
                $this->repository->insertItem([
                    'domain_id' => $domainId,
                    'category_id' => $categoryId,
                    'question' => $question,
                    'answer' => $answer,
                    'sort_order' => $order++,
                    'is_active' => 1,
                ]);
            } catch (\Throwable) {
                // 첫 질문은 편의지 사이트 성립 조건이 아니다. 실패해도 막지 않는다.
            }
        }
    }

    private function result(int $categoryId, string $slug, bool $created): Result
    {
        return Result::success(
            $created ? 'FAQ 카테고리를 생성했습니다.' : '기존 FAQ 카테고리를 사용합니다.',
            ['category_id' => $categoryId, 'category_slug' => $slug, 'created' => $created]
        );
    }
}
