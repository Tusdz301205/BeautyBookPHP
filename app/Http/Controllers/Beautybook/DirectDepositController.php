<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Services\Payments\DirectDepositService;
use Illuminate\Http\Request;

/** direct-deposit.controller.ts: authority lives in the service (owner per business, customer/salon per booking); the webhook is public and signed. */
class DirectDepositController extends Controller
{
    public function __construct(private DirectDepositService $service) {}

    private function body(Request $r): array
    {
        $body = $r->json()->all();
        if (! is_array($body) || ($body !== [] && array_is_list($body))) abort(400, 'Invalid body');

        return $body;
    }

    public function connections(Request $r, int $businessId)
    {
        return response()->json($this->service->connections($r, $businessId))->header('Cache-Control', 'no-store');
    }

    public function create(Request $r, int $businessId)
    {
        return response()->json($this->service->createConnection($r, $businessId, $this->body($r)), 201)->header('Cache-Control', 'no-store');
    }

    public function enable(Request $r, int $businessId, int $id)
    {
        return $this->service->enableConnection($r, $businessId, $id, $this->body($r));
    }

    public function read(Request $r, int $id)
    {
        return response()->json($this->service->read($r, $id))->header('Cache-Control', 'no-store');
    }

    public function action(Request $r, int $id, string $action)
    {
        return response()->json($this->service->action($r, $id, $action, $this->body($r)))->header('Cache-Control', 'no-store');
    }

    public function adminRefunds(Request $r, \App\Services\Payments\DirectDepositAdminService $admin)
    {
        return response()->json($admin->refunds($r))->header('Cache-Control', 'no-store');
    }

    public function adminReceipts(Request $r, \App\Services\Payments\DirectDepositAdminService $admin)
    {
        return response()->json($admin->receipts($r))->header('Cache-Control', 'no-store');
    }

    public function adminReviewReceipt(Request $r, int $id, \App\Services\Payments\DirectDepositAdminService $admin)
    {
        return response()->json($admin->reviewReceipt($r, $id, $this->body($r)))->header('Cache-Control', 'no-store');
    }

    /** Public to the session guard: the HMAC of this connection's own secret is verified before anything is stored. */
    public function webhook(Request $r, int $id)
    {
        return response()->json($this->service->webhook($r, $id))->header('Cache-Control', 'no-store');
    }
}
