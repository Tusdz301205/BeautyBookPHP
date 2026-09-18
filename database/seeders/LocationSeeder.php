<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            'Hà Nội' => [
                'Quận Ba Đình',
                'Quận Hoàn Kiếm',
                'Quận Tây Hồ',
                'Quận Cầu Giấy',
                'Quận Đống Đa',
                'Quận Hai Bà Trưng',
                'Quận Thanh Xuân',
                'Quận Nam Từ Liêm',
                'Quận Bắc Từ Liêm',
                'Quận Hà Đông',
            ],
            'Hồ Chí Minh' => [
                'Quận 1',
                'Quận 3',
                'Quận 5',
                'Quận 7',
                'Quận 10',
                'Quận Bình Thạnh',
                'Quận Phú Nhuận',
                'Quận Tân Bình',
                'Quận Gò Vấp',
                'Thành phố Thủ Đức',
            ],
            'Đà Nẵng' => [
                'Quận Hải Châu',
                'Quận Thanh Khê',
                'Quận Sơn Trà',
                'Quận Ngũ Hành Sơn',
                'Quận Cẩm Lệ',
            ],
            'Hải Phòng' => [
                'Quận Hồng Bàng',
                'Quận Ngô Quyền',
                'Quận Lê Chân',
            ],
            'Cần Thơ' => [
                'Quận Ninh Kiều',
                'Quận Cái Răng',
                'Quận Bình Thủy',
            ],
            'Bình Dương' => [
                'Thành phố Thủ Dầu Một',
                'Thành phố Thuận An',
                'Thành phố Dĩ An',
            ],
        ];

        foreach ($locations as $provinceName => $districts) {
            $provinceId = DB::table('provinces')->insertGetId([
                'name' => $provinceName,
                'slug' => Str::slug($provinceName),
            ]);

            foreach ($districts as $districtName) {
                DB::table('districts')->insert([
                    'province_id' => $provinceId,
                    'name' => $districtName,
                    'slug' => Str::slug($districtName),
                ]);
            }
        }
    }
}
