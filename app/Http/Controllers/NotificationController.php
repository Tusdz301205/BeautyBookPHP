<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return view('notifications.index', [
            'notifications' => DB::table('notifications')->where('user_id', $request->user()->id)
                ->orderByDesc('id')->paginate(20),
            'unreadCount' => DB::table('notifications')->where('user_id', $request->user()->id)
                ->where('is_read', false)->count(),
        ]);
    }

    public function read(Request $request, int $notification)
    {
        $row = DB::table('notifications')->where('id', $notification)
            ->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        DB::table('notifications')->where('id', $row->id)->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return $request->expectsJson()
            ? response()->json(['read' => true])
            : back()->with('status', 'Đã đánh dấu thông báo đã đọc.');
    }
}
