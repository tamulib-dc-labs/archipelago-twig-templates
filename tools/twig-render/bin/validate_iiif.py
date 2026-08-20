#!/usr/bin/env python3
"""Validate rendered IIIF documents with iiif-prezi3.

Second half of the pipeline. bin/render-check.php renders each Metadata
Display template against the committed fixtures and writes the results out;
this parses those documents into the iiif-prezi3 model, which is generated
from the same schema the IIIF Presentation Validator uses.

Why the library rather than the raw JSON Schema: iiif-prezi3 is maintained
alongside the spec, its pydantic errors name the offending field and the type
it wanted, and a defect found here is reportable upstream against the library
people actually consume.

What it does NOT do: say whether a manifest is CORRECT. A document can parse
cleanly into a Manifest and still describe the wrong thing. This answers
"is this a legal Presentation 3 document", nothing more.

Usage:
  validate_iiif.py <index.json>
  validate_iiif.py --format=github <index.json>

<index.json> is written by render-check.php and maps each rendered document to
the template and fixture it came from, so a failure can be reported against the
template rather than a temp file nobody recognises.

Exit 0 = every document is a legal IIIF resource, 1 = at least one is not,
2 = could not run.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

try:
    from iiif_prezi3 import AnnotationPage, Collection, Manifest
    from pydantic import ValidationError
except ImportError as exc:  # pragma: no cover
    print(f"::error::iiif-prezi3 is not installed ({exc}). "
          f"Run: pip install -r tools/twig-render/requirements.txt", file=sys.stderr)
    raise SystemExit(2)

# Which model to parse a document into, keyed by its own "type".
MODELS = {
    "Manifest": Manifest,
    "Collection": Collection,
    "AnnotationPage": AnnotationPage,
}


def escape(value: str) -> str:
    """GitHub workflow commands are newline delimited and use % for escaping."""
    return value.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")


def summarise(error: ValidationError, limit: int = 6) -> list[str]:
    """Turn a pydantic ValidationError into short, pointed lines.

    The raw exception is long and repeats a variant for every branch of a
    union. The location and the expected type are the parts someone acts on.
    """
    lines = []
    for item in error.errors()[:limit]:
        where = "/".join(str(part) for part in item.get("loc", ()))
        lines.append(f"{where or '/'} — {item.get('msg', 'invalid')}")
    remaining = len(error.errors()) - limit
    if remaining > 0:
        lines.append(f"...and {remaining} more")
    return lines


def validate(entry: dict, github: bool) -> bool:
    path = Path(entry["output"])
    template = entry.get("template", path.name)
    fixture = entry.get("fixture", "?")

    try:
        raw = path.read_text(encoding="utf-8")
    except OSError as exc:
        report(github, template, fixture, [f"could not read rendered output ({exc})"])
        return False

    try:
        document = json.loads(raw)
    except json.JSONDecodeError as exc:
        report(github, template, fixture, [f"not valid JSON: {exc}"])
        return False

    declared = document.get("type") if isinstance(document, dict) else None
    model = MODELS.get(declared)
    if model is None:
        report(github, template, fixture,
               [f'cannot validate a document whose "type" is {declared!r}; '
                f'expected one of {", ".join(MODELS)}'])
        return False

    try:
        model.model_validate(document)
    except ValidationError as exc:
        report(github, template, fixture, summarise(exc))
        return False

    print(f"  ok   {template}  ({fixture}, {declared})")
    return True


def report(github: bool, template: str, fixture: str, problems: list[str]) -> None:
    if github:
        for problem in problems:
            print(f"::error file=twig/metadatadisplays/{template},line=1,"
                  f"title=Invalid IIIF ({fixture} fixture)::{escape(problem)}")
    print(f"  FAIL {template}  ({fixture})")
    for problem in problems:
        print(f"         {problem}")


def selftest() -> int:
    """Prove iiif-prezi3 still rejects what it should.

    Without this, a dependency bump that loosened validation would turn every
    manifest green and look exactly like a fix. Guards specifically the defect
    this pipeline found in production: a metadata value given as a bare string
    where Presentation 3 requires an array of strings.
    """
    good = {
        "@context": "http://iiif.io/api/presentation/3/context.json",
        "id": "https://example.org/m/1", "type": "Manifest",
        "label": {"en": ["Test"]},
        "metadata": [{"label": {"en": ["Publisher"]}, "value": {"en": ["wrapped correctly"]}}],
        "items": [],
    }
    bad = json.loads(json.dumps(good))
    bad["metadata"][0]["value"]["en"] = "a bare string"

    failures = 0

    try:
        Manifest.model_validate(good)
        print("  ok   valid manifest              accepted as expected")
    except ValidationError as exc:
        print(f"  FAIL valid manifest              rejected: {summarise(exc, 2)}")
        failures += 1

    try:
        Manifest.model_validate(bad)
        print("  FAIL bare-string metadata value  ACCEPTED -- validation has been loosened")
        failures += 1
    except ValidationError:
        print("  ok   bare-string metadata value  rejected as expected")

    print(f"\n2 checks, {failures} failure{'' if failures == 1 else 's'}")
    return 1 if failures else 0


def main(argv: list[str]) -> int:
    github = False
    paths = []
    for arg in argv:
        if arg == "--format=github":
            github = True
        elif arg == "--selftest":
            return selftest()
        elif arg.startswith("--"):
            print(f"Unknown option: {arg}", file=sys.stderr)
            return 2
        else:
            paths.append(arg)

    if len(paths) != 1:
        print(__doc__, file=sys.stderr)
        return 2

    try:
        index = json.loads(Path(paths[0]).read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        print(f"::error::could not read the render index: {exc}", file=sys.stderr)
        return 2

    if not index:
        # Nothing to check is not the same as everything passing.
        print("::error::the render index is empty -- no IIIF documents were produced, "
              "so nothing was validated", file=sys.stderr)
        return 2

    print(f"iiif-prezi3 validation — {len(index)} document(s)\n")
    results = [validate(entry, github) for entry in index]
    failed = results.count(False)

    print()
    if failed:
        print(f"{failed} of {len(results)} rendered IIIF documents are invalid.")
    else:
        print(f"OK {len(results)} rendered IIIF documents are legal Presentation 3 resources.")

    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
