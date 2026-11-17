#!/bin/sh
# Renders the database seed dump with values from .env.
#
# The MariaDB image imports every *.sql found in /docker-entrypoint-initdb.d
# only while the data volume is created, and does it as the unprivileged mysql
# user. The dump is therefore rendered at container start, before exec'ing the
# stock entrypoint, so dump.sql never has to be edited by hand when the SMF
# version or the environment changes — and no build-time ARG is needed
# (Docker flags secret-bearing ARGs: SecretsUsedInArgOrEnv).
#
# Patches applied to the template dump (dockerfiles/dump.sql):
#   - drops the mariadb-client sandbox marker (the dump is a trusted artifact)
#   - SMF version strings                    -> $SMF_VERSION
#   - session URLs (avatar/smiley/theme/...) -> $FORUM_URL
#   - the seeded RESTful API key             -> $SMF_API_KEY
set -eu

template=${1:-./dump.sql}
target=${2:-/docker-entrypoint-initdb.d/dump.sql}

: "${SMF_VERSION:?SMF_VERSION is required to render the database dump}"
: "${FORUM_URL:?FORUM_URL is required to render the database dump}"
: "${SMF_API_KEY:?SMF_API_KEY is required to render the database dump}"

if [ ! -f "$template" ]; then
	printf 'Dump template %s not found.\n' "$template" >&2
	exit 1
fi

sed -E \
	-e '/^\/\*M!999999/d' \
	-e 's/(window\.smfVersion = \\?"SMF )[0-9][0-9.]*/\1'"$SMF_VERSION"'/' \
	-e 's/(\\"version\\":\\"SMF )[0-9][0-9.]*/\1'"$SMF_VERSION"'/' \
	-e "s/('smfVersion',[[:space:]]*')[0-9][0-9.]*/\1$SMF_VERSION/" \
	-e "s|http://localhost|$FORUM_URL|g" \
	-e "s|smf-dev-key-0001|$SMF_API_KEY|g" \
	"$template" > "$target"

printf 'Database dump rendered for SMF %s (forum %s).\n' "$SMF_VERSION" "$FORUM_URL"
