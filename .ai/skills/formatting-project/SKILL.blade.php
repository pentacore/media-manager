---
name: formatting-project
description: "Use when formatting, cleaning up, linting, or finalizing code changes in this Laravel project, including requests involving Rector, Pint, Prettier, ESLint, npm format, or npm lint."
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Formatting the Project

Run the complete formatting workflow from the repository root. All commands must run through Laravel Sail.

## Required Order

Run every step in this exact order:

1. `{{ $assist->binCommand('rector') }}`
2. `{{ $assist->nodePackageManagerCommand('run format') }}`
3. `{{ $assist->nodePackageManagerCommand('run lint') }}`
4. `{{ $assist->binCommand('pint') }} --dirty --format agent`

Rector must transform PHP before Pint applies final PHP styling. Prettier must format frontend files before ESLint applies its fixes. Pint must run last.

Do not substitute check-only commands for these fix commands. If Sail is not running, start it with `vendor/bin/sail up -d`, then restart the workflow.

## Preserve All Changes

Before formatting, inspect `git status --short` and the current diff so existing user changes remain identifiable.

Every change produced by Rector, Prettier, ESLint, or Pint is part of the formatting pass and must be respected, even when it touches a file outside the current feature or fix.

- Never revert, discard, omit, or unstage a formatter-generated change merely because it is out of scope.
- Never restore a file to its pre-format state merely to reduce the diff.
- Preserve all changes that existed before formatting as well.
- If formatter output needs correction for correctness, fix it forward and rerun the affected command and every later command. Do not solve it by discarding unrelated formatter output.
- When asked to commit the formatting pass, include all formatter-generated changes unless the user explicitly directs otherwise.

## Failures and Verification

Stop at the first failing command without reverting changes already made. Resolve the failure, rerun that command, then continue through the remaining commands in order.

After all four commands succeed, inspect `git status --short` and the final diff. Report failures, fixes, and every file changed by the formatting workflow. Do not characterize formatter-generated changes as disposable scope noise.
