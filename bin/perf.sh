#!/bin/sh
# Performance benchmark on the wp-env tests site (ERASES its content). See tests/perf/README.md.
#   bin/perf.sh seed [posts]
#   bin/perf.sh bench [full|incremental|rebuild|all]
set -e
cmd="${1:-bench}"
shift || true
dir=wp-content/plugins/glaciate/tests/perf
case "$cmd" in
	seed)  exec npx wp-env run tests-cli wp eval-file "$dir/seed.php" "$@" ;;
	bench) exec npx wp-env run tests-cli -- env "GLACIATE_PERF_CONCURRENCY=${GLACIATE_PERF_CONCURRENCY:-8}" \
		wp --exec="define( 'SAVEQUERIES', true );" eval-file "$dir/bench.php" "$@" ;;
	*) echo "usage: $0 seed [posts] | bench [full|incremental|rebuild|all]" >&2; exit 2 ;;
esac
