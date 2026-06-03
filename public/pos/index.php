<?php
/**
 * Cross-origin-isolation shim for the SM Restaurant Hub PWA shell.
 *
 * The production Apache vhost uses AllowOverride None, so public/pos/.htaccess
 * is ignored. expo-sqlite's web worker needs the top-level document served with
 * COOP + COEP so the page is cross-origin isolated (SharedArrayBuffer). Every
 * other asset is same-origin and therefore loads fine under COEP without its
 * own headers — only the document needs them.
 *
 * This file is the DirectoryIndex for /pos/: it emits the isolation headers and
 * then streams the built index.html. The service worker caches this response
 * (headers included), so cross-origin isolation survives offline launches too.
 */
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Embedder-Policy: require-corp');
header('Cross-Origin-Resource-Policy: same-origin');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

readfile(__DIR__ . '/index.html');
