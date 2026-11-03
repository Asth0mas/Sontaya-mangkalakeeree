<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oak Sphere | Smart Red Oak Growth Chamber</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body data-page="login">
    <div class="page-shell">
        <nav class="navbar" data-reveal="1">
            <div class="brand">
                <div class="brand-mark">
                    <img src="assets/images/001.svg" alt="Oak Sphere Logo">
                </div>
                <div class="brand-copy">
                    <strong>Oak Sphere</strong>
                </div>
            </div>

        </nav>

        <main class="container section-stack">
            <section class="panel hero" data-reveal="2">
                <div class="hero-copy">
                    <h1>ติดตามการเติบโต ควบคุมสภาพแวดล้อม และคาดการณ์เก็บเกี่ยว</h1>
                    <div class="hero-points">
                        
                    </div>
                    <div class="hero-actions">
                        <a href="#loginForm" class="button-primary">เข้าสู่ระบบผู้ดูแลระบบ</a>
                        <a href="visitor-dashboard.html" class="button-secondary">ดู Dashboard ผู้ใช้งานทั่วไป</a>
                    </div>
                </div>
                    <div class="login-panel">
                        <span class="eyebrow">Access</span>
                        <h2>เข้าสู่ระบบ</h2>
                        <p>ล็อกอินด้วย <strong>admin / 1234</strong> เพื่อเข้าสู่หน้าควบคุม หรือดูแบบผู้ใช้งานทั่วไป</p>
                        <form id="loginForm" class="form-stack">
                            <div class="field">
                                <label for="username">ชื่อผู้ใช้</label>
                                <input id="username" name="username" type="text" placeholder="เช่น admin" required>
                            </div>
                            <div class="field">
                                <label for="password">รหัสผ่าน</label>
                                <input id="password" name="password" type="password" placeholder="กรอกรหัสผ่าน" required>
                            </div>
                            <div class="inline-actions">
                                <button class="button-soft" type="submit">เข้าสู่ระบบ</button>
                                <a class="button-outline" href="visitor-dashboard.html">ดูแบบไม่ต้องล็อกอิน</a>
                            </div>
                            
                            <div id="loginFeedback" class="feedback" style="display: none; color: #c1544d; margin-top: 10px; font-weight: bold; padding: 10px; background: rgba(193, 84, 77, 0.1); border-radius: 8px;">
                            </div>
                        </form>

                    </div>
                </div>
            </section>
        </main>
    </div>
    
    <script src="js/script.js"></script>
    
    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const feedbackDiv = document.getElementById('loginFeedback');
            const submitBtn = this.querySelector('button[type="submit"]');

            submitBtn.textContent = 'กำลังตรวจสอบ...';
            submitBtn.disabled = true;

            fetch('api_login.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitBtn.textContent = 'เข้าสู่ระบบ';
                submitBtn.disabled = false;

                if (data.status === 'success') {
                    window.location.href = 'pages/trans-control.html';
                } else {
                    feedbackDiv.style.display = 'block';
                    feedbackDiv.innerHTML = data.message;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                submitBtn.textContent = 'เข้าสู่ระบบ';
                submitBtn.disabled = false;
                feedbackDiv.style.display = 'block';
                feedbackDiv.innerHTML = 'เกิดข้อผิดพลาดในการเชื่อมต่อระบบ';
            });
        });
    </script>
</body>
</html>