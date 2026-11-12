<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Services\Finance\SepayWebhookService;
use Illuminate\Http\Request;

/** Public to the session guard; the HMAC signature is verified before any database access (sepay-webhook.controller.ts). */
class SepayController extends Controller
{
    /** Ordinary receipts are stored only; they never credit a wallet or a booking. */
    public function webhook(Request $request, SepayWebhookService $service)
    {
        return response()->json($service->receive($request))->header('Cache-Control', 'no-store');
    }

    /** Private Live-lab channel: the receipt may credit a wallet top-up invoice exactly once. */
    public function liveLabWebhook(Request $request, SepayWebhookService $service)
    {
        return response()->json($service->receiveLiveLab($request))->header('Cache-Control', 'no-store');
    }
}
