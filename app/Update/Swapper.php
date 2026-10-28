<?php

namespace App\Update;

/**
 * Puts the new code (an unpacked release) in place of the old one. Both steps are journaled on disk, so revert() also
 * works in a fresh process after a crash (ignis:update:recover) and finishes or undoes a half-done swap.
 */
interface Swapper
{
    public function swap(string $stagingDir): void;

    public function revert(): void;

    public function commit(): void;

    public function discard(): void;

    /** @return list<string> journals of swaps that were neither committed nor reverted (a crashed run) */
    public function pending(): array;
}
