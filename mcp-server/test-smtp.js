// ponytail: quick SMTP connection & credential verification script
import nodemailer from "nodemailer";
import dotenv from "dotenv";

dotenv.config();

const smtpHost = process.env.SMTP_HOST || "smtp.gmail.com";
const smtpPort = Number(process.env.SMTP_PORT) || 587;
const smtpUser = process.env.SMTP_USER;
const smtpPass = process.env.SMTP_PASS;

console.log("=== PENGUJIAN KONEKSI SMTP MCP ===");
console.log(`Host     : ${smtpHost}`);
console.log(`Port     : ${smtpPort}`);
console.log(`User     : ${smtpUser || "(BELUM DIISI)"}`);
console.log(`Password : ${smtpPass ? "******** (TERISI)" : "(BELUM DIISI)"}`);
console.log("-----------------------------------");

if (!smtpUser || !smtpPass) {
  console.error("❌ ERROR: SMTP_USER dan SMTP_PASS belum diisi di file .env!");
  process.exit(1);
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

console.log("⏳ Sedang memverifikasi handshake dan autentikasi SMTP...");

transporter.verify((error, success) => {
  if (error) {
    console.error("❌ GAGAL: Koneksi atau autentikasi SMTP gagal:");
    console.error(`   Pesan Error: ${error.message}`);
    if (error.code === "EAUTH") {
      console.error("   💡 Tips: Jika menggunakan Gmail, pastikan menggunakan 'App Password' (bukan password akun biasa).");
    }
    process.exit(1);
  } else {
    console.log("✅ SUKSES: Server SMTP siap mengirim email!");
    console.log("   Handshake dan autentikasi berhasil 100%.");
    process.exit(0);
  }
});
