<?php

namespace Tests\Feature\Models\Master;

use App\Models\Master\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

final class UserAuthenticatableTest extends TestCase
{
    public function test_authentication_uses_the_master_user_model(): void
    {
        $provider = Auth::guard()->getProvider();

        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertInstanceOf(EloquentUserProvider::class, $provider);
        $this->assertSame(User::class, $provider->getModel());
    }

    public function test_master_user_implements_authentication_without_password_or_remember_columns(): void
    {
        $user = new User;

        $this->assertInstanceOf(Authenticatable::class, $user);
        $this->assertSame('id', $user->getAuthIdentifierName());
        $this->assertSame('', $user->getAuthPassword());
        $this->assertSame('', $user->getRememberTokenName());
        $this->assertNull($user->getRememberToken());

        $user->setRememberToken('x');

        $this->assertArrayNotHasKey('remember_token', $user->getAttributes());
    }
}
