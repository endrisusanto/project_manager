// ponytail: minimal MCP server with Stdio Transport & Streamable HTTP / SSE Bridge
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { SSEServerTransport } from "@modelcontextprotocol/sdk/server/sse.js";
import { CallToolRequestSchema, ListToolsRequestSchema } from "@modelcontextprotocol/sdk/types.js";
import mysql from "mysql2/promise";
import dotenv from "dotenv";
import nodemailer from "nodemailer";
import http from "http";
import https from "https";
import { exec } from "child_process";

import fs from "fs";
import path from "path";
import { WebSocketServer } from "ws";

dotenv.config();

// ponytail: fallback helper using Invoke-WebRequest (Windows) or curl (Linux) to auto-inherit Windows system corporate proxy credentials
const curlRequest = (url, options, body) => new Promise((resolve, reject) => {
  // ponytail: instead of escaping command line strings (which breaks on Windows with large payloads), write payload to a temp file
  const tempFile = path.join(process.cwd(), `.tmp_payload_${Date.now()}_${Math.random().toString(36).substring(7)}.json`);
  
  try {
    if (body) {
      fs.writeFileSync(tempFile, body, "utf8");
    }
  } catch (err) {
    reject(new Error("Gagal menulis file payload sementara: " + err.message));
    return;
  }

  const headersFlags = Object.entries(options.headers || {})
    .map(([k, v]) => `-H "${k}: ${v.replace(/"/g, '\\"')}"`)
    .join(" ");
  const dataParam = body ? `--data-binary "@${tempFile}"` : "";

  // ponytail: on Windows 10/11, native curl.exe is available and 10x-20x faster than spawning PowerShell
  const cmd = process.platform === "win32"
    ? `curl.exe -s -S -k -X ${options.method || "POST"} ${headersFlags} ${dataParam} "${url}"`
    : `curl -s -S -k -X ${options.method || "POST"} ${headersFlags} ${dataParam} "${url}"`;

  exec(cmd, { maxBuffer: 1024 * 1024 * 50 }, (error, stdout, stderr) => {
    // If curl.exe failed on Windows (e.g. corporate proxy requiring Windows NTLM/Kerberos session auth), fallback to PowerShell
    if (error && process.platform === "win32") {
      const headerItems = [];
      if (options.headers) {
        for (const [k, v] of Object.entries(options.headers)) {
          headerItems.push(`'${k}'='${v.replace(/'/g, "''")}'`);
        }
      }
      const headersArg = headerItems.length > 0 ? `@${'{' + headerItems.join(";") + '}'}` : "@{}";
      const infileArg = body ? `-InFile '${tempFile}'` : "";
      const psCmd = `powershell -NoProfile -Command "[Console]::OutputEncoding = [System.Text.Encoding]::UTF8; try { $r = Invoke-WebRequest -Uri '${url}' -Method '${options.method || "POST"}' -Headers ${headersArg} ${infileArg} -UseBasicParsing; Write-Output $r.Content } catch { if ($_.Exception.Response) { $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream(), [System.Text.Encoding]::UTF8); Write-Output $reader.ReadToEnd() } else { [Console]::Error.WriteLine($_.Exception.Message); exit 1 } }"`;

      exec(psCmd, { maxBuffer: 1024 * 1024 * 50 }, (psErr, psStdout, psStderr) => {
        try { if (fs.existsSync(tempFile)) fs.unlinkSync(tempFile); } catch (_) {}
        if (psErr) {
          reject(new Error(psStderr || psErr.message));
          return;
        }
        resolve({
          ok: true,
          status: 200,
          text: psStdout,
          json: () => {
            try { return JSON.parse(psStdout); } catch (e) { throw new Error(`Respon server bukan JSON yang valid. Output mentah: ${psStdout.trim()}`); }
          }
        });
      });
      return;
    }

    // ponytail: clean up temporary payload file immediately after request finishes
    try {
      if (fs.existsSync(tempFile)) {
        fs.unlinkSync(tempFile);
      }
    } catch (cleanupErr) {
      console.error("Cleanup temp file failed:", cleanupErr.message);
    }

    if (error) {
      reject(new Error(stderr || error.message));
      return;
    }
    resolve({
      ok: true,
      status: 200,
      text: stdout,
      json: () => {
        try {
          return JSON.parse(stdout);
        } catch (e) {
          throw new Error(`Respon server bukan JSON yang valid. Output mentah: ${stdout.trim()}`);
        }
      }
    });
  });
});

// ponytail: use native http/https (not undici/fetch) — Node 24 built-in fetch has TLS issues with Cloudflare tunnels
const httpsRequest = (url, options, body) => new Promise((resolve, reject) => {
  const u = new URL(url);
  const client = u.protocol === "http:" ? http : https;
  const req = client.request({ 
    hostname: u.hostname, 
    port: u.port || (u.protocol === "http:" ? 80 : 443), 
    path: u.pathname + u.search, 
    method: options.method || "POST", 
    headers: options.headers, 
    rejectUnauthorized: u.protocol === "https:" ? false : undefined,
    timeout: 300000 // 5 menit socket timeout
  }, (res) => {
    let data = "";
    res.on("data", c => data += c);
    res.on("end", () => resolve({ ok: res.statusCode >= 200 && res.statusCode < 300, status: res.statusCode, text: data, json: () => JSON.parse(data) }));
  });
  req.on("error", reject);
  req.on("timeout", () => {
    req.destroy();
    reject(new Error("Koneksi ke LM Studio timeout setelah 5 menit memproses data."));
  });
  if (body) req.write(body);
  req.end();
});

// MySQL Connection Pool
const pool = mysql.createPool({
  host: process.env.DB_HOST || "localhost",
  port: Number(process.env.DB_PORT) || 3306,
  user: process.env.DB_USER || "root",
  password: process.env.DB_PASSWORD !== undefined ? process.env.DB_PASSWORD : "",
  database: process.env.DB_NAME || "project_manager_db",
  waitForConnections: true,
  connectionLimit: 5,
});

const toolsDefinition = [
  {
    name: "db_list_projects",
    description: "List projects from MySQL database with optional status filter",
    inputSchema: {
      type: "object",
      properties: {
        status: { type: "string", description: "Filter by project status" },
        limit: { type: "number", description: "Limit number of rows (default 20)" }
      }
    }
  },
  {
    name: "db_list_tasks",
    description: "List GBA tasks from MySQL database with optional filters",
    inputSchema: {
      type: "object",
      properties: {
        progress_status: { type: "string", description: "Filter by progress status (e.g., Pending, In Progress, Done)" },
        model_name: { type: "string", description: "Filter by product/model name" },
        limit: { type: "number", description: "Limit number of rows (default 20)" }
      }
    }
  },
  {
    name: "db_create_task",
    description: "Insert a new GBA task into MySQL database",
    inputSchema: {
      type: "object",
      properties: {
        model_name: { type: "string" },
        ap: { type: "string" },
        cp: { type: "string" },
        csc: { type: "string" },
        pic_email: { type: "string" },
        test_plan_type: { type: "string" },
        progress_status: { type: "string" },
        deadline: { type: "string", description: "YYYY-MM-DD format" },
        notes: { type: "string" }
      },
      required: ["model_name", "pic_email", "test_plan_type", "progress_status"]
    }
  },
  {
    name: "trigger_n8n_webhook",
    description: "Trigger an n8n webhook with a custom payload",
    inputSchema: {
      type: "object",
      properties: {
        path_or_url: { type: "string", description: "n8n Webhook path (e.g. 'task-created') or full URL" },
        payload: { type: "object", description: "JSON data to send to n8n" }
      },
      required: ["path_or_url", "payload"]
    }
  },
  {
    name: "call_n8n_api",
    description: "Call n8n REST API endpoint (requires N8N_API_KEY in .env)",
    inputSchema: {
      type: "object",
      properties: {
        endpoint: { type: "string", description: "API path, e.g. '/api/v1/workflows'" },
        method: { type: "string", enum: ["GET", "POST"], default: "GET" },
        body: { type: "object", description: "JSON body for POST requests" }
      },
      required: ["endpoint"]
    }
  },
  {
    name: "db_delete_task",
    description: "Delete task(s) from MySQL database by ID, test_plan_type, or model_name (Admin Only)",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        test_plan_type: { type: "string" },
        model_name: { type: "string" }
      }
    }
  },
  {
    name: "send_email_smtp",
    description: "Send email notification or project report via SMTP server",
    inputSchema: {
      type: "object",
      properties: {
        to: { type: "string", description: "Recipient email address (e.g. endri.s@samsung.com)" },
        subject: { type: "string", description: "Email subject line" },
        html: { type: "string", description: "Email body in HTML or plain text" },
        cc: { type: "string", description: "Optional CC email address" }
      },
      required: ["to", "subject", "html"]
    }
  },
  {
    name: "get_daily_summary_report",
    description: "Generate executive daily summary report with pipeline breakdown, workload, overdue tasks, and critical deadlines",
    inputSchema: {
      type: "object",
      properties: {
        format: { type: "string", enum: ["markdown", "json"], default: "markdown", description: "Output format preference" }
      }
    }
  },
  {
    name: "get_weekly_summary_report",
    description: "Generate executive weekly summary report for Wednesday to Tuesday cycle covering all statuses",
    inputSchema: {
      type: "object",
      properties: {
        date: { type: "string", description: "Optional anchor date (YYYY-MM-DD), defaults to current week" },
        format: { type: "string", enum: ["markdown", "json"], default: "markdown", description: "Output format preference" }
      }
    }
  }
];

// Core Tool Execution Engine
async function executeTool(name, args = {}) {
  if (name === "db_list_projects") {
    let sql = "SELECT * FROM projects";
    const params = [];
    if (args.status) {
      sql += " WHERE status = ?";
      params.push(args.status);
    }
    sql += " ORDER BY id DESC LIMIT ?";
    params.push(Number(args.limit || 20));

    const [rows] = await pool.query(sql, params);
    return { success: true, data: rows };
  }

  if (name === "db_list_tasks") {
    let sql = "SELECT * FROM gba_tasks WHERE 1=1";
    const params = [];
    if (args.progress_status) {
      sql += " AND progress_status = ?";
      params.push(args.progress_status);
    }
    if (args.model_name) {
      sql += " AND model_name LIKE ?";
      params.push(`%${args.model_name}%`);
    }
    sql += " ORDER BY id DESC LIMIT ?";
    params.push(Number(args.limit || 20));

    const [rows] = await pool.query(sql, params);
    return { success: true, data: rows };
  }

  if (name === "db_create_task") {
    const sql = `INSERT INTO gba_tasks (model_name, ap, cp, csc, pic_email, test_plan_type, progress_status, deadline, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`;
    const [result] = await pool.query(sql, [
      args.model_name || "",
      args.ap || "",
      args.cp || "",
      args.csc || "",
      args.pic_email || "endri.s@samsung.com",
      args.test_plan_type || "Normal MR",
      args.progress_status || "Task Baru",
      args.deadline || null,
      args.notes || null
    ]);

    return { success: true, inserted_id: result.insertId };
  }

  if (name === "db_delete_task") {
    let sql = `DELETE FROM gba_tasks WHERE 1=1`;
    const params = [];
    if (args.id) {
      sql += ` AND id = ?`;
      params.push(args.id);
    }
    if (args.test_plan_type) {
      sql += ` AND LOWER(test_plan_type) = LOWER(?)`;
      params.push(args.test_plan_type);
    }
    if (args.model_name) {
      sql += ` AND LOWER(model_name) = LOWER(?)`;
      params.push(args.model_name);
    }

    const [result] = await pool.query(sql, params);
    return { success: true, affected_rows: result.affectedRows };
  }

  if (name === "trigger_n8n_webhook") {
    let url = args.path_or_url;
    if (!url.startsWith("http://") && !url.startsWith("https://")) {
      const baseUrl = process.env.N8N_WEBHOOK_URL || "http://localhost:5678/webhook/";
      url = new URL(args.path_or_url, baseUrl).toString();
    }

    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(args.payload)
    });

    const responseText = await res.text();
    let responseData;
    try { responseData = JSON.parse(responseText); } catch { responseData = responseText; }

    return { success: res.ok, status: res.status, data: responseData };
  }

  if (name === "call_n8n_api") {
    const baseUrl = process.env.N8N_BASE_URL || "http://localhost:5678";
    const url = `${baseUrl.replace(/\/$/, "")}/${args.endpoint.replace(/^\//, "")}`;
    const apiKey = process.env.N8N_API_KEY;

    const headers = { "Content-Type": "application/json" };
    if (apiKey) headers["X-N8N-API-KEY"] = apiKey;

    const options = { method: args.method || "GET", headers };
    if (args.method === "POST" && args.body) options.body = JSON.stringify(args.body);

    const res = await fetch(url, options);
    const data = await res.json();
    return { success: res.ok, status: res.status, data };
  }

  // ponytail: minimal SMTP sender using nodemailer
  if (name === "send_email_smtp") {
    const smtpHost = process.env.SMTP_HOST || "smtp.gmail.com";
    const smtpPort = Number(process.env.SMTP_PORT) || 587;
    const smtpUser = process.env.SMTP_USER;
    const smtpPass = process.env.SMTP_PASS;
    const smtpFrom = process.env.SMTP_FROM || `"Project Manager" <${smtpUser || "noreply@samsung.com"}>`;

    if (!smtpUser || !smtpPass) {
      throw new Error("SMTP_USER dan SMTP_PASS belum dikonfigurasi di file .env");
    }

    const transporter = nodemailer.createTransport({
      host: smtpHost,
      port: smtpPort,
      secure: process.env.SMTP_SECURE === "true" || smtpPort === 465,
      auth: {
        user: smtpUser,
        pass: smtpPass
      }
    });

    const info = await transporter.sendMail({
      from: smtpFrom,
      to: args.to,
      cc: args.cc || undefined,
      subject: args.subject,
      html: args.html
    });

    return { success: true, messageId: info.messageId, response: info.response };
  }

  // ponytail: Daily Summary Insight Report generator for MCP / AI Assistant
  if (name === "get_daily_summary_report") {
    const [rows] = await pool.query(`
      SELECT 
        t.id, 
        t.model_name, 
        t.ap, 
        t.cp, 
        t.csc, 
        t.pic_email, 
        t.test_plan_type, 
        t.progress_status, 
        t.deadline, 
        t.request_date,
        t.submission_date,
        t.is_urgent,
        u.username
      FROM gba_tasks t
      LEFT JOIN users u ON t.pic_email = u.email
      ORDER BY t.deadline ASC, t.id DESC
    `);

    const todayStr = new Date().toISOString().slice(0, 10);
    const today = new Date(todayStr);

    let totalActive = 0;
    let ongoingCount = 0;
    let submittedCount = 0;
    let pendingCount = 0;
    
    const picLoad = {};
    const testPlanDist = {};
    const lateTasks = [];
    const dueSoonTasks = [];

    rows.forEach(r => {
      const st = r.progress_status || "Task Baru";
      const isCompleted = ["Approved", "Passed", "Batal"].includes(st);
      
      let daysLeft = null;
      if (r.deadline) {
        const dl = new Date(r.deadline);
        const diffTime = dl.getTime() - today.getTime();
        daysLeft = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
      }

      const picName = r.username || (r.pic_email ? r.pic_email.split("@")[0] : "Unassigned");

      if (!isCompleted) {
        totalActive++;
        if (["Task Baru", "Downloaded", "Test Ongoing"].includes(st)) ongoingCount++;
        else if (st === "Submitted") submittedCount++;
        else if (["Pending Feedback", "Feedback Sent"].includes(st)) pendingCount++;

        const tp = r.test_plan_type || "Unassigned";
        testPlanDist[tp] = (testPlanDist[tp] || 0) + 1;
        picLoad[picName] = (picLoad[picName] || 0) + 1;

        if (daysLeft !== null) {
          if (daysLeft < 0) {
            lateTasks.push({ ...r, pic_name: picName, days_left: daysLeft, delay_days: Math.abs(daysLeft) });
          } else if (daysLeft <= 3) {
            dueSoonTasks.push({ ...r, pic_name: picName, days_left: daysLeft });
          }
        }
      }
    });

    // Top aggregates
    const topPic = Object.entries(picLoad).sort((a, b) => b[1] - a[1])[0] || ["-", 0];
    const topTestPlan = Object.entries(testPlanDist).sort((a, b) => b[1] - a[1])[0] || ["-", 0];

    // Markdown Narrative
    const dateFormatted = new Date().toLocaleDateString("id-ID", { day: "2-digit", month: "long", year: "numeric" });
    let md = `## 📊 Daily Summary Insight Report (${dateFormatted})\n\n`;
    md += `### 🚀 Pipeline Breakdown:\n`;
    md += `- **Total Task Aktif**: ${totalActive}\n`;
    md += `- **Test Ongoing**: ${ongoingCount}\n`;
    md += `- **Submitted**: ${submittedCount}\n`;
    md += `- **Pending Feedback**: ${pendingCount}\n`;
    md += `- **Task Late / Overdue**: ${lateTasks.length}\n`;
    md += `- **Jatuh Tempo (H0 - H3)**: ${dueSoonTasks.length}\n\n`;

    md += `### 💡 Key Executive Insights:\n`;
    md += `- **PIC Beban Tertinggi**: ${topPic[0]} (${topPic[1]} task aktif)\n`;
    md += `- **Test Plan Terbanyak**: ${topTestPlan[0]} (${topTestPlan[1]} task)\n\n`;

    if (lateTasks.length > 0) {
      md += `### 🚨 Peringatan Task Late (${lateTasks.length}):\n`;
      lateTasks.forEach(lt => {
        md += `- **[${lt.model_name}]** ${lt.test_plan_type} (PIC: ${lt.pic_name}) — Terlambat ${lt.delay_days} hari (Deadline: ${lt.deadline ? String(lt.deadline).slice(0, 10) : "-"})\n`;
      });
      md += `\n`;
    }

    if (dueSoonTasks.length > 0) {
      md += `### ⏳ Task Mendekati Deadline H0 - H3 (${dueSoonTasks.length}):\n`;
      dueSoonTasks.forEach(dt => {
        const text = dt.days_left === 0 ? "Hari ini (H-0)" : `Tersisa ${dt.days_left} hari`;
        md += `- **[${dt.model_name}]** ${dt.test_plan_type} (PIC: ${dt.pic_name}) — ${text} (Deadline: ${dt.deadline ? String(dt.deadline).slice(0, 10) : "-"})\n`;
      });
      md += `\n`;
    }

    if (args.format === "json") {
      return {
        success: true,
        summary: {
          date: todayStr,
          total_active: totalActive,
          ongoing: ongoingCount,
          submitted: submittedCount,
          pending_feedback: pendingCount,
          late_count: lateTasks.length,
          due_soon_count: dueSoonTasks.length,
          top_pic: { name: topPic[0], count: topPic[1] },
          top_test_plan: { name: topTestPlan[0], count: topTestPlan[1] }
        },
        late_tasks: lateTasks,
        due_soon_tasks: dueSoonTasks,
        markdown: md
      };
    }

    return {
      success: true,
      report_text: md,
      stats: {
        total_active: totalActive,
        ongoing: ongoingCount,
        submitted: submittedCount,
        pending_feedback: pendingCount,
        late: lateTasks.length,
        due_soon: dueSoonTasks.length
      }
    };
  }

  // ponytail: Weekly Summary Insight Report generator (Wednesday - Tuesday cycle)
  if (name === "get_weekly_summary_report") {
    const anchor = args.date ? new Date(args.date) : new Date();
    const dayOfWeek = anchor.getDay(); // 0 (Sun) - 6 (Sat). Wednesday = 3
    const diffToWed = dayOfWeek >= 3 ? dayOfWeek - 3 : dayOfWeek + 4;
    
    const startDt = new Date(anchor);
    startDt.setDate(startDt.getDate() - diffToWed);
    const endDt = new Date(startDt);
    endDt.setDate(endDt.getDate() + 6);

    const startStr = startDt.toISOString().slice(0, 10);
    const endStr = endDt.toISOString().slice(0, 10);

    const [rows] = await pool.query(`
      SELECT 
        t.id, 
        t.model_name, 
        t.ap, 
        t.pic_email, 
        t.test_plan_type, 
        t.progress_status, 
        t.deadline, 
        t.request_date,
        t.submission_date,
        t.approved_date,
        t.is_urgent,
        u.username
      FROM gba_tasks t
      LEFT JOIN users u ON t.pic_email = u.email
      WHERE (
        (t.request_date BETWEEN ? AND ?) OR
        (t.submission_date BETWEEN ? AND ?) OR
        (t.approved_date BETWEEN ? AND ?) OR
        (t.deadline BETWEEN ? AND ?) OR
        (DATE(t.updated_at) BETWEEN ? AND ?) OR
        (t.progress_status NOT IN ('Approved', 'Passed', 'Batal') AND (t.request_date <= ? OR t.request_date IS NULL))
      )
      ORDER BY t.deadline ASC, t.id DESC
    `, [startStr, endStr, startStr, endStr, startStr, endStr, startStr, endStr, startStr, endStr, endStr]);

    let approvedCount = 0;
    let submittedCount = 0;
    let ongoingCount = 0;
    let urgentCount = 0;
    const picLoad = {};
    const testPlanDist = {};

    rows.forEach(r => {
      const st = r.progress_status || 'Task Baru';
      if (['Approved', 'Passed'].includes(st)) approvedCount++;
      else if (st === 'Submitted') submittedCount++;
      else if (st === 'Test Ongoing') ongoingCount++;

      if (r.is_urgent) urgentCount++;

      const pic = r.username || (r.pic_email ? r.pic_email.split('@')[0] : 'Unassigned');
      picLoad[pic] = (picLoad[pic] || 0) + 1;

      const tp = r.test_plan_type || 'Unassigned';
      testPlanDist[tp] = (testPlanDist[tp] || 0) + 1;
    });

    const completionRate = rows.length > 0 ? ((approvedCount / rows.length) * 100).toFixed(1) : 0;
    const topPic = Object.entries(picLoad).sort((a, b) => b[1] - a[1])[0] || ["-", 0];

    let md = `## 📊 Weekly Summary Insight Report (${startStr} s/d ${endStr})\n\n`;
    md += `### 📈 Performance & Metrics (Siklus Rabu - Selasa):\n`;
    md += `- **Total Task Terdata**: ${rows.length}\n`;
    md += `- **Approved / Passed**: ${approvedCount} (${completionRate}%)\n`;
    md += `- **Submitted**: ${submittedCount}\n`;
    md += `- **Test Ongoing**: ${ongoingCount}\n`;
    md += `- **Task Urgent**: ${urgentCount}\n`;
    md += `- **PIC Beban Tertinggi**: ${topPic[0]} (${topPic[1]} task)\n\n`;

    return {
      success: true,
      period: { start: startStr, end: endStr },
      stats: {
        total: rows.length,
        approved: approvedCount,
        completion_rate: completionRate + '%',
        submitted: submittedCount,
        ongoing: ongoingCount,
        urgent: urgentCount,
        top_pic: { name: topPic[0], count: topPic[1] }
      },
      report_text: md
    };
  }

  throw new Error(`Tool not found: ${name}`);
}

// ponytail: factory function for lightweight MCP server instances (Stdio & SSE share same handlers)
const createMcpServer = () => {
  const srv = new Server(
    { name: "project-manager-mcp", version: "1.0.0" },
    { capabilities: { tools: {} } }
  );

  srv.setRequestHandler(ListToolsRequestSchema, async () => ({ tools: toolsDefinition }));

  srv.setRequestHandler(CallToolRequestSchema, async (request) => {
    const { name, arguments: args = {} } = request.params;
    try {
      const res = await executeTool(name, args);
      return { content: [{ type: "text", text: JSON.stringify(res.data !== undefined ? res.data : res, null, 2) }] };
    } catch (error) {
      return { isError: true, content: [{ type: "text", text: `Error executing ${name}: ${error.message}` }] };
    }
  });

  return srv;
};

// Map to track active MCP SSE client sessions
const sseTransports = new Map();

// 2. HTTP / Streamable SSE Server (Port 3800)
const HTTP_PORT = Number(process.env.MCP_HTTP_PORT) || 3800;

const httpServer = http.createServer(async (req, res) => {
  res.setHeader("Access-Control-Allow-Origin", "*");
  res.setHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization, Accept");

  if (req.method === "OPTIONS") {
    res.writeHead(204);
    res.end();
    return;
  }

  const url = new URL(req.url, `http://${req.headers.host}`);

  // GET / - Health & Discovery Info
  if (req.method === "GET" && (url.pathname === "/" || url.pathname === "/health")) {
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify({
      status: "ok",
      name: "project-manager-mcp",
      version: "1.0.0",
      endpoints: {
        streamableHttpMcp: "/mcp (or /sse)",
        messageEndpoint: "/message (or /mcp/message)",
        toolsList: "/api/mcp/tools",
        chat: "/api/mcp/chat"
      }
    }, null, 2));
    return;
  }

  // ponytail: Standard MCP Streamable HTTP (SSE) transport endpoint (/mcp, /sse, /api/mcp/sse)
  if (req.method === "GET" && (url.pathname === "/mcp" || url.pathname === "/sse" || url.pathname === "/api/mcp/sse" || url.pathname === "/api/mcp")) {
    console.log(`[MCP SSE] Client connected to Streamable HTTP SSE via ${url.pathname}`);
    const postEndpoint = url.pathname.startsWith("/mcp") ? "/mcp/message" : "/message";
    const transport = new SSEServerTransport(postEndpoint, res);
    sseTransports.set(transport.sessionId, transport);

    transport.onclose = () => {
      console.log(`[MCP SSE] Session closed: ${transport.sessionId}`);
      sseTransports.delete(transport.sessionId);
    };

    const sseServer = createMcpServer();
    await sseServer.connect(transport);
    return;
  }

  // ponytail: Standard MCP Streamable HTTP incoming message endpoint (/mcp/message, /message, /api/mcp/message)
  if (req.method === "POST" && (url.pathname === "/mcp/message" || url.pathname === "/message" || url.pathname === "/api/mcp/message" || (url.pathname === "/mcp" && url.searchParams.has("sessionId")) || (url.pathname === "/sse" && url.searchParams.has("sessionId")))) {
    const sessionId = url.searchParams.get("sessionId");
    const transport = sseTransports.get(sessionId);
    if (!transport) {
      res.writeHead(404, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ error: `SSE Session '${sessionId}' not found atau sudah closed.` }));
      return;
    }
    await transport.handlePostMessage(req, res);
    return;
  }

  // GET /api/mcp/tools or /mcp/tools
  if (req.method === "GET" && (url.pathname === "/api/mcp/tools" || url.pathname === "/mcp/tools")) {
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify({ success: true, tools: toolsDefinition }));
    return;
  }

  // POST /api/mcp/chat (LM Studio Local LLM Chat)
  if (req.method === "POST" && url.pathname === "/api/mcp/chat") {
    let bodyText = "";
    req.on("data", (chunk) => { bodyText += chunk; });
    req.on("end", async () => {
      try {
        let body = {};
        if (bodyText) {
          try { 
            body = JSON.parse(bodyText); 
            if (body.isBase64 && body.payload) {
              const decodedText = Buffer.from(body.payload, "base64").toString("utf8");
              body = JSON.parse(decodedText);
            }
          } catch (e) { 
            body = {}; 
          }
        }

        const userPrompt = body.chatInput || body.prompt || "Halo";
        const userName = body.user || "User";

        // Fetch Real DB Context for AI
        const fetchTasksFromDB = async () => {
          try {
            const [rows] = await pool.query(`
              SELECT 
                id, 
                model_name, 
                ap, 
                cp, 
                csc, 
                pic_email, 
                test_plan_type, 
                progress_status, 
                request_date,
                submission_date,
                deadline,
                approved_date
              FROM gba_tasks 
              ORDER BY id DESC 
              LIMIT 100
            `);

            return rows.map(r => {
              const formatDate = (d) => {
                if (!d) return "-";
                const dateObj = new Date(d);
                if (isNaN(dateObj.getTime())) return "-";
                return dateObj.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
              };

              const reqStr = formatDate(r.request_date);
              const subStr = formatDate(r.submission_date);
              const dlStr = formatDate(r.deadline);

              // Real Actual Performance Calculation from MySQL Date Columns
              let subKinerja = "Submission: Pending";
              if (r.submission_date && r.deadline) {
                subKinerja = new Date(r.submission_date) <= new Date(r.deadline) ? "Submission: Ontime" : "Submission: Late";
              } else if (r.deadline && new Date() <= new Date(r.deadline)) {
                subKinerja = "Submission: Ontime";
              }

              let appKinerja = "Approval: Pending";
              if (r.approved_date && r.deadline) {
                appKinerja = new Date(r.approved_date) <= new Date(r.deadline) ? "Approval: Ontime" : "Approval: Late";
              } else if (r.deadline && new Date() <= new Date(r.deadline)) {
                appKinerja = "Approval: Ontime";
              }

              return {
                id: r.id,
                model_name: r.model_name,
                ap: r.ap,
                cp: r.cp,
                csc: r.csc,
                pic_email: r.pic_email,
                test_plan_type: r.test_plan_type,
                progress_status: r.progress_status,
                kinerja: `${subKinerja}\n${appKinerja}`,
                tanggal_detail: `Req: ${reqStr}\nSub: ${subStr}\nDeadline: ${dlStr}`
              };
            });
          } catch (e) {
            console.error("DB Context Error:", e.message);
            return [];
          }
        };

        let dbTasks = await fetchTasksFromDB();

        // Automatic Task Creation Intent Handler
        let createdTaskInfo = null;
        const isCreateIntent = /tambah|buat|create|add|insert/i.test(userPrompt) && /task|model/i.test(userPrompt);

        if (isCreateIntent) {
          const modelMatch = userPrompt.match(/(SM-[A-Z0-9]+)/i) || userPrompt.match(/model\s+([A-Za-z0-9\-]+)/i);
          const apMatch = userPrompt.match(/AP:\s*([A-Za-z0-9]+)/i) || userPrompt.match(/AP\s+([A-Za-z0-9]+)/i);
          const cpMatch = userPrompt.match(/CP:\s*([A-Za-z0-9]+)/i) || userPrompt.match(/CP\s+([A-Za-z0-9]+)/i);
          const cscMatch = userPrompt.match(/CSC:\s*([A-Za-z0-9]+)/i) || userPrompt.match(/CSC\s+([A-Za-z0-9]+)/i);
          let newTestPlan = "Normal MR";
          const tpDirectMatch = userPrompt.match(/test\s*plan\s*:?\s*([A-Za-z0-9\s]+)/i);
          if (tpDirectMatch) {
            const extracted = tpDirectMatch[1].trim();
            const knownMatch = extracted.match(/^(SMR|Normal MR|Simple Exception MR|Simple Exception|Regular Variant|SKU|PL)/i);
            if (knownMatch) {
              newTestPlan = knownMatch[1];
            } else {
              newTestPlan = extracted.split(/\s+/)[0];
            }
          } else {
            const standaloneMatch = userPrompt.match(/\b(SMR|Normal MR|Simple Exception MR|Simple Exception|Regular Variant|SKU|PL)\b/i);
            if (standaloneMatch) {
              newTestPlan = standaloneMatch[1];
            }
          }
          const picMatch = userPrompt.match(/[\w.-]+@[\w.-]+\.\w+/) || (userPrompt.toLowerCase().includes("endri") ? ["endri.s@samsung.com"] : null);

          if (modelMatch) {
            const newModelName = modelMatch[1].toUpperCase();
            const newAp = apMatch ? apMatch[1] : "";
            const newCp = cpMatch ? cpMatch[1] : "";
            const newCsc = cscMatch ? cscMatch[1] : "";
            const newPicEmail = picMatch ? picMatch[0] : (userName ? `${userName.toLowerCase()}@samsung.com` : "endri.s@samsung.com");
            const newProgressStatus = "Task Baru";
            const newReqDate = new Date().toISOString().split('T')[0];
            const newDeadline = new Date(Date.now() + 10 * 86400000).toISOString().split('T')[0];

            try {
              const [insertRes] = await pool.query(
                `INSERT INTO gba_tasks (model_name, ap, cp, csc, pic_email, test_plan_type, progress_status, request_date, deadline) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
                [newModelName, newAp, newCp, newCsc, newPicEmail, newTestPlan, newProgressStatus, newReqDate, newDeadline]
              );
              
              createdTaskInfo = {
                id: insertRes.insertId,
                model_name: newModelName,
                ap: newAp,
                cp: newCp,
                csc: newCsc,
                pic_email: newPicEmail,
                test_plan_type: newTestPlan,
                progress_status: newProgressStatus,
                deadline: newDeadline
              };
              console.log(`[MCP Server] Real DB Insert Success! Task ID #${insertRes.insertId} created for ${newModelName}`);

              // Re-fetch 10 recent tasks including newly created one
              dbTasks = await fetchTasksFromDB();
            } catch (dbErr) {
              console.error("[MCP Server] MySQL Insert Error:", dbErr.message);
            }
          }
        }

        // Automatic Task Deletion Intent & Confirmation Handler (with RBAC)
        const userRole = (body.role || body.user_role || (userName.toLowerCase().includes('endri') ? 'admin' : 'admin')).toLowerCase();
        const isConfirmDelete = /ya\s+hapus|konfirmasi\s+hapus|yes\s+delete|confirm\s+delete/i.test(userPrompt);
        const isCancelDelete = /batal\s+hapus|cancel\s+delete|batal|cancel/i.test(userPrompt);

        // Handle Cancel Request
        if (isCancelDelete && /hapus|delete|task/i.test(userPrompt)) {
          res.writeHead(200, { "Content-Type": "application/json" });
          res.end(JSON.stringify({ 
            output: "PENGHAPUSAN DIBATALKAN: Proses penghapusan task telah dibatalkan. Data di database MySQL tetap aman!" 
          }));
          return;
        }

        // Handle Confirmed Deletion (Execute real MySQL DELETE)
        let deletedTaskInfo = null;
        if (isConfirmDelete) {
          if (userRole !== 'admin' && !userName.toLowerCase().includes('endri')) {
            res.writeHead(200, { "Content-Type": "application/json" });
            res.end(JSON.stringify({ 
              output: "AKSES DITOLAK: Hanya pengguna dengan role Admin yang memiliki izin untuk mengonfirmasi penghapusan task." 
            }));
            return;
          }

          let deleteSql = `DELETE FROM gba_tasks WHERE 1=1`;
          const deleteParams = [];

          const idMatch = userPrompt.match(/id\s*#?(\d+)/i) || userPrompt.match(/#(\d+)/);
          const tpMatch = userPrompt.match(/(PL|SMR|Normal MR|Simple Exception MR|SKU|Regular Variant)/i);
          const modelMatch = userPrompt.match(/(SM-[A-Z0-9]+)/i);

          if (idMatch) {
            deleteSql += ` AND id = ?`;
            deleteParams.push(Number(idMatch[1]));
          } else if (tpMatch) {
            deleteSql += ` AND LOWER(test_plan_type) = LOWER(?)`;
            deleteParams.push(tpMatch[1]);
          } else if (modelMatch) {
            deleteSql += ` AND LOWER(model_name) = LOWER(?)`;
            deleteParams.push(modelMatch[1]);
          }

          if (deleteParams.length > 0) {
            const [delRes] = await pool.query(deleteSql, deleteParams);
            const count = delRes.affectedRows;
            
            // Re-fetch 10 recent tasks after deletion
            dbTasks = await fetchTasksFromDB();
            deletedTaskInfo = { count, target: idMatch ? `ID #${idMatch[1]}` : (tpMatch ? `Test Plan ${tpMatch[1]}` : `Model ${modelMatch[1]}`) };
          }
        }

        let rawLmUrl = (body.baseUrl || body.endpoint || process.env.LMSTUDIO_BASE_URL || "https://lmstudio.endrisusanto.my.id/v1").trim();
        // ponytail: sanitize endpoint if user provides full /models or /chat/completions URL
        let lmstudioBaseUrl = rawLmUrl.replace(/\/+$/, "").replace(/\/(models|chat\/completions)$/i, "").replace(/\/+$/, "");
        if (!lmstudioBaseUrl.endsWith("/v1") && !lmstudioBaseUrl.includes("/v1")) {
          lmstudioBaseUrl += "/v1";
        }
        const apiKey = body.apiKey || process.env.LMSTUDIO_API_KEY || "lm-studio";
        const maxTokens = Number(body.maxTokens || process.env.LMSTUDIO_MAX_TOKENS) || 4096;
        const temperature = Number(body.temperature || process.env.LMSTUDIO_TEMPERATURE) || 0.7;

        // ponytail: automatically detect the currently loaded model name in LM Studio, fallback to env configuration if query fails
        let modelName = body.model || process.env.LMSTUDIO_MODEL || "auto";
        const fallbackModel = "google/gemma-4-12b-qat";
        try {
          const lmModelHeaders = {
            "Authorization": `Bearer ${apiKey}`
          };
          let modelsRes;
          try {
            modelsRes = await httpsRequest(`${lmstudioBaseUrl}/models`, { method: "GET", headers: lmModelHeaders });
            if (!modelsRes.ok && [403, 407].includes(modelsRes.status)) {
              modelsRes = await curlRequest(`${lmstudioBaseUrl}/models`, { method: "GET", headers: lmModelHeaders }, "");
            }
          } catch (err) {
            console.log("Direct models fetch failed, trying curl:", err.message);
            modelsRes = await curlRequest(`${lmstudioBaseUrl}/models`, { method: "GET", headers: lmModelHeaders }, "");
          }
          if (modelsRes.ok) {
            const modelsData = modelsRes.json();
            if (modelsData.data && modelsData.data.length > 0) {
              if (modelName === "auto") {
                const chatModel = modelsData.data.find(m => !m.id.toLowerCase().includes("embed")) || modelsData.data[0];
                modelName = chatModel.id;
              }
            }
          }
        } catch (e) {
          console.log("Model auto-detection skipped:", e.message);
        }
        if (modelName === "auto") {
          modelName = fallbackModel;
        }

        const systemMessage = `Anda adalah GBA AI Assistant untuk aplikasi PHP Project Manager yang menyajikan laporan harian untuk seluruh Tim GBA. 
Pengguna/Pengakses: ${userName || "User"} (Role: ${userRole}).
${createdTaskInfo ? `STATUS EKSEKUSI DATABASE: Task ID #${createdTaskInfo.id} untuk model ${createdTaskInfo.model_name} (AP: ${createdTaskInfo.ap}, CP: ${createdTaskInfo.cp}, CSC: ${createdTaskInfo.csc}, PIC: ${createdTaskInfo.pic_email}) SUDAH SUNGGUH-SUNGGUH BERHASIL TERDISIMPAN DI MYSQL DATABASE!` : ''}
${deletedTaskInfo ? `STATUS EKSEKUSI DATABASE: ${deletedTaskInfo.count} task (${deletedTaskInfo.target}) SUDAH SUNGGUH-SUNGGUH BERHASIL DIHAPUS DARI MYSQL DATABASE! Sampaikan konfirmasi berhasil ini secara personal kepada ${userName} dan WAJIB tampilkan data 10 task terbaru dari database di bawah ini dalam format Markdown Table 6 kolom!` : ''}
Berikut adalah data task terbaru dari MySQL database:
${JSON.stringify(dbTasks, null, 2)}

Jawab pertanyaan dan buatkan laporan secara profesional, ringkas, dan jelas dalam Bahasa Indonesia untuk ${userName}. DILARANG menggunakan emoji apapun dalam balasan teks atau tabel.
- Sapaan Resmi: Gunakan sapaan personal "Halo ${userName}" (atau "Halo ${userName}"). DILARANG MENGGUNAKAN "Halo Tim GBA" saat membalas chat ke pengakses pribadi.
- Analisis Deadline Fleksibel: Analisis tanggal deadline secara fleksibel dari data aktual database (sebutkan deadline terdekat, rentang tanggal deadline, serta highlight task yang mendekati deadline).
- PENGHAPUSAN TASK (PERMATANYAAN HAPUS):
  1. Jika pengguna meminta menghapus task: Periksa Role. Jika role pengguna (${userRole}) BUKAN 'admin', TOLAK dengan pesan: 'AKSES DITOLAK: Hanya pengguna dengan role Admin yang memiliki izin untuk menghapus task dari database.'
  2. Jika role adalah Admin dan pengguna belum mengonfirmasi, jawab dengan sopan, periksa task yang cocok dari database, dan SELALU sertakan tombol konfirmasi interaktif HTML berikut di akhir jawaban Anda:
     <div class="hermes-confirm-box">
       <button class="hermes-confirm-btn hermes-btn-danger" onclick="sendChatConfirmation('YA HAPUS TASK [TARGET_KEY]')">✓ Yes, Hapus Task</button>
       <button class="hermes-confirm-btn hermes-btn-cancel" onclick="sendChatConfirmation('BATAL HAPUS TASK')">✕ Cancel</button>
     </div>
1. Jika pengguna meminta data atau daftar task/project (termasuk setelah konfirmasi hapus sukses), WAJIB tampilkan dalam format Markdown Table 7 kolom: | No | Model & Build (AP / CP / CSC) | PIC | Test Plan | Status | Kinerja | Tanggal |.
	   - Detail Kolom Kinerja: Tampilkan status Submission & Approval (contoh: Submission: Ontime<br/>Approval: Ontime).
	   - Detail Kolom Tanggal: Tampilkan 3 tanggal lengkap (contoh: Req: 09 Aug 2026<br/>Sub: 09 Aug 2026<br/>Deadline: 18 Aug 2026).
	   - Catatan Penting: Tampilkan detail AP, CP, dan CSC. Jika nilai CP TIDAK SAMA dengan AP, beri warna merah polos pada teks CP tanpa box/badge/emoji (contoh: CP: <span style="color:#dc2626;font-weight:bold;">...</span>).
2. Jika pengguna meminta chart/grafik/diagram:
   ATURAN MUTLAK DIAGRAM:
   - DILARANG KERAS membuat Pie Chart atau menggunakan elemen <path> dan <circle> (karena kalkulasi trigonometri busur lingkaran menghasilkan diagram yang rusak/tumpang tindih).
   - WAJIB HANYA membuat Horizontal Bar Chart dengan elemen <rect> dan <text> yang rapi, proporsional, serta memiliki badge personal info ${userName} (Role: ${userRole}).
   - Semua batang grafik HARUS sejajar pada x="170", label pada x="30", dan angka pada x="375".
   - Lebar batang grafik (width) bernilai proporsional antara 25 hingga 190 (DILARANG MELEBIHI 190).
   Format SVG yang WAJIB digunakan:
   <svg viewBox="0 0 520 300" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
     <rect width="520" height="300" rx="14" fill="#0f172a" stroke="#334155" stroke-width="1.5" />
     <text x="30" y="37" fill="#38bdf8" font-size="16" font-weight="700">Analisis Distribusi Task GBA</text>
     <rect x="330" y="18" width="160" height="28" rx="14" fill="#1e293b" stroke="#38bdf8" stroke-width="1.2" />
     <text x="410" y="36" fill="#38bdf8" font-size="11" font-weight="700" text-anchor="middle">PIC: ${userName} (${userRole})</text>
     <line x1="30" y1="58" x2="490" y2="58" stroke="#1e293b" stroke-width="1.5" />
     
     <text x="30" y="93" fill="#cbd5e1" font-size="12" font-weight="600">[Kategori 1]</text>
     <rect x="170" y="78" width="190" height="20" rx="5" fill="#1e293b" />
     <rect x="170" y="78" width="[Lebar1_Antara25_sd_190]" height="20" rx="5" fill="#38bdf8" />
     <text x="375" y="93" fill="#f8fafc" font-size="12" font-weight="700">[Nilai1] Tasks</text>

     <text x="30" y="133" fill="#cbd5e1" font-size="12" font-weight="600">[Kategori 2]</text>
     <rect x="170" y="118" width="190" height="20" rx="5" fill="#1e293b" />
     <rect x="170" y="118" width="[Lebar2_Antara25_sd_190]" height="20" rx="5" fill="#10b981" />
     <text x="375" y="133" fill="#f8fafc" font-size="12" font-weight="700">[Nilai2] Tasks</text>

     <text x="30" y="173" fill="#cbd5e1" font-size="12" font-weight="600">[Kategori 3]</text>
     <rect x="170" y="158" width="190" height="20" rx="5" fill="#1e293b" />
     <rect x="170" y="158" width="[Lebar3_Antara25_sd_190]" height="20" rx="5" fill="#f59e0b" />
     <text x="375" y="173" fill="#f8fafc" font-size="12" font-weight="700">[Nilai3] Tasks</text>

     <text x="30" y="213" fill="#cbd5e1" font-size="12" font-weight="600">[Kategori 4]</text>
     <rect x="170" y="198" width="190" height="20" rx="5" fill="#1e293b" />
     <rect x="170" y="198" width="[Lebar4_Antara25_sd_190]" height="20" rx="5" fill="#ef4444" />
     <text x="375" y="213" fill="#f8fafc" font-size="12" font-weight="700">[Nilai4] Tasks</text>

     <line x1="30" y1="245" x2="490" y2="245" stroke="#1e293b" stroke-width="1.5" />
     <text x="260" y="275" fill="#94a3b8" font-size="11" text-anchor="middle">Laporan Personal untuk ${userName} | Data Aktual MySQL Database</text>
   </svg>`;

        // ponytail: backend is stateless, just prepend system prompt to the provided message history array
        const chatMessages = body.messages || [{ role: "user", content: userPrompt }];
        const fullMessages = [{ role: "system", content: systemMessage }].concat(chatMessages);

        const lmPayload = JSON.stringify({
          model: modelName,
          messages: fullMessages,
          temperature: temperature,
          max_tokens: maxTokens
        });
        const lmHeaders = {
          "Content-Type": "application/json",
          "Authorization": `Bearer ${apiKey}`
        };

        // ponytail: check if we should route the request through an external MCP Server Linux bridge
        // Only route through bridge if explicitly configured in MCP_LINUX_BRIDGE_URL
        const bridgeUrl = (process.env.MCP_LINUX_BRIDGE_URL || "").trim();
        let lmRes;

        if (bridgeUrl) {
          console.log(`Routing chat request via Base64 Bridge: ${bridgeUrl}`);
          const base64Body = Buffer.from(lmPayload).toString("base64");
          const bridgePayload = JSON.stringify({
            isBase64: true,
            payload: base64Body
          });
          const bridgeHeaders = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${apiKey}`
          };
          try {
            lmRes = await httpsRequest(bridgeUrl, { method: "POST", headers: bridgeHeaders }, bridgePayload);
            if (!lmRes.ok && [403, 407].includes(lmRes.status)) {
              lmRes = await curlRequest(bridgeUrl, { method: "POST", headers: bridgeHeaders }, bridgePayload);
            }
          } catch (err) {
            console.log("Bridge connection failed, trying curl:", err.message);
            lmRes = await curlRequest(bridgeUrl, { method: "POST", headers: bridgeHeaders }, bridgePayload);
          }
        } else {
          // ponytail: use direct httpsRequest (native) first, fallback to system curl.exe if proxy/firewall rejects direct Node.js sockets
          try {
            lmRes = await httpsRequest(`${lmstudioBaseUrl}/chat/completions`, { method: "POST", headers: lmHeaders }, lmPayload);
            if (!lmRes.ok && [403, 407].includes(lmRes.status)) {
              console.log(`Direct httpsRequest returned ${lmRes.status}, attempting curl fallback`);
              lmRes = await curlRequest(`${lmstudioBaseUrl}/chat/completions`, { method: "POST", headers: lmHeaders }, lmPayload);
            }
          } catch (err) {
            console.log("Direct httpsRequest failed, attempting curl fallback:", err.message);
            lmRes = await curlRequest(`${lmstudioBaseUrl}/chat/completions`, { method: "POST", headers: lmHeaders }, lmPayload);
          }
        }

        if (!lmRes.ok) {
          res.writeHead(200, { "Content-Type": "application/json" });
          res.end(JSON.stringify({ output: `⚠️ LM Studio Server merespons error (${lmRes.status}): ${lmRes.text}. Pastikan Model sudah di-load di LM Studio.` }));
          return;
        }

        const lmData = await lmRes.json();
        const reply = lmData.choices?.[0]?.message?.content || lmData.choices?.[0]?.message?.reasoning_content || "Tidak ada balasan dari model LM Studio.";

        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ output: reply }));
      } catch (error) {
        const errUrl = process.env.LMSTUDIO_BASE_URL || "https://lmstudio.endrisusanto.my.id/v1";
        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ 
          output: `⚠️ Gagal terhubung ke LM Studio (${errUrl} & localhost:1234). Pastikan LM Studio aktif & model sudah di-load. Error: ${error.message}` 
        }));
      }
    });
    return;
  }

  // POST /api/sync/broadcast - Web changes trigger WSS push to desktop bridge clients
  if (req.method === "POST" && url.pathname === "/api/sync/broadcast") {
    let bodyText = "";
    req.on("data", (chunk) => { bodyText += chunk; });
    req.on("end", () => {
      try {
        const authHeader = req.headers["authorization"] || "";
        const token = authHeader.replace("Bearer ", "").trim() || url.searchParams.get("token");
        const syncSecret = process.env.SYNC_TOKEN || "gba-bridge-sync-key-2026";
        if (token !== syncSecret) {
          res.writeHead(401, { "Content-Type": "application/json" });
          res.end(JSON.stringify({ success: false, error: "Unauthorized" }));
          return;
        }

        const payload = JSON.parse(bodyText || "{}");
        const broadcastMsg = JSON.stringify({
          type: "remote_mutation",
          table: payload.table || "gba_tasks",
          action: payload.action || "update",
          data: payload.data || null,
          timestamp: new Date().toISOString()
        });

        let sentCount = 0;
        connectedSyncClients.forEach((client) => {
          if (client.readyState === 1) { // OPEN
            client.send(broadcastMsg);
            sentCount++;
          }
        });

        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ success: true, broadcasted_to: sentCount }));
      } catch (err) {
        res.writeHead(400, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ success: false, error: err.message }));
      }
    });
    return;
  }

  // POST /api/mcp/call or POST /api/mcp/:toolName
  if (req.method === "POST" && url.pathname.startsWith("/api/mcp/")) {
    let bodyText = "";
    req.on("data", (chunk) => { bodyText += chunk; });
    req.on("end", async () => {
      try {
        let body = {};
        if (bodyText) {
          try { body = JSON.parse(bodyText); } catch { body = {}; }
        }

        let toolName = url.pathname.replace("/api/mcp/", "");
        let toolArgs = body.arguments || body;

        if (toolName === "call") {
          toolName = body.name;
          toolArgs = body.arguments || {};
        }

        const result = await executeTool(toolName, toolArgs);
        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ success: true, result }));
      } catch (error) {
        res.writeHead(400, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ success: false, error: error.message }));
      }
    });
    return;
  }

  res.writeHead(404, { "Content-Type": "application/json" });
  res.end(JSON.stringify({ error: "Endpoint not found" }));
});

// ponytail: Persistent WebSocket Server for 2-Way Remote-to-Desktop-Bridge Sync
const wss = new WebSocketServer({ noServer: true });
const connectedSyncClients = new Set();
const SYNC_SECRET = process.env.SYNC_TOKEN || "gba-bridge-sync-key-2026";

httpServer.on("upgrade", (request, socket, head) => {
  const reqUrl = new URL(request.url, `http://${request.headers.host || "localhost"}`);
  if (reqUrl.pathname === "/ws/sync" || reqUrl.pathname === "/ws") {
    const token = reqUrl.searchParams.get("token") || request.headers["authorization"]?.replace("Bearer ", "");
    if (token !== SYNC_SECRET) {
      socket.write("HTTP/1.1 401 Unauthorized\r\n\r\n");
      socket.destroy();
      return;
    }
    wss.handleUpgrade(request, socket, head, (ws) => {
      wss.emit("connection", ws, request);
    });
  } else {
    socket.destroy();
  }
});

wss.on("connection", (ws) => {
  connectedSyncClients.add(ws);
  console.log(`🔌 Desktop Bridge WSS Client Connected. Total clients: ${connectedSyncClients.size}`);
  
  ws.isAlive = true;
  ws.on("pong", () => { ws.isAlive = true; });

  ws.on("message", (data) => {
    try {
      const msg = JSON.parse(data.toString());
      if (msg.type === "ping") {
        ws.send(JSON.stringify({ type: "pong", timestamp: new Date().toISOString() }));
      }
    } catch (_) {}
  });

  ws.on("close", () => {
    connectedSyncClients.delete(ws);
    console.log(`🔌 Desktop Bridge WSS Client Disconnected. Total clients: ${connectedSyncClients.size}`);
  });

  // Welcome handshake
  ws.send(JSON.stringify({
    type: "connected",
    message: "GBA Persistent WSS Bridge Connected",
    timestamp: new Date().toISOString()
  }));
});

// Periodic WSS Heartbeat to keep connection alive
const heartbeatInterval = setInterval(() => {
  wss.clients.forEach((ws) => {
    if (ws.isAlive === false) return ws.terminate();
    ws.isAlive = false;
    ws.ping();
  });
}, 25000);

httpServer.on("error", (err) => {
  if (err.code === "EADDRINUSE") {
    console.error(`⚠️ Port ${HTTP_PORT} is already in use. HTTP/SSE bridge skipped on this instance, running in Stdio MCP mode.`);
  } else {
    console.error("HTTP Server error:", err.message);
  }
});

httpServer.listen(HTTP_PORT, "0.0.0.0", () => {
  console.error(`🚀 MCP Server running on Stdio & HTTP/WSS Bridge (IPv4 port ${HTTP_PORT})`);
});

// Connect Stdio Transport
const stdioServer = createMcpServer();
const stdioTransport = new StdioServerTransport();
await stdioServer.connect(stdioTransport);
