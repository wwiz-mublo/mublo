<?php
declare(strict_types=1);

namespace Tests\Shop\Unit\Service;

use Mublo\Contract\Member\MemberCustomFieldQueryInterface;
use Mublo\Packages\Shop\Service\OrdererPrefillService;
use PHPUnit\Framework\TestCase;

class OrdererPrefillServiceTest extends TestCase
{
    public function testUsesMemberNamePhoneAndEmailFields(): void
    {
        $service = new OrdererPrefillService($this->fields([
            'name' => ' 홍길동 ', 'phone' => '010-1234-5678', 'email' => 'hong@example.com',
        ]));

        self::assertSame(
            ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'hong@example.com'],
            $service->forMember(3, 10, '길동이')
        );
    }

    public function testFallsBackToNicknameWhenNameFieldIsEmpty(): void
    {
        $service = new OrdererPrefillService($this->fields(['name' => '', 'phone' => null]));

        self::assertSame(['name' => '길동이', 'phone' => '', 'email' => ''], $service->forMember(3, 10, '길동이'));
    }

    public function testDropsInvalidEmailAndNonScalarValues(): void
    {
        $service = new OrdererPrefillService($this->fields(['email' => 'not-mail', 'phone' => ['010']]));

        $prefill = $service->forMember(3, 10, null);
        self::assertSame('', $prefill['email']);
        self::assertSame('', $prefill['phone']);
    }

    public function testFieldReadFailureLeavesBlankValues(): void
    {
        $fields = $this->createMock(MemberCustomFieldQueryInterface::class);
        $fields->method('findValue')->willThrowException(new \RuntimeException('decrypt failed'));

        self::assertSame(
            ['name' => '닉', 'phone' => '', 'email' => ''],
            (new OrdererPrefillService($fields))->forMember(3, 10, '닉')
        );
    }

    public function testWithoutContractOrMemberOnlyNicknameIsUsed(): void
    {
        self::assertSame(['name' => '닉', 'phone' => '', 'email' => ''], (new OrdererPrefillService())->forMember(3, 10, '닉'));

        $fields = $this->createMock(MemberCustomFieldQueryInterface::class);
        $fields->expects($this->never())->method('findValue');
        (new OrdererPrefillService($fields))->forMember(0, 10, null);
    }

    private function fields(array $values): MemberCustomFieldQueryInterface
    {
        $fields = $this->createMock(MemberCustomFieldQueryInterface::class);
        $fields->method('findValue')->willReturnCallback(
            static function (int $memberId, int $domainId, string $name) use ($values): mixed {
                self::assertSame(3, $memberId);
                self::assertSame(10, $domainId);
                return $values[$name] ?? null;
            }
        );
        return $fields;
    }
}
