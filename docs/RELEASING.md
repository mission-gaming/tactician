# Releasing

The release checklist. The policy it enforces is in the README's
[Versioning and stability](../README.md#versioning-and-stability) section.

## Checklist

1. **Changelog section.** In [`CHANGELOG.md`](../CHANGELOG.md), rename
   `Unreleased` to the new version with the date the tag will be made (step
   6), add a fresh empty `Unreleased` section above it, and update the
   comparison links at the bottom of the file. A tag without a changelog section is not a release.
2. **Golden fixtures.** Compare `tests/Fixtures/golden/` with the previous
   tag:

   ```bash
   git diff --stat <previous-tag>..HEAD -- tests/Fixtures/golden/
   ```

   Account for every file in that diff:

   - An added fixture pins a new case and changes no output. It needs no
     output-change entry.
   - A changed or removed fixture that fixes an output which was itself broken
     is listed in the new changelog section under the heading "Output change
     (fix)".
   - Any other changed or removed fixture is a change to correct output. It
     blocks a patch release. In a 0.x minor it is a breaking change, listed
     with a migration note.
3. **Version number.** Check the changes against the policy: a patch changes
   no public signature and no correct output; a 0.x minor lists every breaking
   change with a migration note.
4. **Documentation.** The documents must describe the library as it is at the
   tag. Repeat this for every release, however small:

   - [`README.md`](../README.md): the feature list names everything the new
     changelog section adds, each entry links to its usage section, and no
     entry claims something the code does not do. Check each one against
     `src/`.
   - [`docs/USAGE.md`](USAGE.md): every feature the new changelog section adds
     has a section with code blocks that run.
   - [`examples/README.md`](../examples/README.md) and `examples/index.php`:
     both list every script in `examples/`, and a feature worth an example has
     one.
   - [`docs/ROADMAP.md`](ROADMAP.md): what the release ships is recorded as
     shipped, and nothing is described as planned or unreleased that has been
     released.
   - [`CHANGELOG.md`](../CHANGELOG.md): the new section covers every change
     since the previous tag:

     ```bash
     git log --oneline <previous-tag>..HEAD
     ```

   The tests cover the parts a machine can check, and must be green:

   ```bash
   vendor/bin/pest tests/Feature/DocumentationSnippetsTest.php tests/Feature/ExamplesTest.php tests/Feature/ExampleRendererTest.php
   ```

   They execute every `php` block of the README and the usage guide, run every
   example, check what each example computes, and compare the examples index
   and README with the scripts that exist. They cannot tell whether a feature
   is missing from a list or a sentence overstates the code: that part is read
   by a person.
5. **Green CI on `main`.** Merge the changelog and documentation changes
   through a pull request and wait for the CI run on the resulting `main`
   commit to pass.
6. **Annotated tag.** Tag that commit on `main` with an annotated tag and push
   it:

   ```bash
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```

7. **GitHub release.** Create the release for the tag and copy the version's
   changelog section into its notes.
