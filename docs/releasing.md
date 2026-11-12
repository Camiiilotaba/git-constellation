# Releasing

Users reference the action as `Camiiilotaba/git-constellation@v1` (moving major tag), `@latest` (moving tag, newest release) or an exact `@vX.Y.Z` (immutable). The action runs the code at the tag the user pinned, so a tag is a release.

## Cut a release

1. Merge `develop` into `main` with `--no-ff` once the phase or fix is approved, and make sure CI is green on `main`.
2. Choose the version with semantic versioning: a breaking change to inputs, defaults or the published file layout is a new major; new inputs or behaviour are a minor; fixes are a patch.
3. Tag and push the exact version from `main`:

   ```sh
   git switch main && git pull --ff-only
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```

4. Move the major tag and `latest` to the same commit (forced, because they move):

   ```sh
   git tag -fa v1 -m "v1 -> vX.Y.Z" vX.Y.Z^{}
   git tag -fa latest -m "latest -> vX.Y.Z" vX.Y.Z^{}
   git push --force origin v1 latest
   ```

   For a new major version, create `v2` instead of moving `v1`; `v1` stays on the last 1.x release. `latest` always moves.
5. Create a GitHub release for `vX.Y.Z` with the changes.
6. Run the profile workflow once with **Run workflow** to confirm the release works.

Never move or delete an exact `vX.Y.Z` tag. To fix a bad release, publish a new patch version and move `v1` and `latest` to it.
