<?php

declare(strict_types=1);

namespace App;

use Illuminate\Support\Collection;

class Column
{
    use Boundable;

    public int $position;

    public string $title;

    /** @var Collection<Card> */
    public Collection $cards;

    public bool $isActive = false;

    public function __construct(int $position, string $title, ?Collection $cards = null, bool $isActive = false)
    {
        $this->position = $position;
        $this->title = $title;
        $this->cards = $cards ?? collect();
        $this->isActive = $isActive;
    }

    public function addCard(Card $card): void
    {
        $this->cards->push($card);
    }

    public function removeCard(Card $card): void
    {
        $this->cards = $this->cards->reject(fn (Card $existingCard) => $existingCard === $card)->values();
    }
}
