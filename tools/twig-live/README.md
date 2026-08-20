# Archipelago live check

Asks the **deployed** Archipelago the questions the offline linter has to guess at.

| Tool | Question | Needs |
|---|---|---|
| [`tools/twig-lint`](../twig-lint/README.md) | Would Archipelago accept this? *(and where exactly is the problem)* | nothing — 2 seconds, offline |
| **this** | Would **the actual site** accept this? *(and does the repo still match it)* | network access to the site |

Keep both. They are not redundant — see [Reading a disagreement](#reading-a-disagreement).

## How it validates

Archipelago's `metadatadisplay_entity` is a **content** entity, not config, so
there is no YAML to import and nothing to compile. But its `twig` base field
carries a validation constraint ([`MetadataDisplayEntity.php`][mde], line 352):

```php
->addConstraint('NotBlank')
->addConstraint('TwigTemplateConstraint', ['useTwigMessage' => FALSE, ...]);
```

Entity validation runs on save, and JSON:API runs entity validation on `POST`.
So the check is simply: try to save the template as a temporary entity.

```
POST /jsonapi/metadatadisplay_entity/metadatadisplay_entity
  201  ->  the site accepts this template
  422  ->  {"detail": "twig: Value is not a valid Twig template."}
DELETE the probe either way
```

That is the same gate the Metadata Display edit form uses, run by the site
itself — so it knows its own Twig version, its own installed modules and its
own site-local filters. Nothing is stubbed and no registry is consulted.

### It gives a verdict, not a diagnosis

`useTwigMessage` is `FALSE`, so a rejection is always the generic
`Value is not a valid Twig template.` — no line, no reason. That is why the
offline linter still matters: it is the one that tells you *where*.

## Reading a disagreement

Run both and the combination means more than either alone:

| Offline | Live | What it means |
|---|---|---|
| pass | pass | Fine. |
| fail | fail | A real syntax error. The offline linter has your line number. |
| **pass** | **fail** | **The registry trusts a name this site does not have.** The false-pass in [`extensions.json`][reg] — silent until now. `validate-live.php` calls this out explicitly. |
| fail | pass | The registry is missing a name the site does have, or the linter's Twig is older. Add the name. |

Row three is the reason this tool exists. It is the failure mode the offline
linter cannot see by construction, and it closes automatically here.

## Verifying the registry against the site

`verify-registry-live.php` probes every name in
[`tools/twig-lint/registry/extensions.json`][reg] with a one-line template
(`{{ 1|NAME }}`, `{{ NAME() }}`, `{{ 1 is NAME }}`) and reports which ones the
site does not have. A name the registry trusts but the site lacks is the exact
false-pass the offline linter cannot see.

```bash
php tools/twig-live/bin/verify-registry-live.php
```

Against `archipelago-dev` on 08/20/2026 — **2 of 39 missing**:

```
MISSING function sbf_datacite            registered under "site-local"
MISSING function allmaps_annotation_url  registered under "site-local"
```

Both are the `scan_exempt` names, now settled. Compare with what
`registry-drift.yml` reports from upstream source on the same day:

```
37 registered names confirmed, 2 exempt, 0 missing.
Every name the linter trusts was found. No false-pass risk from the registry.
```

The upstream scan says there is no risk; the site proves there is. That gap —
a module that exists upstream but is not installed here — is why this tier
exists. It **verifies** names the registry lists; it cannot **discover** a name
nobody wrote down, which still needs `dump-twig-names.php` over drush.

## What it costs the target site

One create and one delete per template checked. Probes are named
`zzz-ci-probe-DELETE-ME-<run>-<hash>` and deleted immediately; the prefix is
the only thing a sweep will ever match. Real entities are never read for
validation and never written.

If a run is killed between create and delete, `--sweep` clears what it left:

```bash
php tools/twig-live/bin/validate-live.php --sweep              # older than 60 min
php tools/twig-live/bin/validate-live.php --sweep --sweep-age=0  # everything
```

The CI job sweeps before it starts and again in `always()`.

## Running it

Credentials come from the environment — never a file in the repo:

```bash
export ARCHIPELAGO_URL=https://archipelago-dev.library.tamu.edu/
export ARCHIPELAGO_USER=...
export ARCHIPELAGO_PASS=...

php tools/twig-live/bin/validate-live.php                      # every template
php tools/twig-live/bin/validate-live.php twig/metadatadisplays/MODS_3_7.twig.html
php tools/twig-live/bin/validate-live.php --changed=origin/main
php tools/twig-live/bin/drift-check.php                        # repo vs deployed
php tools/twig-live/bin/generate-mapping.php --dry-run         # refresh the mapping
```

No PHP locally? Everything above works in Docker, as long as the container can
reach the host:

```bash
docker run --rm -v "$PWD":/app -w /app \
  -e ARCHIPELAGO_URL -e ARCHIPELAGO_USER -e ARCHIPELAGO_PASS \
  php:8.3-cli php tools/twig-live/bin/validate-live.php
```

Exit codes: `0` accepted, `1` at least one rejected, **`2` the check could not
run at all**. A `2` is never a statement about your templates — it is an
unreachable host, bad credentials or a broken site, and it is deliberately kept
distinguishable so a network problem can never read as a green build.

## Drift: repo vs deployed

These files are copies of rows in a database. Nothing keeps them in step —
someone edits a template in the admin UI and never commits it, or a PR merges
here and nobody pastes it in. `drift-check.php` compares each mapped file to
the live `twig` field, ignoring BOM, CRLF and trailing whitespace, since those
are copy/paste artefacts rather than real differences.

**Drift does not fail the build by default**, because as of 08/20/2026 most of
this repo differs from `archipelago-dev`: only 9 of 26 files match their
deployed entity exactly, and MODS 3.7 is 584 lines here against 348 there. That
is worth knowing, and worth fixing, but gating on it today would just be noise.
Add `--strict` once the two are reconciled.

### `registry/mapping.json`

Filenames do **not** reliably match entity names, so the mapping is generated
once and committed rather than guessed at each run:

| Repo file | Entity |
|---|---|
| `IIIF_Presenation_API_3_Series_Manifest_Unified` | `IIIF Presentation API 3 Series Manifest Unified` (typo in the filename) |
| `Thumbnails_with_Annotation_for_ML` | `Thumbnails with Annotations for ML` (singular vs plural) |

Both are handled by the `aliases` table, which `generate-mapping.php`
preserves across regenerations. Fuzzy-matching these at runtime would invent
drift that is not there — hence a committed file.

Three repo files have no entity on `archipelago-dev` at all:
`iiif_manifest_3.0_thumbnail`, `tamu_custom_collection_hero` and
`TAMU_Custom_Simple_Card_Thumbnail`. They are recorded in `unmapped_files`.

## CI

`.github/workflows/twig-live-check.yml`. Two things about it are load-bearing:

**It needs a self-hosted runner.** `archipelago-dev.library.tamu.edu` answers
only on the campus network; GitHub's hosted runners are not on it. The job
targets a runner labelled `archipelago-campus` with php-cli 8.2+ and ext-curl.

**It is inert until you enable it.** Every job is gated on the repository
variable `ARCHIPELAGO_LIVE_ENABLED == 'true'`, so PRs are not left with a check
that can never start:

```bash
gh variable set ARCHIPELAGO_LIVE_ENABLED --body true
```

Fork PRs are skipped — they cannot read secrets, and the offline `Twig lint`
job still covers them.

### Use a dedicated account

Give CI an account that can create, validate and delete Metadata Display
entities and nothing else. Do not use the Archipelago default `admin` /
`archipelago` pair: it is the first thing anyone tries, and if CI credentials
leak you want the blast radius to be one entity type rather than the site.

## What this still does not check

The live check answers *would the site accept this template*. It does not
answer *is the output correct*:

- **Valid JSON / XML?** No. A trailing comma in a manifest saves perfectly.
- **Right fields?** No. Drupal runs `strict_variables: false`, so a wrong field
  name renders empty rather than erroring — on this check and on the site.
- **Do the Views resolve?** No. `drupal_view()` is not exercised by validation.

Those need real metadata fixtures rendered and their output parsed. That is the
next tier, and it is the one that catches broken manifests.

[mde]: https://github.com/esmero/format_strawberryfield/blob/1.6.0/src/Entity/MetadataDisplayEntity.php
[reg]: ../twig-lint/registry/extensions.json
