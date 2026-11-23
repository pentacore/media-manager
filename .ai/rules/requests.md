---
paths:
  - 'app/Http/Requests/**'
---

# Requests

## Share validation rules through App\Concerns traits
Put reusable rule fragments in a `*ValidationRules` trait under `app/Concerns` exposing `protected fooRules(): array`, and spread it into `rules()` with `...$this->fooRules()`. Do not create `app/Rules` classes or inline Closure rules; cross-field checks go in an `after()` hook on the trait. Keep field rules independent of the environment: Typefinder turns them into the committed request types, so an `in:` list built from config or API keys (configured AI providers) would change the type with each `.env` and fail `GeneratedTypesTest`. Check such a set in the trait's `after()` hook instead (`validatePricedSelection()` checks the provider).
