---
paths:
  - app/Http/Controllers/Admin/AiSettingsController.php
---

# Admin

## Provider checkbox lists: canonical values and absent-vs-empty
Checkbox lists on the AI settings page post canonical provider ids (`gemini`, not `google`), and validation accepts only those. Pass stored lists through `canonicalPricingProviders()` before rendering, because env defaults may use upstream spellings and would otherwise fail validation on save. `auto_create_pricing_providers` always posts a blank placeholder entry, stripped in the request, so "all unchecked" arrives as `[]`. An absent field leaves the saved setting untouched.
