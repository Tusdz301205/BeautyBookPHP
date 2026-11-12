<?php

namespace App\Services\Payments;

use App\Http\Middleware\SepayIpAllowlist;
use Illuminate\Support\Facades\DB;

/**
 * What must be true before direct deposits take real money, as a list of lines "OK|WARN|FAIL <what>". A FAIL means money could be
 * lost, taken twice or exposed. No line ever contains a secret or an account number: only names of settings and counts.
 */
class GoLiveCheck
{
    /** @return list<array{0:string,1:string}> level and message */
    public static function run(): array
    {
        $production = DirectDeposit::production();
        $strict = fn (string $message) => [$production ? 'FAIL' : 'WARN', $message];
        $lines = [];
        $lines[] = $production ? ['OK', 'APP_ENV is production'] : ['WARN', 'APP_ENV is not production: the strict checks below are reported as warnings'];
        $lines[] = config('app.debug') ? $strict('APP_DEBUG is true: error pages would expose configuration') : ['OK', 'APP_DEBUG is false'];
        $lines[] = str_starts_with(strtolower((string) config('app.url')), 'https://') ? ['OK', 'APP_URL is https'] : $strict('APP_URL is not https: SePay delivers webhooks to https addresses only');
        $lines[] = DirectDeposit::enabled() ? ['OK', 'Direct deposits are enabled'] : ['FAIL', 'DIRECT_DEPOSIT_ENABLED is false: no deposit would be taken'];
        $lines[] = preg_match('/^[a-f0-9]{64}$/i', (string) config('wallet.direct.encryption_key')) ? ['OK', 'SEPAY_CONNECTION_ENCRYPTION_KEY is set (64 hex characters)'] : ['FAIL', 'SEPAY_CONNECTION_ENCRYPTION_KEY must be 64 hex characters: connection secrets cannot be sealed'];
        $lines[] = trim((string) config('wallet.mode')) === '' ? ['OK', 'BEAUTYBOOK_PAYMENT_MODE is empty (the simulated wallet is off)'] : ['FAIL', 'BEAUTYBOOK_PAYMENT_MODE is set: the wallet mode wins over direct deposits'];
        $lines[] = config('wallet.live_lab.isolated') || config('wallet.live_lab.enabled') ? $strict('The private Live lab is switched on') : ['OK', 'The private Live lab is off'];
        $ips = trim((string) config('services.sepay.allowed_ips'));
        if ($ips === '') $lines[] = ['WARN', 'SEPAY_ALLOWED_IPS is empty: any address may post receipts (each is still HMAC-signed and checked against its connection)'];
        else $lines[] = SepayIpAllowlist::entries($ips) === [] ? ['FAIL', 'SEPAY_ALLOWED_IPS has no readable address: every receipt would be refused'] : ['OK', 'SEPAY_ALLOWED_IPS lists '.count(SepayIpAllowlist::entries($ips)).' SePay address(es)'];

        if (DB::getSchemaBuilder()->hasTable('payment_connections')) {
            $live = DB::table('payment_connections')->where('environment', 'live')->where('enabled', 1)->where('status', 'READY')->distinct()->count('business_id');
            $test = DB::table('payment_connections')->where('environment', 'test')->where('enabled', 1)->count();
            $lines[] = $live > 0 ? ['OK', $live.' business(es) take live deposits'] : ['WARN', 'No business has an enabled live connection yet'];
            if ($test > 0 && $production && ! DirectDeposit::allowTest()) $lines[] = ['FAIL', $test.' enabled Test mode connection(s) on a production server: customers would be shown a test account'];
        }

        return $lines;
    }
}
