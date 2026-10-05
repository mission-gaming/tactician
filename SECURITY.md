# Security Policy

## Supported versions

Tactician is in its 0.x series. Security fixes are made on the latest 0.x
minor line only, and ship as a patch release of that line. Earlier minor lines
receive no fixes: upgrade to the latest release to get one. The
[changelog](CHANGELOG.md) names the current release, and the README's
[Versioning and stability](README.md#versioning-and-stability) section says
what a patch release may change.

| Version                 | Supported |
| ----------------------- | --------- |
| Latest 0.x minor line   | Yes       |
| Earlier 0.x minor lines | No        |

## Reporting a vulnerability

Report a vulnerability privately, through GitHub's private vulnerability
reporting:

1. Open the repository's
   [Security tab](https://github.com/mission-gaming/tactician/security).
2. Choose "Report a vulnerability".
3. Describe the problem: the affected version, the PHP version, a minimal
   reproduction, and the impact you expect.

Do not open a public issue, pull request, or discussion for a vulnerability.
A public report discloses the problem before a fix exists.

## What to expect

- The report is visible only to you and the maintainers.
- A maintainer acknowledges the report, normally within seven days, and tells
  you whether it is accepted as a vulnerability.
- An accepted report is fixed in private. The fix ships as a patch release of
  the latest 0.x minor line, with a changelog entry and a published security
  advisory that credits you unless you ask not to be named.
- A report that is not a vulnerability is closed with the reason. You are then
  free to open it as an ordinary issue.

Tactician is a library with no production dependencies. It makes no network
requests and reads and writes no files, so the reports most likely to apply
are about input that makes generation fail unsafely or consume unbounded time
or memory.
