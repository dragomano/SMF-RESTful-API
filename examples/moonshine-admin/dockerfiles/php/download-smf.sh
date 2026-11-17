#!/bin/sh
set -eu

filename=$1
destination=$2
archive_dir=${SMF_DOWNLOAD_DIR:-/tmp/smf-downloads}
url="https://download.simplemachines.org/index.php/$filename"

if [ -f "$archive_dir/$filename" ]; then
	cp "$archive_dir/$filename" "$destination"
else
	if ! curl --fail --location --show-error --retry 5 --retry-delay 5 --max-time 300 \
		--output "$destination" "$url"; then
		printf 'Could not download %s. Save the official archive to _docker/dockerfiles/downloads/%s and rebuild.\n' "$url" "$filename" >&2
		exit 1
	fi
fi

if ! unzip -tq "$destination" >/dev/null; then
	printf 'Invalid ZIP archive for %s. Save a valid official archive to _docker/dockerfiles/downloads/%s and rebuild.\n' "$url" "$filename" >&2
	exit 1
fi
