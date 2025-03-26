<?php

namespace App;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use App\Mouse;

use function Laravel\Prompts\text;

class Kanban extends Prompt
{
    public string|bool $required = false;

    public Columns $columns;

    protected int $autoIncrementId = 0;
    public ?Card $selectedCard = null;
    public ?Card $hoveringCard = null;
    public ?Card $draggingCard = null;
    protected ?Card $mouseDownCard = null;

    public Mouse $mouse;
    protected ?MouseButton $lastMouseButton = null;

    public int $mouseDownX;
    public int $mouseDownY;

    // Card offset, so we drag the card from where we clicked in it, not from the top left of mouse pointer
    public int $draggingCardOffsetX = 0;
    public int $draggingCardOffsetY = 0;

    public function __construct()
    {
        static::$themes['default'][Kanban::class] = \App\Themes\Default\KanbanRenderer::class;
        $this->setupColumns();
        $this->setupMouseListening();
        $this->listenForKeys();
    }

    public function setupColumns(): Columns
    {
        $todoColumn = new Column(position: 1, title: 'To Do', isActive: true);
        $inProgressColumn = new Column(position: 2, title: 'In Progress');
        $doneColumn = new Column(position: 3, title: 'Done');

        $this->columns = new Columns();

        $this->columns->push($todoColumn);
        $this->columns->push($inProgressColumn);
        $this->columns->push($doneColumn);

        $this->columns->setActive($todoColumn);
        $firstCard = new Card(id: ++$this->autoIncrementId, title: 'Test arrow keys', description: 'Why not press enter?', column: $todoColumn);
        $this->selectedCard = $firstCard;

        $todoColumn->addCard($firstCard);
        $todoColumn->addCard(new Card(id: ++$this->autoIncrementId, title: 'Click a card', description: 'It will move 👀', column: $todoColumn));
        $todoColumn->addCard(new Card(id: ++$this->autoIncrementId, title: 'Right click one now!', description: '⇽ ⇽ ⇽ ⇽ ⇽', column: $todoColumn));

        $inProgressColumn->addCard(new Card(id: ++$this->autoIncrementId, title: 'Scroll wheel in a column', description: 'You will need multiple cards', column: $inProgressColumn));
        $inProgressColumn->addCard(new Card(id: ++$this->autoIncrementId, title: 'This should help', description: 'Awesome', column: $inProgressColumn));

        $doneColumn->addCard(new Card(id: ++$this->autoIncrementId, title: 'Click & Drag', description: 'In a CLI? Dumb, but fun!', column: $doneColumn));
        $doneColumn->addCard(new Card(id: ++$this->autoIncrementId, title: 'Click a column', description: 'Switch columns!', column: $doneColumn));
        return $this->columns;
    }

    protected function setupMouseListening(): void
    {
        $this->mouse = new Mouse();
        static::writeDirectly($this->mouse->enable()); // Enable any-event tracking (tracks all mouse events)
        register_shutdown_function(function () {
            static::writeDirectly($this->mouse->disable()); // Disable any-event tracking
        });
    }

    protected function listenForMouse(string $key): void
    {
        $event = $this->mouse->parseEvent($key);
        $column = $this->getColumnAtPosition($event->x, $event->y);
        $card = $this->getCardAtPosition($event->x, $event->y);

        if ($column === $this->columns->active()) {
            if ($event->mouseEvent === MouseButton::WHEEL_UP) {
                $this->selectCardAbove();
                return;
            } elseif ($event->mouseEvent === MouseButton::WHEEL_DOWN) {
                $this->selectCardBelow();
                return;
            }
        } elseif ($column && $event->mouseEvent === MouseButton::RELEASED && $this->mouse->lastButtonDown === MouseButton::LEFT && $card === null && $this->draggingCard === null) {
            // We didn't click in the active column, so let's set the column they clicked in as active
            $this->setActiveColumn($column);
            return;
        }

        // Handle dragging a card
        if ($column !== null) {
            if ($event->mouseEvent === MouseMotion::MOTION_LEFT && $this->mouseDownCard !== null && $this->draggingCard === null) {
                // Starting to drag - calculate and store the offset
                $this->selectedCard = $this->mouseDownCard;
                $this->draggingCard = $this->selectedCard;
                $this->draggingCardOffsetX = $this->mouseDownX - $this->draggingCard->bounds->startX;
                $this->draggingCardOffsetY = $this->mouseDownY - $this->draggingCard->bounds->startY;
                $this->hoveringCard = $this->draggingCard;
            } elseif ($event->mouseEvent === MouseButton::RELEASED && $this->draggingCard !== null) {
                // Reset offsets when drag ends
                if ($column !== $this->draggingCard->column) {
                    $this->moveCard($this->draggingCard, $column);
                }
                $this->draggingCard = null;
                $this->draggingCardOffsetX = 0;
                $this->draggingCardOffsetY = 0;
                $this->hoveringCard = null;
                $this->mouseDownCard = null;
                return;
            }
        }

        // We're not hovering, clicking, dragging, etc.. with our cursor above a card
        if (!$card) {
            // If we're moving the mouse around but not over a card, reset hovering
            // But only if we're not dragging a card - a dragged card stays 'hovered' during the drag
            if ($event->mouseEvent === MouseMotion::MOTION && $this->draggingCard === null) {
                $this->hoveringCard = null;
            }
            return;
        }

        $cb = match($event->mouseEvent) {
            // Pushed left button down
            MouseButton::LEFT => function() use ($card, $event) {
                $this->mouseDownX = $event->x;
                $this->mouseDownY = $event->y;
                $this->mouseDownCard = $card;
            },

            // Pushed right button down
            MouseButton::RIGHT => function() { // They pushed the right button down, but $this->mouse tracks that for us, so we don't need to do anything
            },

            // Regular mouse movement over a card, they're hovering over it
            MouseMotion::MOTION => fn() => $this->hoveringCard = $card,

            // Mouse button released on top of a card - this is used for single clicks to move a card one column on click
            MouseButton::RELEASED => function() use ($card, $event) {
                if ($this->draggingCard) {
                    return;
                }

                if ($this->mouse->lastButtonDown === MouseButton::LEFT) {
                    // This was just a click (no drag), move forward one column
                    $this->moveCard($card, $this->columns->nextColumn($card->column));
                } elseif ($this->mouse->lastButtonDown === MouseButton::RIGHT) {
                    // Right click, move backward one column
                    $this->moveCard($card, $this->columns->previousColumn($card->column));
                }

                $this->mouse->lastButtonDown = null;
                $this->mouseDownCard = null;
            },

            default => null,
        };

        if ($cb) {
            $cb();
        }
    }

    protected function listenForKeys(): void
    {
        $this->on('key', function ($key) {
            // TODO: Why is SHIFT_DOWN never triggered? Huh interesting
            $shiftPressed = false;
            $enterPressed = false;
            // Mouse events are sent as \e[M, so we need to check for that
            if ($key[0] === "\e" && $key[2] === 'M') {
                $this->listenForMouse($key);
                return;
            }

            if ($key[0] === "\e") {
                match ($key) {
                    Key::UP, Key::UP_ARROW => $this->selectCardAbove(),
                    Key::DOWN, Key::DOWN_ARROW => $this->selectCardBelow(),
                    Key::RIGHT, Key::RIGHT_ARROW => $this->nextColumn(),
                    Key::LEFT, Key::LEFT_ARROW => $this->previousColumn(),
                    Key::SHIFT_DOWN => $shiftPressed = true,
                    Key::ENTER => $enterPressed = true,
                    default => null,
                };

                if (!$shiftPressed && !$enterPressed) {
                    return;
                }
            }

            // Keys may be buffered.
            foreach (mb_str_split($key) as $key) {
                match ($key) {
                    Key::ENTER => $enterPressed = true,
                    Key::SHIFT_DOWN => $shiftPressed = true,
                    'n' => $this->addNewItem(),
                    'q' => $this->quit(),
                    default => null,
                };
            }

            if ($enterPressed && $this->selectedCard) {
                if ($shiftPressed) {
                    $this->moveCard($this->selectedCard, $this->columns->previousColumn($this->selectedCard->column));
                } else {
                    $this->moveCard($this->selectedCard, $this->columns->nextColumn($this->selectedCard->column));
                }
                return;
            }
        });
    }

    protected function selectCardAbove(?Column $currentColumn = null): void
    {
        $currentColumn = $currentColumn ?? $this->columns->active();
        $currentIndex = $currentColumn->cards->search(fn (Card $card) => $card->id === $this->selectedCard->id);

        // If we're at the first card, wrap to the last card
        /** @var int $currentIndex */
        $newIndex = $currentIndex === 0 ? $currentColumn->cards->count() - 1 : $currentIndex - 1;

        $this->selectedCard = $currentColumn->cards->get($newIndex);
    }

    protected function selectCardBelow(?Column $currentColumn = null): void
    {
        $currentColumn = $currentColumn ?? $this->columns->active();
        $currentIndex = $currentColumn->cards->search(fn (Card $card) => $card->id === $this->selectedCard->id);

        // If we're at the last card, wrap to the first card
        /** @var int $currentIndex */
        $newIndex = $currentIndex === $currentColumn->cards->count() - 1 ? 0 : $currentIndex + 1;

        $this->selectedCard = $currentColumn->cards->get($newIndex);
    }

    protected function setActiveColumn(?Column $newActiveColumn = null): void
    {
        // Store the current vertical position in the old column
        $oldColumn = $this->columns->active();
        $currentIndex = $oldColumn->cards->search(fn (Card $card) => $card->id === $this->selectedCard->id);

        // Set the new active column
        $this->columns->setActive($newActiveColumn);

        // Try to maintain vertical position in the new column
        $newCard = $newActiveColumn->cards->get($currentIndex, $newActiveColumn->cards->last());
        $this->selectedCard = $newCard;
    }

    protected function nextColumn(?Column $currentColumn = null): void
    {
        $currentColumn = $currentColumn ?? $this->columns->active();
        $newActiveColumn = $this->columns->nextColumn($currentColumn);
        $this->setActiveColumn($newActiveColumn);
    }

    protected function previousColumn(?Column $currentColumn = null): void
    {
        $currentColumn = $currentColumn ?? $this->columns->active();
        $newActiveColumn = $this->columns->previousColumn($currentColumn);
        $this->setActiveColumn($newActiveColumn);
    }

    /**
     * @param Card $card
     * @param Column $newColumn
     * @return Card
     */
    protected function moveCard(Card $card, Column $newColumn): Card
    {
        // Remove this card from the old column
        $card->column->removeCard($card);

        // Add the card to the new column, at the end
        $newColumn->addCard($card);
        $card->column = $newColumn;

        // Set the new column as active, wherever the card goes
        $this->columns->setActive($newColumn);
        $this->selectedCard = $card;

        return $card;
    }

    protected function addNewItem(): void
    {
        // Clear our listeners so we don't capture the keypresses registered for the board
        $this->clearListeners();

        // Capture previous new lines rendered
        $this->capturePreviousNewLines();

        // Reset the cursor position back to the top
        $this->resetCursorPosition();

        // Erase everything that's currently on the screen
        $this->eraseDown();

        $title = text('Title', 'Title of task');
        $description = text('Description', 'Description of task');

        // Add the new item to the current column
        $card = new Card(id: $this->autoIncrementId++, title: $title, description: $description, column: $this->columns->active());
        $this->columns->active()->addCard($card);

        // Re-register our key listeners

        $this->listenForKeys();

        // Re-render the board

        $this->prompt();

    }

    protected function resetCursorPosition(): void
    {

        $lines = count(explode(PHP_EOL, $this->prevFrame)) - 1;

        $this->moveCursor(-999, $lines * -1);
    }

    protected function quit(): void
    {
        static::terminal()->exit();
    }

    public function value(): bool
    {
        return (bool) $this->state;
    }

    public function getColumnAtPosition(int $columnX, int $line): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->bounds->startX <= $columnX && $column->bounds->endX >= $columnX) {
                return $column;
            }
        }

        return null;
    }

    public function getCardAtPosition(int $x, int $y): ?Card
    {
        // We need to go through the columns in reverse order, so the last card at the same height 'wins'
        foreach ($this->columns->reverse() as $column) {
            foreach ($column->cards as $card) {
                if ($card->bounds->startX <= $x && $card->bounds->endX >= $x &&
                    $card->bounds->startY <= $y && $card->bounds->endY >= $y) {
                    return $card;
                }
            }
        }

        return null;
    }
}
