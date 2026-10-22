<?php

namespace App\Services\Plaid;

final readonly class SyncResult
{
    public function __construct(
        public bool $successful,
        public int $added = 0,
        public int $modified = 0,
        public int $removed = 0,
        public ?string $error = null,
    ) {}

    public static function failed(string $error): self
    {
        return new self(false, error: $error);
    }

    public function summary(): string
    {
        if (! $this->successful) {
            return $this->error ?? 'Sync failed.';
        }

        return "{$this->added} added, {$this->modified} updated, {$this->removed} removed.";
    }
}
