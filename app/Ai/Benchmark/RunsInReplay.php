<?php

declare(strict_types=1);

namespace App\Ai\Benchmark;

/**
 * Marks a read tool that is pure: its result depends only on its arguments,
 * with no I/O (no HTTP, database, cache, files or run state). Inside a
 * benchmark run ReplayTool runs such a tool for real instead of looking its
 * call up in the case's fixtures, so a call whose arguments were never
 * recorded is not a miss. Never mark a tool that reads or writes anything.
 */
interface RunsInReplay {}
