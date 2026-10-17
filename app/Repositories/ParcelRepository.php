<?php

namespace App\Repositories;

use App\Models\Parcel;
use Illuminate\Database\Eloquent\Collection;

class ParcelRepository
{
    public function getDistrictParcels(int $districtId): Collection
    {
        return Parcel::where('district_id', $districtId)->get();
    }

    public function lockForUpdate(int $parcelId): Parcel
    {
        return Parcel::where('id', $parcelId)->lockForUpdate()->firstOrFail();
    }

    public function getAvailableParcelsCount(int $districtId): int
    {
        return Parcel::where('district_id', $districtId)
                     ->where('status', 'available')
                     ->count();
    }

    public function assignOwner(Parcel $parcel, int $ownerId): bool
    {
        $parcel->owner_id = $ownerId;
        $parcel->status = 'pending_permit';
        return $parcel->save();
    }
}
