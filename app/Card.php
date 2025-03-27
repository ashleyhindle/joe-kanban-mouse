<?php

declare(strict_types=1);

namespace App;

class Card
{
    use Boundable;

    public Column $column; // Recursive reference?!

    public int $id;

    public string $title;

    public string $description;

    public function __construct(int $id, string $title, string $description, Column $column)
    {
        $this->id = $id;
        $this->title = $title;
        $this->description = $description;
        $this->column = $column;
    }
}
