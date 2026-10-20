<?php

namespace App\Repositories;

use App\Models\Document;

class OperationsRepository
{
    public function query(string $model)
    {
        return $model::query();
    }

    public function get(string $model, string $id): Document
    {
        return $model::findOrFail($id);
    }

    public function create(string $model, array $data): Document
    {
        return $model::create($data);
    }

    public function list(string $model, array $filters = [], int $page = 1, int $size = 25)
    {
        $query = $model::query();
        foreach ($filters as $key => $value) {
            if ($value !== null && $value !== '') {
                $query->where($key, $value);
            }
        }

        return $query->orderBy('created_at', 'desc')->paginate(min(max($size, 1), 100), ['*'], 'page', max(1, $page));
    }
}
