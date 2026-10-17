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

Go to _Settings_ → _Page Cache_ to setup the basic configuration options:

- **Enabled caching**: Enable or disable the caching. Note: you need to delete the cache manually.
- **Enable gzip compression**: Serve gzip compressed cached files.
- **Enable brotli compression**: Serve brotli compressed cached files. Only works if the PHP brotli extension is installed.
- **Cache pages with query strings**: Off by default. Every query string (e.g. `?page=2`) creates its own cache file, so only enable it together with **Excluded URL's** or **Included URL's**.
- **When globals are saved**: Choose what happens when globals got saved. Choose between "Refresh cache" & "Delete cache"
- **Excluded URL's**: Define URL's which should **never** be cached (regex possible, `#` is used as delimiter, so `news/archive` works without escaping).
- **Included URL's**: Define URL's which should **always** be cached (regex possible). This options overwrites the **Excluded URL's** and **Cache pages with query strings** options.

Cached pages are only created and served for guests. Logged-in users, previews and requests with a token always get the live page.
- **Cache folder path**: Define a custom path, where cached files should be stored. Aliases (like `@webroot`) allowed.

## Add rewrite rules rules

To speed up your page even more you can add rewrite rules for .htaccess, nginx and apache.
You can use the examples for .htaccess (with and without gzip/brotli compression):

- [resources/rewrite-rules/.htaccess.example](resources/rewrite-rules/.htaccess.example)
- [resources/rewrite-rules/.htaccess.example.br](resources/rewrite-rules/.htaccess.example.br)
- [resources/rewrite-rules/.htaccess.example.gzip](resources/rewrite-rules/.htaccess.example.gzip)

The [brotli](resources/rewrite-rules/.htaccess.example.br) and [gzip](resources/rewrite-rules/.htaccess.example.gzip) compressions can be used together, separately or not at all. Just make sure to add them **before** the [normal rewrite rules](resources/rewrite-rules/.htaccess.example)

The examples skip the cache for requests with a `CraftSessionId` cookie, so logged-in users get the live page. Adjust the cookie name if you changed `phpSessionName`. If **Cache pages with query strings** is off, you can remove the rules with `%{QUERY_STRING}` in the path.

## Using Page Cache

- Go to _Settings_ → _Page Cache_
- Enable caching and optionally customize the settings
- As soon as a user visits a page, it got's cached.
- Optional: go to _Entries_, mark all cachable entries (→ entries with URL's), and Choose _Refresh cache_

## Delete Page Caches

- Go to _Utilities_ → _Caches_
- Select _Page Cache_
- Click on _Clear caches_

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

## Page Cache Roadmap

Some things to do, and ideas for potential features:

- Add entry action to exclude entries
- Add config option to include URL's

Brought to you by [Benjamin Ammann](https://github.com/ammannbe)
