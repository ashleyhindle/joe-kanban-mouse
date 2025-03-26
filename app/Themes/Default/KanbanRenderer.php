<?php

namespace App\Themes\Default;

use App\Bounds;
use App\Card;
use App\Column;
use App\Kanban;
use Laravel\Prompts\Themes\Default\Concerns\DrawsBoxes;
use Laravel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use Laravel\Prompts\Themes\Default\Renderer;

// TODO: Add ColumnRenderer and CardRenderer classes for better readability/management
class KanbanRenderer extends Renderer
{
    use DrawsBoxes, InteractsWithStrings;

    public function __invoke(Kanban $kanban): string
    {
        // Available width of terminal minus some buffer
        $totalWidth = $kanban->terminal()->cols() - 16;

        // Available height of terminal minus some buffer
        $totalHeight = $kanban->terminal()->lines() - 7;

        // Calculate column width with more conservative spacing
        $columnWidth = (int) floor($totalWidth / count($kanban->columns)) - 2;
        $cardWidth =  $columnWidth - 6;

        $columns = $kanban->columns->map(function (Column $column, int $columnIndex) use (
            $cardWidth,
            $columnWidth,
            $kanban,
            $totalHeight
        ) {
            $currentLine = 3;
            // First column _card_ starts at 7, second column _card_ starts at 53, third column _card_ starts at 98
            $columnStartX = ($columnIndex * $columnWidth) + (($columnIndex+1) * 4) + $columnIndex; // Padding, border, space, border | | | ?
            $cardsOutput = collect($column->cards)->map(
                function (Card $card) use (
                    $columnIndex,
                    $columnStartX,
                    $cardWidth,
                    $kanban,
                    &$currentLine
                ) {
                    if ($kanban->draggingCard === $card) {
                        // We don't want to render the dragging card here, as it's being moved
                        // So we need to render it where the mouse pointer is above everything else
                        return '';
                    }

                    $cardStartLine = $currentLine;
                    if ($kanban->hoveringCard === $card) {
                        $cardColor = 'cyan';
                    } elseif ($kanban->selectedCard === $card) {
                        $cardColor = 'green';
                    } else {
                        $cardColor = 'dim';
                    }

                    $output = $this->getBoxOutput(
                        $card->title,
                        PHP_EOL . $card->description . PHP_EOL,
                        $cardColor,
                        $cardWidth
                    );

                    $cardLines = count(explode(PHP_EOL, $output));
                    // Store the card's position
                    $bounds = new Bounds(
                        $columnStartX + 1,
                        $columnStartX + $cardWidth + 4, // padding, border, padding
                        $cardStartLine + 1,
                        $cardStartLine + $cardLines - 1, // padding
                    );

                    $card->setBounds($bounds); // TODO: Calculate bounds based on the output so it's more accurate
                    $card->setOutput($output);

                    $currentLine += $cardLines;

                    return $output;
                }
            );

            $column->setBounds(new Bounds(
                $columnStartX,
                $columnStartX + $columnWidth,
                $currentLine,
                $currentLine + $totalHeight
            )); // TODO: Calculate bounds based on the output so it's more accurate

            $cardContent = PHP_EOL . $cardsOutput->implode(PHP_EOL);
            $cardContent .= str_repeat(PHP_EOL, $totalHeight - count(explode(PHP_EOL, $cardContent)) + 1);

            $columnContent = $this->getBoxOutput(
                $column->isActive ? $this->cyan($column->title) : $this->dim($column->title),
                $cardContent,
                $column->isActive ? 'cyan' : 'dim',
                $columnWidth
            );

            $column->setOutput($columnContent);

            return explode(PHP_EOL, $columnContent);
        });

        // Zip the columns together for proper display
        $result = PHP_EOL . collect($columns->first())
            ->zip(...$columns->map(fn ($column) => collect($column))->slice(1))
            ->map(fn ($row) => $row->filter()->implode(''))
            ->implode(PHP_EOL);

        $result = $this->renderHelp($kanban, $result);

        // Render the dragging card on top of the existing output
        // by moving the cursor to the mouse position, writing a line, then moving the cursor down to write the next line
        if ($kanban->draggingCard) {
            // Calculate X position ensuring it stays within screen bounds
            $maxX = $kanban->terminal()->cols() - $cardWidth - 4; // -4 for padding and borders
            $x = max(0, min($maxX, $kanban->mouse->x - $kanban->draggingCardOffsetX));

            // Calculate Y position ensuring it stays within screen bounds
            $cardHeight = count(explode(PHP_EOL, $kanban->draggingCard->output));
            $maxY = $kanban->terminal()->lines() - $cardHeight;
            $y = max(1, min($maxY, $kanban->mouse->y - $kanban->draggingCardOffsetY));

            // Position cursor for dragging card using the pre-calculated offsets
            $result .= sprintf("\033[%d;%dH", $y, $x);

            // Add the dragging card output
            $this->setDraggingCardOutput($kanban->draggingCard, $cardWidth);
            $draggingLines = explode(PHP_EOL, $kanban->draggingCard->output);
            foreach ($draggingLines as $i => $line) {
                $result .= sprintf("\033[%d;%dH", $y + $i, $x);
                $result .= $line;
            }
            // Return cursor to bottom
            $result .= "\033[" . ($kanban->terminal()->lines() - 1) . ";1H";
        }

        return $result;
    }

    private function setDraggingCardOutput(Card $card, int $cardWidth)
    {
        $output = $this->getBoxOutput(
            $card->title,
            PHP_EOL . $card->description . PHP_EOL,
            'yellow',
            $cardWidth
        );

        $card->setBounds(new Bounds(999, 999, 999, 999)); // It doesn't really _have_ bounds right now
        $card->setOutput($output);
    }

    private function renderHelp(Kanban $kanban, string $result): string
    {
        return $result . PHP_EOL . PHP_EOL
            . $this->dim('(Click/Drag) Move card  ')
            . $this->dim('(Mouse wheel) Select card up/down  ')
            . $this->dim('(Click) Move card forward  ')
            . $this->dim('(Right click) Move card back  ')
            . $this->dim('(n) New card  ')
            . $this->dim('(q) Quit');
    }

    protected function getBoxOutput(string $title, string $body, string $color, int $width): string
    {
        // Reset the output string
        $this->output = '';

        // Set the minWidth to the desired width
        $this->minWidth = $width;

        $this->box(
            $title,
            $body,
            '',
            $color
        );

        $content = $this->output;

        $this->output = '';

        return $content;
    }
}
