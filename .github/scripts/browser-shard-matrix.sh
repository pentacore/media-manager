#!/usr/bin/env bash
# Prints the Pest job matrix for CI as `matrix=<json>` (for $GITHUB_OUTPUT):
# the unit & feature leg plus as many browser shards as the suite needs.
#
# The browser shard count comes from the per-class timings in
# tests/.pest/shards.json (seconds, measured serially):
#
#   shards = ceil(total / target), capped at ceil(total / slowest class)
#            (more shards cannot finish before the slowest class does),
#            then clamped to 1..max.
#
# Usage: browser-shard-matrix.sh <shards.json> <target seconds> <max shards>
# A short summary goes to stderr; CI copies it to the job summary.
set -euo pipefail

file=${1:?usage: browser-shard-matrix.sh <shards.json> <target seconds> <max shards>}
target=${2:?target seconds missing}
max=${3:?max shards missing}

if ! [[ $target =~ ^[0-9]+$ && $target -gt 0 && $max =~ ^[0-9]+$ && $max -gt 0 ]]; then
    echo "target and max must be positive integers (got target=$target max=$max)" >&2
    exit 1
fi

jq -c --argjson target "$target" --argjson max "$max" '
    def ceil_div($a; $b): ($a / $b) | ceil;
    [.timings[] | numbers] as $times
    | ($times | add // 0) as $total
    | ($times | max // 0) as $slowest
    | (if $total == 0 then 1
       else [ceil_div($total; $target), ceil_div($total; $slowest)] | min
       end) as $wanted
    | ([[$wanted, $max] | min, 1] | max) as $shards
    | {
        total: ($total | round),
        slowest: ($slowest | round),
        shards: $shards,
        include: (
            [{name: "unit & feature", suite: "unit-feature"}]
            + [range(1; $shards + 1) | {name: "browser \(.)/\($shards)", suite: "browser", shard: "\(.)/\($shards)"}]
        )
    }
' "$file" > "${TMPDIR:-/tmp}/browser-shard-plan.json"

plan="${TMPDIR:-/tmp}/browser-shard-plan.json"
jq -r --argjson target "$target" --argjson max "$max" \
    '"Browser shards: \(.shards) (timings total \(.total) s, slowest class \(.slowest) s, target \($target) s per shard, max \($max))"' "$plan" >&2
echo "matrix=$(jq -c '{include}' "$plan")"
