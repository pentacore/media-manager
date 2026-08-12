---
paths:
  - 'app/Http/Requests/**'
---

# Requests

## Share validation rules through App\Concerns traits
Put reusable rule fragments in a `*ValidationRules` trait under `app/Concerns` exposing `protected fooRules(): array`, and spread it into `rules()` with `...$this->fooRules()`. Do not create `app/Rules` classes or inline Closure rules; cross-field checks go in an `after()` hook on the trait.
