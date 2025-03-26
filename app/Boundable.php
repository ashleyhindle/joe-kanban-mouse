<?php

declare(strict_types=1);

namespace App;

use App\Bounds;
use SoloTerm\Grapheme\Grapheme;

trait Boundable
{
    public Bounds $bounds;
    public string $output;
    public int $width; // in columns
    public int $height; // in lines
    // We don't _actually_ use 'x' and 'y' pixels anywhere. X and Y is just common terminology, we're still using columns/lines.

    public function setBounds(Bounds $bounds): void
    {
        $this->bounds = $bounds;
    }

    public function setOutput(string $output): void
    {
        $this->output = $output;
        $this->width = max(array_map(fn (string $line) => Grapheme::wcwidth($line), explode(PHP_EOL, $output)));
        $this->height = count(explode(PHP_EOL, $output));
    }
}
