/*
 * ReplayWeb.page service-worker entry point (pinned 2.4.6, vendored — no CDN).
 *
 * ReplayWeb.page registers its service worker from `replayBase + swName`
 * (here: assets/vendor/replaywebpage/replay/sw.js). The worker MUST be served
 * from the SAME origin (protocol + host + port) as the page that registers it.
 *
 * To keep a single authoritative copy of the ~1 MB wabac worker, this thin
 * entry point just imports the real, vendored worker that lives one directory
 * up. The relative path is resolved against this file's own URL, so it stays
 * same-origin. This mirrors ReplayWeb.page's documented `/replay/sw.js`
 * pattern, but pointing at a local file instead of a CDN URL.
 */
importScripts( "../sw.js" );
