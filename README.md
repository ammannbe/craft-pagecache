# Page Cache plugin for Craft CMS 4.x

Simple but useful Page Cache Plugin.

With this plugin you can create static HTML files of your entries.

## Requirements

This plugin requires Craft CMS 4.x.

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
- **Optimize HTML**: Minify and optimize HTML. Use with caution!
- **Enable gzip compression**: Serve gzip compressed cached files.
- **Enable brotli compression**: Serve brotli compressed cached files. Only works if the PHP brotli extension is installed.
- **When globals are saved**: Choose what happens when globals got saved. Choose between "Renew cache", "Recreate cache (delete query)", "Delete cache"
- **Excluded URL's**: Define URL's which should not be cached (regex possible).
- **Cache folder path**: Define a custom path, where cached files should be stored. Aliases (like `@webroot`) allowed.

## Add rewrite rules rules

To speed up your page even more you can add rewrite rules for .htaccess, nginx and apache.
You can use the examples for .htaccess (with and without gzip/brotli compression):

- [resources/rewrite-rules/.htaccess.example](resources/rewrite-rules/.htaccess.example)
- [resources/rewrite-rules/.htaccess.example.br](resources/rewrite-rules/.htaccess.example.br)
- [resources/rewrite-rules/.htaccess.example.gzip](resources/rewrite-rules/.htaccess.example.gzip)

The [brotli](resources/rewrite-rules/.htaccess.example.br) and [gzip](resources/rewrite-rules/.htaccess.example.gzip) compressions can be used together, separately or not at all. Just make sure to add them **before** the [normal rewrite rules](resources/rewrite-rules/.htaccess.example)

## Using Page Cache

- Go to _Settings_ → _Page Cache_
- Enable caching and optionally customize the settings
- As soon as a user visits a page, it got's cached.
- Optional: go to _Entries_, mark all cachable entries (→ entries with URL's), and Choose _Create/Renew cache_

## Delete Page Caches

- Go to _Utilities_ → _Caches_
- Select _Page Cache_
- Click on _Clear caches_

## Console commands

Clear the whole page cache (this is the same as under _Utilities_):

```bash
php craft clear-caches/pagecache
```

Recreate existing page caches:

```bash
# Run `php craft pagecache/recreate --help` to see the possible arguments

php craft pagecache/recreate
```

## Page Cache Roadmap

Some things to do, and ideas for potential features:

- Add entry action to exclude entries
- Add config option to include URL's

Brought to you by [Benjamin Ammann](https://github.com/ammannbe)
