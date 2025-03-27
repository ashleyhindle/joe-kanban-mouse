<?php

namespace App;

use App\Bounds;
use App\Boundable;

class ContextMenuOption
{
    use Boundable;

    /**
     * @param string $label
     * @param callable $action(Kanban $kanban): void
     */
    public function __construct(
        public string $label,
        public $action,
        public string $hoverColor = 'cyan',
    ) {}
}
