#!/usr/bin/env python3
"""Make zensical-i18n work on Windows.

zensical-i18n 0.3.x hands the paths of the staged sources to Zensical as they
are, and on Windows they carry backslashes — which the Rust core of Zensical
rejects with `invariant: Id(Format(Path(Backslash)))`.

The patch turns the four places that pass paths into POSIX ones. It is only
needed on Windows; on Linux the paths are already POSIX.

Run it once, after installing the requirements:

    python docs/tools/patch-zensical-i18n-windows.py
"""

from __future__ import annotations

import pathlib
import sys

PATCHES = {
    "builder.py": [
        (
            '"docs_dir": str(work_dir / "docs" / language.locale),',
            '"docs_dir": (work_dir / "docs" / language.locale).as_posix(),',
        ),
        (
            '"site_dir": str(work_dir / "site" / language.locale),',
            '"site_dir": (work_dir / "site" / language.locale).as_posix(),',
        ),
        (
            "str(settings.config_file),",
            'str(settings.config_file).replace("\\\\", "/"),',
        ),
    ],
    "runner.py": [
        (
            'config["theme_dirs"] = [str(theme_dir), *config["theme_dirs"]]',
            'config["theme_dirs"] = [theme_dir.as_posix(), *config["theme_dirs"]]',
        ),
    ],
}


def package() -> pathlib.Path:
    """Return the folder of the installed zensical_i18n package."""
    try:
        import zensical_i18n
    except ImportError:
        print("zensical-i18n is not installed in this environment.")
        sys.exit(1)
    return pathlib.Path(zensical_i18n.__file__).parent


def main() -> int:
    folder = package()
    changed = 0
    for name, patches in PATCHES.items():
        path = folder / name
        if not path.exists():
            print(f"{path} is missing.")
            continue
        text = path.read_text(encoding="utf-8")
        for old, new in patches:
            if old in text:
                text = text.replace(old, new)
                changed += 1
            elif new not in text:
                print(f"{name}: neither the old nor the new code was found:\n  {old}")
        path.write_text(text, encoding="utf-8", newline="")
    print(f"{changed} places patched in {folder}.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
