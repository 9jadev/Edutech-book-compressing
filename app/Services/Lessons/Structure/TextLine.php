<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

/**
 * One line of text from a chapter PDF, with the font facts used to spot headings.
 * Lines that belong together (a heading wrapped over two lines) are merged into one TextLine.
 */
final class TextLine
{
    public function __construct(
        public readonly int $page,
        public readonly int $column,       // 0 = left / full width, 1 = right column
        public readonly float $top,
        public readonly float $left,
        public readonly float $size,
        public readonly bool $bold,
        public readonly string $color,
        public readonly string $text,
    ) {}

    /** Lines with the same style were set the same way by the typesetter. */
    public function style(): string
    {
        return $this->size.'|'.($this->bold ? 'b' : 'r').'|'.$this->color;
    }

    /**
     * Sort key for reading order: page, then column, then top to bottom.
     *
     * @return array{int, int, float, float}
     */
    public function orderKey(): array
    {
        return [$this->page, $this->column, $this->top, $this->left];
    }

    public function comesBefore(self $other): bool
    {
        return $this->orderKey() < $other->orderKey();
    }

    public function withText(string $text): self
    {
        return new self($this->page, $this->column, $this->top, $this->left, $this->size, $this->bold, $this->color, $text);
    }
}
