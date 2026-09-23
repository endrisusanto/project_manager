#!/usr/bin/env node
// ponytail: Zero-dependency local web preview server using Node.js stdlib
import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SRC_DIR = path.resolve(__dirname, '..', 'src');
const PORT = process.env.PORT || 3000;

const MIME = {
    '.html': 'text/html',
    '.css': 'text/css',
    '.js': 'text/javascript',
    '.png': 'image/png',
    '.ico': 'image/x-icon',
    '.svg': 'image/svg+xml'
};

function startServer(port) {
    const server = http.createServer((req, res) => {
        let filePath = path.join(SRC_DIR, req.url === '/' ? 'index.html' : req.url);
        if (!fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) {
            filePath = path.join(SRC_DIR, 'index.html');
        }

        const ext = path.extname(filePath).toLowerCase();
        res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
        fs.createReadStream(filePath).pipe(res);
    });

    server.once('error', (err) => {
        if (err.code === 'EADDRINUSE') {
            startServer(port + 1);
        } else {
            console.error('Server error:', err);
        }
    });

    server.listen(port, () => {
        console.log(`\x1b[32m✔ Preview Desktop Bridge UI running at:\x1b[0m \x1b[36mhttp://localhost:${port}\x1b[0m`);
    });
}

startServer(parseInt(process.env.PORT || '3050', 10));
