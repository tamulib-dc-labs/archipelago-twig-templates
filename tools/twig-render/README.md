# Render and validate IIIF output

Answers the question the linter cannot:

> **Does this IIIF template produce a valid document?**

Scope is deliberately IIIF only. Every template in `twig/metadatadisplays` is
still syntax-checked by [`tools/twig-lint`](../twig-lint/README.md); this tier
renders the IIIF ones and inspects what they produce, because those are the
documents that break viewers and interoperability when they are malformed.

[`tools/twig-lint`](../twig-lint/README.md) asks whether Archipelago would
*accept* a template. That is decided at **parse** time, and it is blind to this:

```twig
{"items":[{% for x in [1,2,3] %}{"n": {{ x }}},{% endfor %}]}
```

That parses perfectly. Archipelago saves it without complaint. It emits
`{"items":[{"n":1},{"n":2},{"n":3},]}` — a trailing comma, invalid JSON, and
every viewer downstream breaks. Catching it requires **rendering** the template
and parsing what comes out.

## How it works

Two stages, split by language because each side uses the best tool available.

**1. PHP renders** each template against the committed fixtures and checks the
output is well formed for the mimetype its Metadata Display declares:

| mimetype | check |
|---|---|
| any `json` / `ld+json` | parses as JSON |
| the same, with `fragment` | parses once wrapped in braces |
| anything else | rendered non-empty |

**2. Python validates the IIIF documents** with [`iiif-prezi3`][prezi], parsing
each into the model for the class it declares — `Manifest`, `Collection` or
`AnnotationPage`.

`iiif-prezi3` rather than a vendored copy of [`iiif_3_0.json`][schema]: the
library is generated from that schema, its pydantic errors name the offending
field and the type it expected, and a defect found here is reportable upstream
against the library consumers actually use.

The two stages are joined by `index.json`, written by the render step, which
carries each rendered document's template and fixture through so a failure is
reported against the template someone can fix rather than a temp file nobody
recognises.

## Running it

```bash
composer install -d tools/twig-render
pip install -r tools/twig-render/requirements.txt

# stage 1 — render and check well-formedness
php tools/twig-render/bin/render-check.php
php tools/twig-render/bin/render-check.php GeoJSON.twig.html   # one template
php tools/twig-render/bin/render-check.php --write=/tmp/out    # dump documents

# stage 2 — IIIF conformance
python3 tools/twig-render/bin/validate_iiif.py /tmp/out/index.json

# check the harnesses themselves
php tools/twig-render/selftest.php
python3 tools/twig-render/bin/validate_iiif.py --selftest
```

Exit 0 = everything valid, 1 = at least one document is not, 2 = could not run.

## The renderer is real, not stubbed

This is the important difference from the linter. `twig-lint` registers every
Archipelago name as a **no-op**, which is right for parsing — Twig only needs
the name to exist. Here we *execute*, so a no-op would produce empty output
that then validates as meaningless and the whole job would report green over
nothing.

So `ArchipelagoExtension` has exactly two kinds of member:

- **implemented** — `url`, `render`, `preg_replace`, `edtf_2_iso_date`,
  `markdown_2_html`, and the safe-to-approximate Drupal filters
- **unsupported** — registered, but **throws** when called

Nothing silently returns null. A template reaching for `drupal_view`,
`sbf_search_api` or anything else wanting a database fails loudly and names the
filter.

Autoescaping is left **on**, because Drupal renders Metadata Displays through an
autoescaping environment — that is *why* these templates are full of `|raw` and
`|json_encode|raw`. Turning it off would be more convenient and less faithful: a
value emitted without `|raw` inside a JSON document escapes its quotes and
breaks the JSON in production too, and that is a bug worth catching rather than
papering over.

### Fidelity limits

`url()` returns a configured base rather than a real Drupal route, and
`edtf_2_iso_date` covers EDTF level 0/1 rather than the full grammar. For
**structural** validity this does not matter — the model cares that a value is
a string of the right shape, not what the string says. It would matter for
asserting exact output, which this harness does not do.

## What is covered

Nine IIIF templates exist. **Five render offline; four cannot**, and say why in
`templates.json` rather than going quietly missing:

| Not covered | Why |
|---|---|
| `IIIF_..._Collection_Manifest` | `drupal_view()` — queries for member objects |
| `IIIF_..._Creative_Works_Series_Manifest` | `drupal_view()`, `bamboo_load_entity()` |
| `IIIF_Presenation_..._Series_Manifest_Unified` | `sbf_drupal_view_paged()`, `bamboo_load_entity()` |
| `Multiple Thumbnails via IIIF and FontAwesome` | `drupal_view()` |

All four aggregate *other* objects, so no amount of fixture data makes them
renderable — they need a database. Covering them means a live site.

Two of the five are **fragments**: the thumbnail templates emit a bare
`"thumbnail": [...]` member rather than a document, so they are parsed wrapped
in braces. A trailing comma or unbalanced bracket there corrupts every manifest
that embeds the snippet, which is worth catching even though the snippet is not
valid JSON standing alone.

`IIIF_Presentation_API_2.1_Manifest` is checked for JSON validity only:
iiif-prezi3 models Presentation 3, and parsing a 2.1 manifest with it would fail
for the wrong reason.

Non-IIIF templates — `GeoJSON`, `schema.org`, `DataCite`, the MODS/DC/OAI set —
are out of scope here by design. They are still syntax-checked by `twig-lint`,
but nothing renders them.

> **Known, unchecked:** `GeoJSON.twig.html` emits
> `{"type":"FeatureCollection","features":[null]}` for any record without a
> `geojson_feature`, because `[x] ?: []` always takes the left branch — a
> one-element array is truthy even when the element is null. Well formed JSON,
> not valid GeoJSON. Out of scope for this tier; recorded so it is not lost.

## Fixtures are the ground truth

`fixtures/*.json` are real Strawberryfield records **from production**, named
after the node they came from so their provenance is never in question.

| Fixture | What it is |
|---|---|
| `node_2637` | Map — 46 keys. `agent_linked_data` populated, `digital_publisher` as an **array** |
| `node_4276` | U.S.S. Pathfinder log, Map — 23 keys. `digital_publisher` as a bare **string** |

Between them those two cover both shapes `digital_publisher` takes in real data,
which is exactly why the template coerces with `is iterable` rather than
assuming one.

### Production only, on purpose

An earlier version of this used records pulled from `archipelago-dev`. That was
a mistake and they have been removed. Dev holds test records whose fields do not
match the metadata model, and testing against them produces **confident, wrong
findings**: a dev record carrying `agent_linked_data` as
`{uri, label, role_uri, role_label}` was reported here as a data defect, when
real production data is `{value, uri, role}` — exactly what the template reads
and what [`mappings/main.yml`][mappings] specifies. The template was right the
whole time; the fixture was junk.

So: **fixtures come from production, or they do not go in.** A wrong fixture is
worse than a missing one, because it manufactures bugs that do not exist and
costs someone the time to disprove them.

### Adding one

Drop the raw `field_descriptive_metadata` value in as `node_<nid>.json` and add
its name to `defaults.fixtures` in `templates.json`.

**This tier is only as good as these files.** Current gaps: both fixtures are
Maps, so no A/V or PDF object exercises the `as:video` or `as:document`
branches, and neither is a collection. The self-test fails if the fixtures
directory is empty, so "0 documents checked" can never pass as success.

[mappings]: https://github.com/tamulib-dc-labs/archipelago-metadata-mappings/blob/main/mappings/main.yml

## The self-test exists for a reason

Both ways this harness can rot look exactly like success:

1. A dependency bump loosens validation, or a stub starts accepting anything, and
   everything reports green forever.
2. An unimplemented filter starts returning null instead of throwing, so
   templates needing Drupal render empty and "validate" fine.

`selftest.php` asserts a trailing comma is caught, a `drupal_view` template
throws rather than rendering, and at least one fixture exists.
`validate_iiif.py --selftest` asserts iiif-prezi3 still rejects a bare-string
metadata value — the exact defect this pipeline found in `main`. Both run
before the real checks in CI.

## What this still does not check

- **Anything that is not IIIF.** MODS, Dublin Core, OAI-PMH, GeoJSON,
  schema.org and DataCite are neither rendered here nor syntax-checked by
  `twig-lint` — that is roughly 1,500 lines of XML-emitting Twig with no
  coverage at all. A malformed MODS record breaks OAI-PMH harvesters the same
  way a malformed manifest breaks viewers. Expected in a later PR; until then a
  green build says nothing about them.
- **Semantic correctness.** A manifest can parse cleanly and still describe the
  wrong thing. Valid ≠ right.
- **The aggregate manifests**, per above.
- **Whether the deployed site matches this repo.** These files are copies of
  database rows; nothing here compares them to what is live.

[schema]: https://github.com/IIIF/presentation-validator/blob/main/schema/iiif_3_0.json

[prezi]: https://github.com/iiif-prezi/iiif-prezi3
