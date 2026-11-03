<?php
date_default_timezone_set('Asia/Bangkok');
$bot_file = __DIR__ . "/bot_background.php";
$log_file = "/tmp/bot_log.txt";

$server_issues = [];
if (!function_exists('exec') || !function_exists('shell_exec')) {
    $server_issues[] = "❌ เซิร์ฟเวอร์ปิดการใช้งานฟังก์ชัน exec() หรือ shell_exec() ทำให้ไม่สามารถสั่งรันบอทผ่านเว็บได้";
}
if (!is_writable(__DIR__)) {
    $server_issues[] = "❌ โฟลเดอร์ปัจจุบัน (".__DIR__.") ไม่ได้รับสิทธิ์ให้เขียนไฟล์ (Permission Denied) ระบบจึงสร้างไฟล์ Log ไม่ได้";
}
$php_path = trim(shell_exec("which php"));
if (empty($php_path)) {
    $php_path = "/usr/bin/php"; // ค่ามาตรฐานของ Linux
}

if (is_writable(__DIR__)) {
    if (!file_exists($log_file)) {
        file_put_contents($log_file, "--- เริ่มต้น Log ---" . PHP_EOL);
    }
    chmod($log_file, 0777);
}

if (isset($_GET['stop']) && $_GET['stop'] == 'true') {
    exec("pkill -f bot_background.php");
    header("Location: bot_manager.php");
    exit;
}

if (isset($_GET['start']) && $_GET['start'] == 'true') {
    exec("nohup {$php_path} {$bot_file} > {$log_file} 2>&1 &");
    header("Location: bot_manager.php");
    exit;
}

$process_check = shell_exec("ps aux | grep bot_background.php | grep -v grep");
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oak Sphere | Bot Manager (Debug)</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; background: #f4f7f4; padding: 30px 15px; color: #1e293b; }
        .container { max-width: 760px; margin: 0 auto; background: #fff; border-radius: 16px; padding: 35px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        h2 { color: #144d53; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; margin-top: 0; }
        .alert { background: #fffbeb; border-left: 6px solid #f59e0b; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert p { margin: 5px 0; color: #b45309; font-weight: 600; }
        .status-box { padding: 20px; border-radius: 12px; margin-bottom: 25px; border-left: 6px solid; }
        .offline { background: #fef2f2; border-color: #ef4444; }
        .online { background: #ecfdf5; border-color: #10b981; }
        .btn { display: inline-block; padding: 12px 24px; border-radius: 8px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; }
        .btn-start { background: #10b981; color: white; }
        .btn-stop { background: #ef4444; color: white; margin-right: 10px; }
        .btn-refresh { background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; }
        pre { background: #1e293b; color: #a7f3d0; padding: 15px; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h2>⚙️ ระบบจัดการ Telegram Bot (ตรวจสอบปัญหา)</h2>
        
        <?php if (!empty($server_issues)): ?>
            <div class="alert">
                <h3 style="margin-top:0; color: #d97706;">⚠️ ตรวจพบข้อจำกัดของเซิร์ฟเวอร์</h3>
                <?php foreach($server_issues as $issue): ?>
                    <p><?php echo $issue; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($process_check)): ?>
            <div class="status-box offline">
                <h3 style="color: #ef4444; margin-top:0;">🔴 สถานะ: บอทหยุดทำงาน (Offline)</h3>
                <p>ขณะนี้ไม่มี Process ของบอททำงานอยู่ในเซิร์ฟเวอร์</p>
                <p style="font-size: 0.85rem; color: #7f1d1d; margin-top: 10px;">* คำสั่งที่ใช้รัน: <code style="background:#fee2e2; padding:2px 5px;">nohup <?php echo $php_path; ?> <?php echo $bot_file; ?> ...</code></p>
            </div>
            <a href="?start=true" class="btn btn-start">▶️ คลิกเพื่อเปิดการทำงานบอท</a>
        <?php else: ?>
            <div class="status-box online">
                <h3 style="color: #047857; margin-top:0;">🟢 สถานะ: บอทกำลังทำงานปกติ (Online)</h3>
                <pre><?php echo htmlspecialchars($process_check); ?></pre>
            </div>
            <a href="?stop=true" class="btn btn-stop" onclick="return confirm('หยุดบอท?');">🛑 บังคับปิดบอท</a>
            <a href="bot_manager.php" class="btn btn-refresh">🔄 รีเฟรชสถานะ</a>
        <?php endif; ?>

        <?php if (file_exists($log_file)): ?>
            <h3 style="margin-top: 35px;">📝 ประวัติการทำงานล่าสุด</h3>
            <pre style="background: #f8fafc; color: #475569; border: 1px solid #e2e8f0;"><?php echo htmlspecialchars(shell_exec("tail -n 15 {$log_file}")); ?></pre>
        <?php endif; ?>
    </div>
</body>
</html>