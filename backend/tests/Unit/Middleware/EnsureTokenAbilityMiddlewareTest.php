<?php
namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureTokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnsureTokenAbilityMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_when_token_has_ability(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['messaging:sms']);

        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn() => auth()->user());

        $result = (new EnsureTokenAbility)->handle($request, fn() => response('ok'), 'messaging:sms');
        $this->assertEquals('ok', $result->getContent());
    }

    public function test_blocks_when_token_lacks_ability(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['messaging:email']);

        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn() => auth()->user());

        $result = (new EnsureTokenAbility)->handle($request, fn() => response('ok'), 'messaging:sms');
        $this->assertEquals(403, $result->getStatusCode());
    }

    public function test_blocks_when_no_user(): void
    {
        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn() => null);

        $result = (new EnsureTokenAbility)->handle($request, fn() => response('ok'), 'messaging:sms');
        $this->assertEquals(403, $result->getStatusCode());
    }
}
