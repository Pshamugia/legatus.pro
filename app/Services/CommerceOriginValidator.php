<?php

namespace App\Services;

final class CommerceOriginValidator
{
    public function normalize(string $origin): string
    {
        return $this->connectionTarget($origin)['origin'];
    }

    /**
     * @return array{origin: string, host: string, addresses: list<string>, is_ip_literal: bool}
     */
    public function connectionTarget(string $origin): array
    {
        if ($origin === '' || trim($origin) !== $origin || preg_match('/[\x00-\x20\x7F]/', $origin)) {
            throw new \InvalidArgumentException('The connector URL must be an exact public HTTPS origin.');
        }

        $parts = parse_url($origin);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new \InvalidArgumentException('The connector URL must use standard-port HTTPS with no path, query, fragment, or credentials.');
        }

        $host = strtolower((string) $parts['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        } elseif (str_contains($host, '[') || str_contains($host, ']')) {
            throw new \InvalidArgumentException('The connector host is malformed.');
        }
        if ($host === '' || str_ends_with($host, '.')) {
            throw new \InvalidArgumentException('The connector host must resolve only to public internet addresses.');
        }

        $isIpLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $this->publicAddresses($host);
        if ($addresses === []) {
            throw new \InvalidArgumentException('The connector host must resolve only to public internet addresses.');
        }

        $formattedHost = str_contains($host, ':') ? "[{$host}]" : $host;

        return [
            'origin' => "https://{$formattedHost}",
            'host' => $host,
            'addresses' => $addresses,
            'is_ip_literal' => $isIpLiteral,
        ];
    }

    /** @return list<string> */
    private function publicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host) ? [$host] : [];
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return [];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        if ($addresses === [] || ! collect($addresses)->every(fn (string $address): bool => $this->isPublicIp($address))) {
            return [];
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
