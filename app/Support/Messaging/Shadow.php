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
 * of the placeholder it belongs to), each held as a packed run of 32-bit integers so a copy is a `substr` rather than
 * an array rebuild (a 2,000-character body can grow to tens of kilobytes under NFKC). A replacement's bytes all carry the smallest range covering the bytes they
 * replaced, so a placeholder that later swallows part of another placeholder, or part of a rewritten word,
 * widens to the whole of it — never to a fragment. Pure; no I/O.
 */
final class Shadow
{
    /** One packed 32-bit little-endian integer per byte of `$text`. */
    private const WIDTH = 4;

    /** Words read per step by `placeholderSpans()`. */
    private const CHUNK = 4096;

    private function __construct(
        public readonly string $text,
        private readonly string $from,
        private readonly string $to,
        private readonly string $placeholder,
        private readonly int $nextId,
    ) {}

    /** The original text mapped onto itself: byte i comes from [i, i + 1). */
    public static function identity(string $text): self
    {
        $length = strlen($text);

        return new self(
            $text,
            $length === 0 ? '' : pack('V*', ...range(0, $length - 1)),
            $length === 0 ? '' : pack('V*', ...range(1, $length)),
            str_repeat(pack('V', 0), $length),
            1,
        );
    }

    /** @return list<int> */
    public function fromList(): array
    {
        return self::unpacked($this->from);
    }

    /** @return list<int> */
    public function toList(): array
    {
        return self::unpacked($this->to);
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
        $from = '';
        $to = '';
        $placeholder = '';
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

                [$low, $high] = $this->originalSpan($offset + $start, $offset + $end);
                $size = strlen($piece);

                $text .= $piece;
                $from .= str_repeat(pack('V', $low), $size);
                $to .= str_repeat(pack('V', $high), $size);
                $placeholder .= str_repeat(pack('V', $asPlaceholder ? $id : 0), $size);
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
        $length = strlen($this->placeholder);
        $spans = [];

        // Whole 32-bit words at a time, so an id below 256 (which leaves zero bytes inside its word) cannot split a
        // run; a chunk with nothing hidden in it, which is most of most bodies, is skipped on one byte scan.
        for ($offset = 0; $offset < $length; $offset += self::CHUNK * self::WIDTH) {
            $size = min(self::CHUNK * self::WIDTH, $length - $offset);

            if (strspn($this->placeholder, "\0", $offset, $size) === $size) {
                continue;
            }

            $ids = self::unpacked(substr($this->placeholder, $offset, $size));
            $from = self::unpacked(substr($this->from, $offset, $size));
            $to = self::unpacked(substr($this->to, $offset, $size));

            foreach ($ids as $index => $id) {
                if ($id === 0) {
                    continue;
                }

                $spans[$id] = isset($spans[$id])
                    ? [min($spans[$id][0], $from[$index]), max($spans[$id][1], $to[$index])]
                    : [$from[$index], $to[$index]];
            }
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
        $from = self::unpacked(substr($this->from, $start * self::WIDTH, ($end - $start) * self::WIDTH));
        $to = self::unpacked(substr($this->to, $start * self::WIDTH, ($end - $start) * self::WIDTH));

        return [$from === [] ? PHP_INT_MAX : min($from), $to === [] ? 0 : max($to)];
    }

    private function copy(int $start, int $end, string &$text, string &$from, string &$to, string &$placeholder): void
    {
        if ($end <= $start) {
            return;
        }

        $text .= substr($this->text, $start, $end - $start);
        $from .= substr($this->from, $start * self::WIDTH, ($end - $start) * self::WIDTH);
        $to .= substr($this->to, $start * self::WIDTH, ($end - $start) * self::WIDTH);
        $placeholder .= substr($this->placeholder, $start * self::WIDTH, ($end - $start) * self::WIDTH);
    }

    /** @return list<int> */
    private static function unpacked(string $packed): array
    {
        if ($packed === '') {
            return [];
        }

        $values = unpack('V*', $packed);

        if ($values === false) {
            throw new LogicException('Shadow could not read its byte map.');
        }

        return array_values($values);
    }
}
