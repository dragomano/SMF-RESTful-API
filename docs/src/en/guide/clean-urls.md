---
description: Optional rewrite rules for short /api/* URLs on Apache and Nginx; the direct index.php?action=api;path=... address always works.
---

# Clean URLs (rewrite rules) { #clean-urls-rewrite-rules }

Rewrite rules are optional: the API is always reachable at its "direct" address, `index.php?action=api;path=v1/members/5`. The rules merely turn it into the short `/api/v1/members/5`. The mod strips the leading `api` segment itself, so the request ends up at `index.php?action=api` with the path in the `path` parameter; the remaining query parameters (for example, `?limit=25&offset=50`) are preserved.

## Apache (.htaccess in the forum root) { #apache-htaccess-in-the-forum-root }

```apache title=".htaccess"
RewriteEngine On
# /api/v1/members/5  ->  index.php?action=api;path=v1/members/5
RewriteRule ^api/(.*)$ index.php?action=api;path=$1 [QSA,L]
```

The `QSA` flag appends the original query string, so filters and pagination keep working. If the forum is installed in a subdirectory, add a `RewriteBase` before the rule — for example, `RewriteBase /forum/`.

## Nginx { #nginx }

```nginx title="nginx.conf"
# /api/v1/members/5  ->  index.php?action=api;path=v1/members/5
location ^~ /api/ {
    rewrite ^/api/(.*)$ /index.php?action=api;path=$1 last;
}
```

The `^~` modifier gives this block priority over the general `location ~ \.php$` (the order in which they are declared does not matter), and `/index.php` is then handled by the forum's usual PHP location. Nginx automatically appends the original query parameters to the `rewrite` target.

!!! tip "Direct address always works"

    You can skip rewrite rules entirely and use `index.php?action=api;path=v1/members/5` (or `index.php?action=api&path=v1/members/5` depending on your separator style).


!!! warning "The Authorization header has to reach PHP"

    Some server setups strip the `Authorization` header before it reaches PHP — Apache with CGI does it, and it also breaks when the request passes through a proxy. The mod reads the stripped header from `REDIRECT_HTTP_AUTHORIZATION` as well, so it usually works out of the box. If authentication fails with a valid key, forward the header explicitly (for Apache: `CGIPassAuth On` or `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).


The path is also read from `PATH_INFO` when the `path` parameter is missing, so a rewrite rule that only rewrites the URL — without passing the path as a parameter — works as well.
