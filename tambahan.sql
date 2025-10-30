-- 1. Tambahkan kolom updated_at untuk waktu pembaruan otomatis
-- Menggunakan TIMESTAMP dengan default CURRENT_TIMESTAMP dan otomatis update saat ada perubahan baris
ALTER TABLE gba_tasks 
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 2. Tambahkan kolom updated_by_email untuk menyimpan identitas (email) user
ALTER TABLE gba_tasks 
ADD COLUMN updated_by_email VARCHAR(255) NULL;



CREATE TABLE activity_log (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_id INT(11) NULL, -- ID dari gba_tasks yang diubah (NULL jika aksi umum)
    action_type VARCHAR(50) NOT NULL, -- Contoh: 'TASK_CREATED', 'TASK_UPDATED', 'STATUS_CHANGE'
    details TEXT NULL, -- Deskripsi detail perubahan/aksi
    user_email VARCHAR(255) NULL, -- Email pengguna yang melakukan aksi
    action_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);