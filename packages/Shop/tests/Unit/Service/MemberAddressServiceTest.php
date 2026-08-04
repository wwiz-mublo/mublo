<?php
/**
 * MemberAddressService 단위 테스트 — PII 암호화 왕복
 *
 * 검증 항목:
 * - create()  — 저장 직전 수령인/연락처/우편번호/주소가 암호화된다
 * - getList()  — 조회 시 복호화되어 평문으로 노출된다
 * - 하위호환   — 복호화 실패(기존 평문 행)는 원본을 그대로 유지한다
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\MemberAddressService;
use Mublo\Packages\Shop\Repository\MemberAddressRepository;
use Mublo\Packages\Shop\Entity\MemberAddress;
use Mublo\Contract\Security\SensitiveValueCodecInterface;

class MemberAddressServiceTest extends TestCase
{
    private function makeEncryption(): SensitiveValueCodecInterface
    {
        $enc = $this->createMock(SensitiveValueCodecInterface::class);
        // 가역 스텁: encrypt→'ENC(x)', decrypt→x (ENC 접두어 없으면 null=평문)
        $enc->method('encrypt')->willReturnCallback(fn(string $v) => 'ENC(' . $v . ')');
        $enc->method('decrypt')->willReturnCallback(
            fn(string $v) => str_starts_with($v, 'ENC(') ? substr($v, 4, -1) : null
        );
        return $enc;
    }

    public function testCreateEncryptsPiiBeforePersist(): void
    {
        $repo = $this->createMock(MemberAddressRepository::class);
        $repo->method('countByMember')->willReturn(0);

        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $data): bool {
                return $data['recipient_name'] === 'ENC(홍길동)'
                    && $data['recipient_phone'] === 'ENC(010-1234-5678)'
                    && $data['zip_code'] === 'ENC(06236)'
                    && $data['address1'] === 'ENC(서울시 강남구)'
                    // 비-PII 필드는 그대로
                    && $data['member_id'] === 7
                    && $data['address_name'] === '자택';
            }))
            ->willReturn(11);

        $service = new MemberAddressService($repo, $this->makeEncryption());

        $result = $service->create(7, 1, [
            'address_name'    => '자택',
            'recipient_name'  => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zip_code'        => '06236',
            'address1'        => '서울시 강남구',
            'address2'        => '',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(11, $result->get('address_id'));
    }

    public function testGetListDecryptsPii(): void
    {
        $repo = $this->createMock(MemberAddressRepository::class);
        $repo->method('findByMember')->willReturn([
            MemberAddress::fromArray([
                'address_id'      => 1,
                'member_id'       => 7,
                'domain_id'       => 1,
                'recipient_name'  => 'ENC(김철수)',
                'recipient_phone' => 'ENC(010-9999-0000)',
                'zip_code'        => 'ENC(12345)',
                'address1'        => 'ENC(부산시 해운대구)',
                'address2'        => 'ENC(101동 202호)',
            ]),
        ]);

        $service = new MemberAddressService($repo, $this->makeEncryption());

        $addresses = $service->getList(7, 1)->get('addresses');

        $this->assertSame('김철수', $addresses[0]['recipient_name']);
        $this->assertSame('010-9999-0000', $addresses[0]['recipient_phone']);
        $this->assertSame('부산시 해운대구', $addresses[0]['address1']);
        $this->assertSame('101동 202호', $addresses[0]['address2']);
    }

    public function testSaveDefaultFromCheckoutSetsExistingWhenAddressMatches(): void
    {
        // 동일 주소가 이미 주소록에 있으면 신규 생성 없이 그것을 기본으로 지정한다.
        $repo = $this->createMock(MemberAddressRepository::class);
        $repo->method('findByMember')->willReturn([
            MemberAddress::fromArray([
                'address_id'      => 42,
                'member_id'       => 7,
                'domain_id'       => 1,
                'recipient_name'  => 'ENC(홍길동)',
                'recipient_phone' => 'ENC(010-1234-5678)',
                'zip_code'        => 'ENC(06236)',
                'address1'        => 'ENC(서울시 강남구)',
                'address2'        => 'ENC(1층)',
                'is_default'      => 0,
            ]),
        ]);
        $repo->expects($this->never())->method('create');
        $repo->expects($this->once())->method('setDefault')->with(7, 1, 42);

        $service = new MemberAddressService($repo, $this->makeEncryption());

        $result = $service->saveDefaultFromCheckout(7, 1, [
            'recipient_name'  => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zip_code'        => '06236',
            'address1'        => '서울시 강남구',
            'address2'        => '1층',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(42, $result->get('address_id'));
    }

    public function testSaveDefaultFromCheckoutCreatesWhenNoMatch(): void
    {
        // 동일 주소가 없으면 신규 배송지로 추가하며 기본(is_default=1)으로 저장한다.
        $repo = $this->createMock(MemberAddressRepository::class);
        $repo->method('findByMember')->willReturn([]);   // 주소록 비어있음
        $repo->method('countByMember')->willReturn(0);

        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(array $data): bool =>
                $data['recipient_name'] === 'ENC(김철수)'
                && $data['address1'] === 'ENC(부산시 해운대구)'
                && $data['is_default'] === 1
            ))
            ->willReturn(99);

        $service = new MemberAddressService($repo, $this->makeEncryption());

        $result = $service->saveDefaultFromCheckout(7, 1, [
            'recipient_name'  => '김철수',
            'recipient_phone' => '010-9999-0000',
            'zip_code'        => '48095',
            'address1'        => '부산시 해운대구',
            'address2'        => '',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(99, $result->get('address_id'));
    }

    public function testGetListKeepsLegacyPlaintext(): void
    {
        $repo = $this->createMock(MemberAddressRepository::class);
        $repo->method('findByMember')->willReturn([
            MemberAddress::fromArray([
                'address_id'     => 1,
                'member_id'      => 7,
                'domain_id'      => 1,
                'recipient_name' => '이영희',           // 기존 평문 (ENC 접두어 없음 → decrypt null)
                'address1'       => '대전시 유성구',
            ]),
        ]);

        $service = new MemberAddressService($repo, $this->makeEncryption());

        $addresses = $service->getList(7, 1)->get('addresses');

        $this->assertSame('이영희', $addresses[0]['recipient_name']);
        $this->assertSame('대전시 유성구', $addresses[0]['address1']);
    }
}
