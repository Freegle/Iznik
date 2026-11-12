#!/bin/sh
# Run the unit suite. No containers, no network, no model: the forum and the model are
# stand-ins. Needs Node 22.13 or later and nothing installed.
cd "$(dirname "$0")" || exit 1
exec node --disable-warning=ExperimentalWarning --test --test-reporter=spec "test/*.test.mjs"
