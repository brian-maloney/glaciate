# Performance benchmark

Measures full and incremental builds of a large site on the wp-env tests site (`:8889`). **It erases that site's
content**, like the system tests.

```bash
npm run perf -- seed 80000     # 80,000 posts, 50 categories, 500 tags, 5 authors: about 100,000 addresses
npm run perf -- bench          # full build, then incremental builds after no change, a content edit, a title edit, a new post
npm run perf -- bench incremental
```

`STILLPRESS_PERF_CONCURRENCY` sets the render concurrency (default 8).

The site uses a one-file theme (`theme/`) that renders like a plain blog: posts with their categories, tags, author and
neighbours, and paginated home, term and author archives, 10 posts a page. Posts are inserted with SQL, so seeding
100,000 takes seconds.

The report is JSON. For each build: wall time per phase, the build process's peak memory and query count, and the ten
query shapes that took the most database time. Pages render in the web container, so their own queries aren't counted.
Results from the 1.0.0 benchmark runs are in `docs/DECISIONS.md` (D23, D24).
