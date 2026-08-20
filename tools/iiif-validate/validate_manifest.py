#!/usr/bin/env python3
"""Validate a rendered IIIF Presentation 3 manifest using iiif-prezi3.

This checks that the JSON produced by render.php parses into a valid
iiif_prezi3.Manifest -- i.e. it structurally conforms to the Presentation 3
model (required fields, types, shapes). It is not a full spec/schema
validator and does not check against another manifest; it is a
"does this parse as a legal Presentation 3 Manifest" check.

Usage: validate_manifest.py <rendered.json> [<rendered2.json> ...]
"""
import json
import sys

from iiif_prezi3 import Manifest
from pydantic import ValidationError


def validate(path: str) -> bool:
    try:
        with open(path, encoding="utf-8") as f:
            raw = f.read()
    except OSError as exc:
        print(f"::error::{path}: could not read file ({exc})")
        return False

    try:
        json.loads(raw)
    except json.JSONDecodeError as exc:
        print(f"::error::{path}: not valid JSON ({exc})")
        return False

    try:
        Manifest.model_validate_json(raw)
    except ValidationError as exc:
        print(f"::error::{path}: failed IIIF Presentation 3 validation")
        print(exc)
        return False

    print(f"OK: {path}")
    return True


def main(argv: list[str]) -> int:
    if not argv:
        print("Usage: validate_manifest.py <rendered.json> [...]", file=sys.stderr)
        return 2

    results = [validate(path) for path in argv]
    return 0 if all(results) else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
