# Tainacan WACZ Player

Renders **`.wacz`** (and optionally raw **`.warc`**) web archives attached to a
[Tainacan](https://tainacan.org) item **directly on the public item page**, in a
section placed right below the **Attachments** box, using a **locally bundled**
[ReplayWeb.page](https://replayweb.page) viewer. **Zero external CDN.**

This is a **standalone plugin**. It is **not** part of the DIP Importer — it only
reads the attachments an item already has and adds a viewer for the web-archive
ones.

---

## Why `.wacz` and not `.warc` (no conversion)

The Memorial Digital da Pandemia imports DIP packages produced by Archivematica
into Tainacan. When the source is a web capture (`.warc`), Archivematica
normalizes it to **`.wacz`** (Web Archive Collection Zipped) — a packaged,
indexed form of one or more WARCs.

**Do not convert `.wacz` back to `.warc`.** A `.wacz` already contains the WARC
records internally, **plus** a CDXJ index and metadata that make replay fast.
ReplayWeb.page consumes `.wacz` **natively**. Converting would only add I/O and
throw away the index. This plugin therefore plays the `.wacz` exactly as stored.

---

## What it does

On every public Tainacan item page the plugin:

1. Confirms the post is a Tainacan item (`Theme_Helper::is_post_an_item()`).
2. Collects the item's attachments via the official theme tag
   `tainacan_get_the_attachments()` (plus the main document if it is an
   attachment).
3. Keeps only web archives — extension `.wacz`/`.warc`, or MIME
   `application/x-wacz` / `application/warc`. Detection is **extension-first**
   because servers disagree on the MIME of a `.wacz` (often `application/zip`).
4. Renders one section per archive, **below the Attachments box**:

   ```html
   <section class="twp-player-section">
     <h2 class="twp-player-title">Visualizar arquivo web</h2>
     <replay-web-page
       source="<attachment URL>"
       url="<entry-point URL>"
       replayBase="<plugin>/assets/vendor/replaywebpage/replay/">
     </replay-web-page>
   </section>
   ```

The **entry-point URL** (`url=`) is detected from `pages.jsonl` inside the
`.wacz`. The archive is read **straight from the local attachment file** with
`ZipArchive` (only the small `pages.jsonl` member is inflated — a 100 MB+ archive
is *not* fully unzipped), and the result is cached in post meta. If detection
fails, the configurable default (`/`) is used.

## Hooks used

| Purpose | Hook / API |
| --- | --- |
| Inject the player on the **official Tainacan theme** (tainacan-interface / `tainacan-theme`) | `add_action( 'tainacan-interface-single-item-after-attachments', … )` — the theme renders the item with its own template (`tainacan/single-items.php`) and fires this action **right below the Attachments section**. This is the case for the Memorial site. |
| Inject the player when Tainacan renders the **default** single-item content | `add_filter( 'tainacan_single_item_content', …, 20, 2 )` — Tainacan core builds Document/Metadata/Attachments and applies this filter at the end, with the Attachments section already included, so the player is appended after it. |
| Optional manual placement (FSE/block themes, or custom themes) | `register_block_type( 'wacz-player/inline', [ 'render_callback' => … ] )` |
| Settings page in the Tainacan menu | A class extending `\Tainacan\Pages`, registered with `add_submenu_page( $this->tainacan_root_menu_slug, …, 'read', …, 80 )` — see the [Tainacan admin-pages docs](https://tainacan.github.io/tainacan-wiki/#/dev/creating-tainacan-admin-pages). |
| Optional player in the item-edit "Document" area | `tainacan_register_admin_hook( 'item', …, 'end-left' )` (only when the API exists) |

## Plugin structure

```
tainacan-wacz-player/
├── tainacan-wacz-player.php          Header + bootstrap (constants, autoloader)
├── uninstall.php                     Removes options + cached post meta
├── readme.txt                        WordPress.org readme
├── README.md                         This file
├── composer.json                     Dev deps (phpcs / WPCS)
├── phpcs.xml.dist                    Strict ruleset (WP-Core/Extra/Security/PluginCheck)
├── includes/
│   ├── Plugin.php                    Singleton bootstrap, option access
│   ├── Player.php                    Detection + <replay-web-page> rendering
│   ├── Assets.php                    Enqueue of the vendored bundle (pinned)
│   ├── Blocks/WaczInlineBlock.php    Optional Gutenberg block
│   └── Admin/Settings.php            Settings page via \Tainacan\Pages
└── assets/
    ├── vendor/replaywebpage/
    │   ├── ui.js                     Pinned ReplayWeb.page UI bundle
    │   ├── sw.js                     Pinned ReplayWeb.page service worker
    │   ├── replay/sw.js              Same-origin SW entry (importScripts ../sw.js)
    │   └── .htaccess                 Optional Service-Worker-Allowed header
    ├── css/player.css                Section styling
    └── js/
        ├── init.js                   SW/secure-context check + download fallback
        └── block-editor.js           Block editor UI (no build step)
```

## Vendored ReplayWeb.page version

Pinned to **`replaywebpage@2.4.6`** (see `TWACZ_REPLAYWEBPAGE_VERSION`). Files were
downloaded from `https://cdn.jsdelivr.net/npm/replaywebpage@2.4.6/` and committed
locally — **nothing is loaded from a CDN at runtime**.

| File | SHA-256 |
| --- | --- |
| `assets/vendor/replaywebpage/ui.js` | `b7d386457aa77f4a3e6029c6b90211672df33b1d77ad1c579ee3de7bc9f3b982` |
| `assets/vendor/replaywebpage/sw.js` | `5d1388d7e29cbbb4e24f5adbbc763a1283a9da43ab6906441e845590c17dab1b` |

## Service Worker — the part that most often breaks

ReplayWeb.page needs a **Service Worker** to intercept replay requests, and that
worker **must be served from the same origin** (same protocol + host + port) as
the page registering it.

* The plugin serves the worker at
  `assets/vendor/replaywebpage/replay/sw.js`. That file is a thin entry point
  that `importScripts("../sw.js")` — the real, vendored worker — keeping a single
  authoritative copy, same origin, no CDN.
* `replayBase` points at `…/replaywebpage/replay/`, so the worker is registered
  there and claims the `…/replay/` scope. Browsers allow a worker to control its
  own directory **without any special header**, so this works out of the box on
  Apache (XAMPP included).
* A commented **`.htaccess`** is shipped next to the worker for hosts that need a
  broader `Service-Worker-Allowed` scope (e.g. if you relocate the worker). On
  Nginx, add `add_header Service-Worker-Allowed "/";` to the matching location.

### Secure context (HTTPS)

The viewer only runs in a **secure context** — HTTPS or `localhost`. On plain
HTTP the plugin hides the player and shows a **download** fallback instead.

### CORS

Because the `.wacz` and the player are served from the **same WordPress origin**,
there is **no CORS** to configure. (Apache already sends `Accept-Ranges: bytes`,
so large archives load progressively.)

## Settings

Under **Tainacan menu → WACZ Player**:

| Setting | Default |
| --- | --- |
| Public auto-injection below Attachments | On |
| Default player height (px) | 700 |
| Default entry-point URL (when not detected) | `/` |
| Accept raw `.warc` files too | On |

Viewing the page requires the `read` capability (like Tainacan's own light
pages); **saving** the settings requires `manage_options`.

## Development

```bash
composer install
composer lint        # phpcs --standard=phpcs.xml.dist
```

## License

GPL-2.0-or-later. Bundled ReplayWeb.page is licensed under AGPL-3.0 by
Webrecorder Software.
