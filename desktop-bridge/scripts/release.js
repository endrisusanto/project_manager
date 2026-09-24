#!/usr/bin/env node
// ponytail: Automated Release Script for Tauri v2 Desktop Bridge
// Handles: Version bump, signing env injection, MSI/NSIS build, signature extraction, latest.json manifest update, git commit & tag.

import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const ROOT_DIR = path.resolve(__dirname, '..');
const REPO_ROOT = path.resolve(ROOT_DIR, '..');

// File paths
const PKG_JSON = path.join(ROOT_DIR, 'package.json');
const CARGO_TOML = path.join(ROOT_DIR, 'src-tauri', 'Cargo.toml');
const TAURI_CONF = path.join(ROOT_DIR, 'src-tauri', 'tauri.conf.json');
const HTML_FILE = path.join(ROOT_DIR, 'src', 'index.html');
const ENV_UPDATER = path.join(ROOT_DIR, '.env.updater');
const LATEST_JSON = path.join(ROOT_DIR, 'updater', 'latest.json');

// Helper: Run command
function run(cmd, env = {}, options = {}) {
    console.log(`\x1b[36m> ${cmd}\x1b[0m`);
    return execSync(cmd, {
        cwd: options.cwd || ROOT_DIR,
        stdio: options.silent ? 'pipe' : 'inherit',
        env: { ...process.env, ...env },
        encoding: 'utf-8'
    });
}

// Helper: Parse .env file
function loadEnvFile(filePath) {
    if (!fs.existsSync(filePath)) return {};
    const content = fs.readFileSync(filePath, 'utf-8');
    const env = {};
    content.split('\n').forEach(line => {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#')) return;
        const eqIdx = trimmed.indexOf('=');
        if (eqIdx > 0) {
            const key = trimmed.slice(0, eqIdx).trim();
            const val = trimmed.slice(eqIdx + 1).trim();
            env[key] = val;
        }
    });
    return env;
}

// Calculate bumped version
function getNextVersion(currentVersion, bumpType) {
    const parts = currentVersion.split('.').map(n => parseInt(n, 10));
    if (parts.length !== 3 || parts.some(isNaN)) {
        throw new Error(`Invalid semantic version format: ${currentVersion}`);
    }

    let [major, minor, patch] = parts;
    if (bumpType === 'major') {
        major++;
        minor = 0;
        patch = 0;
    } else if (bumpType === 'minor') {
        minor++;
        patch = 0;
    } else if (bumpType === 'patch') {
        patch++;
    } else if (/^\d+\.\d+\.\d+$/.test(bumpType)) {
        return bumpType;
    } else {
        throw new Error(`Unknown bump type or invalid version: "${bumpType}". Use patch, minor, major, or X.Y.Z`);
    }

    return `${major}.${minor}.${patch}`;
}

async function main() {
    console.log('\x1b[1m\x1b[34m====================================================\x1b[0m');
    console.log('\x1b[1m\x1b[34m   GBA Bridge Sync — Automated Release Pipeline     \x1b[0m');
    console.log('\x1b[1m\x1b[34m====================================================\x1b[0m\n');

    const args = process.argv.slice(2);
    const bumpArg = args.find(a => !a.startsWith('--')) || 'patch';
    const isDryRun = args.includes('--dry-run');
    const skipBuild = args.includes('--skip-build');
    const releaseNotes = args.find(a => a.startsWith('--notes='))?.replace('--notes=', '') || `Release update v`;

    // 1. Read current version
    const pkg = JSON.parse(fs.readFileSync(PKG_JSON, 'utf-8'));
    const oldVersion = pkg.version;
    const newVersion = getNextVersion(oldVersion, bumpArg);

    console.log(`📌 Version Bump: \x1b[33mv${oldVersion}\x1b[0m -> \x1b[32mv${newVersion}\x1b[0m`);

    // 2. Load signing keys
    const updaterEnv = loadEnvFile(ENV_UPDATER);
    const signingKey = process.env.TAURI_SIGNING_PRIVATE_KEY || updaterEnv.TAURI_SIGNING_PRIVATE_KEY;
    const signingPass = process.env.TAURI_SIGNING_PRIVATE_KEY_PASSWORD || updaterEnv.TAURI_SIGNING_PRIVATE_KEY_PASSWORD || 'password123';

    if (!signingKey) {
        console.warn('\x1b[33m[PERINGATAN] TAURI_SIGNING_PRIVATE_KEY tidak ditemukan di .env.updater atau environment.\x1b[0m');
    } else {
        console.log('🔑 Digital Signing Key (minisign): Terdeteksi');
    }

    if (isDryRun) {
        console.log('\n\x1b[35m[DRY-RUN] Melewati perubahan file fisik dan proses build.\x1b[0m');
        return;
    }

    // 3. Update version in files
    console.log('\n📝 Memperbarui versi di package.json, Cargo.toml, tauri.conf.json, index.html...');
    
    // package.json
    pkg.version = newVersion;
    fs.writeFileSync(PKG_JSON, JSON.stringify(pkg, null, 2) + '\n');

    // Cargo.toml
    let cargoToml = fs.readFileSync(CARGO_TOML, 'utf-8');
    cargoToml = cargoToml.replace(/version\s*=\s*"[^"]+"/, `version = "${newVersion}"`);
    fs.writeFileSync(CARGO_TOML, cargoToml);

    // tauri.conf.json
    const tauriConf = JSON.parse(fs.readFileSync(TAURI_CONF, 'utf-8'));
    tauriConf.version = newVersion;
    fs.writeFileSync(TAURI_CONF, JSON.stringify(tauriConf, null, 2) + '\n');

    // index.html
    if (fs.existsSync(HTML_FILE)) {
        let html = fs.readFileSync(HTML_FILE, 'utf-8');
        html = html.replace(/<strong id="app-version-label"[^>]*>v[^<]*<\/strong>/, `<strong id="app-version-label" style="color:#e0e7ff;">v${newVersion}</strong>`);
        fs.writeFileSync(HTML_FILE, html);
    }

    console.log('✅ Semua metadata versi berhasil diperbarui.');

    // 4. Build Tauri App with Signing (if not skipped)
    let generatedSignature = '';
    if (!skipBuild) {
        console.log('\n🚀 Memulai Build Tauri (NSIS / MSI dengan Signature .sig)...');
        try {
            const isWindows = process.platform === 'win32';
            const buildCmd = isWindows ? 'npx tauri build --bundles nsis,nsis.zip,wix' : 'npx tauri build';
            run(buildCmd, {
                TAURI_SIGNING_PRIVATE_KEY: signingKey || '',
                TAURI_SIGNING_PRIVATE_KEY_PASSWORD: signingPass
            });
        } catch (err) {
            console.warn('\x1b[33mCatatan: Build native MSI/NSIS Windows dari host Linux memerlukan target Windows atau dijalankan di Windows CI/local.\x1b[0m');
        }

        // Look for generated signatures in target directory
        const bundleDir = path.join(ROOT_DIR, 'src-tauri', 'target', 'release', 'bundle');
        if (fs.existsSync(bundleDir)) {
            const findSigs = (dir) => {
                let sigs = [];
                for (const item of fs.readdirSync(dir, { withFileTypes: true })) {
                    const full = path.join(dir, item.name);
                    if (item.isDirectory()) sigs.push(...findSigs(full));
                    else if (item.name.endsWith('.sig')) sigs.push(full);
                }
                return sigs;
            };

            const sigFiles = findSigs(bundleDir);
            if (sigFiles.length > 0) {
                generatedSignature = fs.readFileSync(sigFiles[0], 'utf-8').trim();
                console.log(`🔏 Signature berhasil diekstrak dari: ${path.basename(sigFiles[0])}`);
            }
        }
    }

    // 5. Update latest.json manifest for auto-updater
    console.log('\n📦 Memperbarui manifest updater/latest.json...');
    const manifest = {
        version: newVersion,
        notes: `${releaseNotes} ${newVersion}`,
        pub_date: new Date().toISOString(),
        platforms: {
            "windows-x86_64": {
                signature: generatedSignature || "",
                url: `https://github.com/endrisusanto/project_manager/releases/download/v${newVersion}/GBA-Bridge-Sync_${newVersion}_x64_en-US.msi`
            },
            "windows-x86_64-nsis": {
                signature: generatedSignature || "",
                url: `https://github.com/endrisusanto/project_manager/releases/download/v${newVersion}/GBA-Bridge-Sync_${newVersion}_x64-setup.exe`
            }
        }
    };
    fs.writeFileSync(LATEST_JSON, JSON.stringify(manifest, null, 2) + '\n');
    console.log('✅ updater/latest.json berhasil diperbarui.');

    // 6. Git Commit, Tag, and Push
    console.log('\n📦 Melakukan Git Commit & Tag...');
    try {
        run(`git add "${PKG_JSON}" "${CARGO_TOML}" "${TAURI_CONF}" "${HTML_FILE}" "${LATEST_JSON}"`, {}, { cwd: REPO_ROOT });
        run(`git commit -m "chore(release): v${newVersion} [auto-release]"`, {}, { cwd: REPO_ROOT });
        run(`git tag -a "v${newVersion}" -m "Release v${newVersion}"`, {}, { cwd: REPO_ROOT });
        console.log(`\x1b[32m🎉 Sukses! Tag v${newVersion} telah dibuat.\x1b[0m`);

        if (!process.argv.includes('--no-push')) {
            console.log('\n🚀 Mendorong commit & tag ke origin master (--tags)...');
            run(`git push origin master --tags`, {}, { cwd: REPO_ROOT });
            console.log(`\x1b[32m✅ Sukses! Release v${newVersion} berhasil dipush ke GitHub & trigger auto-build CI.\x1b[0m\n`);
        } else {
            console.log(`\nSilakan push commit dan tag ke remote repository:`);
            console.log(`  \x1b[36mgit push origin master --tags\x1b[0m\n`);
        }
    } catch (gitErr) {
        console.error('Git error: ' + gitErr.message);
    }
}

main().catch(err => {
    console.error('\x1b[31mTerjadi kesalahan:\x1b[0m', err);
    process.exit(1);
});
