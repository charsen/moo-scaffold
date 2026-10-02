#!/usr/bin/env bash
# Keep the npm and bash entry points; use NUL-safe Git paths in the Node runner.
exec node "$(dirname "$0")/safe-run.cjs" "$@"
