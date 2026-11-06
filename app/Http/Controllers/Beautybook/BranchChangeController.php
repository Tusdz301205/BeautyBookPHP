<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\{Branch, BranchChangeRequest};
use App\Services\Beautybook\{ApiScope, SourceDistrictCatalog};
use App\Services\Branches\BranchChangeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchChangeController extends Controller
{
    public function __construct(private ApiScope $scope, private BranchChangeService $changes) {}

    public function index(Request $request, int $branchId): array
    {
        $branch = $this->scope->branch($request, $branchId);
        $this->scope->owner($request, $branch->business_id);

        return $this->scope->paginate(BranchChangeRequest::where('branch_id', $branchId)->latest('id'), $request, fn ($row) => $this->present($row));
    }

    public function store(Request $request, int $branchId): array
    {
        $branch = $this->scope->branch($request, $branchId);
        $this->scope->owner($request, $branch->business_id);
        $data = $request->validate([
            'addressLine' => 'required|string|min:3|max:255', 'districtId' => ['required','string',Rule::in(array_column(app(SourceDistrictCatalog::class)->all(),'id'))],
            'ward' => 'nullable|string|max:160', 'registrationType' => ['required', Rule::in(BranchChangeService::TYPES)],
            'mediaId' => 'required|integer|min:1', 'reason' => 'nullable|string|max:2000',
        ]);

        return $this->present($this->changes->create($request, $branchId, $data));
    }

    public function withdraw(Request $request, int $branchId, int $requestId): array
    {
        $data = $request->validate(['expectedVersion' => 'required|integer|min:1']);

        return $this->present($this->changes->decide($request, $branchId, $requestId, $data['expectedVersion'], 'WITHDRAW'));
    }

    public function queue(Request $request): array
    {
        $this->scope->platform($request);
        $data = $request->validate(['status' => 'sometimes|in:PENDING,APPROVED,REJECTED,WITHDRAWN']);

        return $this->scope->paginate(BranchChangeRequest::where('status', $data['status'] ?? 'PENDING')->latest('id'), $request, fn ($row) => $this->present($row));
    }

    public function review(Request $request, int $id): array
    {
        $this->scope->platform($request);
        $data = $request->validate(['expectedVersion' => 'required|integer|min:1', 'decision' => 'required|in:APPROVE,REJECT', 'note' => 'required_if:decision,REJECT|nullable|string|min:1|max:2000']);
        $change = BranchChangeRequest::findOrFail($id);

        return $this->present($this->changes->decide($request, $change->branch_id, $id, $data['expectedVersion'], $data['decision'], $data['note'] ?? null));
    }

    private function present(BranchChangeRequest $row): array
    {
        $branch = Branch::findOrFail($row->branch_id);

        return ['id' => (string) $row->id, 'branchId' => (string) $row->branch_id, 'branchName' => $branch->name,
            'status' => $row->status, 'version' => $row->version, 'baseSnapshot' => $row->base_snapshot,
            'proposedSnapshot' => $row->proposed_snapshot, 'reason' => $row->reason, 'reviewNote' => $row->review_note,
            'mediaId' => (string) $row->media_id, 'evidenceUrl' => '/api/v1/media/'.$row->media_id.'/content',
            'createdAt' => $row->created_at?->toIso8601String(), 'reviewedAt' => $row->reviewed_at?->toIso8601String()];
    }
}
