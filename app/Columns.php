<?php

declare(strict_types=1);

namespace App;

use Illuminate\Support\Collection;

class Columns extends Collection
{
    public function active(): Column
    {
        return $this->firstWhere('isActive', true);
    }

    public function setActive(Column $newColumn): Column
    {
        // Use foreach instead of each() to ensure we modify the original objects
        foreach ($this as $column) {
            $column->isActive = false;
        }

        $newColumn->isActive = true;

        return $newColumn;
    }

    /**
     * Return the column after the currently active column
     * If we're at the end, wrap around to the first column
     */
    public function nextColumn(?Column $currentColumn = null): Column
    {
        $fromColumn = $currentColumn ?? $this->active();
        $activeIndex = $this->search(fn (Column $column) => $column === $fromColumn);
        $nextIndex = $activeIndex + 1;
        if ($nextIndex >= $this->count()) {
            $nextIndex = 0;
        }

        return $this->get($nextIndex);
    }

    /**
     * Return the column before the currently active column
     * If we're at the beginning, wrap around to the last column
     */
    public function previousColumn(?Column $currentColumn = null): Column
    {
        $fromColumn = $currentColumn ?? $this->active();
        $activeIndex = $this->search(fn (Column $column) => $column === $fromColumn);
        $previousIndex = $activeIndex - 1;
        if ($previousIndex < 0) {
            $previousIndex = $this->count() - 1;
        }

        return $this->get($previousIndex);
    }
}
