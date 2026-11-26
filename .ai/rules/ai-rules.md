---
paths:
  - '.ai/**'
---

# Rules about the rules

## Regenerate and commit the index after editing .ai
After editing anything under `.ai/`, run `php artisan boost:index-rules` and `php artisan boost:update`, and commit the regenerated `.ai/rules/index.md` in the same commit as the rule change.

## Record a rule under the narrowest glob
Record a convention with `record-rule` or in a rule file whose `paths:` frontmatter is the narrowest glob that covers it. Only app-wide conventions go in `general.md` (`**`).

## Put an area's rule in its area file
A rule about one feature area goes in that area's file, whatever layer it is about: `actions.md` (the action queue), `ai-chat.md` (the AI chat and its tools), `arr.md` (the Sonarr, Radarr and Whisparr libraries) or `media-replacement.md` (media replacement and subtitles). Their globs cover every layer of the area, so an agent editing the area's page, job or test sees the rule. Layer files (`controllers.md`, `js.md`, …) hold only conventions for the whole layer. Keep each rule in one file; when a rule touches a second area, add a one-line pointer there, not a copy. When an area gains a folder, add it to the area file's globs. `tests/Unit/AiRuleGlobsTest.php` fails on a glob that matches no tracked file.

## Keep GLOSSARY.md current
A change that adds or renames a domain term updates `GLOSSARY.md` in the same commit. Use its words in code, UI copy and rules, not the ones it lists to avoid.

## Tag the owner's decisions
When a rule records a decision the owner made, end it with "(owner's rule, YYYY-MM-DD)". Do not reword, weaken or "fix" such a rule without the owner.

## Rules are constraints, procedures are skills
Keep each rule a short constraint. Put a multi-step procedure in a skill under `.ai/skills/` and let the rule point at it.

## Capture reusable workflows as skills
When a multi-step workflow is needed a second time (running a tool a particular way, a setup or release procedure), write it as a skill under `.ai/skills/<name>/SKILL.blade.php` instead of re-deriving it or leaving it in a rule.
