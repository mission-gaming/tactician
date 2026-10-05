# Releasing

The release checklist. The policy it enforces is in the README's
[Versioning and stability](../README.md#versioning-and-stability) section.

## Checklist

1. **Changelog section.** In [`CHANGELOG.md`](../CHANGELOG.md), rename
   `Unreleased` to the new version with the date the tag will be made (step
   5), add a fresh empty
   `Unreleased` section above it, and update the comparison links at the
   bottom of the file. A tag without a changelog section is not a release.
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
4. **Green CI on `main`.** Merge the changelog change through a pull request
   and wait for the CI run on the resulting `main` commit to pass.
5. **Annotated tag.** Tag that commit on `main` with an annotated tag and push
   it:

   ```bash
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```

6. **GitHub release.** Create the release for the tag and copy the version's
   changelog section into its notes.
