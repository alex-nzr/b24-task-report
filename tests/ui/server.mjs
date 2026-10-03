import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { resolve, extname, sep } from 'node:path';

const root = resolve('public/build');
const fixtures = resolve('.ai/.tmp/ui-fixtures');
const pages = { '/': 'index', '/login': 'login', '/invalid': 'invalid' };

createServer(async (request, response) => {
    const path = new URL(request.url, 'http://localhost').pathname;
    if (request.method !== 'GET') {
        response.writeHead(204);
        response.end();
        return;
    }
    let file;
    if (path.startsWith('/build/')) {
        file = resolve(root, '.' + path.substring(6));
        if (!file.startsWith(root + sep)) file = null;
    } else if (pages[path]) {
        file = resolve(fixtures, pages[path] + '.html');
    } else if (path === '/reports/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb') {
        file = resolve(fixtures, 'partial.html');
    } else if (/^\/reports\/[a-f0-9]{32}\/progress$/.test(path)) {
        const html = await readFile(resolve(fixtures, 'report.html'), 'utf8');
        const payload = JSON.parse(html.match(/<script[^>]*id="app-data"[^>]*>([\s\S]*?)<\/script>/)[1]).pageData;
        response.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'private, no-store' });
        response.end(JSON.stringify({ run: payload.run, rows: payload.rows, errors: payload.errors, total: payload.total, complete: true }));
        return;
    } else if (/^\/reports\/[a-f0-9]{32}$/.test(path)) {
        file = resolve(fixtures, 'report.html');
    }
    if (!file) {
        response.writeHead(404);
        response.end();
        return;
    }
    try {
        const contents = await readFile(file);
        const types = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript', '.css': 'text/css', '.woff2': 'font/woff2' };
        response.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream' });
        response.end(contents);
    } catch {
        response.writeHead(404);
        response.end();
    }
}).listen(8091, '127.0.0.1');
