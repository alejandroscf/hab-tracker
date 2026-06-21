<?php
// Shared in-memory (APCu) response cache for the API proxies, so multiple
// clients polling the same callsign/device share one upstream fetch.
//
// cached_fetch($key, $ttl, $fetch): returns the upstream response body. Serves a
// cached body while it is younger than $ttl; otherwise one request refreshes it
// (guarded by an atomic lock) while the others get the previous body. On an
// upstream failure the last good body is returned (stale-on-error). Falls back to
// a direct, uncached fetch if APCu is unavailable.
//
// The last operation's status is recorded for cache_send_headers(), which exposes
// it to the client (debug overlay) as X-Cache / X-Cache-Age / X-Cache-TTL.

function apcu_ready() {
    return function_exists('apcu_enabled') && apcu_enabled();
}

// Upstream cache TTL in seconds (global $ApiCacheTtl from settings.php; default 15).
function api_cache_ttl() {
    global $ApiCacheTtl;
    return (isset($ApiCacheTtl) && (int)$ApiCacheTtl > 0) ? (int)$ApiCacheTtl : 15;
}

// status: HIT (fresh) | MISS (fetched upstream) | STALE (served old copy) | OFF.
function cache_set_meta($status, $age, $ttl) {
    $GLOBALS['__cache_meta'] = ['status' => $status, 'age' => $age, 'ttl' => $ttl];
}
function cache_send_headers() {
    if (empty($GLOBALS['__cache_meta'])) return;
    $m = $GLOBALS['__cache_meta'];
    header('X-Cache: ' . $m['status']);
    if ($m['age'] !== null) { header('X-Cache-Age: ' . $m['age']); }
    if ($m['ttl'] !== null) { header('X-Cache-TTL: ' . $m['ttl']); }
}

function cached_fetch($key, $ttl, callable $fetch) {
    if (!apcu_ready()) { cache_set_meta('OFF', null, null); return $fetch(); }

    $entry = apcu_fetch($key, $ok);
    if ($ok && isset($entry['ts']) && (time() - $entry['ts']) < $ttl) {
        cache_set_meta('HIT', time() - $entry['ts'], $ttl);
        return $entry['body'];                       // fresh hit — no upstream call
    }

    // Stale or missing: one worker refreshes, the rest serve the previous body.
    if (apcu_add($key . ':lock', 1, 15)) {
        $body = $fetch();
        if ($body !== false && $body !== null && trim($body) !== '') {
            apcu_store($key, ['ts' => time(), 'body' => $body], 600);  // keep 10 min for stale-on-error
            apcu_delete($key . ':lock');
            cache_set_meta('MISS', 0, $ttl);
            return $body;
        }
        apcu_delete($key . ':lock');
        cache_set_meta($ok ? 'STALE' : 'MISS', $ok ? time() - $entry['ts'] : null, $ttl);
        return $ok ? $entry['body'] : null;          // stale-on-error
    }

    if ($ok) {                                       // another worker is refreshing
        cache_set_meta('STALE', time() - $entry['ts'], $ttl);
        return $entry['body'];
    }

    // Cold start: nothing cached and the lock is held — fetch directly this once.
    $body = $fetch();
    cache_set_meta('MISS', 0, $ttl);
    return ($body !== false && $body !== null && trim($body) !== '') ? $body : null;
}
