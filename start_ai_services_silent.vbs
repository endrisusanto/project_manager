Set WshShell = CreateObject("WScript.Shell")

' =========================================================================
' Skrip VBScript Startup Windows Silap (Otomatis & Tanpa Jendela CMD)
' =========================================================================

' Fungsi untuk mematikan proses yang sedang menggunakan port tertentu (Re-open aman)
Sub KillPort(port)
    ' Cari PID yang menggunakan port tersebut dan hentikan paksak
    WshShell.Run "cmd /c FOR /F ""tokens=5"" %p IN ('netstat -a -n -o ^| findstr :" & port & "') DO taskkill /F /PID %p", 0, True
End Sub

' Bersihkan / Matikan port lama jika script ini dijalankan ulang
KillPort 3800
KillPort 8080
KillPort 5678

' Tunggu 1.5 detik agar Windows benar-benar membebaskan memori port tersebut
WScript.Sleep 1500

' 1. Jalankan MCP Server HTTP Bridge di background (Port 3800)
' Output console akan disimpan ke file mcp-server.log
WshShell.Run "cmd /c cd /d C:\xampp\htdocs\tkdn\mcp-server && node index.js > mcp-server.log 2>&1", 0, False

' 2. Jalankan Open WebUI AI Portal di background (Port 8080)
WshShell.Run "cmd /c open-webui serve", 0, False

' 3. Jalankan n8n Automation Engine di background (Port 5678)
WshShell.Run "cmd /c n8n start", 0, False

' Tampilkan Pop-up Notifikasi bahwa proses telah dikirim ke background
MsgBox "Port lama berhasil ditutup!" & vbCrLf & "Semua AI Services (MCP Server, Open WebUI, n8n) telah dijalankan ulang di background!" & vbCrLf & vbCrLf & "Cek log MCP Server di: C:\xampp\htdocs\tkdn\mcp-server\mcp-server.log", 64, "GBA AI Services Startup"
