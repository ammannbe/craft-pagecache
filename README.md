# Page Cache plugin for Craft CMS

Simple but useful Page Cache Plugin.

With this plugin you can create static HTML files of your entries.

## Requirements

This plugin requires Craft CMS 3.x or later.

## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

```bash
cd /path/to/project
```

2. Then tell Composer to load the plugin:

```bash
composer require suhype/craft-pagecache
```

3. In the Control Panel, go to _Settings_ → _Plugins_ and click the “Install” button for Page Cache.

## Page Cache Overview

Page Cache is a Craft CMS plugin which can create static HTML files of your entries.

## Configuring Page Cache

Go to _Settings_ → _Page Cache_ to setup the configuration options (grouped in tabs). Every setting can also be set in `config/pagecache.php`, copy [resources/config/pagecache.php](resources/config/pagecache.php) as a starting point. Settings from the config file are locked in the control panel.

- **Enabled caching**: Enable or disable the caching. Note: you need to delete the cache manually.
- **Enable gzip compression**: Serve gzip compressed cached files.
- **Enable brotli compression**: Serve brotli compressed cached files. Only works if the PHP brotli extension is installed.
- **Cache pages with query strings**: Off by default. Every query string (e.g. `?page=2`) creates its own cache file, so only enable it together with **Excluded URL's** or **Included URL's**.
- **Refresh mode**: What happens with the cache of a page when its content changes:
  - **Clear right away and generate again** (default): the cache is deleted when the element is saved, so changes are visible immediately. The queue job caches the page again afterwards.
  - **Keep until generated again**: visitors get the old page until the queue job created the new one. Always fast, but briefly outdated.
  - **Clear only**: the cache is deleted, the next visitor caches the page again.
  Pages of disabled, expired or deleted elements are always deleted right away.
- **Track dependencies**: On by default. Remembers which elements (entries, nested entries, assets, categories, …) and element queries (e.g. `craft.entries.section('news')`) a page uses, so it is refreshed when one of them changes. See [Dependency tracking](#dependency-tracking).
- **When globals are saved**: Choose what happens when globals got saved. Choose between "Refresh cache" & "Delete cache"
- **Excluded URL's**: Define URL's which should **never** be cached (regex possible, `#` is used as delimiter, so `news/archive` works without escaping).
- **Included URL's**: Define URL's which should **always** be cached (regex possible). This options overwrites the **Excluded URL's** and **Cache pages with query strings** options.

Cached pages are only created and served for guests. Logged-in users, previews and requests with a token always get the live page.
- **Cache folder path**: Define a custom path, where cached files should be stored. Aliases (like `@webroot`) allowed.
- **Concurrent requests** / **Timeout per page**: How the queue job requests the pages to generate their cache.
- **Basic auth username / password**: If the site is protected with basic auth (e.g. staging). The password must be an environment variable (e.g. `$BASIC_AUTH_PASSWORD`), so it is not stored in the project config. The queue job uses Craft's Guzzle client, so `config/guzzle.php` applies as well.
- **Show in the main navigation**: Show Page Cache as its own page in the main navigation (same content as the utility, with a _Refresh cache_ button).
- **Show “Refresh cache” in the main navigation**: A navigation item that refreshes all cached pages with one click (e.g. in the refresh mode “Keep until generated again”).

## Add rewrite rules

To speed up your page even more you can add rewrite rules, so the web server serves cached pages without starting PHP.
You can use the examples for Apache/.htaccess (with and without gzip/brotli compression) and nginx:

- [resources/rewrite-rules/.htaccess.example](resources/rewrite-rules/.htaccess.example)
- [resources/rewrite-rules/.htaccess.example.br](resources/rewrite-rules/.htaccess.example.br)
- [resources/rewrite-rules/.htaccess.example.gzip](resources/rewrite-rules/.htaccess.example.gzip)
- [resources/rewrite-rules/nginx.conf.example](resources/rewrite-rules/nginx.conf.example)

The [brotli](resources/rewrite-rules/.htaccess.example.br) and [gzip](resources/rewrite-rules/.htaccess.example.gzip) compressions can be used together, separately or not at all. Just make sure to add them **before** the [normal rewrite rules](resources/rewrite-rules/.htaccess.example)

The examples skip the cache for requests with a `CraftSessionId` cookie, so logged-in users get the live page. Adjust the cookie name if you changed `phpSessionName`. If **Cache pages with query strings** is off, you can remove the rules with `%{QUERY_STRING}` (nginx: `$args`) in the path.

The queue job requests pages with a `pagecache-generate` query parameter, so the rewrite rules don't serve the old cached file (the plugin removes the parameter before rendering, the secret token is sent as header). Keep the rule that only serves the cache without query string (`RewriteCond %{QUERY_STRING} ^$`).

## Using Page Cache

- Go to _Settings_ → _Page Cache_
- Enable caching and optionally customize the settings
- As soon as a guest visits a page, it gets cached. To cache all pages right away, use _Cache all pages_ in the utility.
- When an element is saved, all pages that show it are refreshed (see **Refresh mode**). Saving an entry collects all changes of the request (e.g. its nested entries) and queues the pages once. A single queue job processes them, new pages are added to the waiting job.
- The sidebar of an entry shows whether its page is cached (and since when) or waiting in the queue, with buttons to refresh or delete its cache. The element index has the same actions for several entries.

## Page Cache utility

_Utilities_ → _Page Cache_ (optionally also as its own page in the main navigation) shows:

- **Notices**: problems and improvement suggestions with links to the documentation, e.g. pages that are not cached because of a CSRF token, a queue that doesn't run, missing rewrite rules or a missing cron job for scheduled entries. Dismissed notices show up again when their content changes (e.g. a new URL).
- **Overview**: refresh mode, cached pages per site, pages in the queue and tracked elements and queries, updated every few seconds.
- **Actions**: refresh, cache or delete all pages (optionally of one site), refresh pages by URL or by tag.
- **Template optimizations** (second tab): template patterns that don't work well with a static cache, with explanation, fix and a ready-made prompt for AI coding assistants (per finding or for all):
  - found in the site templates, with file and line: `csrfInput()` without `async`, `csrfToken`, `{% cache %}` tags, random content (`RAND()`, `shuffle`, `random()`), `now`, `{% paginate %}`, `Cache-Control` headers
  - found while pages are rendered, with URL: CSRF tokens, image transforms that were not generated yet, pages with more than 100 element queries (missing eager loading), random order

The utility requires the _Page Cache_ utility permission.

## Dependency tracking

While a page is rendered, the plugin remembers:

- every **element** that is loaded (entries, nested entries such as Matrix blocks, assets, categories, users, …)
- every **element query** that could return other elements, e.g. `craft.entries.section('news').limit(3)` (limits and order are ignored, so a new news entry always refreshes the listing)

When an element is saved or deleted, the pages that showed it are refreshed right away. Whether a new or changed element appears in a stored element query is checked by the queue job, because it runs the queries again. Queries for specific elements (by ID, nested elements of an owner, eager loading) are covered by the element tracking.

Entries change status without being saved when their post or expiry date passes. Their pages are refreshed by `php craft pagecache/cache/refresh-expired`. Site requests run this check every 5 minutes as well, but run it with a cron job (e.g. every 5 minutes) if the site has little traffic or cached pages are served by the rewrite rules:

```bash
*/5 * * * * php /path/to/project/craft pagecache/cache/refresh-expired
```

After updating to 2.3, the existing caches are generated again once, so their dependencies are known.

Limitations:

- Content inside `{% cache %}` tags is not tracked when Craft serves it from its template cache (no queries run). Avoid `{% cache %}` on cached pages, or refresh the pages with `craft.pagecache.tags()` and the tag actions.
- Queries restricted by custom conditions (e.g. `.andWhere()`, relation fields) are not stored as queries; the elements they return are tracked.
- Globals are not tracked, see **When globals are saved**.

## Excluding pages and tags

Exclude a page from the cache in its template:

```twig
{% do craft.pagecache.exclude() %}
```

Tag a page, so it can be refreshed or deleted by tag (`--tags` in the console commands):

```twig
{% do craft.pagecache.tags(['news', 'events']) %}
```

These pages are never cached automatically:

- Paginated URLs (e.g. `news/p2`)
- Non-HTML responses (e.g. `sitemap.xml` templates or `{% header "Content-Type: application/json" %}`)
- Pages that use a CSRF token while rendering, e.g. `{{ csrfInput() }}` without `async` or `{{ craft.app.request.csrfToken }}`. The token belongs to the visitor who rendered the page, so forms would fail for everyone else. Use `{{ csrfInput({ async: true }) }}` instead; a warning is logged otherwise.
- Pages that contain image transforms which are not generated yet
- Requests of visitors with a flash message (e.g. “Thank you for your message”), so the message neither ends up in the cache nor gets hidden by a cached page

### Cache-Control headers

`Cache-Control` headers (e.g. `{% header "Cache-Control: no-cache" %}`) only control browsers and proxies, **not** the page cache: the page is still cached on the server. Use `craft.pagecache.exclude()` to exclude a page.

Note that cached pages are served without the headers of the template, by the web server (rewrite rules) or by the plugin. If browsers must not cache a page, also set the `Cache-Control` header for cached pages in the web server configuration (e.g. for the `pagecache` folder).

## Delete Page Caches

- Go to _Utilities_ → _Page Cache_ and click _Delete cache_, or
- go to _Utilities_ → _Caches_, select _Page Cache_ and click _Clear caches_

## MCP

On Craft 5.12+, the plugin adds tools to Craft's MCP server (same permission as the utility):

- `pagecache_status`: settings, cached pages, queue, notices
- `pagecache_page_status`: whether the page of an element is cached and since when
- `pagecache_refresh`: refresh pages by URL, element ID, tag, site or all
- `pagecache_clear`: delete pages by tag, site or all
- `pagecache_warm`: cache all pages

Craft's own `caches_clear` tool can clear the whole page cache as well (key `pagecache`).

## Console commands

Clear the whole page cache (this is the same as under _Utilities_):

```bash
php craft clear-caches/pagecache
```

Refresh all cached pages (optionally only for a site or for pages with one of the given tags):

```bash
php craft pagecache/cache/refresh
php craft pagecache/cache/refresh --siteId=1 --tags=news,events
```

Delete cached pages (same options as `refresh`):

```bash
php craft pagecache/cache/delete --tags=news
```

Warm the cache of all entries with URL's:

```bash
php craft pagecache/cache
```

Refresh the pages of entries whose post or expiry date passed (see [Dependency tracking](#dependency-tracking)):

```bash
php craft pagecache/cache/refresh-expired
```

## Page Cache Roadmap

Some things to do, and ideas for potential features:

- Add entry action to exclude entries

Brought to you by [Benjamin Ammann](https://github.com/ammannbe)
