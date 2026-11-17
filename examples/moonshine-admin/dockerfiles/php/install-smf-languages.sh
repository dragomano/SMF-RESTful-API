#!/bin/sh
# Installs SMF language packs that are available in the local downloads folder.
#
# Usage: install-smf-languages.sh <smf-version-with-dashes> [target-dir]
#
# Every smf_<version>_language-<language>.zip found in the downloads folder is
# installed, so any language (russian, spanish_es, ...) works without changing
# the Dockerfile. When no language pack is present, only the install package is
# used and SMF keeps its built-in English files.
set -eu

version_dash=$1
target_dir=${2:-/var/www/html}
archive_dir=${SMF_DOWNLOAD_DIR:-/tmp/smf-downloads}

if [ -z "$version_dash" ]; then
	printf 'Usage: %s <smf-version-with-dashes> [target-dir]\n' "$0" >&2
	exit 1
fi

if [ ! -d "$target_dir/Themes/default/languages" ]; then
	printf 'Target directory %s does not contain Themes/default/languages. Install SMF first.\n' "$target_dir" >&2
	exit 1
fi

work_dir=$(mktemp -d)
trap 'rm -rf "$work_dir"' EXIT

installed=0
for archive in "$archive_dir"/smf_"$version_dash"_language-*.zip; do
	[ -f "$archive" ] || continue

	filename=$(basename "$archive")
	language=${filename#smf_"$version_dash"_language-}
	language=${language%.zip}

	if ! unzip -tq "$archive" >/dev/null; then
		printf 'Invalid ZIP archive %s. Save a valid official language archive to _docker/dockerfiles/downloads/%s and rebuild.\n' \
			"$archive" "$filename" >&2
		exit 1
	fi

	unpack_dir="$work_dir/$language"
	mkdir -p "$unpack_dir"
	unzip -q "$archive" -d "$unpack_dir"

	if [ ! -d "$unpack_dir/Themes/default/languages" ]; then
		printf 'Archive %s does not contain Themes/default/languages. Save a valid official language archive to _docker/dockerfiles/downloads/%s and rebuild.\n' \
			"$archive" "$filename" >&2
		exit 1
	fi

	cp -R "$unpack_dir/Themes/default/languages/." "$target_dir/Themes/default/languages/"

	if [ -f "$unpack_dir/agreement.$language.txt" ]; then
		cp "$unpack_dir/agreement.$language.txt" "$target_dir/agreement.$language.txt"
	fi

	printf 'Installed SMF language pack: %s\n' "$language"
	installed=$((installed + 1))
done

if [ "$installed" -eq 0 ]; then
	printf 'No smf_%s_language-*.zip found in %s; using the built-in English language files.\n' \
		"$version_dash" "$archive_dir"
fi
