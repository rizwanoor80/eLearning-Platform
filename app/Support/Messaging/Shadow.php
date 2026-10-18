<?php

namespace App\Support\Messaging;

use LogicException;

/**
 * R231: a working copy of a message body that remembers, for every byte, which stretch of the original text it
 * came from. `SpelledOutPrePass` rewrites a copy ("at" becomes "@") so the unchanged masking chain can see the
 * shape; `MessageMasker` then maps whatever the chain hides back onto the original so the stored body shows
 * the sender's own words everywhere else.
 *
 * Every byte carries `from`/`to` (a half-open byte range of the original text) and `placeholder` (0, or the id
 * of the placeholder it belongs to). A replacement's bytes all carry the smallest range covering the bytes they
 * replaced, so a placeholder that later swallows part of another placeholder, or part of a rewritten word,
 * widens to the whole of it — never to a fragment. Pure; no I/O.
 */
final class Shadow
{
    /**
     * @param  list<int>  $from
     * @param  list<int>  $to
     * @param  list<int>  $placeholder
     */
    private function __construct(
        public readonly string $text,
        public readonly array $from,
        public readonly array $to,
        public readonly array $placeholder,
        private readonly int $nextId,
    ) {}

    /** The original text mapped onto itself: byte i comes from [i, i + 1). */
    public static function identity(string $text): self
    {
        $length = strlen($text);

        return new self($text, $length === 0 ? [] : range(0, $length - 1), $length === 0 ? [] : range(1, $length), array_fill(0, $length, 0), 1);
    }

    /**
     * Applies edits that do not overlap, in text order. Each edit is `[offset, length, pieces]` in THIS copy's
     * bytes, where `pieces` is a list of `[replacement text, start, end]` and `start`/`end` (relative to the
     * offset, `end > start`) say which of the replaced bytes that piece stands for. With `$asPlaceholder` each
     * edit becomes one placeholder with an id of its own.
     *
     * @param  list<array{int, int, list<array{string, int, int}>}>  $edits
     */
    public function apply(array $edits, bool $asPlaceholder = false): self
    {
        if ($edits === []) {
            return $this;
        }

        $text = '';
        $from = [];
        $to = [];
        $placeholder = [];
        $position = 0;
        $id = $this->nextId;

        foreach ($edits as [$offset, $length, $pieces]) {
            if ($offset < $position || $offset + $length > strlen($this->text)) {
                throw new LogicException('Shadow edits must be in order, inside the text and not overlap.');
            }

            $this->copy($position, $offset, $text, $from, $to, $placeholder);

            foreach ($pieces as [$piece, $start, $end]) {
                if ($end <= $start || $end > $length) {
                    throw new LogicException('A shadow piece must stand for at least one replaced byte.');
                }

                $low = PHP_INT_MAX;
                $high = 0;

                for ($index = $offset + $start; $index < $offset + $end; $index++) {
                    $low = min($low, $this->from[$index]);
                    $high = max($high, $this->to[$index]);
                }

                $size = strlen($piece);

                $text .= $piece;
                array_push($from, ...array_fill(0, $size, $low));
                array_push($to, ...array_fill(0, $size, $high));
                array_push($placeholder, ...array_fill(0, $size, $asPlaceholder ? $id : 0));
            }

            if ($asPlaceholder) {
                $id++;
            }
            $position = $offset + $length;
        }

        $this->copy($position, strlen($this->text), $text, $from, $to, $placeholder);

        return new self($text, $from, $to, $placeholder, $id);
    }

    /**
     * The original-text ranges the placeholders in this copy stand for, widest per placeholder id, in no
     * particular order and possibly overlapping.
     *
     * @return list<array{int, int}>
     */
    public function placeholderSpans(): array
    {
        $spans = [];

        foreach ($this->placeholder as $index => $id) {
            if ($id === 0) {
                continue;
            }

            $spans[$id] = isset($spans[$id])
                ? [min($spans[$id][0], $this->from[$index]), max($spans[$id][1], $this->to[$index])]
                : [$this->from[$index], $this->to[$index]];
        }

        return array_values($spans);
    }

    /**
     * The original-text range that the bytes `$start` to `$end` (exclusive) of this copy came from.
     *
     * @return array{int, int}
     */
    public function originalSpan(int $start, int $end): array
    {
        $low = PHP_INT_MAX;
        $high = 0;

        for ($index = $start; $index < $end; $index++) {
            $low = min($low, $this->from[$index]);
            $high = max($high, $this->to[$index]);
        }

        return [$low, $high];
    }

    /**
     * @param  list<int>  $from
     * @param  list<int>  $to
     * @param  list<int>  $placeholder
     */
    private function copy(int $start, int $end, string &$text, array &$from, array &$to, array &$placeholder): void
    {
        if ($end <= $start) {
            return;
        }

        $text .= substr($this->text, $start, $end - $start);
        array_push($from, ...array_slice($this->from, $start, $end - $start));
        array_push($to, ...array_slice($this->to, $start, $end - $start));
        array_push($placeholder, ...array_slice($this->placeholder, $start, $end - $start));
    }
}
