<?php

namespace App;

enum MouseButton: int
{
    case LEFT = 0;
    case MIDDLE = 1;
    case RIGHT = 2;
    case RELEASED = 3;
    case WHEEL_UP = 64;
    case WHEEL_DOWN = 65;
}
