=== Tainacan WACZ Player ===
Contributors: marcossigismundo
Tags: tainacan, web archive, wacz, warc, replayweb
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plays .wacz / .warc web archives attached to Tainacan items right on the public item page, with a locally bundled ReplayWeb.page viewer. No external CDN.

== Description ==

Tainacan WACZ Player renders web-archive files (.wacz, and optionally raw .warc)
attached to a Tainacan item directly on the public item page, in a new section
placed right below the Attachments box. Visitors browse the archived website
inside the page instead of downloading an opaque file.

This is a standalone plugin. It is NOT part of the DIP Importer — it only reads
the attachments an item already has.

**Why .wacz and not .warc?** A .wacz (Web Archive Collection Zipped) already
contains the WARC records internally, plus a CDXJ index and metadata that make
replay fast. ReplayWeb.page (Webrecorder) consumes .wacz natively. Converting a
.wacz back to .warc would only add I/O and lose the index, so this plugin plays
the .wacz as-is.

**No CDN.** The ReplayWeb.page player (ui.js + service worker) is bundled inside
the plugin, pinned to a specific version, and served from your own origin.

= Features =

* Automatic display below the Attachments box on every item page (toggle).
* One viewer per archive when an item has several .wacz / .warc attachments.
* Detects the snapshot entry-point URL from the archive's pages.jsonl.
* Optional "WACZ Player (inline)" Gutenberg block for FSE/block themes.
* Settings page integrated into the Tainacan admin menu.
* Graceful fallback (download link) when the browser cannot run the player.

== Installation ==

1. Requires an active Tainacan installation.
2. Upload the `tainacan-wacz-player` folder to `/wp-content/plugins/`.
3. Activate the plugin through the "Plugins" menu in WordPress.
4. Configure it under Tainacan menu > WACZ Player (optional; defaults are sane).
5. Open any item that has a .wacz attachment to see the viewer.

= Service Worker note =

ReplayWeb.page uses a Service Worker that must be served from the SAME origin as
the site. The plugin ships it under
`assets/vendor/replaywebpage/replay/sw.js`, which claims only its own directory
scope and works out of the box on Apache. A commented `.htaccess` is included
for hosts that need a broader `Service-Worker-Allowed` scope. The viewer also
requires a secure context (HTTPS or localhost).

== Frequently Asked Questions ==

= Does it modify or convert my files? =

No. It reads the existing attachment and plays it in place.

= My item shows a download fallback instead of the viewer. =

The browser is on plain HTTP (not HTTPS/localhost) or lacks Service Worker
support. Serve the site over HTTPS.

== Changelog ==

= 1.0.0 =
* Initial release.
