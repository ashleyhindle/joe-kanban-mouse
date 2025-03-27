<?php

declare(strict_types=1);

namespace App;

use Illuminate\Support\Collection;

class ContextMenu
{
    /** @param Collection<ContextMenuOption> $options */
    public function __construct(
        public int $x,
        public int $y,
        public Collection $options,
    ) {}
}
