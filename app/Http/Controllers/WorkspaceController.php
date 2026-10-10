<?php

namespace App\Http\Controllers;

use App\Models\RoleAssignment;
use App\Services\Identity\SessionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        return view('workspace.index', [
            'assignments' => $request->user()
                ->roleAssignments()
                ->with(['business', 'branch'])
                ->orderBy('id')
                ->get(),
            'current' => app(SessionContext::class)->current($request),
        ]);
    }

    public function store(Request $request, SessionContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'assignment_id' => ['required', 'integer'],
        ]);

        $assignment = RoleAssignment::query()
            ->whereKey($validated['assignment_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $context->select($request, $assignment);

        return redirect()->route('app')->with('status', 'workspace-selected');
    }
}
