#!/bin/sh
# Performance benchmark on the wp-env tests site (ERASES its content). See tests/perf/README.md.
#   bin/perf.sh seed [posts]
#   bin/perf.sh bench [full|incremental|all]
set -e
cmd="${1:-bench}"
shift || true
dir=wp-content/plugins/stillpress/tests/perf
case "$cmd" in
	seed)  exec npx wp-env run tests-cli wp eval-file "$dir/seed.php" "$@" ;;
	bench) exec npx wp-env run tests-cli -- env "STILLPRESS_PERF_CONCURRENCY=${STILLPRESS_PERF_CONCURRENCY:-8}" \
		wp --exec="define( 'SAVEQUERIES', true );" eval-file "$dir/bench.php" "$@" ;;
	*) echo "usage: $0 seed [posts] | bench [full|incremental|all]" >&2; exit 2 ;;
esac
