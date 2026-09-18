<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BookingStatusHistory;
use App\Models\Branch;
use App\Models\BranchWorkingHour;
use App\Models\Business;
use App\Models\District;
use App\Models\Province;
use App\Models\Review;
use App\Models\SavedService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BeautyBookSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Users
        $owner = User::create([
            'name' => 'Nguyễn Văn Chủ',
            'email' => 'owner@beautybook.vn',
            'phone' => '0901234567',
            'password' => Hash::make('password'),
            'role' => 'business_owner',
            'gender' => 'male',
            'date_of_birth' => '1990-05-15',
            'address' => '123 Nguyễn Huệ, Quận 1, TP. Hồ Chí Minh',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $customer = User::create([
            'name' => 'Trần Thị Mai',
            'email' => 'customer@beautybook.vn',
            'phone' => '0912345678',
            'password' => Hash::make('password'),
            'role' => 'customer',
            'gender' => 'female',
            'date_of_birth' => '1996-08-20',
            'address' => '456 Lê Duẩn, Quận 1, TP. Hồ Chí Minh',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $customer2 = User::create([
            'name' => 'Lê Hoàng Nam',
            'email' => 'nam@beautybook.vn',
            'phone' => '0987654321',
            'password' => Hash::make('password'),
            'role' => 'customer',
            'gender' => 'male',
            'date_of_birth' => '1994-11-10',
            'address' => '78 Cầu Giấy, Hà Nội',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Find locations
        $hcm = Province::where('name', 'Hồ Chí Minh')->first();
        $q1 = District::where('province_id', $hcm?->id)->where('name', 'Quận 1')->first();
        $q3 = District::where('province_id', $hcm?->id)->where('name', 'Quận 3')->first();

        $hanoi = Province::where('name', 'Hà Nội')->first();
        $cauGiay = District::where('province_id', $hanoi?->id)->where('name', 'Quận Cầu Giấy')->first();
        $hoanKiem = District::where('province_id', $hanoi?->id)->where('name', 'Quận Hoàn Kiếm')->first();

        // 2. Businesses
        $business1 = Business::create([
            'owner_id' => $owner->id,
            'name' => 'Luxe Beauty & Spa',
            'slug' => 'luxe-beauty-spa',
            'description' => 'Viện thẩm mỹ & spa chăm sóc sắc đẹp cao cấp theo tiêu chuẩn quốc tế. Chuyên điều trị chuyên sâu về da, thư giãn toàn thân với các liệu pháp tự nhiên kết hợp công nghệ hiện đại.',
            'logo' => 'https://images.unsplash.com/photo-1540555700478-4be289fbecef?auto=format&fit=crop&w=400&q=80',
            'phone' => '02838999888',
            'email' => 'contact@luxespa.vn',
            'website' => 'https://luxespa.vn',
            'tax_code' => '0315894123',
            'status' => 'active',
        ]);

        $business2 = Business::create([
            'owner_id' => $owner->id,
            'name' => 'Aura Hair Studio & Academy',
            'slug' => 'aura-hair-studio',
            'description' => 'Hệ thống salon tóc thời thượng hàng đầu, quy tụ đội ngũ chuyên gia tạo mẫu tóc được đào tạo tại Hàn Quốc và Anh Quốc.',
            'logo' => 'https://images.unsplash.com/photo-1560066984-138dadb4c035?auto=format&fit=crop&w=400&q=80',
            'phone' => '02437666999',
            'email' => 'booking@aurahair.vn',
            'website' => 'https://aurahair.vn',
            'tax_code' => '0108965412',
            'status' => 'active',
        ]);

        // 3. Branches
        $branch1 = Branch::create([
            'business_id' => $business1->id,
            'district_id' => $q1?->id,
            'name' => 'Luxe Spa - Chi nhánh Bến Nghé Quận 1',
            'slug' => 'luxe-spa-ben-nghe-q1',
            'address' => '88 Nguyễn Huệ, Phường Bến Nghé, Quận 1',
            'phone' => '02838999881',
            'email' => 'q1@luxespa.vn',
            'latitude' => 10.7745,
            'longitude' => 106.7032,
            'status' => 'active',
        ]);

        $branch2 = Branch::create([
            'business_id' => $business1->id,
            'district_id' => $hoanKiem?->id,
            'name' => 'Luxe Spa - Chi nhánh Hoàn Kiếm Hà Nội',
            'slug' => 'luxe-spa-hoan-kiem',
            'address' => '12 Tràng Tiền, Quận Hoàn Kiếm, Hà Nội',
            'phone' => '02439111222',
            'email' => 'hn@luxespa.vn',
            'latitude' => 21.0252,
            'longitude' => 105.8574,
            'status' => 'active',
        ]);

        $branch3 = Branch::create([
            'business_id' => $business2->id,
            'district_id' => $cauGiay?->id,
            'name' => 'Aura Hair - Flagship Cầu Giấy',
            'slug' => 'aura-hair-cau-giay',
            'address' => '105 Xuân Thủy, Dịch Vọng Hậu, Cầu Giấy',
            'phone' => '02437666991',
            'email' => 'caugiay@aurahair.vn',
            'latitude' => 21.0368,
            'longitude' => 105.7828,
            'status' => 'active',
        ]);

        // Branch working hours
        foreach ([$branch1, $branch2, $branch3] as $branch) {
            for ($day = 0; $day <= 6; $day++) {
                BranchWorkingHour::create([
                    'branch_id' => $branch->id,
                    'day_of_week' => $day,
                    'open_time' => '08:30:00',
                    'close_time' => '21:00:00',
                    'is_closed' => false,
                ]);
            }
        }

        // 4. Service Categories
        $catSkincare = ServiceCategory::create([
            'business_id' => $business1->id,
            'name' => 'Chăm sóc da & Liệu trình Facial',
            'slug' => 'cham-soc-da-facial',
            'description' => 'Các gói chăm sóc da chuyên sâu, phục hồi và cấp ẩm',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $catMassage = ServiceCategory::create([
            'business_id' => $business1->id,
            'name' => 'Massage thư giãn & Thải độc Body',
            'slug' => 'massage-body',
            'description' => 'Trị liệu bấm huyệt, đá nóng và tinh dầu thiên nhiên',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $catHairCut = ServiceCategory::create([
            'business_id' => $business2->id,
            'name' => 'Cắt & Tạo kiểu tóc',
            'slug' => 'cat-tao-kieu-toc',
            'description' => 'Thiết kế kiểu tóc phù hợp gương mặt và xu hướng mới nhất',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $catHairColor = ServiceCategory::create([
            'business_id' => $business2->id,
            'name' => 'Uốn - Nhuộm - Phục hồi',
            'slug' => 'uon-nhuom-phuc-hoi',
            'description' => 'Hóa chất cao cấp L’Oréal, Olaplex bảo vệ tối đa cấu trúc tóc',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        // 5. Services
        $svc1 = Service::create([
            'branch_id' => $branch1->id,
            'category_id' => $catSkincare->id,
            'name' => 'Liệu trình HydroFacial Làm sạch sâu & Cấp ẩm tức thì',
            'slug' => 'hydrofacial-cap-am-sau',
            'description' => 'Sử dụng công nghệ xoáy nước hút sạch bã nhờn sâu trong lỗ chân lông, kết hợp đẩy tinh chất Hyaluronic Acid giúp da căng bóng sáng mịn màng.',
            'duration_minutes' => 60,
            'price' => 550000,
            'discount_price' => 450000,
            'status' => 'active',
            'is_bookable' => true,
        ]);

        $svc2 = Service::create([
            'branch_id' => $branch1->id,
            'category_id' => $catMassage->id,
            'name' => 'Massage Body đá nóng Thụy Điển & Tinh dầu Trầm',
            'slug' => 'massage-body-da-nong',
            'description' => 'Giải tỏa căng thẳng cơ bắp, kích thích lưu thông tuần hoàn máu với đá nóng bazan tự nhiên và tinh dầu thảo mộc cao cấp.',
            'duration_minutes' => 75,
            'price' => 650000,
            'discount_price' => null,
            'status' => 'active',
            'is_bookable' => true,
        ]);

        $svc3 = Service::create([
            'branch_id' => $branch2->id,
            'category_id' => $catSkincare->id,
            'name' => 'Cấy tảo xoắn Nano trẻ hóa & se khít chân lông',
            'slug' => 'cay-tao-xoan-nano',
            'description' => 'Kích thích tăng sinh collagen tự thân, phục hồi bề mặt da sần sùi và thâm mụn hiệu quả.',
            'duration_minutes' => 60,
            'price' => 600000,
            'discount_price' => 499000,
            'status' => 'active',
            'is_bookable' => true,
        ]);

        $svc4 = Service::create([
            'branch_id' => $branch3->id,
            'category_id' => $catHairCut->id,
            'name' => 'Combo Cắt tóc Layer/Bob + Gội đầu dưỡng sinh thảo dược',
            'slug' => 'combo-cat-toc-goi-duong-sinh',
            'description' => 'Tư vấn kiểu tóc theo tỉ lệ gương mặt, gội đầu thư giãn bấm huyệt vùng đầu vai gáy chuyên sâu.',
            'duration_minutes' => 50,
            'price' => 300000,
            'discount_price' => 250000,
            'status' => 'active',
            'is_bookable' => true,
        ]);

        $svc5 = Service::create([
            'branch_id' => $branch3->id,
            'category_id' => $catHairColor->id,
            'name' => 'Nhuộm màu thời trang Balayage / Ombre chuẩn Châu Âu',
            'slug' => 'nhuom-balayage-ombre',
            'description' => 'Kỹ thuật nhuộm chuyển màu tự nhiên, không lộ chân tóc khi mọc dài. Tặng kèm hấp dưỡng phục hồi sâu.',
            'duration_minutes' => 150,
            'price' => 1500000,
            'discount_price' => 1250000,
            'status' => 'active',
            'is_bookable' => true,
        ]);

        // 6. Staff
        $staff1 = Staff::create([
            'branch_id' => $branch1->id,
            'name' => 'Hoàng Thu Thảo',
            'position' => 'Chuyên viên da liễu cao cấp',
            'bio' => '7 năm kinh nghiệm chăm sóc da công nghệ cao và phục hồi da nhạy cảm.',
            'phone' => '0911223344',
            'status' => 'active',
        ]);

        $staff2 = Staff::create([
            'branch_id' => $branch1->id,
            'name' => 'Phạm Lan Anh',
            'position' => 'Kỹ thuật viên Trị liệu Spa',
            'bio' => 'Chuyên sâu massage bấm huyệt cổ truyền và trị liệu đá nóng.',
            'phone' => '0922334455',
            'status' => 'active',
        ]);

        $staff3 = Staff::create([
            'branch_id' => $branch3->id,
            'name' => 'Lê Minh Bảo',
            'position' => 'Art Director / Master Stylist',
            'bio' => 'Tu nghiệp tại Toni&Guy Academy Singapore, chuyên thiết kế form tóc hiện đại.',
            'phone' => '0933445566',
            'status' => 'active',
        ]);

        // Attach services to staff
        $staff1->services()->sync([$svc1->id]);
        $staff2->services()->sync([$svc2->id]);
        $staff3->services()->sync([$svc4->id, $svc5->id]);

        // 7. Bookings
        // Booking 1: Completed with review
        $booking1 = Booking::create([
            'booking_code' => 'BK-' . strtoupper(Str::random(8)),
            'customer_id' => $customer->id,
            'branch_id' => $branch1->id,
            'appointment_date' => Carbon::now()->subDays(3)->setTime(10, 0),
            'total_duration_minutes' => 60,
            'total_price' => 450000,
            'status' => 'completed',
            'notes' => 'Khách da nhạy cảm, nhờ chuyên viên tư vấn kỹ',
        ]);

        BookingService::create([
            'booking_id' => $booking1->id,
            'service_id' => $svc1->id,
            'staff_id' => $staff1->id,
            'service_name' => $svc1->name,
            'price' => 450000,
            'duration_minutes' => 60,
        ]);

        BookingStatusHistory::create([
            'booking_id' => $booking1->id,
            'from_status' => null,
            'to_status' => 'pending',
            'changed_by' => $customer->id,
            'note' => 'Khách hàng tạo lịch hẹn trực tuyến',
        ]);

        BookingStatusHistory::create([
            'booking_id' => $booking1->id,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'changed_by' => $owner->id,
            'note' => 'Spa xác nhận lịch hẹn',
        ]);

        BookingStatusHistory::create([
            'booking_id' => $booking1->id,
            'from_status' => 'confirmed',
            'to_status' => 'completed',
            'changed_by' => $owner->id,
            'note' => 'Đã hoàn thành dịch vụ',
        ]);

        // Review for Booking 1
        Review::create([
            'booking_id' => $booking1->id,
            'customer_id' => $customer->id,
            'branch_id' => $branch1->id,
            'overall_rating' => 5,
            'comment' => 'Dịch vụ HydroFacial tại Luxe Spa cực kỳ êm ái, nhân viên Thảo làm rất nhẹ nhàng, da mình sau khi làm căng bóng thấy rõ! Không gian yên tĩnh, sạch sẽ và mùi tinh dầu rất thơm.',
            'is_anonymous' => false,
            'status' => 'approved',
            'business_reply' => 'Luxe Spa chân thành cảm ơn bạn Mai đã tin tưởng và ủng hộ! Rất mong được đón tiếp bạn trong các liệu trình tiếp theo.',
            'replied_at' => Carbon::now()->subDays(2),
        ]);

        // Booking 2: Confirmed (upcoming)
        $booking2 = Booking::create([
            'booking_code' => 'BK-' . strtoupper(Str::random(8)),
            'customer_id' => $customer->id,
            'branch_id' => $branch3->id,
            'appointment_date' => Carbon::now()->addDays(2)->setTime(14, 30),
            'total_duration_minutes' => 50,
            'total_price' => 250000,
            'status' => 'confirmed',
            'notes' => 'Muốn cắt tóc layer ngắn trẻ trung',
        ]);

        BookingService::create([
            'booking_id' => $booking2->id,
            'service_id' => $svc4->id,
            'staff_id' => $staff3->id,
            'service_name' => $svc4->name,
            'price' => 250000,
            'duration_minutes' => 50,
        ]);

        BookingStatusHistory::create([
            'booking_id' => $booking2->id,
            'from_status' => null,
            'to_status' => 'pending',
            'changed_by' => $customer->id,
            'note' => 'Khách hàng tạo lịch hẹn trực tuyến',
        ]);

        BookingStatusHistory::create([
            'booking_id' => $booking2->id,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'changed_by' => $owner->id,
            'note' => 'Aura Salon đã xác nhận xếp lịch cho Master Stylist Bảo',
        ]);

        // Booking 3: Pending
        $booking3 = Booking::create([
            'booking_code' => 'BK-' . strtoupper(Str::random(8)),
            'customer_id' => $customer2->id,
            'branch_id' => $branch1->id,
            'appointment_date' => Carbon::now()->addDays(1)->setTime(16, 0),
            'total_duration_minutes' => 75,
            'total_price' => 650000,
            'status' => 'pending',
            'notes' => 'Đặt lịch massage thư giãn sau giờ làm',
        ]);

        BookingService::create([
            'booking_id' => $booking3->id,
            'service_id' => $svc2->id,
            'staff_id' => $staff2->id,
            'service_name' => $svc2->name,
            'price' => 650000,
            'duration_minutes' => 75,
        ]);

        // 8. Saved Services (Favorites)
        SavedService::create([
            'user_id' => $customer->id,
            'service_id' => $svc1->id,
        ]);

        SavedService::create([
            'user_id' => $customer->id,
            'service_id' => $svc4->id,
        ]);

        SavedService::create([
            'user_id' => $customer2->id,
            'service_id' => $svc2->id,
        ]);
    }
}
