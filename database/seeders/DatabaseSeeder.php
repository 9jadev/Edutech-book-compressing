<?php

namespace Database\Seeders;

use App\Models\Player;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Sovereign State Treasury
        $treasury = \App\Models\Player::create([
            'name' => 'Sovereign State Treasury',
            'email' => 'treasury@state.gov',
            'password' => \Illuminate\Support\Facades\Hash::make('secret'),
            'balance' => 0.00,
            'is_government' => true,
        ]);

        // 2. Appfather
        $appfather = \App\Models\Player::create([
            'name' => 'Appfather',
            'email' => 'email@email.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password0123'),
            'balance' => 1000000.00,
            'is_government' => false,
        ]);

        // 3. Government Office
        \App\Models\GovernmentOffice::create([
            'title' => 'director_of_lands',
            'current_holder_id' => $appfather->id,
            'signature_fee' => 25000.00,
            'tenure_expires_at' => now()->addDays(30),
        ]);

        // 4. District 0: Capital
        $district = \App\Models\District::create([
            'name' => 'Capital',
            'grid_size' => 10,
            'base_price' => 250000.00,
            'is_active' => true,
        ]);

        // 5. Parcels (10x10)
        $governmentCoords = [
            ['x' => 4, 'y' => 4, 'name' => 'State House'],
            ['x' => 4, 'y' => 5, 'name' => 'Lands Bureau'],
            ['x' => 5, 'y' => 4, 'name' => 'Central Bank'],
            ['x' => 5, 'y' => 5, 'name' => 'University'],
        ];

        for ($x = 0; $x < 10; $x++) {
            for ($y = 0; $y < 10; $y++) {
                $isGov = false;
                $facilityName = null;

                foreach ($governmentCoords as $coord) {
                    if ($coord['x'] === $x && $coord['y'] === $y) {
                        $isGov = true;
                        $facilityName = $coord['name'];
                        break;
                    }
                }

                if ($isGov) {
                    \App\Models\Parcel::create([
                        'district_id' => $district->id,
                        'grid_x' => $x,
                        'grid_y' => $y,
                        'owner_id' => $treasury->id,
                        'price' => 0.00,
                        'status' => 'active',
                        'facility_name' => $facilityName,
                        'facility_type' => 'government_building',
                    ]);
                } else {
                    \App\Models\Parcel::create([
                        'district_id' => $district->id,
                        'grid_x' => $x,
                        'grid_y' => $y,
                        'owner_id' => null,
                        'price' => 250000.00,
                        'status' => 'available',
                    ]);
                }
            }
        }
    }
}
