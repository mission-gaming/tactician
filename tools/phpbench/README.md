# The benchmark runner

[phpbench](https://phpbench.readthedocs.io/) runs the benchmark suite in
`tests/Benchmark/`. It is installed from this directory, with a
`composer.json` and a `composer.lock` of its own, and is not one of the
library's development dependencies.

The reason is one of its dependencies. phpbench requires
`doctrine/annotations`, which is abandoned with no replacement named. The
weekly workflow fails when the library's `composer.lock` holds an abandoned
package, because that is where a maintainer finds out about one. A package
that nothing can be done about would fail that check every week and hide the
next one, so the tool is kept where it cannot reach the library's dependency
set. `tests/Feature/GateConfigurationTest.php` fails if phpbench, or any
abandoned package, appears in the root `composer.lock`.

Nothing in `composer ci` needs this directory installed. The benchmark
classes are plain PHP and name nothing of phpbench, so the gate analyses
them without it.

```bash
composer bench-install    # composer install in this directory
composer bench            # run the suite
```

The `Benchmarks` job of the CI workflow installs the tool and audits this
lock file for security advisories. An abandoned package is reported there
and does not fail the job.
