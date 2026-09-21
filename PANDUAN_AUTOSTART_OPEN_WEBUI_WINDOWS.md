# ⚙️ Panduan Menjalankan Open WebUI Otomatis di Background / System Tray saat Startup Windows

Dokumen ini menjelaskan **3 Metode Terbaik** untuk membuat **Open WebUI (`open-webui serve`)** langsung berjalan secara otomatis di background (tanpa jendela CMD terbuka) setiap kali PC Windows dinyalakan (*Windows Startup*).

---

## 🛠️ 3 Metode Autostart Windows (Silap & Tanpa Jendela CMD)

| Metode | Keunggulan | Kesulitan |
| :--- | :--- | :--- |
| **Metode 1: VBScript + Folder Startup (Rekomendasi Paling Mudah)** | Tanpa software tambahan, 100% senyap di background | 🟢 Sangat Mudah |
| **Metode 2: NSSM (Windows Native Background Service)** | Berjalan sebagai Service Windows resmi (otomatis aktif sebelum login) | 🟡 Sedang |
| **Metode 3: PM2 for Windows** | Sangat bagus jika Anda juga mengelola service Node.js/MCP Server | 🟡 Sedang |

---

## 🔹 Metode 1: VBScript + Windows Startup Folder (Tanpa Install Software)

Metode ini membuat skrip pembuka yang **menyembunyikan jendela Command Prompt (CMD)** sehingga Open WebUI berjalan 100% di background.

### Langkah-langkah:
1. Tekan kombinasi tombol **`Win + R`** di keyboard Anda.
2. Ketik **`shell:startup`** lalu tekan **Enter**.
   *(Windows Explorer akan membuka folder `C:\Users\<NamaUser>\AppData\Roaming\Microsoft\Windows\Start Menu\Programs\Startup`)*.
3. Di dalam folder Startup tersebut, buat file baru dengan nama **`start_ai_services_silent.vbs`**.
4. Klik kanan file `start_ai_services_silent.vbs` ➔ **Edit dengan Notepad**, lalu paste kode berikut:

```vbs
Set WshShell = CreateObject("WScript.Shell")

' 1. Jalankan MCP Server HTTP Bridge di background (Port 3800)
WshShell.Run "cmd /c cd /d C:\xampp\htdocs\tkdn\mcp-server && node index.js", 0, False

' 2. Jalankan Open WebUI AI Portal di background (Port 8080)
WshShell.Run "cmd /c open-webui serve", 0, False

' 3. Jalankan n8n Automation Engine di background (Port 5678)
WshShell.Run "cmd /c n8n start", 0, False
```

> 💡 **Penjelasan Kode**: Angka `0` membuat ketiga perintah (Open WebUI, n8n, dan MCP Server) berjalan secara **100% tersembunyi (invisible)** di background Windows tanpa menampilkan jendela CMD sama sekali!

5. Simpan (`Ctrl + S`) dan tutup Notepad.
6. **Selesai!** Setiap kali Anda menyalakan PC/Laptop Windows:
   * **Open WebUI** siap diakses di `http://localhost:8080`
   * **n8n Automation Engine** siap diakses di `http://localhost:5678`
   * **MCP Server Bridge** aktif melayani di `http://localhost:3800`

---

## 🔹 Metode 2: NSSM (Windows Background Service Resmi)

Jika Anda ingin Open WebUI berjalan sebagai **Windows Service resmi** yang aktif otomatis bahkan sebelum Anda melakukan login user:

### Langkah-langkah:
1. Download **NSSM (Non-Sucking Service Manager)** dari [nssm.cc](https://nssm.cc/download) (atau via WinGet: `winget install NSSM.NSSM`).
2. Ekstrak `nssm.exe` (pilih versi 64-bit) ke folder `C:\nssm\`.
3. Buka **Command Prompt (CMD) sebagai Administrator**.
4. Ketik perintah berikut:
   ```cmd
   C:\nssm\nssm.exe install OpenWebUI
   ```
5. Jendela GUI NSSM akan terbuka:
   * **Path**: Cari lokasi file `open-webui.exe` (biasanya di `C:\Users\<NamaUser>\AppData\Local\Programs\Python\Python311\Scripts\open-webui.exe` atau cukup isi `open-webui`).
   * **Arguments**: `serve`
   * **Startup type**: Pilih `Automatic`.
6. Klik tombol **Install service**.
7. Jalankan service pertama kali dengan perintah:
   ```cmd
   nssm start OpenWebUI
   ```

---

## 🔹 Metode 3: PM2 for Windows

Jika Anda sudah terbiasa dengan Node.js & PM2 untuk mengelola MCP Server:

```cmd
# 1. Install PM2 dan PM2 Windows Startup secara global
npm install pm2 -g
npm install pm2-windows-startup -g
pm2-startup install

# 2. Daftarkan open-webui ke PM2
pm2 start "open-webui serve" --name "open-webui"
pm2 save
```

---

## 📌 Cara Memindahkan/Meminimalkan ke System Tray (Opsional)

Jika Anda ingin ada **Ikon Open WebUI di System Tray (Pojok Kanan Bawah Jam Windows)** agar bisa diklik untuk Minimize / Restore:

1. Download tool gratis ringan **[MinimizeToTray]** atau **[RBTray]** atau **[TrayIt!]**.
2. Dengan RBTray aktif: Cukup **klik kanan** pada tombol Minimize (`_`) di jendela mana saja, maka jendela tersebut akan otomatis sembunyi menjadi ikon kecil di System Tray dekat jam Windows! 📌

---

## 🔍 Cara Cek & Menghentikan Open WebUI yang Berjalan di Background

Jika Anda ingin mematikan/mereboot Open WebUI yang berjalan di background:

1. Buka **Task Manager** (`Ctrl + Shift + Esc`).
2. Cari proses **Python** atau **cmd.exe** / **OpenWebUI**.
3. Klik **End Task** (atau ketik perintah CMD: `taskkill /f /im python.exe`).
