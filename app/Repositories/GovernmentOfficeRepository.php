<?php

namespace App\Repositories;

use App\Models\GovernmentOffice;

class GovernmentOfficeRepository
{
    public function getDirectorOfLands(): GovernmentOffice
    {
        return GovernmentOffice::where('title', 'director_of_lands')->firstOrFail();
    }
}
