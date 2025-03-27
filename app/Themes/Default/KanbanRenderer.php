<?php

namespace App\Themes\Default;

use App\Bounds;
use App\Card;
use App\Column;
use App\ContextMenu;
use App\ContextMenuOption;
use App\Kanban;
use Laravel\Prompts\Themes\Default\Concerns\DrawsBoxes;
use Laravel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use Laravel\Prompts\Themes\Default\Renderer;
use SoloTerm\Grapheme\Grapheme;

// TODO: Add ColumnRenderer and CardRenderer classes for better readability/management
class KanbanRenderer extends Renderer
{
    use DrawsBoxes, InteractsWithStrings;

    public int $cursorX = 1;
    public int $cursorY = 1;

    public function __invoke(Kanban $kanban): string
    {
        $this->cursorX = 1;
        $this->cursorY = 1;
        // Available width of terminal minus some buffer
        $totalWidth = $kanban->terminal()->cols() - 16;

        // Available height of terminal minus some buffer
        $totalHeight = $kanban->terminal()->lines() - 7;

        // Calculate column width with more conservative spacing
        $columnWidth = (int) floor($totalWidth / count($kanban->columns)) - 2;
        $cardWidth = $columnWidth - 6;

        $columns = $kanban->columns->map(function (Column $column, int $columnIndex) use (
            $cardWidth,
            $columnWidth,
            $kanban,
            $totalHeight
        ) {
            $currentLine = 3;
            // First column _card_ starts at 7, second column _card_ starts at 53, third column _card_ starts at 98
            $columnStartX = ($columnIndex * $columnWidth) + (($columnIndex + 1) * 4) + $columnIndex; // Padding, border, space, border | | | ?
            $cardsOutput = collect($column->cards)->map(
                function (Card $card) use (
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
                        PHP_EOL.$card->description.PHP_EOL,
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

            $cardContent = PHP_EOL.$cardsOutput->implode(PHP_EOL);
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
        $result = PHP_EOL.collect($columns->first())
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
            $result .= $this->getMoveCursorTo($x, $y);

            // Add the dragging card output
            $this->setDraggingCardOutput($kanban->draggingCard, $cardWidth);
            $draggingLines = explode(PHP_EOL, $kanban->draggingCard->output);
            foreach ($draggingLines as $i => $line) {
                $result .= $this->getMoveCursorTo($x, $y + $i);
                $result .= $line;
            }
            // Return cursor to bottom
            $result .= $this->getMoveCursorTo(1, $kanban->terminal()->lines() - 1);
        }

        if ($kanban->contextMenu) {
            $result .= $this->getContextMenuOutput($kanban->contextMenu, $kanban);
        }

        return $result;
    }

    private function getContextMenuOutput(ContextMenu $contextMenu, Kanban $kanban)
    {
        $moveCursor = $this->getMoveCursorTo($contextMenu->x, $contextMenu->y);
        // X = ' | ' (padding, border, padding)
        $this->getMoveCursorTo($contextMenu->x + 3, $contextMenu->y + 1); // When we put the menu options inside the menu box, there'll be padding/borders and the bounds of the options will actually be further in/down than the contextMenu->x and y

        // TODO: Fix the fake cursor positioning to calculate bounds. This is related to the 'fit on screen' thing below too, so 2 wins at once

        // TODO: If the menu won't fit on the screen, we need to offset the menu to the left
        // But we don't know if it will fit until we generate it, which also sets the bounds
        // So if we then _render_ it differently to where we've generated it, we'll need to offset the bounds _too_ which is messy, hmmm
        // Can we set the bounds only on render?

        $maxOptionWidth = $contextMenu->options->max(fn (ContextMenuOption $option) => strlen($option->label));
        $menuOptionOutput = trim($contextMenu->options->map(function (ContextMenuOption $option) use ($contextMenu, $maxOptionWidth, $kanban) {
            [$output, $bounds, $newX, $newY] = $this->getBoundableBoxOutput('', $option->label, $option === $kanban->highlightedContextMenuOption ? $option->hoverColor : 'dim', $maxOptionWidth);
            $option->setBounds($bounds);
            $this->getMoveCursorTo($contextMenu->x + 3, $newY); // Fake move cursor to set cursorX/cursorY so bounds are accurate - TODO: Make this better :grimace:
            return $output;
        })->implode(''));

        $output = $this->getBoxOutput(
            'Card menu',
            $menuOptionOutput,
            'green',
            $maxOptionWidth
        );

        $lines = explode(PHP_EOL, $output);
        foreach ($lines as $i => $line) {
            $lines[$i] = $this->getMoveCursorTo($contextMenu->x, $contextMenu->y + $i);
            $lines[$i] .= $line;
        }

        return $moveCursor . trim(implode('', $lines));
    }

    private function setDraggingCardOutput(Card $card, int $cardWidth)
    {
        $output = $this->getBoxOutput(
            $card->title,
            PHP_EOL.$card->description.PHP_EOL,
            'yellow',
            $cardWidth
        );

        $card->setBounds(new Bounds(999, 999, 999, 999)); // It doesn't really _have_ bounds right now
        $card->setOutput($output);
    }

    private function getMoveCursorTo(int $x, int $y): string
    {
        $this->cursorX = $x;
        $this->cursorY = $y;

        return sprintf("\033[%d;%dH", $y, $x);
    }

    private function renderHelp(Kanban $kanban, string $result): string
    {
        return $result.PHP_EOL.PHP_EOL
            .$this->dim('(Click/Drag) Move card  ')
            .$this->dim('(Mouse wheel) Select card up/down  ')
            .$this->dim('(Click) Move card forward  ')
            .$this->dim('(Right click) Context menu  ')
            .$this->dim('(n) New card  ')
            .$this->dim('(q) Quit');
    }

    /** @return array<string, Bounds, int, int> */
    protected function getBoundableBoxOutput(string $title, string $body, string $color, int $width): array
    {
        // Wrap any lines of the body that are too long to the width
        $bodyLines = explode(PHP_EOL, $body);
        $bodyLines = array_map(function (string $line) use ($width) {
            return wordwrap($line, $width, PHP_EOL, true);
        }, $bodyLines);
        $body = implode(PHP_EOL, $bodyLines);
        $output = $this->getBoxOutput($title, $body, $color, $width);

        // Calculate the bounds of the output
            // We need to know the width and height of the output to do this
            // And we need to know our current cursor position, so we can convert the width/height to x/y
        $paddingStartX = 1;
        $paddingStartY = 0;
        $paddingEndX = 1;
        $paddingEndY = 1;
        $height = count(explode(PHP_EOL, trim($output)));
        $cleanLines = explode(PHP_EOL, $this->stripEscapeSequences($output));
        $width = max(array_map(fn (string $line) => Grapheme::wcwidth($line), $cleanLines));

        // Calculate the bounds of the output
        $bounds = new Bounds(
            startX: $this->cursorX + $paddingStartX,
            endX: $this->cursorX + $width - $paddingEndX,
            startY: $this->cursorY + $paddingStartY,
            endY: $this->cursorY + $height - $paddingEndY
        );

        $newX = $this->cursorX + $width;
        $newY = $this->cursorY + $height;
        return [$output, $bounds, $newX, $newY];
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
