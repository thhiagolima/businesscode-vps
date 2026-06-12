<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureRoleTest extends TestCase
{
    use RefreshDatabase;

    private function invoke(User $user, string $required)
    {
        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn() => $user);
        return (new EnsureRole)->handle($request, fn() => response('ok'), $required);
    }

    public function test_passes_for_exact_role(): void
    {
        $user = User::factory()->create(['role' => 'finance']);
        $resp = $this->invoke($user, 'finance');
        $this->assertEquals('ok', $resp->getContent());
    }

    public function test_passes_for_superadmin_wildcard(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $resp = $this->invoke($user, 'finance');
        $this->assertEquals('ok', $resp->getContent());
    }

    public function test_blocks_user_role(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $resp = $this->invoke($user, 'finance');
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function test_accepts_pipe_separated_list(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $resp = $this->invoke($user, 'admin|finance');
        $this->assertEquals('ok', $resp->getContent());
    }
}
