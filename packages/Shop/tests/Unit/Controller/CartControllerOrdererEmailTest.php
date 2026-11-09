<?php
declare(strict_types=1);

namespace Tests\Shop\Unit\Controller;

use Mublo\Packages\Shop\Controller\Front\CartController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CartControllerOrdererEmailTest extends TestCase
{
    #[DataProvider('cases')]
    public function testOrdererEmailValidation(string $email, bool $required, ?string $expected): void
    {
        $method = new ReflectionMethod(CartController::class, 'ordererEmailError');
        self::assertSame($expected, $method->invoke(null, $email, $required));
    }

    public static function cases(): array
    {
        return [
            'optional empty'      => ['', false, null],
            'optional valid'      => ['a@example.com', false, null],
            'optional invalid'    => ['not-mail', false, '이메일 형식이 올바르지 않습니다.'],
            'required empty'      => ['', true, '주문인 이메일을 입력해주세요.'],
            'required valid'      => ['a@example.com', true, null],
            'required invalid'    => ['a@', true, '이메일 형식이 올바르지 않습니다.'],
            'too long'            => [str_repeat('a', 250) . '@example.com', false, '이메일 형식이 올바르지 않습니다.'],
        ];
    }
}
