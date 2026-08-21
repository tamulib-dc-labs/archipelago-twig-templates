# Archipelago Twig lint (IIIF)

Answers one question, in about two seconds, without a Drupal install:

> **Would Archipelago accept this template?**

**Scope: the nine IIIF templates only.** MODS, Dublin Core, OAI-PMH, GeoJSON,
schema.org and DataCite are deliberately not checked, and are expected to get
their own tier in a later PR. Nothing here looks at them, so a green build says
nothing about them.

The list is read from [`tools/twig-render/templates.json`](../twig-render/templates.json)
rather than kept as a second copy here. That file already has to enumerate every
IIIF template — the five it renders and the four it cannot — so there is exactly
one definition of "which templates are IIIF" and the two tools cannot drift.
Pass paths explicitly to lint anything outside that set.

## Why this is worth a CI job

When a Metadata Display entity fails validation, `format_strawberryfield` does
this ([`MetadataDisplayEntity::processHtml()`][mde]):

```php
$validated = $this->validateSource($twigtemplate);
if ($validated !== TRUE) {
  if (\Drupal::currentUser()->isAuthenticated()) {
    $twigtemplate = "{{ '" . $validated . "' }}";   // logged in: see the error
  }
  else {
    $twigtemplate = "{{ '' }}";                     // anonymous: see NOTHING
  }
}
```

A broken template shows the error to whoever is logged in and renders **empty
output to the public**. Whoever pasted it in sees a working site. That is the
failure mode this job exists to prevent, and it is why a red build here is
never cosmetic.

All nine IIIF templates currently pass. Two did not when this tool was written —
`IIIF_Presenation_API_3_Series_Manifest_Unified` (an unknown `json_endcode_raw`
filter) and `iiif_manifest_3.0_thumbnail` (a Twig 2 `{% spaceless %}` tag and
two inline `for … if` loops). Both have since been fixed. Every one of those
failures is preserved in `tests/fixtures/` so the linter keeps proving it can
catch that bug class now that the originals are gone.

## What it actually does

Archipelago's own gate is two lines ([`MetadataDisplayEntity::validateSource()`][mde]):

```php
$source = new Source($twigtemplate, $this->label() ?? $this->uuid(), '');
try {
  $this->twigEnvironment()->parse($this->twigEnvironment()->tokenize($source));
}
catch (\Twig\Error\SyntaxError $e) { return $e->getMessage(); }
return TRUE;
```

`src/Linter.php` does the same thing against a Twig environment carrying the
same extension *names*. That is the entire trick: Twig resolves filter,
function and test names at **parse** time, so an unknown filter is a
`SyntaxError`, not a runtime error — no data and no Drupal needed to catch it.

We deliberately **do not compile**. The real check does not either, so
compiling would report problems Archipelago would happily accept.

## Running it

In CI this runs on every PR touching an IIIF template. Locally:

```bash
composer install -d tools/twig-lint
php tools/twig-lint/lint.php                    # the nine IIIF templates
php tools/twig-lint/lint.php twig/metadatadisplays/MODS_3_7.twig.html
php tools/twig-lint/selftest.php                # check the linter itself
```

No PHP on your machine? Everything above works in Docker:

```bash
docker run --rm -v "$PWD":/app -w /app/tools/twig-lint composer:2 install
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tools/twig-lint/lint.php
```

Exit code is 0 if every template parses, 1 otherwise. `--format=github` emits
`::error` workflow commands so failures land as inline annotations on the exact
line in the PR's *Files changed* tab.

**One error per file.** Twig's parser stops at the first `SyntaxError`, so a
file can have more problems behind the one reported. Re-run after each fix —
that is how the third bug in `iiif_manifest_3.0_thumbnail` was found.

## In CI

`.github/workflows/twig-lint.yml` deliberately uses **no marketplace actions**.
The only action is `actions/checkout`, published by GitHub itself, which is
permitted under even the strictest org Actions policy. There is no toolchain
setup action because the GitHub-hosted Ubuntu runner already ships PHP and
Composer.

That means the PHP version comes from the runner image and can change under
you, so the workflow prints `php -v` and `composer --version` on every run.
Verified identical results on PHP 8.2.33, 8.3.33 and 8.4.24.

The one remaining external dependency is `composer install` reaching packagist.
If that also has to go, commit `tools/twig-lint/vendor/` (drop the entry from
`.gitignore`) and delete the install step — the linter then needs nothing but
`php`.

## `registry/extensions.json` — the part that needs maintenance

The linter has to know every Twig name that exists in the target Archipelago
install but is not built in to `twig/twig`. That list is `registry/extensions.json`,
and it is the tool's single point of failure:

| Registry problem | Symptom | How bad |
|---|---|---|
| Name **missing** | False failure on a valid template | Loud, obvious, quick to fix |
| Name present that the site **doesn't have** | False pass — CI green, production empty | Silent. Worse. |

So the registry is deliberately **minimal**: it lists what these templates
actually use, plus the fully-verified `format_strawberryfield` set. It is not a
speculative catalogue of everything Drupal might offer.

### Adding a name

1. Confirm it really exists in the deployed site — grep the module source, or
   check `/admin/reports/status`. Do not add it just to make CI pass.
2. Add it to `tests/fixtures/good_archipelago_idioms.twig.html`, so removing it
   later fails the self-test instead of silently weakening the check.

### Nothing verifies this file

Worth stating plainly, because it is the tool's one silent failure mode and
there is now no automation behind it. The registry is maintained by hand and
trusted as written. A name in here that the deployed site does not actually
have makes the linter **accept templates Archipelago will reject** — a green
build over a manifest that renders empty to the public.

There were two checks against that and both have been removed at the
maintainers' request: a weekly job comparing the registry to upstream module
source, and a live check that asked the deployed site directly.

**Two registered names are known to be wrong.** Measured against
`archipelago-dev` on 08/20/2026 by attempting to save a one-line template using
each — both were rejected, so neither exists there:

| Name | Where it is used | In IIIF scope? |
|---|---|---|
| `sbf_datacite` | `AMI_Ingest_JSON_Template`, inside a `{#- … #}` comment block, so Twig never resolves it | No |
| `allmaps_annotation_url` | `Object_Description.twig.html` line 111, a live call | No |

Neither is used by any of the nine IIIF templates, so **narrowing this tool to
IIIF removed its only known false pass**. That is a genuine improvement in what
a green build here means — but it is narrowing, not fixing. Both names are still
registered, and `Object_Description` is still rejected by the site while nothing
in CI looks at it any more.

Whether production also lacks them is unresolved: the measurement was against
dev, and dev is not a faithful mirror. `Object_Description` hardcodes
`digitalcollections.library.tamu.edu`, so it was written for production. Worth
settling when the non-IIIF tier is built.

## What this does NOT check

This is tier 1 of a larger plan. It says a template is *syntactically
acceptable to Archipelago*. It says nothing about whether the output is
correct:

- **Does it emit valid JSON / XML?** No. Trailing commas, broken manifests and
  malformed MODS all parse fine as Twig. That is what [`tools/twig-render`](../twig-render/README.md)
  is for -- it renders each template against real fixtures and validates the result.
- **Does it emit the right fields?** No. `item.name_label` on a field that has
  no `name_label` parses perfectly and renders `null`. Needs checking against
  [`archipelago-metadata-mappings`][mappings]`/mappings/main.yml`.
- **Do the Views exist?** No. `drupal_view('data_collection_manifest', ...)`
  is a stub here. If the View is missing or renamed, only a real site notices.
- **Does the deployed site match this repo?** No. These files are copies of what
  lives in `/admin/structure/metadatadisplay`. Passing here means *the file* is
  valid — someone still has to paste it in.

## Known gaps

- **Twig version.** We lint with the newest patched `twig/twig` 3.x, not
  necessarily the version Drupal 10 ships. Linting with a newer Twig is a
  false-pass risk if a template ever uses a recently added filter. See the
  `_pinning_note` in `composer.json`; pinning low is blocked by security
  advisories on every 3.14.x release.
- **`allmaps_annotation_url`** (used in `Object_Description.twig.html`) could
  not be traced to a published module, so it is registered on faith. If it
  turns out not to exist in the deployed site, that template is already broken
  and this linter would not have told you.
- **Drupal's own Twig environment** adds node visitors we do not replicate.
  Nothing observed so far depends on them.

[mde]: https://github.com/esmero/format_strawberryfield/blob/main/src/Entity/MetadataDisplayEntity.php
[mappings]: https://github.com/tamulib-dc-labs/archipelago-metadata-mappings
