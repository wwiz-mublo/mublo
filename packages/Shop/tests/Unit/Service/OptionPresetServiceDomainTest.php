<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\OptionPresetRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\OptionPresetService;
use PHPUnit\Framework\TestCase;

/**
 * 상품옵션 프리셋의 도메인 경계 검증
 *
 * 프리셋은 도메인 소유물이므로, 다른 도메인의 preset_id 를 들고 와도
 * 조회·수정·삭제·상품적용 중 어느 것도 통과해서는 안 된다.
 */
class OptionPresetServiceDomainTest extends TestCase
{
    /**
     * 타 도메인 프리셋은 findInDomain 이 null 을 돌려준다는 전제의 저장소 목
     */
    private function presetsNotInDomain(): OptionPresetRepository
    {
        $presets = $this->createMock(OptionPresetRepository::class);
        $presets->method('findInDomain')->willReturn(null);

        return $presets;
    }

    private function service(
        ?OptionPresetRepository $presets = null,
        ?ProductRepository $products = null
    ): OptionPresetService {
        return new OptionPresetService(
            $presets ?? $this->presetsNotInDomain(),
            $this->createMock(ProductOptionRepository::class),
            null,
            $products ?? $this->createMock(ProductRepository::class)
        );
    }

    public function testDetailOfAnotherDomainPresetIsRejected(): void
    {
        $result = $this->service()->getDetail(7, 1);

        $this->assertTrue($result->isFailure());
    }

    public function testUpdateOfAnotherDomainPresetIsRejectedBeforeWriting(): void
    {
        $presets = $this->presetsNotInDomain();
        $presets->expects($this->never())->method('updateInDomain');
        $presets->expects($this->never())->method('deletePresetOptions');

        $result = $this->service($presets)->update(7, ['name' => '바꾸기'], 1);

        $this->assertTrue($result->isFailure());
    }

    public function testDeleteOfAnotherDomainPresetIsRejectedBeforeWriting(): void
    {
        $presets = $this->presetsNotInDomain();
        $presets->expects($this->never())->method('deleteInDomain');
        $presets->expects($this->never())->method('deletePresetOptions');

        $result = $this->service($presets)->delete(7, 1);

        $this->assertTrue($result->isFailure());
    }

    public function testApplyToProductOfAnotherDomainPresetIsRejected(): void
    {
        $presets = $this->presetsNotInDomain();
        $presets->expects($this->never())->method('getPresetOptions');

        $result = $this->service($presets)->applyToProduct(7, 3, 1);

        $this->assertTrue($result->isFailure());
    }

    public function testApplyToProductRejectsProductFromAnotherDomain(): void
    {
        // 프리셋은 내 도메인 것이지만 상품이 남의 도메인이면 막아야 한다
        $presets = $this->createMock(OptionPresetRepository::class);
        $presets->method('findInDomain')->willReturn(
            \Mublo\Packages\Shop\Entity\OptionPreset::fromArray([
                'preset_id' => 7,
                'domain_id' => 1,
                'name' => '내 프리셋',
                'option_mode' => 'SINGLE',
            ])
        );
        $presets->expects($this->never())->method('getPresetOptions');

        $products = $this->createMock(ProductRepository::class);
        $products->method('findInDomain')->willReturn(null);

        $result = $this->service($presets, $products)->applyToProduct(7, 3, 1);

        $this->assertTrue($result->isFailure());
    }
}
