<?php

namespace App;

class Bounds
{
    public function __construct(
        public int $startX,
        public int $endX,
        public int $startY,
        public int $endY,
    ) {}
}
