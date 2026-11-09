<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use App\Services\Beautybook\ApiScope;
use App\Services\Staff\StaffCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffCalendarController extends Controller
{
    public function __construct(private ApiScope $scope, private StaffCalendarService $calendar) {}

    public function publicHours(int $id): array
    {
        return $this->calendar->hours($this->publicStaff($id));
    }

    public function hours(Request $request, int $id): array
    {
        $staff = StaffProfile::findOrFail($id);
        $this->authorizeCalendar($request, $staff);

        return $this->calendar->hours($staff);
    }

    public function replaceHours(Request $request, int $id): array
    {
        $staff = StaffProfile::findOrFail($id);
        $grant = $this->authorizeCalendar($request, $staff, write: true);
        $validated = $request->validate([
            'days' => 'required|array|list|size:7',
            'days.*' => 'array:dayOfWeek,isOff,intervals',
            'days.*.dayOfWeek' => 'required|integer|between:0,6|distinct',
            'days.*.isOff' => 'required|boolean',
            'days.*.intervals' => 'present|array|list|max:24',
            'days.*.intervals.*' => 'array:startTime,endTime',
            'days.*.intervals.*.startTime' => ['required', 'string', 'regex:/\A(?:[01]\d|2[0-3]):[0-5]\d\z/'],
            'days.*.intervals.*.endTime' => ['required', 'string', 'regex:/\A(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)\z/'],
        ]);

        return $this->calendar->replaceHours($staff, $grant, $validated['days']);
    }

    public function publicTimeOff(Request $request, int $id): array
    {
        return $this->calendar->timeOff($this->publicStaff($id), $this->range($request), false);
    }

    public function timeOff(Request $request, int $id): array
    {
        $staff = StaffProfile::findOrFail($id);
        $this->authorizeCalendar($request, $staff);

        return $this->calendar->timeOff($staff, $this->range($request), true);
    }

    public function saveTimeOff(Request $request, int $id, ?int $offId = null): JsonResponse
    {
        $staff = StaffProfile::findOrFail($id);
        $grant = $this->authorizeCalendar($request, $staff, write: true);
        $input = $request->validate([
            'startsAt' => 'required|string|max:29',
            'endsAt' => 'required|string|max:29',
            'reason' => 'nullable|string|max:500',
        ]);

        return response()->json($this->calendar->saveTimeOff($staff, $grant, $input, $offId), $offId === null ? 201 : 200);
    }

    public function removeTimeOff(Request $request, int $id, int $offId): array
    {
        $staff = StaffProfile::findOrFail($id);
        $grant = $this->authorizeCalendar($request, $staff, write: true);

        return $this->calendar->removeTimeOff($staff, $grant, $offId);
    }

    private function authorizeCalendar(Request $request, StaffProfile $staff, bool $write = false): RoleAssignment
    {
        $roles = $write ? ['BUSINESS_OWNER'] : ['BUSINESS_OWNER', 'RECEPTIONIST'];

        return $this->scope->forRoles($request, $roles, $staff->business_id, $staff->branch_id);
    }

    private function publicStaff(int $id): StaffProfile
    {
        return StaffProfile::publicBookable()->whereHas('branch', fn ($query) => $query->publicReady())->findOrFail($id);
    }

    private function range(Request $request): array
    {
        return $request->validate([
            'from' => 'required|string|max:29',
            'to' => 'required|string|max:29',
            'page' => 'sometimes|integer|between:1,100000',
            'limit' => 'sometimes|integer|between:1,100',
        ]);
    }
}
