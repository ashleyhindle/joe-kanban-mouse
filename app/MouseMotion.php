<?php

namespace App;

enum MouseMotion: int
{
    // Motion events with buttons held
    case MOTION_LEFT = 32;      // 32 + BUTTON_LEFT
    case MOTION_MIDDLE = 33;    // 32 + BUTTON_MIDDLE
    case MOTION_RIGHT = 34;     // 32 + BUTTON_RIGHT
    case MOTION = 35;      // 32 + BUTTON_RELEASED (motion with no buttons pressed)
}
