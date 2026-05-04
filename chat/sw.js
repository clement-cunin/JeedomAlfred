'use strict';

// Increment CACHE_VERSION on each deployment to invalidate stale caches.
var CACHE_VERSION = 'v1';
var SHELL_CACHE   = 'alfred-shell-' + CACHE_VERSION;
var CDN_CACHE     = 'alfred-cdn-'   + CACHE_VERSION;

// Static assets pre-cached on install (local, never change between sessions).
var PRECACHE_ASSETS = [
    './favicon.ico',
    './manifest.json',
    '../plugin_info/alfred_icon.png',
    '../plugin_info/alfred_icon_128.png',
    '../plugin_info/alfred_icon_256.png',
    '../plugin_info/alfred_icon_512.png',
    '../plugin_info/alfred_icon_1024.png',
    '../plugin_info/alfred_icon_fullsize.png'
];

// URL substrings that must always go to the network (Jeedom API / SSE stream).
var API_PATTERNS = [
    '/core/ajax/alfred.ajax.php',
    '/api/chat.php',
    '/api/upload.php',
    '/api/share.php'
];

// ── Install: pre-cache app-shell statics ─────────────────────────────────────
self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(SHELL_CACHE)
            .then(function (cache) { return cache.addAll(PRECACHE_ASSETS); })
            .then(function () { return self.skipWaiting(); })
    );
});

// ── Activate: delete caches from previous versions ───────────────────────────
self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys()
            .then(function (keys) {
                return Promise.all(
                    keys
                        .filter(function (key) {
                            // Remove any alfred-* cache that is not the current version.
                            return key.startsWith('alfred-') &&
                                   key !== SHELL_CACHE &&
                                   key !== CDN_CACHE;
                        })
                        .map(function (key) { return caches.delete(key); })
                );
            })
            .then(function () { return self.clients.claim(); })
    );
});

// ── Fetch ─────────────────────────────────────────────────────────────────────
self.addEventListener('fetch', function (e) {
    var url = e.request.url;

    // 1. Network-only — Jeedom API calls and SSE stream must never be stale.
    if (API_PATTERNS.some(function (p) { return url.indexOf(p) !== -1; })) {
        e.respondWith(fetch(e.request));
        return;
    }

    // 2. Cache-first — CDN resources (jQuery, FontAwesome).
    //    Cached on first use; served from cache on subsequent loads.
    if (url.indexOf('cdn.jsdelivr.net') !== -1) {
        e.respondWith(
            caches.open(CDN_CACHE).then(function (cache) {
                return cache.match(e.request).then(function (cached) {
                    if (cached) return cached;
                    return fetch(e.request).then(function (response) {
                        if (response.ok) cache.put(e.request, response.clone());
                        return response;
                    });
                });
            })
        );
        return;
    }

    // 3. Network-first — PHP pages (index.php, manifest.php).
    //    Always attempt a fresh load; fall back to cache when offline.
    if (url.indexOf('.php') !== -1) {
        e.respondWith(
            fetch(e.request)
                .then(function (response) {
                    if (response.ok) {
                        caches.open(SHELL_CACHE).then(function (cache) {
                            cache.put(e.request, response.clone());
                        });
                    }
                    return response;
                })
                .catch(function () {
                    return caches.match(e.request);
                })
        );
        return;
    }

    // 4. Cache-first — everything else (icons, favicon, manifest.json, etc.).
    //    Pre-cached assets are served immediately; others are cached on first fetch.
    e.respondWith(
        caches.match(e.request).then(function (cached) {
            if (cached) return cached;
            return fetch(e.request).then(function (response) {
                if (response.ok) {
                    caches.open(SHELL_CACHE).then(function (cache) {
                        cache.put(e.request, response.clone());
                    });
                }
                return response;
            });
        })
    );
});
