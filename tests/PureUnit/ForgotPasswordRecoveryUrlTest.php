<?php

declare(strict_types=1);

namespace PureUnit;

use App\Controller\Frontend\Account\ForgotPasswordAction;
use App\Http\RouterInterface;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;

final class ForgotPasswordRecoveryUrlTest extends TestCase
{
    public function testRecoveryUrlUsesConfiguredServerUrl(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('buildBaseUrl')
            ->with(false)
            ->willReturn(new Uri('https://radio.example.test/base'));
        $router->expects(self::once())
            ->method('named')
            ->with('account:login-token', ['token' => 'reset-token'])
            ->willReturn('/account/login/reset-token');

        self::assertSame(
            'https://radio.example.test/account/login/reset-token',
            ForgotPasswordAction::buildRecoveryUrl($router, 'reset-token')
        );
    }
}
