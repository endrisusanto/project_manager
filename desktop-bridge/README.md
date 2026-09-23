# GBA Project Manager — Tauri Desktop Bridge Sync

Aplikasi desktop bridge ringan berbasis **Tauri v2 (Rust)** yang berjalan di **System Tray** (App Tray) untuk menyinkronkan database MySQL lokal (XAMPP intranet kantor) ke web Project Manager remote via reverse proxy Cloudflare (`https://gba.endrisusanto.my.id`).

---

## 🚀 Fitur Utama
1. **System Tray Integration**:
   - Berjalan di latar belakang (RAM < 30 MB).
   - Menu Tray: Status koneksi, *Sync Now*, Buka/Sembunyikan Dashboard, Keluar.
   - Tombol tutup jendela otomatis meminimalkan aplikasi ke System Tray (*minimize to tray*).
2. **Background Auto-Sync**:
   - Scheduler berkala (default tiap 30 detik) yang mendeteksi dan mengirim data tabel (`gba_tasks`, `projects`, `users`, `new_tasks`) secara otomatis.
3. **Aman & Ringan**:
   - Menggunakan autentikasi `Bearer <BRIDGE_SYNC_TOKEN>`.
   - Transaksi database atomic pada endpoint server (`api_sync_receiver.php`).
4. **Live Dashboard & Konfigurasi**:
   - Status live koneksi MySQL lokal & Remote Cloudflare.
   - Live activity terminal logs.
   - Counter jumlah task, project, dan user yang tersinkronisasi.

---

## 🛠️ Cara Menjalankan & Build

### Prasyarat:
- **Rust & Cargo**: [https://rustup.rs/](https://rustup.rs/)
- **Node.js**: v18+ (opsional jika menggunakan `cargo tauri`)
- **Tauri CLI**:
  ```bash
  cargo install tauri-cli --version "^2.0"
  ```

### 1. Menjalankan dalam Mode Development:
```bash
cd desktop-bridge
cargo tauri dev
```

### 2. Mem-build Binary Executable (`.exe` untuk Windows / Binary Linux):
```bash
cd desktop-bridge
cargo tauri build
```
File executable hasil build akan berada di:
- **Windows**: `desktop-bridge/src-tauri/target/release/project-manager-bridge.exe`
- **Linux**: `desktop-bridge/src-tauri/target/release/project-manager-bridge`

---

## ⚙️ Konfigurasi Default

Konfigurasi disimpan secara otomatis di:
- **Windows**: `%APPDATA%\gba-bridge-sync\bridge_config.json`
- **Linux**: `~/.config/gba-bridge-sync/bridge_config.json`

| Parameter | Default | Keterangan |
| :--- | :--- | :--- |
| `local_db_host` | `127.0.0.1` | Host MySQL XAMPP lokal |
| `local_db_port` | `3306` | Port MySQL XAMPP |
| `local_db_user` | `root` | Username MySQL |
| `local_db_password`| `""` | Password MySQL (kosong untuk default XAMPP) |
| `local_db_name` | `project_manager_db` | Nama database lokal |
| `remote_sync_url` | `https://gba.endrisusanto.my.id/api_sync_receiver.php` | Endpoint receiver remote |
| `sync_token` | `gba-bridge-sync-key-2026` | Token autentikasi sinkronisasi |
| `sync_interval_seconds` | `30` | Interval auto-sync background (detik) |
| `auto_sync_enabled` | `true` | Status auto-sync otomatis |

---

## 🔄 Pembaruan Software & Digital Signature (.sig)

Desktop bridge dilengkapi dengan sistem **Auto-Updater Tauri v2** yang terlindungi dengan enkripsi Ed25519 Minisign:
- **Public Key**: Dikonfigurasi dalam `tauri.conf.json` (`plugins.updater.pubkey`).
- **Private Key**: Disimpan dalam `.env.updater` (dilindungi gitignore).
- **Manifest Endpoint**: `desktop-bridge/updater/latest.json`.
- **Target Installer**: **NSIS** (`.exe` setup) & **MSI** (Windows Installer `x64`).

---

## 🚀 Skrip Auto Release, Commit, & Version Bump

Untuk merilis versi baru secara otomatis (bump version, digital signature, bundle MSI/NSIS, update `latest.json`, git commit, dan git tag):

```bash
cd desktop-bridge

# 1. Bump Patch (contoh: 1.0.0 -> 1.0.1)
npm run release:patch

# 2. Bump Minor (contoh: 1.0.0 -> 1.1.0)
npm run release:minor

# 3. Bump Major (contoh: 1.0.0 -> 2.0.0)
npm run release:major

# 4. Versi Kustom Spesifik
node scripts/release.js 1.2.5 --notes="Pembaruan perbaikan koneksi"

# 5. Simulasi / Dry Run (tanpa mengubah file)
npm run release:dry
```

### Yang Dijalankan oleh Skrip Release:
1. **Version Bump**: Memperbarui `package.json`, `Cargo.toml`, `tauri.conf.json`, dan `src/index.html`.
2. **Kunci Enkripsi**: Membaca `TAURI_SIGNING_PRIVATE_KEY` dari `.env.updater`.
3. **Build Installer**: Menjalankan build Tauri dengan target `nsis` & `msi` serta menghasilkan signature `.sig`.
4. **Update Manifest**: Mengisi hash signature `.sig` ke dalam `updater/latest.json`.
5. **Git Automation**: Melakukan `git add`, `git commit -m "chore(release): vX.Y.Z [auto-release]"`, dan membuat `git tag vX.Y.Z`.

---

## ⚡ GitHub Actions CI/CD Release

Workflow otomatis diatur pada `.github/workflows/release-desktop-bridge.yml`.

### Cara Kerja:
1. Saat tag `v*` dipush (`git push origin master --tags`), GitHub Action akan:
   - Menyiapkan runner Windows (`windows-latest`).
   - Mengompilasi binary executable Tauri, installer **NSIS** (`.exe`) dan **MSI** (`.msi`).
   - Menandatangani paket secara kriptografis menggunakan Minisign (`.sig`).
   - Mengunggah seluruh installer dan signature ke **GitHub Release**.

### Konfigurasi GitHub Repository Secrets:
Buka repository di GitHub $\rightarrow$ **Settings** $\rightarrow$ **Secrets and variables** $\rightarrow$ **Actions** $\rightarrow$ **New repository secret**:
1. `TAURI_SIGNING_PRIVATE_KEY`: Isi dengan private key dari file `.env.updater`.
2. `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`: Password private key (default: `password123`).


