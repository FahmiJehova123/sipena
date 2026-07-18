// sw.js
const CACHE_NAME = 'siakad-cache-v1';
const STATIC_ASSETS = [
  '/siakad/offline.html',   // halaman offline (buat dulu)
  '/siakad/manifest.json',
  'https://cdn.tailwindcss.com',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css',
  'https://unpkg.com/aos@2.3.1/dist/aos.css',
  'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css',
  'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
  'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2'
];

// Install event: cache aset statis (toleransi kegagalan)
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return Promise.allSettled(STATIC_ASSETS.map(asset => cache.add(asset)))
        .then(results => {
          const failed = results.filter(r => r.status === 'rejected');
          if (failed.length) console.warn('Gagal cache aset:', failed);
        });
    })
  );
  self.skipWaiting();
});

// Aktifkan dan hapus cache lama
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
      );
    })
  );
  self.clients.claim();
});

// Strategi fetch: network first untuk API dan halaman, cache first untuk aset statis
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  const request = event.request;

  // API dinamis: network first (dengan fallback offline)
  if (url.pathname.includes('/api/') || url.pathname.includes('/uploads/')) {
    event.respondWith(
      fetch(request)
        .then(response => {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
          return response;
        })
        .catch(() => caches.match(request))
    );
    return;
  }

  // Request navigasi (halaman HTML): network first, fallback offline.html
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .catch(() => caches.match('/siakad/offline.html'))
    );
    return;
  }

  // Aset statis lainnya: cache first
  event.respondWith(
    caches.match(request).then(response => {
      return response || fetch(request).then(fetchRes => {
        if (fetchRes && fetchRes.status === 200) {
          const clone = fetchRes.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
        }
        return fetchRes;
      });
    })
  );
});