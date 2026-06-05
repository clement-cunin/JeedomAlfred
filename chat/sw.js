'use strict';

// Increment CACHE_VERSION on each deployment to invalidate stale caches.
var CACHE_VERSION = 'v2';
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
    '/api/share.php',
    '/api/file.php',
    '/api/push.php'
];

// ── Install: pre-cache app-shell statics ─────────────────────────────────────
self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(SHELL_CACHE)
            .then(function (cache) {
                // Cache each asset individually so one failure doesn't abort the install.
                return Promise.all(
                    PRECACHE_ASSETS.map(function (url) {
                        return cache.add(url).catch(function () { /* ignore */ });
                    })
                );
            })
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
                        // Clone SYNCHRONOUSLY before any async operation —
                        // once caches.open() resolves the body may already be consumed.
                        var clone = response.clone();
                        caches.open(SHELL_CACHE).then(function (cache) {
                            cache.put(e.request, clone);
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
                    var clone = response.clone(); // clone synchronously
                    caches.open(SHELL_CACHE).then(function (cache) {
                        cache.put(e.request, clone);
                    });
                }
                return response;
            });
        })
    );
});

// ── Push token — sent by the main page on every load ─────────────────────────
var _alfredPushToken = null;

self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'ALFRED_PUSH_TOKEN') {
        _alfredPushToken = event.data.token || null;
    }
});

// ── Push event — SW receives push, fetches pending notifications from server ──
self.addEventListener('push', function (event) {
    // Derive API URL from SW scope: .../plugins/alfred/chat/ → .../plugins/alfred/api/push.php
    var apiBase = self.registration.scope.replace(/\/chat\/?$/, '/');
    var apiUrl  = apiBase + 'api/push.php';
    var iconUrl = apiBase + 'plugin_info/alfred_icon.png';
    var chatUrl = new URL('index.php', self.registration.scope).href;

    function showGeneric() {
        return self.registration.showNotification('Alfred', {
            body:  'Vous avez un nouveau message.',
            icon:  iconUrl,
            badge: iconUrl,
            data:  { url: chatUrl },
            tag:   'alfred-push',
        });
    }

    event.waitUntil(
        (function () {
            if (!_alfredPushToken) {
                return showGeneric();
            }

            return fetch(apiUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'pending', token: _alfredPushToken }),
            })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (notifications) {
                if (!Array.isArray(notifications) || notifications.length === 0) {
                    return showGeneric();
                }

                // Mark as read immediately so they don't re-appear
                fetch(apiUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ action: 'read', token: _alfredPushToken }),
                });

                return Promise.all(notifications.map(function (n) {
                    return self.registration.showNotification(n.title || 'Alfred', {
                        body:  n.body  || '',
                        icon:  iconUrl,
                        badge: iconUrl,
                        data:  {
                            url:        chatUrl,
                            session_id: n.session_id || '',
                        },
                        tag: 'alfred-push-' + (n.id || Date.now()),
                    });
                }));
            })
            .catch(function () { return showGeneric(); });
        })()
    );
});

// ── Notification click — open the linked Alfred conversation ─────────────────
self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var data    = event.notification.data || {};
    var baseUrl = data.url || new URL('index.php', self.registration.scope).href;
    var url     = data.session_id
        ? baseUrl + '?session=' + encodeURIComponent(data.session_id)
        : baseUrl;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var c = clientList[i];
                if (c.url.indexOf('/alfred/chat/') !== -1 && 'focus' in c) {
                    return c.focus();
                }
            }
            return clients.openWindow(url);
        })
    );
});
