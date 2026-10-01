// Lokaler Testserver ohne installiertes PHP (PHP läuft als WebAssembly in Node.js).
// Start: npm install && npm start  → http://localhost:8123

import http from 'node:http';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

import path from 'node:path';
const root = path.resolve(process.argv[2] || '.');
const port = Number(process.argv[3] || 8123);
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
php.mkdir('/www');
await php.mount('/www', createNodeFsMountHandler(root));
const handler = new PHPRequestHandler({
  php, documentRoot: '/www/public', absoluteUrl: `http://localhost:${port}`, cookieStore: false,
});

let queue = Promise.resolve();
http.createServer((req, res) => {
  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', () => {
    queue = queue.then(async () => {
      try {
        const headers = {};
        for (const [k, v] of Object.entries(req.headers)) headers[k] = Array.isArray(v) ? v.join(', ') : v;
        const r = await handler.request({
          url: `http://localhost:${port}${req.url}`, method: req.method, headers,
          body: chunks.length ? new Uint8Array(Buffer.concat(chunks)) : undefined,
        });
        for (const [k, v] of Object.entries(r.headers)) res.setHeader(k, v);
        if (req.url.split('?')[0].endsWith('.webmanifest')) res.setHeader('content-type', 'application/manifest+json');
        res.statusCode = r.httpStatusCode;
        res.end(Buffer.from(r.bytes));
        console.log(req.method, req.url, r.httpStatusCode);
        if (r.errors) console.error(r.errors);
      } catch (e) {
        console.error(e); res.statusCode = 500; res.end(String(e));
      }
    });
  });
}).listen(port, () => console.log('listening', port));
