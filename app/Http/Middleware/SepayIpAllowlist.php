<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Optional filter in front of every SePay webhook: SEPAY_ALLOWED_IPS lists the addresses SePay publishes (single IPs or CIDR, IPv4 and
 * IPv6). Empty means no filter (the HMAC signature still authenticates every receipt). A list that is set but has no readable entry
 * refuses everything: a typo must close the door, not open it. Behind a proxy set TRUSTED_PROXIES so the client address is the real one.
 */
class SepayIpAllowlist
{
    /** @return list<string> the entries that are really an address or a range */
    public static function entries(string $list): array
    {
        $entries = [];
        foreach (preg_split('/[\s,;]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) continue;
            $max = str_contains($ip, ':') ? 128 : 32;
            if ($bits !== null && (! ctype_digit($bits) || (int) $bits > $max)) continue;
            $entries[] = $entry;
        }

        return $entries;
    }

    public function handle(Request $request, Closure $next)
    {
        $list = trim((string) config('services.sepay.allowed_ips'));
        if ($list === '') return $next($request);
        $entries = self::entries($list);
        abort_unless($entries !== [] && IpUtils::checkIp((string) $request->ip(), $entries), 403, 'Address not allowed');

        return $next($request);
    }
}
