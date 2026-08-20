# Archipelago Twig lint

Answers one question, in about two seconds, without a Drupal install:

> **Would Archipelago accept this template?**

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

As of 08/11/2026 two templates in `main` fail this check:
`IIIF_Presenation_API_3_Series_Manifest_Unified` and
`iiif_manifest_3.0_thumbnail`. **They are not fixed here** — this tool only
reports. Each failure is copied into `tests/fixtures/` so the linter keeps
proving it can catch that bug class after the templates are eventually
corrected.

Because of that, the job is **red on `main` today**. Decide how to handle it
before making it a required check — fix the two templates, or add a baseline.

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

In CI this runs on every PR touching `twig/**`. Locally:

```bash
composer install -d tools/twig-lint
php tools/twig-lint/lint.php                    # all templates
php tools/twig-lint/lint.php twig/metadatadisplays/MODS_3_7.twig.html
php tools/twig-lint/selftest.php                # check the linter itself

# check the registry against real upstream module source
php tools/twig-lint/bin/build-dump.php --out=dump.json
php tools/twig-lint/bin/compare-registry.php dump.json
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
2. Put it under the source that provides it, so the drift check below stays
   possible.
3. Add it to `tests/fixtures/good_archipelago_idioms.twig.html`, so removing it
   later fails the self-test instead of silently weakening the check.

### Drift — checked automatically

`.github/workflows/registry-drift.yml` runs weekly. It fetches the module
sources pinned in `registry/sources.json`, extracts every declared
`TwigFilter` / `TwigFunction` / `TwigTest` using PHP's tokenizer, and confirms
that every name the registry trusts really exists. Run it yourself with:

```bash
php tools/twig-lint/bin/build-dump.php --out=dump.json
php tools/twig-lint/bin/compare-registry.php dump.json
```

As of 08/11/2026 that reports **37 registered names confirmed, 2 exempt, 0
missing** against Archipelago 1.6.0 sources (114 names offered across 362
files).

Bump the refs in `registry/sources.json` when Archipelago is upgraded — that
is the trigger for re-checking, and the job will tell you what moved.

### The authoritative check

`build-dump.php` can only see the upstream modules we thought to list. It
cannot see a TAMU-local module. For the real answer, dump the names from a
running site — read-only, touches no content, staging is fine:

```bash
docker cp tools/twig-lint/bin/dump-twig-names.php esmero-php:/tmp/
docker exec esmero-php drush php:script /tmp/dump-twig-names.php > site-dump.json
php tools/twig-lint/bin/compare-registry.php site-dump.json
```

That reads the live Twig service, so it covers every installed module plus
Drupal core, and settles the two exempt names below.

### Two names the upstream scan cannot confirm

Both are marked `scan_exempt` in `registry/extensions.json`:

| Name | Status |
|---|---|
| `sbf_datacite` | Expected — comes from Fragaria, a separate subscription module not in `sources.json`. |
| `allmaps_annotation_url` | **Unresolved.** Scanning `format_strawberryfield`, `strawberryfield`, `webform_strawberryfield`, `twig_tweak`, `bamboo_twig` and Drupal core found no such function anywhere. It is either a TAMU-local module or it does not exist — and if it does not, `Object_Description.twig.html` is already broken in production. The site dump above settles it. |

## What this does NOT check

This is tier 1 of a larger plan. It says a template is *syntactically
acceptable to Archipelago*. It says nothing about whether the output is
correct:

- **Does it emit valid JSON / XML?** No. Trailing commas, broken manifests, and
  malformed MODS all parse fine as Twig. Needs fixture rendering + `iiif-validator`
  / `xmllint`.
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
