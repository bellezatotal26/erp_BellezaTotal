/**
 * Seri POS Offline — cola durable, catálogo IndexedDB, sync por lotes.
 * API pública: window.SeriPosOffline
 */
(function (global) {
    'use strict';

    var DB_NAME = 'seri_pos_offline';
    var DB_VERSION = 1;
    var QUEUE_KEY = 'seri_pos_pending_sales';
    var CONFLICT_KEY = 'seri_pos_conflict_sales';
    var META_KEY = 'seri_pos_offline_meta';
    var syncInFlight = null;

    function uuid() {
        if (global.crypto && typeof global.crypto.randomUUID === 'function') {
            return global.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function isOnline() {
        return global.navigator.onLine !== false;
    }

    function isNetworkFailure(err, response) {
        if (!isOnline()) return true;
        if (err) {
            var msg = String(err.message || err.name || '');
            if (err.name === 'TypeError') return true;
            if (/failed to fetch|networkerror|load failed|network request failed/i.test(msg)) return true;
        }
        if (response && (response.status === 0 || response.type === 'opaque')) return true;
        return false;
    }

    function safeParse(raw, fallback) {
        try {
            var v = raw ? JSON.parse(raw) : fallback;
            return v == null ? fallback : v;
        } catch (e) {
            return fallback;
        }
    }

    function loadQueue() {
        var arr = safeParse(localStorage.getItem(QUEUE_KEY), []);
        return Array.isArray(arr) ? arr : [];
    }

    function loadConflicts() {
        var arr = safeParse(localStorage.getItem(CONFLICT_KEY), []);
        return Array.isArray(arr) ? arr : [];
    }

    function persist(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value || []));
            return true;
        } catch (e) {
            console.warn('localStorage full/blocked', key, e);
            return false;
        }
    }

    function saveQueue(arr) {
        persist(QUEUE_KEY, arr || []);
        emitQueueChange();
    }

    function saveConflicts(arr) {
        persist(CONFLICT_KEY, arr || []);
        emitQueueChange();
    }

    function emitQueueChange() {
        var n = loadQueue().length;
        var c = loadConflicts().length;
        try {
            global.dispatchEvent(new CustomEvent('seri:pos-queue', {
                detail: { count: n, conflicts: c }
            }));
        } catch (e) { /* ignore */ }
        if (global.SeriPosOffline && typeof global.SeriPosOffline._onQueueChange === 'function') {
            global.SeriPosOffline._onQueueChange(n, c);
        }
    }

    /** Reconstruye items desde claves planas items[0][product_id]. */
    function rebuildItems(obj) {
        if (!obj || typeof obj !== 'object') return [];
        if (Array.isArray(obj.items)) {
            return obj.items.filter(function (it) {
                return it && Number(it.product_id) > 0 && Number(it.quantity) > 0;
            }).map(function (it) {
                return {
                    product_id: Number(it.product_id),
                    quantity: Number(it.quantity),
                    unit_price: it.unit_price != null ? Number(it.unit_price) : 0,
                    product_name: String(it.product_name || ''),
                    subtotal: it.subtotal != null ? Number(it.subtotal) : undefined,
                    stock_oversell_reason: String(it.stock_oversell_reason || ''),
                    stock_oversell_note: String(it.stock_oversell_note || '')
                };
            });
        }
        var items = [];
        Object.keys(obj).forEach(function (key) {
            var m = key.match(/^items\[(\d+)\]\[(\w+)\]$/);
            if (!m) return;
            var idx = parseInt(m[1], 10);
            if (!items[idx]) items[idx] = {};
            items[idx][m[2]] = obj[key];
        });
        return items.filter(Boolean).map(function (it) {
            return {
                product_id: Number(it.product_id) || 0,
                quantity: Number(it.quantity) || 0,
                unit_price: Number(it.unit_price) || 0,
                product_name: String(it.product_name || ''),
                subtotal: it.subtotal != null ? Number(it.subtotal) : undefined,
                stock_oversell_reason: String(it.stock_oversell_reason || ''),
                stock_oversell_note: String(it.stock_oversell_note || '')
            };
        }).filter(function (it) {
            return it.product_id > 0 && it.quantity > 0;
        });
    }

    function rebuildPaymentSplits(obj) {
        if (!obj || typeof obj !== 'object') return [];
        if (Array.isArray(obj.payment_splits)) {
            return obj.payment_splits.filter(function (s) {
                return s && String(s.method || '') !== '' && Number(s.amount) > 0;
            }).map(function (s) {
                return {
                    method: String(s.method || ''),
                    amount: Math.round(Number(s.amount) * 100) / 100
                };
            });
        }
        var rows = [];
        Object.keys(obj).forEach(function (key) {
            var m = key.match(/^payment_splits\[(\d+)\]\[(\w+)\]$/);
            if (!m) return;
            var idx = parseInt(m[1], 10);
            if (!rows[idx]) rows[idx] = {};
            rows[idx][m[2]] = obj[key];
        });
        return rows.filter(Boolean).map(function (s) {
            return {
                method: String(s.method || s.payment_method || ''),
                amount: Math.round(Number(s.amount || 0) * 100) / 100
            };
        }).filter(function (s) {
            return s.method && s.amount > 0;
        });
    }

    /** Payload canónico para cola / batch / conflictos. */
    function normalizePayload(raw) {
        var src = raw || {};
        var items = rebuildItems(src);
        var splits = rebuildPaymentSplits(src);
        return {
            client_sale_uuid: String(src.client_sale_uuid || ''),
            customer_id: src.customer_id || '',
            payment_method: String(src.payment_method || 'cash'),
            payment_type: String(src.payment_type || 'full'),
            document_type: String(src.document_type || 'invoice'),
            payment_terms: String(src.payment_terms || 'cash'),
            sale_date: String(src.sale_date || ''),
            discount_percent: src.discount_percent != null ? String(src.discount_percent) : '0',
            delivery_fee: src.delivery_fee != null ? String(src.delivery_fee) : '0',
            delivery_company_fee: src.delivery_company_fee != null ? String(src.delivery_company_fee) : '0',
            delivery_total: src.delivery_total != null ? String(src.delivery_total) : '0',
            sale_channel: String(src.sale_channel || 'retail'),
            initial_payment: src.initial_payment != null ? String(src.initial_payment) : '0',
            notes: String(src.notes || ''),
            cash_received: src.cash_received != null ? String(src.cash_received) : '',
            cash_change: src.cash_change != null ? String(src.cash_change) : '',
            offline_sync: '1',
            items: items,
            payment_splits: splits
        };
    }

    function formDataToObject(fd) {
        var obj = {};
        fd.forEach(function (value, key) {
            if (Object.prototype.hasOwnProperty.call(obj, key)) {
                if (!Array.isArray(obj[key])) obj[key] = [obj[key]];
                obj[key].push(value);
            } else {
                obj[key] = value;
            }
        });
        return obj;
    }

    /** Expande items[] / payment_splits[] a claves planas para FormData. */
    function objectToFormData(obj) {
        var fd = new FormData();
        var src = obj || {};
        Object.keys(src).forEach(function (key) {
            if (key === 'items' || key === 'payment_splits') return;
            var val = src[key];
            if (val === undefined || val === null || val === '') return;
            if (Array.isArray(val)) {
                val.forEach(function (v) { fd.append(key, v); });
            } else {
                fd.append(key, val);
            }
        });
        (src.items || []).forEach(function (it, i) {
            if (!it) return;
            fd.append('items[' + i + '][product_id]', String(it.product_id));
            fd.append('items[' + i + '][quantity]', String(it.quantity));
            if (it.unit_price != null) fd.append('items[' + i + '][unit_price]', String(it.unit_price));
            if (it.product_name) fd.append('items[' + i + '][product_name]', String(it.product_name));
            if (it.subtotal != null) fd.append('items[' + i + '][subtotal]', String(it.subtotal));
            if (it.stock_oversell_reason) {
                fd.append('items[' + i + '][stock_oversell_reason]', String(it.stock_oversell_reason));
                fd.append('items[' + i + '][stock_oversell_note]', String(it.stock_oversell_note || ''));
            }
        });
        (src.payment_splits || []).forEach(function (s, i) {
            if (!s || !s.method) return;
            fd.append('payment_splits[' + i + '][method]', String(s.method));
            fd.append('payment_splits[' + i + '][amount]', String(s.amount));
        });
        return fd;
    }

    function upsertConflict(entry, result) {
        var list = loadConflicts();
        var id = entry.client_sale_uuid || (entry.payload && entry.payload.client_sale_uuid) || '';
        var next = {
            id: entry.id || ('c_' + id),
            client_sale_uuid: id,
            at: entry.at || new Date().toISOString(),
            payload: normalizePayload(entry.payload || {}),
            message: (result && result.message) || entry.last_error || 'Conflicto al sincronizar',
            code: (result && result.code) || 'conflict',
            stock_conflicts: (result && result.stock_conflicts) || [],
            updated_at: new Date().toISOString()
        };
        var idx = list.findIndex(function (c) { return c.client_sale_uuid === id; });
        if (idx >= 0) list[idx] = next;
        else list.push(next);
        saveConflicts(list);
    }

    function removeConflict(id) {
        if (!id) return;
        saveConflicts(loadConflicts().filter(function (c) {
            return c.client_sale_uuid !== id;
        }));
    }

    function discardConflict(id) {
        removeConflict(id);
    }

    function requeueConflict(id) {
        var list = loadConflicts();
        var hit = null;
        var rest = [];
        list.forEach(function (c) {
            if (c.client_sale_uuid === id) hit = c;
            else rest.push(c);
        });
        saveConflicts(rest);
        if (hit && hit.payload) {
            enqueueSale(hit.payload, id);
            return true;
        }
        return false;
    }

    function adjustLocalStock(items, direction) {
        if (!items || !items.length) return Promise.resolve();
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction('products', 'readwrite');
                var store = tx.objectStore('products');
                var pending = items.length;
                items.forEach(function (it) {
                    var id = Number(it.product_id);
                    var qty = Number(it.quantity) || 0;
                    if (!id || qty <= 0) {
                        pending--;
                        return;
                    }
                    var req = store.get(id);
                    req.onsuccess = function () {
                        var row = req.result;
                        if (row) {
                            var next = Number(row.stock) || 0;
                            next = direction < 0 ? next - qty : next + qty;
                            row.stock = next;
                            store.put(row);
                        }
                        pending--;
                    };
                    req.onerror = function () { pending--; };
                });
                tx.oncomplete = function () { db.close(); resolve(); };
                tx.onerror = function () { db.close(); reject(tx.error); };
            });
        }).catch(function () { /* catálogo opcional */ });
    }

    function enqueueSale(payload, clientUuid) {
        var clean = normalizePayload(payload);
        var uuidVal = clientUuid || clean.client_sale_uuid || uuid();
        clean.client_sale_uuid = uuidVal;
        if (!/Offline/i.test(clean.notes)) {
            clean.notes = (clean.notes ? clean.notes + ' | ' : '') + 'Offline';
        }
        clean.offline_sync = '1';

        var q = loadQueue();
        var exists = q.some(function (e) {
            return e.client_sale_uuid === uuidVal;
        });
        if (exists) {
            emitQueueChange();
            return uuidVal;
        }
        q.push({
            id: 'p_' + uuidVal,
            client_sale_uuid: uuidVal,
            at: new Date().toISOString(),
            payload: clean,
            attempts: 0
        });
        saveQueue(q);
        // Stock local optimista: evita sobrevender mientras no hay red.
        adjustLocalStock(clean.items, -1);
        return uuidVal;
    }

    function openDb() {
        return new Promise(function (resolve, reject) {
            if (!global.indexedDB) {
                reject(new Error('IndexedDB no disponible'));
                return;
            }
            var req = global.indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function (ev) {
                var db = ev.target.result;
                if (!db.objectStoreNames.contains('products')) {
                    var store = db.createObjectStore('products', { keyPath: 'id' });
                    store.createIndex('code', 'code', { unique: false });
                    store.createIndex('name', 'name', { unique: false });
                }
                if (!db.objectStoreNames.contains('meta')) {
                    db.createObjectStore('meta', { keyPath: 'key' });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error || new Error('IDB open failed')); };
        });
    }

    function idbPutAll(products) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(['products', 'meta'], 'readwrite');
                var store = tx.objectStore('products');
                store.clear();
                (products || []).forEach(function (p) {
                    store.put({
                        id: Number(p.id),
                        name: String(p.name || ''),
                        code: String(p.code || ''),
                        sale_price: Number(p.sale_price) || 0,
                        stock: Number(p.stock) || 0,
                        product_type: String(p.product_type || 'product')
                    });
                });
                var syncedAt = (products && products.synced_at) || new Date().toISOString();
                tx.objectStore('meta').put({
                    key: 'catalog',
                    synced_at: syncedAt,
                    count: (products || []).length
                });
                tx.oncomplete = function () {
                    db.close();
                    resolve((products || []).length);
                };
                tx.onerror = function () {
                    db.close();
                    reject(tx.error);
                };
            });
        });
    }

    function idbSearch(query, limit) {
        limit = limit || 25;
        var q = String(query || '').trim().toLowerCase();
        if (!q) return Promise.resolve([]);

        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction('products', 'readonly');
                var req = tx.objectStore('products').getAll();
                req.onsuccess = function () {
                    var all = req.result || [];
                    var exact = [];
                    var starts = [];
                    var contains = [];
                    all.forEach(function (p) {
                        var code = String(p.code || '').toLowerCase();
                        var name = String(p.name || '').toLowerCase();
                        var idStr = String(p.id);
                        if (code === q || idStr === q) exact.push(p);
                        else if (code.indexOf(q) === 0 || name.indexOf(q) === 0) starts.push(p);
                        else if (code.indexOf(q) !== -1 || name.indexOf(q) !== -1) contains.push(p);
                    });
                    db.close();
                    resolve(exact.concat(starts, contains).slice(0, limit));
                };
                req.onerror = function () {
                    db.close();
                    reject(req.error);
                };
            });
        }).catch(function () { return []; });
    }

    function idbUpsertMany(products) {
        if (!products || !products.length) return Promise.resolve(0);
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction('products', 'readwrite');
                var store = tx.objectStore('products');
                products.forEach(function (p) {
                    store.put({
                        id: Number(p.id),
                        name: String(p.name || ''),
                        code: String(p.code || ''),
                        sale_price: Number(p.sale_price) || 0,
                        stock: Number(p.stock) || 0,
                        product_type: String(p.product_type || 'product')
                    });
                });
                tx.oncomplete = function () { db.close(); resolve(products.length); };
                tx.onerror = function () { db.close(); reject(tx.error); };
            });
        });
    }

    function getProduct(id) {
        id = Number(id);
        if (!id) return Promise.resolve(null);
        return openDb().then(function (db) {
            return new Promise(function (resolve) {
                var tx = db.transaction('products', 'readonly');
                var req = tx.objectStore('products').get(id);
                req.onsuccess = function () { db.close(); resolve(req.result || null); };
                req.onerror = function () { db.close(); resolve(null); };
            });
        }).catch(function () { return null; });
    }

    /** Valida stock local de un carrito antes de encolar offline. */
    function validateLocalStock(cartItems) {
        var list = cartItems || [];
        return Promise.all(list.map(function (it) {
            return getProduct(it.product_id).then(function (p) {
                var available = p ? Number(p.stock) : Number(it.stock);
                var isService = (p && p.product_type === 'service')
                    || !!(it.is_open_item)
                    || (p && (p.is_open_item === 1 || p.is_open_item === true || p.is_open_item === '1'))
                    || String((p && p.code) || it.code || '').toUpperCase() === 'COMODIN';
                var hasOversell = !!(it.stock_oversell_reason);
                if (!isService && available < Number(it.quantity) && !hasOversell) {
                    return {
                        ok: false,
                        product_id: it.product_id,
                        name: (p && p.name) || it.product_name || ('#' + it.product_id),
                        requested: Number(it.quantity),
                        available: available
                    };
                }
                return { ok: true };
            });
        })).then(function (rows) {
            var bad = rows.filter(function (r) { return !r.ok; });
            return { ok: bad.length === 0, conflicts: bad };
        });
    }

    function syncCatalog(url) {
        if (!url || !isOnline()) {
            return Promise.resolve({ success: false, offline: true });
        }
        return fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function (d) {
            if (!d || !d.success || !Array.isArray(d.products)) {
                throw new Error((d && d.message) || 'Catálogo inválido');
            }
            var list = d.products;
            list.synced_at = d.synced_at || new Date().toISOString();
            return idbPutAll(list).then(function (count) {
                persist(META_KEY, { synced_at: list.synced_at, count: count });
                return { success: true, count: count, synced_at: list.synced_at };
            });
        });
    }

    function payloadForBatch(entry) {
        return normalizePayload(Object.assign({}, entry.payload || {}, {
            client_sale_uuid: entry.client_sale_uuid || (entry.payload && entry.payload.client_sale_uuid) || ''
        }));
    }

    function syncPendingSales(saleUrl, csrf, syncUrl) {
        if (syncInFlight) return syncInFlight;
        if (!isOnline()) {
            emitQueueChange();
            return Promise.resolve({
                synced: 0,
                remaining: loadQueue().length,
                conflicts: loadConflicts(),
                summary: null
            });
        }
        var q = loadQueue();
        if (!q.length) {
            emitQueueChange();
            return Promise.resolve({
                synced: 0,
                remaining: 0,
                conflicts: loadConflicts(),
                summary: { ok: 0, conflict: 0, retry: 0, idempotent: 0 }
            });
        }

        syncInFlight = (syncUrl
            ? syncBatch(syncUrl, csrf, q)
            : syncOneByOne(saleUrl, csrf, q)
        ).then(function (res) {
            syncInFlight = null;
            return res;
        }).catch(function (err) {
            syncInFlight = null;
            throw err;
        });
        return syncInFlight;
    }

    function applyBatchResults(q, results) {
        var remaining = [];
        var newConflicts = [];
        var synced = 0;
        var seen = {};

        results.forEach(function (result) {
            var idx = typeof result.index === 'number' ? result.index : -1;
            var entry = idx >= 0 ? q[idx] : null;
            if (!entry) return;
            seen[idx] = true;
            var status = result.status || 'retry';
            var id = entry.client_sale_uuid || result.client_sale_uuid;

            if (status === 'ok' || status === 'idempotent') {
                synced++;
                removeConflict(id);
                return;
            }
            if (status === 'conflict') {
                upsertConflict(entry, result);
                // Devolver stock local: el servidor no aplicó la venta.
                adjustLocalStock((entry.payload && entry.payload.items) || [], +1);
                newConflicts.push({ entry: entry, message: result.message, result: result });
                return;
            }
            entry.attempts = (entry.attempts || 0) + 1;
            entry.last_error = result.message || 'Reintentar';
            remaining.push(entry);
        });

        q.forEach(function (entry, i) {
            if (!seen[i]) remaining.push(entry);
        });

        saveQueue(remaining);
        return { synced: synced, remaining: remaining.length, newConflicts: newConflicts };
    }

    function syncBatch(syncUrl, csrf, q) {
        var sales = q.map(payloadForBatch);
        return fetch(syncUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({ csrf_token: csrf || '', sales: sales })
        }).then(function (r) {
            return r.text().then(function (text) {
                var d = null;
                try { d = text ? JSON.parse(text) : null; } catch (e) { d = null; }
                return { ok: r.ok, status: r.status, data: d };
            });
        }).then(function (res) {
            if (!res.data || !Array.isArray(res.data.results)) {
                return {
                    synced: 0,
                    remaining: q.length,
                    conflicts: loadConflicts(),
                    summary: null,
                    message: (res.data && res.data.message) || 'Sync batch sin resultados'
                };
            }
            var applied = applyBatchResults(q, res.data.results);
            return {
                synced: applied.synced,
                remaining: applied.remaining,
                conflicts: loadConflicts(),
                newConflicts: applied.newConflicts,
                summary: res.data.summary || null,
                message: res.data.message || ''
            };
        }).catch(function (err) {
            if (!isNetworkFailure(err)) console.warn('syncBatch error', err);
            emitQueueChange();
            return {
                synced: 0,
                remaining: loadQueue().length,
                conflicts: loadConflicts(),
                summary: null,
                message: 'No se pudo contactar el sync batch'
            };
        });
    }

    function syncOneByOne(saleUrl, csrf, q) {
        if (!saleUrl) {
            return Promise.resolve({
                synced: 0,
                remaining: q.length,
                conflicts: loadConflicts(),
                summary: null
            });
        }
        var remaining = [];
        var newConflicts = [];
        var synced = 0;
        var chain = Promise.resolve();

        q.forEach(function (entry) {
            chain = chain.then(function () {
                var payload = payloadForBatch(entry);
                var fd = objectToFormData(payload);
                if (csrf) fd.set('csrf_token', csrf);
                return fetch(saleUrl, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    credentials: 'same-origin'
                }).then(function (r) {
                    return r.json().then(function (d) { return { data: d }; });
                }).then(function (res) {
                    var d = res.data || {};
                    var status = d.sync_status || (d.success ? (d.idempotent ? 'idempotent' : 'ok') : 'retry');
                    if (d.success || status === 'ok' || status === 'idempotent') {
                        synced++;
                        removeConflict(entry.client_sale_uuid);
                        return;
                    }
                    if (status === 'conflict' || (d.stock_conflicts && d.stock_conflicts.length)) {
                        upsertConflict(entry, {
                            message: d.message,
                            code: d.code || 'stock',
                            stock_conflicts: d.stock_conflicts || []
                        });
                        adjustLocalStock(payload.items || [], +1);
                        newConflicts.push({ entry: entry, message: d.message });
                        return;
                    }
                    entry.attempts = (entry.attempts || 0) + 1;
                    entry.last_error = d.message || 'Error';
                    remaining.push(entry);
                }).catch(function (err) {
                    entry.attempts = (entry.attempts || 0) + 1;
                    remaining.push(entry);
                    if (!isNetworkFailure(err)) {
                        entry.last_error = String(err && err.message ? err.message : err);
                    }
                });
            });
        });

        return chain.then(function () {
            saveQueue(remaining);
            return {
                synced: synced,
                remaining: remaining.length,
                conflicts: loadConflicts(),
                newConflicts: newConflicts,
                summary: null
            };
        });
    }

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return Promise.resolve(null);
        var meta = document.querySelector('meta[name="sw-url"]');
        var scopeMeta = document.querySelector('meta[name="sw-scope"]');
        var url = meta ? meta.getAttribute('content') : '';
        var scope = scopeMeta ? scopeMeta.getAttribute('content') : '/';
        if (!url) return Promise.resolve(null);
        return navigator.serviceWorker.register(url, { scope: scope }).catch(function (err) {
            console.warn('SW register failed', err);
            return null;
        });
    }

    global.SeriPosOffline = {
        uuid: uuid,
        isOnline: isOnline,
        isNetworkFailure: isNetworkFailure,
        loadQueue: loadQueue,
        saveQueue: saveQueue,
        loadConflicts: loadConflicts,
        saveConflicts: saveConflicts,
        discardConflict: discardConflict,
        requeueConflict: requeueConflict,
        enqueueSale: enqueueSale,
        normalizePayload: normalizePayload,
        syncPendingSales: syncPendingSales,
        syncCatalog: syncCatalog,
        searchLocal: idbSearch,
        getProduct: getProduct,
        validateLocalStock: validateLocalStock,
        upsertProducts: idbUpsertMany,
        formDataToObject: formDataToObject,
        objectToFormData: objectToFormData,
        registerServiceWorker: registerServiceWorker,
        QUEUE_KEY: QUEUE_KEY,
        CONFLICT_KEY: CONFLICT_KEY,
        _onQueueChange: null
    };
})(window);
