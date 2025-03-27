<?php

namespace App;

class MouseEvent
{
    /** @var MouseMotion|MouseButton */
    public function __construct(public $mouseEvent, public int $x = 0, public int $y = 0) {}
}
