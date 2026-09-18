<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $upcomingBookings = Booking::with(['branch.business', 'bookingServices.service', 'bookingServices.staff'])
            ->where('customer_id', $user->id)
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
            ->orderBy('appointment_date', 'asc')
            ->take(5)
            ->get();

        $recentBookings = Booking::with(['branch.business', 'bookingServices.service', 'review'])
            ->where('customer_id', $user->id)
            ->whereIn('status', ['completed', 'cancelled', 'no_show'])
            ->latest('appointment_date')
            ->take(5)
            ->get();

        $stats = [
            'total_bookings' => Booking::where('customer_id', $user->id)->count(),
            'completed' => Booking::where('customer_id', $user->id)->where('status', 'completed')->count(),
            'upcoming' => $upcomingBookings->count(),
            'saved_services' => $user->savedServices()->count(),
        ];

        return view('customer.dashboard', compact(
            'upcomingBookings',
            'recentBookings',
            'stats'
        ));
    }
}
