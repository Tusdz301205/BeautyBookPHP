<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Services\Reviews\AbsaContract;
use App\Services\Reviews\AbsaReadService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AbsaController extends Controller
{
    public function __construct(private AbsaReadService $reader) {}

    public function summary(Request $request): array
    {
        return $this->reader->summary($request, $this->query($request));
    }

    public function reviews(Request $request): array
    {
        return $this->reader->reviews($request, $this->query($request, true));
    }

    private function query(Request $request, bool $list = false): array
    {
        $rules = [
            'branchId' => ['required', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'visibility' => ['sometimes', Rule::in(['PUBLIC', 'PRIVATE'])],
        ];
        if ($request->filled('from')) $rules['to'][] = 'after_or_equal:from';
        if ($list) $rules += [
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'aspect' => ['sometimes', Rule::in(AbsaContract::ASPECTS)],
            'sentiment' => ['sometimes', Rule::in(['POS', 'NEG', 'NEU'])],
            'status' => ['sometimes', Rule::in(AbsaReadService::STATUSES)],
        ];

        return $request->validate($rules);
    }
}
