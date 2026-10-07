// ponytail: quick SMTP connection & credential verification script with fallback support
import nodemailer from "nodemailer";
import dotenv from "dotenv";

dotenv.config();

const smtpHost = process.env.SMTP_HOST || "smtp.gmail.com";
const smtpPort = Number(process.env.SMTP_PORT) || 587;
const smtpUser = process.env.SMTP_USER;
const smtpPass = process.env.SMTP_PASS;
const smtpPassFallback = process.env.SMTP_PASS_FALLBACK;

console.log("=== PENGUJIAN KONEKSI SMTP MCP ===");
console.log(`Host              : ${smtpHost}`);
console.log(`Port              : ${smtpPort}`);
console.log(`User              : ${smtpUser || "(BELUM DIISI)"}`);
console.log(`Password Utama    : ${smtpPass ? "******** (TERISI)" : "(BELUM DIISI)"}`);
console.log(`Password Fallback : ${smtpPassFallback ? "******** (TERISI)" : "(TIDAK DIKONFIGURASI)"}`);
console.log("-----------------------------------");

if (!smtpUser || (!smtpPass && !smtpPassFallback)) {
  console.error("❌ ERROR: SMTP_USER dan setidaknya satu password (SMTP_PASS / SMTP_PASS_FALLBACK) harus diisi di .env!");
  process.exit(1);
}

async function verifyCredential(label, pass) {
  if (!pass) return { success: false, skipped: true };
  const transporter = nodemailer.createTransport({
    host: smtpHost,
    port: smtpPort,
    secure: process.env.SMTP_SECURE === "true" || smtpPort === 465,
    auth: { user: smtpUser, pass }
  });

  try {
    await transporter.verify();
    return { success: true };
  } catch (err) {
    return { success: false, error: err };
  }
}

console.log("⏳ Memverifikasi koneksi dan kredensial SMTP...");

(async () => {
  let anySuccess = false;

  if (smtpPass) {
    process.stdout.write("👉 Menguji Password Utama... ");
    const resPrimary = await verifyCredential("Utama", smtpPass);
    if (resPrimary.success) {
      console.log("✅ BERHASIL");
      anySuccess = true;
    } else {
      console.log(`❌ GAGAL (${resPrimary.error.message})`);
    }
  }

  if (smtpPassFallback) {
    process.stdout.write("👉 Menguji Password Fallback... ");
    const resFallback = await verifyCredential("Fallback", smtpPassFallback);
    if (resFallback.success) {
      console.log("✅ BERHASIL");
      anySuccess = true;
    } else {
      console.log(`❌ GAGAL (${resFallback.error.message})`);
    }
  }

  console.log("-----------------------------------");
  if (anySuccess) {
    console.log("🎉 Server SMTP siap mengirim email!");
    process.exit(0);
  } else {
    console.error("❌ Semua password SMTP gagal diautentikasi.");
    process.exit(1);
  }
})();
