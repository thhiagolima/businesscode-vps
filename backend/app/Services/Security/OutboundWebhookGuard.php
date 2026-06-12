<?php

namespace App\Services\Security;

/**
 * Validates outbound webhook URLs against SSRF.
 *
 * Rejects:
 *   - Non-http(s) schemes (file://, ftp://, gopher://, dict://, …)
 *   - Hostnames that resolve only to private/loopback/link-local IPs
 *   - IPv4 ranges: 0.0.0.0/8, 10.0.0.0/8, 127.0.0.0/8, 169.254.0.0/16, 172.16.0.0/12, 192.168.0.0/16
 *   - IPv6 ranges: ::1, fc00::/7 (ULA), fe80::/10 (link-local), ::ffff:0:0/96 (mapped IPv4)
 *
 * Resolves DNS at validate time AND host is re-validated post-resolution; the HTTP client
 * should re-resolve at fetch time to fully close DNS-rebinding holes.
 */
class OutboundWebhookGuard
{
    /**
     * Resolve a URL para o primeiro IP público válido E retorna um array
     * `['host' => ..., 'port' => ..., 'ip' => ...]` que pode ser passado ao
     * curl via `CURLOPT_RESOLVE` para fechar a janela TOCTOU de DNS rebinding.
     *
     * O atacante pode controlar o DNS para retornar IP público no primeiro
     * lookup (nosso validate) e IP interno no segundo (curl). Pinando o IP
     * resolvido no curl evitamos o re-lookup.
     *
     * @throws OutboundWebhookGuardException
     */
    public function pinnedResolution(string $url): array
    {
        $this->assertSafeUrl($url);

        $parts = parse_url($url);
        $host = trim((string) $parts['host'], '[]');
        $scheme = strtolower((string) $parts['scheme']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Se já é IP literal, devolve direto.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ['host' => $host, 'port' => $port, 'ip' => $host];
        }

        // Primeiro IP que assertSafeUrl validou como público.
        foreach ($this->resolve($host) as $ip) {
            if (! $this->isBlockedIp($ip)) {
                return ['host' => $host, 'port' => $port, 'ip' => $ip];
            }
        }
        // Não deve ocorrer (assertSafeUrl já bloquearia), mas guard de cinto.
        throw new OutboundWebhookGuardException("Webhook host has no safe IP: {$host}");
    }

    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new OutboundWebhookGuardException("Webhook URL is malformed: {$url}");
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new OutboundWebhookGuardException("Webhook scheme not allowed: {$scheme}");
        }

        $host = trim($parts['host'], '[]'); // strip IPv6 brackets

        // Reject obvious local/internal hostnames before DNS lookup.
        $lowerHost = strtolower($host);
        if (in_array($lowerHost, ['localhost', 'localhost.localdomain', 'broadcasthost'], true)) {
            throw new OutboundWebhookGuardException("Webhook host is local: {$host}");
        }

        $ips = $this->resolve($host);
        if (empty($ips)) {
            throw new OutboundWebhookGuardException("Webhook host did not resolve: {$host}");
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new OutboundWebhookGuardException("Webhook resolves to blocked IP: {$ip}");
            }
        }
    }

    private function resolve(string $host): array
    {
        // If already an IP literal, return as-is.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        $a = @dns_get_record($host, DNS_A);
        if (is_array($a)) {
            foreach ($a as $r) {
                if (! empty($r['ip'])) $ips[] = $r['ip'];
            }
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $r) {
                if (! empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }

        // Fall back to gethostbyname (returns hostname unchanged on failure).
        if (empty($ips)) {
            $v4 = gethostbyname($host);
            if ($v4 !== $host && filter_var($v4, FILTER_VALIDATE_IP)) {
                $ips[] = $v4;
            }
        }

        return array_unique($ips);
    }

    private function isBlockedIp(string $ip): bool
    {
        // Reject anything that is NOT a global routable address.
        // FILTER_FLAG_NO_PRIV_RANGE blocks RFC1918 (and IPv6 ULA);
        // FILTER_FLAG_NO_RES_RANGE blocks loopback/link-local/multicast/reserved.
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $isPublic === false;
    }
}
