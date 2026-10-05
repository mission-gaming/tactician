---
description: Run the full quality gate and report each tool's own summary, without fixing anything
---

Run the repository's gate and report what happened. Do not change any file.

1. Run `composer ci` from the repository root and wait for it to finish. It
   runs, in this order: `composer normalize --dry-run`, PHPStan, Rector
   (dry run), PHP-CS-Fixer (dry run), the Pest suite, and the example
   smoke-run. It stops at the first step that fails.
2. Report the exit status of `composer ci`.
3. For each step that ran, quote the tool's own summary line exactly as it
   printed it (for example PHPStan's `[OK] No errors` or error count, and
   Pest's `Tests:` line with its passed, failed and skipped counts). Do not
   paraphrase a summary and do not infer one for a step that did not run;
   say that it did not run.
4. If a step failed, quote the first failure it reported, with its file and
   line.

Do not run any `*-fix` script, do not edit files, and do not re-run a step to
get a different result. If the gate is red, say so and stop.
