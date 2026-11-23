---
paths:
  - '.ai/**'
---

# Rules about the rules

## Regenerate and commit the index after editing .ai
After editing anything under `.ai/`, run `php artisan boost:index-rules` and `php artisan boost:update`, and commit the regenerated `.ai/rules/index.md` in the same commit as the rule change.

## Record a rule under the narrowest glob
Record a convention with `record-rule` or in a rule file whose `paths:` frontmatter is the narrowest glob that covers it. Only app-wide conventions go in `general.md` (`**`).

## Tag the owner's decisions
When a rule records a decision the owner made, end it with "(owner's rule, YYYY-MM-DD)". Do not reword, weaken or "fix" such a rule without the owner.

## Rules are constraints, procedures are skills
Keep each rule a short constraint. Put a multi-step procedure in a skill under `.ai/skills/` and let the rule point at it.

## Capture reusable workflows as skills
When a multi-step workflow is needed a second time (running a tool a particular way, a setup or release procedure), write it as a skill under `.ai/skills/<name>/SKILL.blade.php` instead of re-deriving it or leaving it in a rule.
