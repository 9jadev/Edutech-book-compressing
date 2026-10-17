<?php

namespace App\Repositories;

use App\Models\Parcel;
use Illuminate\Support\Str;

class LandRegistryRepository
{
    public function issueDeed(Parcel $parcel, int $directorId): bool
    {
        $parcel->deed_number = 'COF-' . strtoupper(Str::random(10));
        $parcel->signed_by_director_id = $directorId;
        $parcel->c_of_o_signed_at = now();
        return $parcel->save();
    }

    public function findParcelByDeed(string $deedNumber): ?Parcel
    {
        return Parcel::where('deed_number', $deedNumber)->first();
    }
}
