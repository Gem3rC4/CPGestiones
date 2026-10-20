<?php

namespace App\Repositories;

use App\Models\CardApplication;

class ApplicationRepository
{
    public function find(string $id): CardApplication
    {
        return CardApplication::findOrFail($id);
    }

    public function create(array $data): CardApplication
    {
        return CardApplication::create($data);
    }
}
