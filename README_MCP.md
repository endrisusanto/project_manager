# 📖 Dokumentasi & Panduan Setup MCP Server (PHP/MySQL & n8n)

Dokumen ini berisi panduan lengkap instalasi, konfigurasi, integrasi, dan pengujian **Model Context Protocol (MCP) Server**, **n8n AI Chatbot Widget**, serta **Automated SMTP Email Reporting** untuk aplikasi **Project Manager** berbasis PHP Native & MySQL.

---

## 📐 Arsitektur Sistem Lengkap (dengan ChatGPT API)

```mermaid
graph TD
    subgraph "1. User Interface (PHP Web App)"
        A["User / Admin"] -->|"1. Ketik pertanyaan di Chat Widget"| B["Floating Chatbot Widget (header.php)"]
        B -->|"2. fetch() POST Payload JSON"| C["n8n Webhook Trigger (/webhook/chat)"]
    end

    subgraph "2. n8n AI Engine & Orchestration"
        C -->|"3. Teruskan Input Pesan"| D["n8n AI Agent Node"]
        
        subgraph "Modul AI & Data Source"
            E["🤖 OpenAI Chat Model Node<br/>(ChatGPT API: gpt-4o / gpt-3.5-turbo)"] <-->|"4. Reasoning & Penataan Teks"| D
            F["🗄️ MySQL Tool / MCP Server Node<br/>(Query data project_manager_db)"| <-->|"5. Mengambil Data Real-Time DB"| D
            G["🧠 Window Buffer Memory Node<br/>(Simpan Context Riwayat Chat)"| <--> D
        end

        D -->|"6. Hasil Respon AI"| C
    end

    C -->|"7. Return HTTP Response"| B
    B -->|"8. Tampilkan Balasan Chatbot"| A

    subgraph "3. Scheduled Task (Automated SMTP Email)"
        H["⏰ Schedule Trigger (Setiap 08:00 AM)"] --> I["🗄️ MySQL Node (Fetch Task Pending)"]
        I --> J["🤖 OpenAI Node (Rangkum Executive Summary)"]
        J --> K["📧 Send Email Node (SMTP)"]
        K --> L["📩 Laporan Diterima Tim / Admin"]
    end
```

---

## 📁 Struktur Direktori Project

```text
project_manager/
├── .vscode/
│   └── mcp.json                               # Konfigurasi otomatis untuk VS Code / Codex
├── mcp-server/
│   ├── index.js                               # Entrypoint MCP Server (Node.js ESM)
│   ├── test-cli.js                            # CLI Test Script
│   ├── package.json                           # Dependensi npm (@modelcontextprotocol/sdk, mysql2, dotenv)
│   ├── .env.example                           # Template variabel lingkungan
│   ├── .env                                   # File Konfigurasi lokal
│   └── n8n-workflows/
│       ├── n8n_chat_ai_agent.json             # Blueprint Workflow Chatbot n8n
│       ├── n8n_scheduled_smtp_report.json     # Blueprint Workflow Scheduled SMTP Email
│       ├── hermes_agent_mcp_integration.json  # Blueprint Hermes AI Agent & MCP Integration
│       └── daily_overall_status_mcp_report.json # Blueprint Daily Overall Status Report (MCP + AI)
├── header.php                                 # PHP Header dengan Floating Chatbot UI
└── README_MCP.md                              # Dokumen ini
```

---

## ⚙️ Persyaratan Sistem

- **Node.js**: v18.x atau lebih baru.
- **Database**: MySQL 5.7+ / MariaDB (XAMPP / Standalone).
- **n8n Engine**: Local / Cloud instance (`http://localhost:5678`).
- **OpenAI API Key**: (Opsional untuk ChatGPT LLM Node di n8n).

---

## 🚀 Panduan Setup & Instalasi

### 1. Setup di Linux / macOS

```bash
# 1. Masuk ke direktori mcp-server
cd /path/to/project_manager/mcp-server

# 2. Install dependensi npm
npm install

# 3. Salin konfigurasi .env
cp .env.example .env
```

### 2. Setup di XAMPP Windows

1. Pastikan **Apache & MySQL** aktif di XAMPP Control Panel.
2. Buka Command Prompt (CMD) atau PowerShell:
   ```cmd
   cd C:\xampp\htdocs\project_manager\mcp-server
   npm install
   copy .env.example .env
   ```

---

## 🔧 Konfigurasi `.env`

Edit file `mcp-server/.env` sesuai konfigurasi lokal Anda:

```env
# Database MySQL (Kredensial XAMPP / Linux)
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASSWORD=
DB_NAME=project_manager_db

# n8n Engine Setup
N8N_BASE_URL=http://localhost:5678
N8N_WEBHOOK_URL=http://localhost:5678/webhook/
N8N_API_KEY=
```

---

## 💬 Chatbot Widget UI (`header.php`)

Aplikasi PHP ini dilengkapi dengan **Floating Chatbot Widget** native di pojok kanan bawah halaman.

- **URL Endpoint Default**: `http://localhost:5678/webhook/chat`
- **Konfigurasi URL Kustom**:
  Dapat disesuaikan secara global di halaman PHP dengan mendefinisikan `window.HERMES_N8N_WEBHOOK`:
  ```html
  <script>
    window.HERMES_N8N_WEBHOOK = 'https://n8n.yourdomain.com/webhook/chat';
  </script>
  ```

---

## 📦 n8n Workflow Blueprints (Siap Import)

Tersedia 2 file blueprint JSON di folder [mcp-server/n8n-workflows/](file:///home/endri-pro/dev/App/project_manager/mcp-server/n8n-workflows/):

### 1. [n8n_chat_ai_agent.json](file:///home/endri-pro/dev/App/project_manager/mcp-server/n8n-workflows/n8n_chat_ai_agent.json)
* **Deskripsi**: Workflow AI Agent berbasis ChatGPT yang mendengarkan `POST /webhook/chat` dan menjawab pertanyaan user.
* **Cara Import**:
  1. Buka n8n UI (`http://localhost:5678`).
  2. Klik **Workflows** ➔ **Import from File**.
  3. Pilih file `n8n_chat_ai_agent.json`.
  4. Masukkan **OpenAI API Key** pada node *OpenAI Chat Model*.
  5. Aktifkan workflow (**Active: ON**).

### 2. [n8n_scheduled_smtp_report.json](file:///home/endri-pro/dev/App/project_manager/mcp-server/n8n-workflows/n8n_scheduled_smtp_report.json)
* **Deskripsi**: Workflow otomatis yang berjalan setiap jam 08:00 AM, menarik data task pending dari MySQL, merangkumnya, dan mengirimkan laporan HTML via SMTP Email.
* **Cara Import**:
  1. Buka n8n UI (`http://localhost:5678`).
  2. Klik **Workflows** ➔ **Import from File**.
  3. Pilih file `n8n_scheduled_smtp_report.json`.
  4. Masukkan kredensial **MySQL** & **SMTP Email** di n8n.
  5. Aktifkan workflow (**Active: ON**).

---

## 🔌 Integrasi ke Client MCP (Codex / Cursor / VS Code / Claude)

### A. Otomatis via Workspace VS Code / Codex
File [.vscode/mcp.json](file:///home/endri-pro/dev/App/project_manager/.vscode/mcp.json) sudah tersedia. IDE yang mendukung MCP akan langsung mendeteksi server ini saat workspace dibuka.

### B. Manual Config File Client

#### Linux / macOS:
```json
{
  "mcpServers": {
    "project-manager-n8n": {
      "command": "node",
      "args": [
        "/home/endri-pro/dev/App/project_manager/mcp-server/index.js"
      ],
      "env": {
        "DB_HOST": "localhost",
        "DB_NAME": "project_manager_db",
        "N8N_WEBHOOK_URL": "http://localhost:5678/webhook/"
      }
    }
  }
}
```

#### XAMPP Windows:
```json
{
  "mcpServers": {
    "project-manager-n8n": {
      "command": "node",
      "args": [
        "C:\\xampp\\htdocs\\project_manager\\mcp-server\\index.js"
      ],
      "env": {
        "DB_HOST": "localhost",
        "DB_NAME": "project_manager_db",
        "N8N_WEBHOOK_URL": "http://localhost:5678/webhook/"
      }
    }
  }
}
```

---

## 🛠️ Referensi Tool MCP

| Nama Tool | Deskripsi | Argumen Parameter |
| :--- | :--- | :--- |
| `db_list_projects` | Mengambil daftar project dari MySQL | `status` (string, opsional), `limit` (number, default: 20) |
| `db_list_tasks` | Mengambil daftar task dari `gba_tasks` | `progress_status` (string), `model_name` (string), `limit` (number) |
| `db_create_task` | Menambahkan task baru ke MySQL | `model_name`, `pic_email`, `test_plan_type`, `progress_status`, `deadline`, `notes` |
| `trigger_n8n_webhook` | Mentrigger Webhook n8n | `path_or_url` (string), `payload` (object JSON) |
| `call_n8n_api` | Memanggil n8n REST API | `endpoint` (string), `method` ("GET" / "POST"), `body` (object JSON) |

---

## 🧪 3 Cara Menguji System

### 1. Pengujian CLI Terintegrasi (`npm test`)
```bash
cd mcp-server
npm test
```
*Menguji jabat tangan JSON-RPC `initialize` & `tools/list` via Stdio.*

### 2. Pengujian Visual via MCP Inspector (Web UI Browser)
```bash
cd mcp-server
npx @modelcontextprotocol/inspector node index.js
```
*Buka browser yang ditampilkan di terminal untuk mencoba semua tool secara interaktif.*

### 3. Pengujian Chatbot UI di Browser
Buka aplikasi PHP (misal `http://localhost/project_manager/index.php`), klik ikon **Chatbot di pojok kanan bawah**, lalu kirim pertanyaan.

---

## ❓ FAQ & Troubleshooting

1. **Error `ECONNREFUSED 127.0.0.1:3306`:**
   - Pastikan MySQL di XAMPP / Linux sudah dalam keadaan `Running`.
2. **Error `Gagal terhubung ke n8n Chat Webhook` di UI Chatbot:**
   - Pastikan n8n sudah berjalan di `http://localhost:5678` dan workflow chat dalam keadaan **Active (ON)**.
3. **Path Windows Error pada MCP Client:**
   - Gunakan double backslash (`\\`) pada file JSON konfigurasi Windows (misal: `C:\\xampp\\htdocs\\...`).
