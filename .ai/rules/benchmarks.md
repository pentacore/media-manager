---
paths:
  - 'app/Services/Benchmark/**'
  - 'app/Ai/Decision/DecisionPrompt.php'
  - 'app/Concerns/DecisionEventValidationRules.php'
  - 'app/Concerns/VerifierCaseValidationRules.php'
  - 'resources/js/components/benchmarks/**'
---

# Benchmarks

## Benchmark case payloads are stored as JSON text
A webhook payload inside a benchmark case (`input.event.payload_json`, `input.verifier.payload_json`, `input.verifier.event_payload_json`) is stored and sent as its JSON text, never as a decoded object or a `jsonb` value. Postgres `jsonb` and the browser both reorder object keys, which would silently change the rebuilt prompt. Decode it with `DecisionPrompt::payloadFromJson()` (key order kept, `null` unless it is a JSON object) only where the prompt is built, and write it with `DecisionPrompt::payloadJson()`, which stores an empty payload as `{}` and a payload too long for the case limit compact.
