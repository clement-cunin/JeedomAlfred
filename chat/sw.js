'use strict';

self.addEventListener('install', function (e) {
    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(self.clients.claim());
});

// Network-only: the app requires a live Jeedom connection
self.addEventListener('fetch', function (e) {
    e.respondWith(fetch(e.request));
});
