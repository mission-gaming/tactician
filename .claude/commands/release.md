---
description: Walk the release checklist in docs/RELEASING.md step by step, stopping before any tag is created or pushed
argument-hint: "[version]"
---

Walk the release checklist for version `$ARGUMENTS`. If no version was given,
ask for it before doing anything else.

Read `docs/RELEASING.md` now and follow it as written. It is the checklist;
this file adds only how to work through it. If the two disagree,
`docs/RELEASING.md` wins.

- Take the steps in order, one at a time. For each, say which step it is,
  do it, and show the evidence (the diff, the command output) before moving
  on.
- For the golden-fixture step, run the `git diff --stat` command the
  checklist gives against the previous tag and account for every file it
  lists, as the checklist requires.
- Check the version number against the policy in the README's "Versioning
  and stability" section, and say why the changes fit a patch or need a
  minor.
- Changelog changes go through a pull request. Never commit to `main`.
- Do not continue past the CI step until the run on the resulting `main`
  commit has passed. Report its status; do not assume it.

**Stop before the tag.** Do not run `git tag`, do not push a tag, and do not
create a GitHub release until the maintainer has confirmed, in this
conversation, the exact version and the exact commit to tag. Show both and
wait. Ask again before the push, and again before creating the release: a
confirmation for one of them is not a confirmation for the next.
