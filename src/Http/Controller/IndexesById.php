<?php

declare(strict_types=1);

namespace App\Http\Controller;

trait IndexesById
{
    /**
     * @param list<object{id: int}> $items
     * @return array<int, object>
     */
    private function indexById(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item->id] = $item;
        }

        return $indexed;
    }
}
