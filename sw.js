const CACHE_NAME = 'learnova-cache-v2';
const LEGACY_DATABASE_NAME = 'QueensPaDB';
const DATABASE_NAME = 'LearnovaDB';
const STATIC_ASSETS = [
    '/teacher/dashboard.php',
    '/assets/images/logo.png',
    '/assets/images/default_logo.png',
    '/assets/images/default_avatar.png',
    'https://cdn.tailwindcss.com',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css',
    'https://code.jquery.com/jquery-3.6.0.min.js',
    'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        Promise.all([deleteOldCaches(), migrateLegacyDatabase()])
            .then(() => self.clients.claim())
    );
});

async function deleteOldCaches() {
    const cacheNames = await caches.keys();
    await Promise.all(
        cacheNames
            .filter(name => name !== CACHE_NAME)
            .map(name => caches.delete(name))
    );
}

// Preserve locally stored schedules and notifications when changing the app identity.
function migrateLegacyDatabase() {
    return new Promise(resolve => {
        const request = indexedDB.open(LEGACY_DATABASE_NAME);
        request.onerror = () => resolve();
        request.onsuccess = event => {
            const oldDb = event.target.result;
            const stores = ['schedules', 'notifications'].filter(name => oldDb.objectStoreNames.contains(name));
            if (stores.length === 0) {
                oldDb.close();
                resolve();
                return;
            }

            const readTransaction = oldDb.transaction(stores, 'readonly');
            const recordsByStore = {};
            let pending = stores.length;
            stores.forEach(name => {
                const readRequest = readTransaction.objectStore(name).getAll();
                readRequest.onsuccess = () => {
                    recordsByStore[name] = readRequest.result;
                    if (--pending === 0) {
                        oldDb.close();
                        writeMigratedRecords(recordsByStore).then(resolve).catch(resolve);
                    }
                };
                readRequest.onerror = () => {
                    oldDb.close();
                    resolve();
                };
            });
        };
    });
}

function writeMigratedRecords(recordsByStore) {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE_NAME, 1);
        request.onupgradeneeded = event => {
            const db = event.target.result;
            ['schedules', 'notifications'].forEach(name => {
                if (!db.objectStoreNames.contains(name)) {
                    db.createObjectStore(name, { keyPath: 'id' });
                }
            });
        };
        request.onerror = () => reject(request.error);
        request.onsuccess = event => {
            const db = event.target.result;
            const stores = Object.keys(recordsByStore);
            if (stores.length === 0) {
                db.close();
                resolve();
                return;
            }
            const transaction = db.transaction(stores, 'readwrite');
            stores.forEach(name => recordsByStore[name].forEach(record => transaction.objectStore(name).put(record)));
            transaction.oncomplete = () => { db.close(); resolve(); };
            transaction.onerror = () => { db.close(); reject(transaction.error); };
        };
    });
}

self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request).then(response =>
            response || fetch(event.request).catch(() => caches.match('/teacher/dashboard.php'))
        )
    );
});

self.addEventListener('message', event => {
    if (!event.data) return;
    if (event.data.type === 'UPDATE_SCHEDULES') {
        saveToIndexedDB('schedules', event.data.schedules || []);
    } else if (event.data.type === 'UPDATE_NOTIFICATIONS') {
        saveToIndexedDB('notifications', event.data.notifications || []);
    }
});

function initIndexedDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE_NAME, 1);
        request.onupgradeneeded = event => {
            const db = event.target.result;
            ['schedules', 'notifications'].forEach(name => {
                if (!db.objectStoreNames.contains(name)) {
                    db.createObjectStore(name, { keyPath: 'id' });
                }
            });
        };
        request.onsuccess = event => resolve(event.target.result);
        request.onerror = event => reject(event.target.error);
    });
}

function saveToIndexedDB(storeName, data) {
    initIndexedDB().then(db => {
        const transaction = db.transaction([storeName], 'readwrite');
        const store = transaction.objectStore(storeName);
        data.forEach(item => store.put(item));
        transaction.oncomplete = () => {
            db.close();
            if (storeName === 'schedules') checkSchedules();
        };
        transaction.onerror = () => db.close();
    }).catch(err => console.error('Learnova IndexedDB initialization error:', err));
}

function readFromIndexedDB(storeName) {
    return initIndexedDB().then(db => new Promise((resolve, reject) => {
        const transaction = db.transaction([storeName], 'readonly');
        const request = transaction.objectStore(storeName).getAll();
        request.onsuccess = () => { db.close(); resolve(request.result); };
        request.onerror = () => { db.close(); reject(request.error); };
    }));
}

function checkSchedules() {
    readFromIndexedDB('schedules').then(schedules => {
        const now = new Date();
        const currentDay = now.toLocaleString('en-US', { weekday: 'long' });
        const currentTime = now.getHours() * 60 + now.getMinutes();
        schedules.forEach(schedule => {
            if (schedule.day_of_week === currentDay) {
                const [hours, minutes] = schedule.start_time.split(':').map(Number);
                const timeDiff = hours * 60 + minutes - currentTime;
                if (timeDiff > 0 && timeDiff <= 5) {
                    showNotification(
                        `Class Reminder: ${schedule.class_name}`,
                        `Your ${schedule.subject_name} class with ${schedule.class_name} starts at ${schedule.start_time} in 5 minutes.`,
                        '/teacher/class_schedule.php'
                    );
                }
            }
        });
    }).catch(err => console.error('Error reading Learnova schedules:', err));
}

function showNotification(title, body, url) {
    self.registration.showNotification(title, {
        body,
        icon: '/assets/images/logo.png',
        badge: '/assets/images/logo.png',
        data: { url },
        tag: `class-reminder-${Date.now()}`,
        renotify: true,
        requireInteraction: true
    });
}

self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientsArr => {
            const url = event.notification.data.url;
            const client = clientsArr.find(candidate => candidate.url === url && 'focus' in candidate);
            if (client) return client.focus();
            if (clientsArr.length) return clientsArr[0].focus();
            return clients.openWindow(url);
        })
    );
});

setInterval(checkSchedules, 60000);

self.addEventListener('periodicsync', event => {
    if (event.tag === 'sync-notifications') {
        event.waitUntil(
            readFromIndexedDB('notifications').then(notifications => {
                notifications.forEach(notification => showNotification(
                    notification.title,
                    notification.message,
                    notification.link || '/teacher/notifications.php'
                ));
            }).catch(err => console.error('Error syncing Learnova notifications:', err))
        );
    }
});
