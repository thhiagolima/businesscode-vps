<?php

namespace Tests\Unit\Security;

use App\Services\Security\OutboundWebhookGuard;
use App\Services\Security\OutboundWebhookGuardException;
use PHPUnit\Framework\TestCase;

class OutboundWebhookGuardTest extends TestCase
{
    private OutboundWebhookGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new OutboundWebhookGuard();
    }

    public function test_rejects_loopback_ipv4(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://127.0.0.1/x');
    }

    public function test_rejects_loopback_via_alternate_notation(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://127.1/x');
    }

    public function test_rejects_imds_aws(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://169.254.169.254/latest/meta-data');
    }

    public function test_rejects_link_local_subnet(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://169.254.1.1/x');
    }

    public function test_rejects_rfc1918_10_0_0_0(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://10.0.0.5/x');
    }

    public function test_rejects_rfc1918_172_16(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://172.20.0.5/x');
    }

    public function test_rejects_rfc1918_192_168(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://192.168.1.1/x');
    }

    public function test_rejects_ipv6_loopback(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://[::1]/x');
    }

    public function test_rejects_unique_local_ipv6_fc00(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://[fd00::1]/x');
    }

    public function test_rejects_localhost_hostname(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://localhost/x');
    }

    public function test_rejects_zero_address(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('http://0.0.0.0/x');
    }

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('file:///etc/passwd');
    }

    public function test_rejects_ftp_scheme(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('ftp://example.com/x');
    }

    public function test_rejects_garbage_url(): void
    {
        $this->expectException(OutboundWebhookGuardException::class);
        $this->guard->assertSafeUrl('not a url at all');
    }

    public function test_accepts_public_https_url(): void
    {
        // 8.8.8.8 (Google DNS) is a routable public IP, always.
        $this->guard->assertSafeUrl('https://8.8.8.8/webhook');
        $this->assertTrue(true);
    }

    public function test_accepts_public_hostname(): void
    {
        // example.com always resolves to a public IP.
        $this->guard->assertSafeUrl('https://example.com/webhook');
        $this->assertTrue(true);
    }
}
