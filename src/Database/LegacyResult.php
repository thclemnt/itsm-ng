<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Closure;
use Doctrine\DBAL\Result;
use OutOfBoundsException;
use RuntimeException;

/** Seekable result for legacy iterators. New repositories use Doctrine results directly. */
final class LegacyResult
{
    private array $names = [];
    private array $rows = [];
    /** Large results spill to disk; each index entry is an eight-byte row offset. */
    private mixed $buffer = null;
    private mixed $offsets = null;
    private string $pendingRows = '';
    private string $pendingOffsets = '';
    private int $bufferSize = 0;
    private int $position = 0;
    public readonly int $field_count;
    public readonly int $num_rows;

    public function __construct(Result $result, ?Closure $normalize = null)
    {
        try {
            $this->field_count = $result->columnCount();
            for ($i = 0; $i < $this->field_count; $i++) {
                $this->names[] = $result->getColumnName($i);
            }
            $count = 0;
            $bufferedBytes = 0;
            while (($row = $result->fetchNumeric()) !== false) {
                $row = $normalize === null ? $row : $normalize($row);
                if ($this->buffer === null) {
                    // Include array/zval overhead as well as payload, so ordinary
                    // dropdown lists stay fast without retaining large exports.
                    $bufferedBytes += 128 + count($row) * 32;
                    foreach ($row as $value) {
                        $bufferedBytes += is_string($value) ? strlen($value) : 0;
                    }
                }
                if ($this->buffer === null && $bufferedBytes <= 1048576) {
                    $this->rows[] = $row;
                } else {
                    if ($this->buffer === null) {
                        $this->buffer = $this->openBuffer();
                        $this->offsets = $this->openBuffer();
                        foreach ($this->rows as $cached) {
                            $this->append($cached);
                        }
                        $this->rows = [];
                    }
                    $this->append($row);
                }
                $count++;
            }
            $this->num_rows = $count;
            if ($this->buffer !== null) {
                $this->flush();
                rewind($this->buffer);
            }
        } finally {
            $result->free();
        }
    }

    public function fetch_row(): ?array
    {
        if ($this->buffer === null) {
            return $this->rows[$this->position++] ?? null;
        }
        if ($this->position++ >= $this->num_rows) {
            return null;
        }
        $length = unpack('N', $this->read($this->buffer, 4))[1];
        return unserialize($this->read($this->buffer, $length), ['allowed_classes' => false]);
    }

    public function fetch_assoc(): ?array
    {
        $row = $this->fetch_row();
        return $row === null ? null : array_combine($this->names, $row);
    }

    public function fetch_array(): ?array
    {
        $row = $this->fetch_row();
        return $row === null ? null : $row + array_combine($this->names, $row);
    }

    public function fetch_object(): ?object
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : (object)$row;
    }

    public function data_seek(int $offset): bool
    {
        if ($offset < 0 || $offset >= $this->num_rows) {
            return false;
        }
        if ($this->buffer !== null) {
            if (fseek($this->offsets, $offset * 8) !== 0) {
                throw new RuntimeException('Cannot seek database result index.');
            }
            $position = unpack('J', $this->read($this->offsets, 8))[1];
            if (fseek($this->buffer, $position) !== 0) {
                throw new RuntimeException('Cannot seek database result.');
            }
        }
        $this->position = $offset;
        return true;
    }

    public function fieldName(int $index): string
    {
        return $this->names[$index] ?? throw new OutOfBoundsException('Invalid result column');
    }

    public function free(): bool
    {
        $this->rows = [];
        $this->pendingRows = $this->pendingOffsets = '';
        foreach ([$this->buffer, $this->offsets] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->buffer = $this->offsets = null;
        return true;
    }

    public function __destruct()
    {
        $this->free();
    }

    private function openBuffer(): mixed
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        return $stream === false ? throw new RuntimeException('Cannot buffer database result.') : $stream;
    }

    private function append(array $row): void
    {
        $bytes = serialize($row);
        $this->pendingOffsets .= pack('J', $this->bufferSize);
        $this->pendingRows .= pack('N', strlen($bytes)) . $bytes;
        $this->bufferSize += 4 + strlen($bytes);
        if (strlen($this->pendingRows) >= 65536) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        $this->write($this->offsets, $this->pendingOffsets);
        $this->write($this->buffer, $this->pendingRows);
        $this->pendingRows = $this->pendingOffsets = '';
    }

    private function write(mixed $stream, string $bytes): void
    {
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length; $offset += $written) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot buffer database result.');
            }
        }
    }

    private function read(mixed $stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($stream, $length - strlen($bytes));
            if ($part === false || $part === '') {
                throw new RuntimeException('Cannot read buffered database result.');
            }
            $bytes .= $part;
        }
        return $bytes;
    }
}
