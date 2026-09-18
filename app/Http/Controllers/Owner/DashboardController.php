<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Review;
use App\Models\Service;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;

        if (!$business) {
            // First time business owner without business yet
            return view('owner.business.setup');
        }

        $branchIds = $business->branches()->pluck('id');

        // Revenue from completed bookings
        $totalRevenue = Booking::whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->sum('total_price');

        $monthlyRevenue = Booking::whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->whereMonth('appointment_date', Carbon::now()->month)
            ->whereYear('appointment_date', Carbon::now()->year)
            ->sum('total_price');

        $totalBookings = Booking::whereIn('branch_id', $branchIds)->count();
        $pendingBookingsCount = Booking::whereIn('branch_id', $branchIds)
            ->where('status', 'pending')
            ->count();

        // Today's appointments
        $todayBookings = Booking::with(['customer', 'branch', 'bookingServices.service', 'bookingServices.staff'])
            ->whereIn('branch_id', $branchIds)
            ->whereDate('appointment_date', Carbon::today())
            ->orderBy('appointment_date', 'asc')
            ->get();

        // Recent reviews needing reply
        $unrepliedReviews = Review::with(['customer', 'branch', 'booking'])
            ->whereIn('branch_id', $branchIds)
            ->whereNull('business_reply')
            ->latest()
            ->take(5)
            ->get();

        $stats = [
            'total_revenue' => $totalRevenue,
            'monthly_revenue' => $monthlyRevenue,
            'total_bookings' => $totalBookings,
            'pending_bookings' => $pendingBookingsCount,
            'branches_count' => $branchIds->count(),
            'services_count' => Service::whereIn('branch_id', $branchIds)->count(),
            'staff_count' => Staff::whereIn('branch_id', $branchIds)->count(),
        ];

        return view('owner.dashboard', compact(
            'business',
            'stats',
            'todayBookings',
            'unrepliedReviews'
        ));
    }
}
