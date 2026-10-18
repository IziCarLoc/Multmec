// Service worker mínimo: só deixa o app instalável e mostra uma página amigável quando a internet cai
// numa troca de tela. NÃO guarda páginas, pedidos do Livewire, fotos nem envios em cache: tudo que a
// tela mostra vem sempre do servidor (dados de OS mudam a toda hora e são de várias pessoas).
const OFFLINE = '/offline.html';
const CACHE = 'multmec-offline-v1';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([OFFLINE, '/images/logo.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    // Só a navegação (abrir uma tela) ganha a página de "sem conexão"; o resto passa direto.
    if (event.request.mode === 'navigate' && event.request.method === 'GET') {
        event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE)));

        return;
    }

    // O logo da página de "sem conexão" (sem internet não haveria de onde buscar).
    if (new URL(event.request.url).pathname === '/images/logo.png') {
        event.respondWith(fetch(event.request).catch(() => caches.match('/images/logo.png')));
    }
});
