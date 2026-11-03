const STORAGE_KEYS = {
    settings: "oakSphereSettings", cropStart: "oakSphereCropStart", mode: "oakSphereMode", history: "oakSphereHistory"
};
const DEFAULT_SETTINGS = {
    tempMin: 25, tempMax: 30, humidityMin: 60, humidityMax: 90, soilMin: 60, soilMax: 80, seedlingLightOn: 12, growthLightOn: 16
};
const DEFAULT_MANUAL = { cooling: false, fan: true, mist: false, led: true, pump: false };
const SENSOR_OFFLINE_THRESHOLD_MS = 12000;
const SENSOR_DEFINITIONS = [
    { key: "sht31", label: "SHT31", description: "อุณหภูมิ / ความชื้นอากาศ" }, { key: "bh1750", label: "BH1750", description: "ความเข้มแสง" },
    { key: "soil", label: "Soil Moisture", description: "ความชื้นในดิน" }, { key: "vl53l1x", label: "VL53L1X", description: "ความสูงทรงพุ่ม" }
];

const appState = {
    settings: loadJSON(STORAGE_KEYS.settings, DEFAULT_SETTINGS), cropStart: storageGet(STORAGE_KEYS.cropStart) || getDefaultCropStart(),
    mode: storageGet(STORAGE_KEYS.mode) || "auto", manual: { ...DEFAULT_MANUAL },
    deviceStatus: { cooling: "0", fan: "0", mist: "0", led: "0", pump: "0" }, metrics: { temp: 0, humidity: 0, soil: 0, light: 0, height: 0 },
    sensorHealth: createInitialSensorHealth(), factors: { gdd: 0, height: 0, water: 0, readiness: 0 }, 
    gddAcc: 0, wurPercentDB: 0, serverMemory: { maxHeight: 0 },
    historyEntries: [], historyFilter: { start: "", end: "" }, chart: null, chartCanvasId: "", chartMode: "none", timers: [], isLiveMode: true,
    smoothedHeight: undefined, sensorAlertSent: false 
};

document.addEventListener("DOMContentLoaded", () => {
    initSharedElements();
    const page = document.body.dataset.page;
    if (page === "visitor") initVisitorPage();
    if (page === "admin") initAdminPage();

    document.querySelectorAll('.sidebar-nav .nav-item').forEach(item => {
        item.addEventListener('click', function() {
            document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
            this.classList.add('active');
            const targetPageId = this.getAttribute('data-target');
            document.querySelectorAll('.page-view').forEach(page => page.classList.remove('active'));
            document.getElementById(targetPageId).classList.add('active');
            if(targetPageId === 'page-analytics' && appState.chart) { appState.chart.resize(); }
        });
    });

    document.getElementById("viewChartBtn")?.addEventListener("click", function() {
        this.classList.add("active"); document.getElementById("viewTableBtn").classList.remove("active");
        document.getElementById("chartContainer").style.display = "block"; document.getElementById("tableContainer").style.display = "none";
    });
    document.getElementById("viewTableBtn")?.addEventListener("click", function() {
        this.classList.add("active"); document.getElementById("viewChartBtn").classList.remove("active");
        document.getElementById("chartContainer").style.display = "none"; document.getElementById("tableContainer").style.display = "block";
        renderHistoryTable(); 
    });
    
    document.querySelectorAll('.col-toggle').forEach(cb => {
        cb.addEventListener('change', renderHistoryTable);
    });
});

function initSharedElements() { writeText("currentYear", String(new Date().getFullYear())); loadHarvestMemory(); startRealtimeUpdates(); }
function initVisitorPage() { renderModeState(); renderCycleMeta(); renderSensorStates(); renderDeviceStates(false); updateChart(); safeMountChart("environmentChart"); }
function initAdminPage() { bindModeButtons(); bindManualToggles(); bindDateActions(); renderModeState(); renderCycleMeta(); renderSensorStates(); renderHarvestSection(); renderDeviceStates(true); updateChart(); safeMountChart("environmentChart"); }

function mountChart(canvasId) {
    const canvas = document.getElementById(canvasId); if (!canvas) return; appState.chartCanvasId = canvasId;
    const series = buildChartSeries();
    if (typeof Chart === "undefined") { appState.chart = null; appState.chartMode = "fallback"; renderFallbackChart(canvasId, series); return; }
    appState.chart = new Chart(canvas, {
        type: "line",
        data: {
            labels: series.labels,
            datasets: [
                { label: "อุณหภูมิ (°C)", data: series.temperature, borderColor: "#eecc0e", backgroundColor: "rgba(48, 118, 114, 0.14)", tension: 0.35, fill: false, pointRadius: 0, pointHoverRadius: 6 },
                { label: "ความชื้นอากาศ (%RH)", data: series.humidity, borderColor: "#37cc31", backgroundColor: "rgba(138, 191, 135, 0.14)", tension: 0.35, fill: false, pointRadius: 0, pointHoverRadius: 6 },
                { label: "ความชื้นดิน (%)", data: series.soil, borderColor: "#d23a3a", backgroundColor: "rgba(216, 138, 34, 0.14)", tension: 0.35, fill: false, pointRadius: 0, pointHoverRadius: 6 },
                { label: "ความเข้มแสง (Lux)", data: series.light, borderColor: "#b10aaf", backgroundColor: "rgba(20, 77, 83, 0.1)", tension: 0.35, yAxisID: "y1", fill: false, pointRadius: 0, pointHoverRadius: 6 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: "index", intersect: false }, plugins: { legend: { position: "bottom" } }, scales: { y: { min: 20, max: 95, grid: { color: "rgba(20, 77, 83, 0.08)" } }, y1: { position: "right", min: 0, max: 25000, grid: { drawOnChartArea: false } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } } } }
    });
    appState.chartMode = "chartjs";
}

function safeMountChart(canvasId) { try { mountChart(canvasId); } catch (e) { appState.chart = null; appState.chartMode = "fallback"; renderFallbackChart(canvasId, buildChartSeries()); } }
function getLocalTodayStr() { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().split('T')[0]; }

function bindDateActions() {
    const todayStr = getLocalTodayStr(); appState.isLiveMode = true; appState.historyEntries = [];
    document.getElementById("applyHistoryFilter")?.addEventListener("click", () => {
        const start = document.getElementById("historyStart")?.value || ""; const end = document.getElementById("historyEnd")?.value || "";
        if (!start || !end) return showToast("กรุณาเลือกวันที่เริ่มต้นและสิ้นสุด"); if (end < start) return showToast("วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น");
        appState.isLiveMode = false; appState.historyFilter.start = start; appState.historyFilter.end = end; fetchHistoryData(start, end);
        
        const toggleGroup = document.getElementById("viewToggleGroup");
        if (toggleGroup) toggleGroup.style.display = "inline-flex";
    });
    document.getElementById("resetHistoryFilter")?.addEventListener("click", () => {
        const start = document.getElementById("historyStart"); const end = document.getElementById("historyEnd");
        if (start) start.value = todayStr; if (end) end.value = todayStr;
        appState.historyFilter.start = ""; appState.historyFilter.end = ""; appState.isLiveMode = true; appState.historyEntries = []; updateChart();
        
        const toggleGroup = document.getElementById("viewToggleGroup");
        if (toggleGroup) toggleGroup.style.display = "none";
        
        document.getElementById("viewChartBtn")?.click();
    });
}

function fetchHistoryData(startDate, endDate) {
    const isVisitor = document.body.dataset.page === "visitor";
    const apiPath = isVisitor ? 'api_get_history.php' : '../api_get_history.php';
    
    fetch(`${apiPath}?start=${startDate}&end=${endDate}`).then(res => res.json()).then(data => {
        if (data.status === 'success' && data.data.length > 0) {
            
            const SENSOR_INSTALL_HEIGHT = 33; 

            appState.historyEntries = data.data.map(item => {
                let rawHeight = parseFloat(item.plant_height) || 0;
                let actualHeight = 0;
                
                if (rawHeight > 0 && rawHeight <= SENSOR_INSTALL_HEIGHT) {
                    actualHeight = SENSOR_INSTALL_HEIGHT - rawHeight;
                }

                return { 
                    timestamp: item.record_time.replace(' ', 'T'), 
                    temp: Number(parseFloat(item.temp).toFixed(1)), 
                    humidity: Number(parseFloat(item.humidity).toFixed(1)), 
                    soil: Number(parseFloat(item.soil_moisture).toFixed(1)), 
                    light: Number(parseFloat(item.light_intensity).toFixed(0)), 
                    height: Number(actualHeight.toFixed(1)) 
                };
            }); 
            
            updateChart();
        } else { 
            appState.historyEntries = []; 
            updateChart(); 
            showToast(`ไม่พบข้อมูลประวัติในช่วง ${startDate} ถึง ${endDate}`); 
        }
    }).catch(e => showToast("ไม่สามารถดึงข้อมูลประวัติจากเซิร์ฟเวอร์ได้"));
}

function bindModeButtons() {
    document.querySelectorAll("[data-mode]").forEach((button) => {
        button.addEventListener("click", () => {
            const newMode = button.dataset.mode;
            fetch('../api_device_control.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'set_mode', mode: newMode }) })
            .then(res => res.json()).then(data => {
                if (data.status === 'success') {
                    appState.mode = newMode; storageSet(STORAGE_KEYS.mode, appState.mode); 
                    if (newMode === 'standby' || newMode === 'auto') {
                        ["cooling", "fan", "mist", "led", "pump"].forEach(key => { appState.manual[key] = false; appState.deviceStatus[key] = "0"; const btn = document.getElementById(`toggle-${key}`); if (btn) btn.checked = false; });
                    }
                    renderModeState(); renderDeviceStates(document.body.dataset.page === "admin"); showToast(`สลับเป็นโหมด${getModeLabel(appState.mode)}แล้ว`);
                } else { showToast("เกิดข้อผิดพลาด: " + data.message); }
            }).catch(e => showToast("ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้"));
        });
    });
}

function bindManualToggles() {
    ["cooling", "fan", "mist", "led", "pump"].forEach((id) => {
        document.getElementById(`toggle-${id}`)?.addEventListener("change", (event) => {
            const isChecked = event.target.checked; const statusValue = isChecked ? "1" : "0";
            fetch('../api_device_control.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'set_toggle', device_name: id, status: statusValue }) })
            .then(res => res.json()).then(data => {
                if (data.status === 'success') { appState.manual[id] = isChecked; appState.deviceStatus[id] = statusValue; renderDeviceStates(document.body.dataset.page === "admin"); } 
                else { event.target.checked = !isChecked; showToast("เกิดข้อผิดพลาด: " + data.message); }
            }).catch(e => { event.target.checked = !isChecked; showToast("ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้"); });
        });
    });
}

function renderModeState() {
    document.querySelectorAll("[data-mode]").forEach((b) => { b.classList.toggle("active", b.dataset.mode === appState.mode); });
    writeText("modeValue", getModeLabel(appState.mode));
    
    const manualPanel = document.getElementById('manualControlPanel');
    const autoPanel = document.getElementById('autoParametersPanel');
    
    if (manualPanel && autoPanel) {
        if (appState.mode === 'auto') {
            manualPanel.style.display = 'none';
            autoPanel.style.display = 'block';
        } else if (appState.mode === 'manual') {
            manualPanel.style.display = 'block';
            autoPanel.style.display = 'none';
        } else {
            manualPanel.style.display = 'none';
            autoPanel.style.display = 'none';
        }
    }

    const manualEnabled = appState.mode === "manual";
    document.querySelectorAll("[data-manual-control]").forEach((input) => { input.disabled = !manualEnabled; });
}

function renderCycleMeta() {
    if (!appState.cropStart) { writeText("cycleDays", "-"); writeText("cycleStage", "-"); writeText("cycleHarvest", "-"); writeText("summaryCycleDays", "-"); return; }
    const days = getCycleDays(); const harvestOffset = Math.max(0, 45 - days);
    writeText("cycleDays", `${days} วัน`); writeText("cycleStage", getGrowthStage(days)); writeText("cycleHarvest", harvestOffset <= 0 ? "พร้อมประเมินเก็บเกี่ยว" : `อีกประมาณ ${harvestOffset} วัน`); writeText("summaryCycleDays", `${days} วัน`);
}

function renderSensorStates() {
    const container = document.getElementById("mainSensorGrid"); if (!container) return;
    const onlineCount = SENSOR_DEFINITIONS.filter((sensor) => getSensorStatus(sensor.key).online).length;
    const summaryNode = document.getElementById("sensorHealthMeta");
    if (summaryNode) summaryNode.innerHTML = `<span class="badge ${onlineCount === SENSOR_DEFINITIONS.length ? "success" : "warning"}">จำนวนเซนเซอร์ ${onlineCount}/${SENSOR_DEFINITIONS.length} กำลัง Online</span>`;

    container.innerHTML = SENSOR_DEFINITIONS.map((sensor) => {
        const status = getSensorStatus(sensor.key); const dotClass = status.online ? "online" : "offline"; const textStatus = status.online ? "ทำงานปกติ" : "ขาดการเชื่อมต่อ"; const fontColor = status.online ? "#144d53" : "#94a3b8"; 
        return `<article class="sensor-card ${status.online ? "is-online" : "is-offline"}"><div class="sensor-card-top"><div><strong style="font-size: 1.1rem; color: #1e293b;">${sensor.label}</strong><div class="helper-note">${sensor.description}</div></div><span class="sensor-badge ${dotClass}"><span class="sensor-badge-dot"></span>${textStatus}</span></div><div class="sensor-reading" style="color: ${fontColor};">${getSensorReadingLabel(sensor.key)}</div></article>`;
    }).join("");
}

function loadHarvestMemory() {
    const isVisitor = document.body.dataset.page === "visitor";
    const apiPath = isVisitor ? 'api_harvest_factors.php' : '../api_harvest_factors.php';
    fetch(apiPath).then(res => res.json()).then(data => { if (data.status === 'success') { appState.serverMemory.maxHeight = data.data.max_height || 0; } }).catch(e => console.log('ไม่มีไฟล์ JSON ความจำ'));
}

function renderHarvestSection() {
    const readiness = appState.cropStart && appState.factors.readiness !== undefined ? appState.factors.readiness : 0;
    const gddVal = appState.factors.gdd || 0;
    const heightVal = appState.factors.height || 0;
    const waterVal = appState.factors.water || 0;

    const degrees = readiness * 3.6;
    const ring = document.getElementById("harvestRing");
    if (ring) ring.style.background = `conic-gradient(#307672 ${degrees}deg, #e9ecef ${degrees}deg)`;
    
    writeText("harvestReadiness", `${readiness}%`); 
    if(document.getElementById("summaryReadiness")) writeText("summaryReadiness", `${readiness}%`);
    
    writeText("gddValue", `${gddVal}%`); 
    writeText("heightValue", `${heightVal}%`); 
    writeText("waterValue", `${waterVal}%`);

    const elGdd = document.getElementById("factorGdd"); 
    if(elGdd) { elGdd.style.width = `${gddVal}%`; elGdd.style.background = '#307672'; }
    
    const elHeight = document.getElementById("factorHeight"); 
    if(elHeight) { elHeight.style.width = `${heightVal}%`; elHeight.style.background = '#307672'; }
    
    const elWater = document.getElementById("factorWater"); 
    if(elWater) { elWater.style.width = `${waterVal}%`; elWater.style.background = '#307672'; }

    const statusNode = document.getElementById("harvestStatusBadge");
    if (statusNode) {
        if (!appState.cropStart) { 
            statusNode.style.background = '#f8fafc'; statusNode.style.color = '#64748b'; statusNode.textContent = "รอเริ่มรอบการปลูกใหม่"; 
        } else {
            if (readiness >= 100) { 
                statusNode.style.background = '#ecfdf5'; statusNode.style.color = '#047857'; statusNode.textContent = "พร้อมเก็บเกี่ยว 100%"; 
            } else if (readiness >= 90) { 
                statusNode.style.background = '#fffbeb'; statusNode.style.color = '#b45309'; statusNode.textContent = "ใกล้เก็บเกี่ยว (เกณฑ์ 90%)"; 
            } else { 
                statusNode.style.background = '#f0fdf4'; statusNode.style.color = '#144d53'; statusNode.textContent = "กำลังติดตามการเติบโต"; 
            }
        }
    }
}

function renderDeviceStates(isAdmin) {
    const rows = [ { label: "ชุดทำความเย็น", key: "cooling" }, { label: "พัดลมระบายอากาศ", key: "fan" }, { label: "เครื่องพ่นหมอก", key: "mist" }, { label: "หลอดไฟ LED", key: "led" }, { label: "ปั๊มน้ำ", key: "pump" } ];
    const container = document.getElementById("deviceStateList"); if (!container) return;
    container.style.display = "grid"; container.style.gridTemplateColumns = "repeat(auto-fit, minmax(110px, 1fr))"; container.style.gap = "12px"; container.style.width = "100%";
    container.innerHTML = rows.map((row) => {
        const isOn = appState.deviceStatus && appState.deviceStatus[row.key] === "1";
        const colorBg = isAdmin ? (isOn ? "rgba(255, 255, 255, 0.15)" : "rgba(255, 255, 255, 0.05)") : (isOn ? "#ffffff" : "#f8f9fa"); 
        const borderStyle = isAdmin ? (isOn ? "1px solid rgba(255, 255, 255, 0.4)" : "1px solid rgba(255, 255, 255, 0.1)") : (isOn ? "1px solid #a7f3d0" : "1px solid #e9ecef");
        const colorText = isAdmin ? "#ffffff" : "#2d3436"; const statusText = isOn ? "เปิด" : "ปิด"; 
        const statusColor = isAdmin ? (isOn ? "#a7f3d0" : "#94a3b8") : (isOn ? "#10b981" : "#94a3b8");
        const toggleBg = isOn ? "#10b981" : "#cbd5e1"; const knobPos = isOn ? "calc(100% - 20px)" : "2px";
        return `<div style="background: ${colorBg}; border: ${borderStyle}; border-radius: 12px; padding: 16px 10px; display: flex; flex-direction: column; align-items: center; justify-content: space-between; text-align: center; transition: all 0.2s ease; backdrop-filter: blur(4px); min-height: 90px;"><div><div style="font-size: 0.9rem; font-weight: 600; color: ${colorText}; margin-bottom: 2px;">${row.label}</div><div style="font-size: 0.8rem; font-weight: 500; color: ${statusColor}; margin-bottom: 12px;">${statusText}</div></div><div style="width: 40px; height: 22px; background: ${toggleBg}; border-radius: 12px; position: relative; transition: all 0.3s ease;"><div style="width: 18px; height: 18px; background: #ffffff; border-radius: 50%; position: absolute; top: 2px; left: ${knobPos}; transition: all 0.3s cubic-bezier(0.4, 0.0, 0.2, 1);"></div></div></div>`;
    }).join("");
    if (isAdmin) { rows.forEach(row => { const toggle = document.getElementById(`toggle-${row.key}`); const isOn = appState.deviceStatus && appState.deviceStatus[row.key] === "1"; if (toggle) { toggle.checked = isOn; } }); } 
}

function updateChart() {
    const series = buildChartSeries(); 
    if (appState.chartMode === "fallback") { renderFallbackChart(appState.chartCanvasId || "environmentChart", series); return; }
    if (!appState.chart) return; 
    appState.chart.data.labels = series.labels; 
    appState.chart.data.datasets[0].data = series.temperature; 
    appState.chart.data.datasets[1].data = series.humidity; 
    appState.chart.data.datasets[2].data = series.soil; 
    appState.chart.data.datasets[3].data = series.light; 
    appState.chart.update();
    renderHistoryTable();
}

function renderHistoryTable() {
    const tbody = document.getElementById("historyTableBody");
    if (!tbody) return;
    
    const showTemp = document.querySelector('.col-toggle[value="temp"]')?.checked ?? true;
    const showHum = document.querySelector('.col-toggle[value="humidity"]')?.checked ?? true;
    const showSoil = document.querySelector('.col-toggle[value="soil"]')?.checked ?? true;
    const showLight = document.querySelector('.col-toggle[value="light"]')?.checked ?? true;
    const showHeight = document.querySelector('.col-toggle[value="height"]')?.checked ?? true;

 
    const thTemp = document.querySelector('th[data-col="temp"]'); if(thTemp) thTemp.style.display = showTemp ? '' : 'none';
    const thHum = document.querySelector('th[data-col="humidity"]'); if(thHum) thHum.style.display = showHum ? '' : 'none';
    const thSoil = document.querySelector('th[data-col="soil"]'); if(thSoil) thSoil.style.display = showSoil ? '' : 'none';
    const thLight = document.querySelector('th[data-col="light"]'); if(thLight) thLight.style.display = showLight ? '' : 'none';
    const thHeight = document.querySelector('th[data-col="height"]'); if(thHeight) thHeight.style.display = showHeight ? '' : 'none';

    if (appState.historyEntries.length === 0) {
        const visibleCols = 1 + [showTemp, showHum, showSoil, showLight, showHeight].filter(Boolean).length;
        tbody.innerHTML = `<tr><td colspan="${visibleCols}" style="text-align: center; padding: 24px; color: var(--text-soft);">ไม่มีข้อมูลในช่วงเวลาที่เลือก</td></tr>`;
        return;
    }
    
    tbody.innerHTML = appState.historyEntries.map(e => `
        <tr>
            <td data-label="วัน-เวลา" style="font-weight: 500; color: var(--primary);">${e.timestamp.replace('T', ' ')}</td>
            ${showTemp ? `<td data-label="อุณหภูมิ (°C)">${e.temp}</td>` : ''}
            ${showHum ? `<td data-label="ความชื้นอากาศ (%RH)">${e.humidity}</td>` : ''}
            ${showSoil ? `<td data-label="ความชื้นดิน (%)">${e.soil}</td>` : ''}
            ${showLight ? `<td data-label="ความเข้มแสง (Lux)">${e.light.toLocaleString()}</td>` : ''}
            ${showHeight ? `<td data-label="ความสูง (cm)">${e.height}</td>` : ''}
        </tr>
    `).join("");
}

function renderFallbackChart(canvasId, series) {
    const canvas = document.getElementById(canvasId); const shell = canvas?.closest(".chart-shell"); if (!shell) return;
    const colors = { temperature: "#1ff5ea", humidity: "#f33232", soil: "#d88a22", light: "#144d53" };
    const tempPoints = makeSvgPoints(series.temperature, 20, 95); const humidityPoints = makeSvgPoints(series.humidity, 20, 95); const soilPoints = makeSvgPoints(series.soil, 20, 95); const lightPoints = makeSvgPoints(series.light, 0, 25000);
    const labels = series.labels.map((label, index) => `<span>${index === 0 || index === series.labels.length - 1 || index === Math.floor(series.labels.length / 2) ? label : ""}</span>`).join("");
    shell.innerHTML = `<div class="chart-fallback"><svg viewBox="0 0 100 100" preserveAspectRatio="none" class="chart-fallback-svg"><polyline fill="none" stroke="${colors.temperature}" stroke-width="1.4" points="${tempPoints}"></polyline><polyline fill="none" stroke="${colors.humidity}" stroke-width="1.4" points="${humidityPoints}"></polyline><polyline fill="none" stroke="${colors.soil}" stroke-width="1.4" points="${soilPoints}"></polyline><polyline fill="none" stroke="${colors.light}" stroke-width="1.4" points="${lightPoints}"></polyline></svg><div class="chart-fallback-labels">${labels}</div></div>`;
}

function buildChartSeries() { const entries = appState.historyEntries; const multipleDays = hasMultipleDays(entries); return { labels: entries.map((entry) => formatHistoryLabel(entry.timestamp, multipleDays)), temperature: entries.map((e) => e.temp), humidity: entries.map((e) => e.humidity), soil: entries.map((e) => e.soil), light: entries.map((e) => e.light), height: entries.map((e) => e.height) }; }
function getCycleDays() { if (!appState.cropStart) return 0; const now = new Date(); const start = new Date(appState.cropStart); return Math.max(0, Math.round((now.setHours(0, 0, 0, 0) - start.setHours(0, 0, 0, 0)) / 86400000)); }
function getGrowthStage(days) { if (days <= 14) return "ระยะต้นกล้า"; if (days <= 35) return "ระยะเจริญเติบโต"; return "ระยะก่อนเก็บเกี่ยว"; }
function getModeLabel(mode) { return mode === "manual" ? "ควบคุมด้วยตนเอง" : mode === "standby" ? "พักระบบ" : "ควบคุมอัตโนมัติ"; }
function hasMultipleDays(entries) { return new Set(entries.map((entry) => entry.timestamp.slice(0, 10))).size > 1; }
function formatHistoryLabel(timestamp, multipleDays) { const date = new Date(timestamp); if (appState.isLiveMode) return date.toLocaleTimeString("th-TH", { hour: "2-digit", minute: "2-digit", second: "2-digit" }); if (multipleDays) return date.toLocaleDateString("th-TH", { day: "2-digit", month: "2-digit" }); return date.toLocaleTimeString("th-TH", { hour: "2-digit", minute: "2-digit" }); }
function formatDateThai(value) { if (!value) return "ยังไม่ได้ระบุ"; return new Date(value).toLocaleDateString("th-TH", { year: "numeric", month: "short", day: "numeric" }); }
function makeSvgPoints(values, min, max) { if (!values.length) return ""; return values.map((value, index) => { const x = values.length === 1 ? 50 : (index / (values.length - 1)) * 100; const y = 90 - ((clamp(value, min, max) - min) / (max - min)) * 75; return `${x.toFixed(2)},${y.toFixed(2)}`; }).join(" "); }
function clamp(value, min, max) { return Math.min(max, Math.max(min, value)); }
function showToast(message) { const wrap = document.getElementById("toastWrap"); if (!wrap) return; const toast = document.createElement("div"); toast.className = "toast"; toast.textContent = message; wrap.appendChild(toast); window.setTimeout(() => toast.remove(), 2400); }
function loadJSON(key, fallback) { try { return storageGet(key) ? { ...fallback, ...JSON.parse(storageGet(key)) } : fallback; } catch (e) { return fallback; } }
function storageGet(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } }
function storageSet(key, value) { try { window.localStorage.setItem(key, value); } catch (e) {} }
function getDefaultCropStart() { return ""; }
function writeText(id, value) { const node = document.getElementById(id); if (node) node.textContent = value; }
function createInitialSensorHealth() { return SENSOR_DEFINITIONS.reduce((acc, sensor) => { acc[sensor.key] = { lastUpdateStr: "", localUpdatedAt: 0 }; return acc; }, {}); }

function touchSensorHealth(data) {
    const now = Date.now(); const serverTimestampStr = data.last_update || ""; let shtHealth = appState.sensorHealth["sht31"];
    if (shtHealth.lastUpdateStr !== serverTimestampStr && (data.temp !== 0 || data.humidity !== 0)) { shtHealth.lastUpdateStr = serverTimestampStr; shtHealth.localUpdatedAt = now; }
    ["bh1750", "soil", "vl53l1x"].forEach(key => { let health = appState.sensorHealth[key]; if (health.lastUpdateStr !== serverTimestampStr) { health.lastUpdateStr = serverTimestampStr; health.localUpdatedAt = now; } });
}

function getSensorStatus(sensorKey) {
    const health = appState.sensorHealth[sensorKey]; let isOnline = health.localUpdatedAt > 0 && (Date.now() - health.localUpdatedAt <= SENSOR_OFFLINE_THRESHOLD_MS);
    if (sensorKey === "sht31" && (appState.metrics.temp <= 0 || appState.metrics.humidity <= 0)) isOnline = false; if (sensorKey === "bh1750" && appState.metrics.light < 0) isOnline = false; if (sensorKey === "soil" && appState.metrics.soil <= 0) isOnline = false; if (sensorKey === "vl53l1x" && (appState.metrics.height < 0 || appState.metrics.height > 50)) isOnline = false;
    return { online: isOnline };
}

function getSensorReadingLabel(sensorKey) {
    const isOnline = getSensorStatus(sensorKey).online;
    if (sensorKey === "sht31") { return `<div style="display: flex; flex-direction: column; gap: 8px; line-height: 1; margin-top: 4px;"><div style="display: flex; align-items: baseline; gap: 8px;"><span>${isOnline ? Number(appState.metrics.temp).toFixed(1) : "0.0"}</span><span style="font-size: 1.2rem; font-weight: 500;">°C</span></div><div style="display: flex; align-items: baseline; gap: 8px;"><span>${isOnline ? Number(appState.metrics.humidity).toFixed(1) : "0.0"}</span><span style="font-size: 1.2rem; font-weight: 500;">%RH</span></div></div>`; }
    if (sensorKey === "bh1750") return `${isOnline ? Math.round(appState.metrics.light).toLocaleString() : "0"} <span style="font-size: 1.2rem; font-weight: 500;">Lux</span>`;
    if (sensorKey === "soil") return `${isOnline ? Number(appState.metrics.soil).toFixed(1) : "0.0"} <span style="font-size: 1.2rem; font-weight: 500;">%</span>`;
    if (sensorKey === "vl53l1x") return `${isOnline ? Number(appState.metrics.height).toFixed(1) : "0.0"} <span style="font-size: 1.2rem; font-weight: 500;">cm</span>`;
    return "-";
}

let lastReceivedTimestamp = "";

window.loadCropCycle = function() {
    const isVisitor = document.body.dataset.page === "visitor";
    const apiPath = isVisitor ? 'api_crop_cycle.php' : '../api_crop_cycle.php';
    fetch(apiPath, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'get_active' }) })
    .then(res => res.json()).then(data => {
        const cropDateInput = document.getElementById('cropStartDate');
        if(data.status === 'success') {
            appState.cropStart = data.start_date; appState.gddAcc = parseFloat(data.gdd_acc) || 0; appState.wurPercentDB = parseInt(data.wur_percent) || 0; 
            if (cropDateInput) cropDateInput.value = data.start_date;
            if (typeof toggleCropFormUI === 'function') toggleCropFormUI(true); 
        } else {
            appState.cropStart = ""; appState.gddAcc = 0; appState.wurPercentDB = 0;
            if (cropDateInput) cropDateInput.value = new Date().toISOString().split('T')[0];
            if (typeof toggleCropFormUI === 'function') toggleCropFormUI(false); 
        }
        renderCycleMeta(); 
        if (!isVisitor) renderHarvestSection(); 
    }).catch(e => console.error(e));
};

function startRealtimeUpdates() { loadRealtimeSensors(); loadDeviceStatus(); setInterval(() => { loadRealtimeSensors(); loadDeviceStatus(); }, 3000); }

function loadDeviceStatus() {
    const isVisitor = document.body.dataset.page === "visitor"; const apiPath = isVisitor ? 'api_get_device_status.php' : '../api_get_device_status.php';
    fetch(apiPath + '?t=' + new Date().getTime()).then(res => res.json()).then(data => {
        if (data && data.devices) { appState.deviceStatus = data.devices; if (data.mode) { appState.mode = data.mode; } renderDeviceStates(document.body.dataset.page === "admin"); }
    }).catch(e => console.log('Error'));
}

function loadRealtimeSensors() {
    const isVisitor = document.body.dataset.page === "visitor"; const jsonPath = isVisitor ? 'sensor_realtime_cache.json' : '../sensor_realtime_cache.json';
    fetch(jsonPath + '?t=' + new Date().getTime()).then(res => { if (!res.ok) throw new Error('Error'); return res.json(); }).then(data => {
        if (typeof appState !== 'undefined') {
            if (appState.mode === "standby") return; 
            appState.metrics.temp = data.temp || 0; appState.metrics.humidity = data.humidity || 0; appState.metrics.soil = data.soil_moisture || 0; appState.metrics.light = data.light_lux || 0; 
            
            // --- ดึงข้อมูลเปอร์เซ็นต์จากเซิร์ฟเวอร์มาแสดง UI ---
            if (data.harvest_data) {
                appState.factors.readiness = data.harvest_data.readiness;
                appState.factors.gdd = data.harvest_data.gdd_percent;
                appState.factors.height = data.harvest_data.height_percent;
                appState.factors.water = data.harvest_data.wur_percent;
            }

            const SENSOR_INSTALL_HEIGHT = 33; 
            let rawDistance = data.plant_height || 0; let instantHeight = 0;
            if (rawDistance <= 0 || rawDistance > SENSOR_INSTALL_HEIGHT) { instantHeight = -1; } else { instantHeight = SENSOR_INSTALL_HEIGHT - rawDistance; }
            if (instantHeight !== -1) {
                if (appState.smoothedHeight === undefined || instantHeight > appState.smoothedHeight) { appState.smoothedHeight = instantHeight; }
                appState.metrics.height = appState.smoothedHeight;
            } else { appState.metrics.height = -1; }
            touchSensorHealth(data);
            if (appState.isLiveMode) {
                const serverTime = data.last_update || "";
                if (serverTime !== lastReceivedTimestamp) {
                    lastReceivedTimestamp = serverTime; const now = new Date();
                    appState.historyEntries.push({ timestamp: now.toISOString(), temp: appState.metrics.temp <= 0 ? 0 : Number(Number(appState.metrics.temp).toFixed(1)), humidity: appState.metrics.humidity <= 0 ? 0 : Number(Number(appState.metrics.humidity).toFixed(1)), soil: appState.metrics.soil <= 0 ? 0 : Number(Number(appState.metrics.soil).toFixed(1)), light: appState.metrics.light < 0 ? 0 : Number(Number(appState.metrics.light).toFixed(0)), height: (appState.metrics.height < 0 || appState.metrics.height > 50) ? 0 : Number(Number(appState.metrics.height).toFixed(1)) });
                    
                    if (appState.historyEntries.length > 50) { appState.historyEntries.shift(); } 
                    
                    updateChart();
                }
            }
        }
        if (typeof renderSensorStates === 'function') renderSensorStates();
        if (!isVisitor && typeof renderHarvestSection === 'function') renderHarvestSection();
        checkSensorAlerts();
    }).catch(error => console.log('Error'));
}

function checkSensorAlerts() {
    if (!appState.isLiveMode) return;
    let offlineSensors = [];
    if (appState.metrics.temp <= 0 || appState.metrics.humidity <= 0) offlineSensors.push("- SHT31 (อุณหภูมิ/ความชื้นขาดการเชื่อมต่อ)");
    if (appState.metrics.soil <= 0) offlineSensors.push("- Soil Moisture (เซ็นเซอร์ดินขาดการเชื่อมต่อ)");
    if (appState.metrics.height < 0 || appState.metrics.height > 50) offlineSensors.push("- VL53L1X (ระยะความสูงเพี้ยน)");
    if (offlineSensors.length > 0) {
        if (!appState.sensorAlertSent) {
            appState.sensorAlertSent = true;
            const apiPath = document.body.dataset.page === "visitor" ? 'api_telegram_alert.php' : '../api_telegram_alert.php';
            fetch(apiPath, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sensor_error: offlineSensors.join("\n") }) }).catch(e => console.log('Sensor Alert Failed'));
        }
    } else { appState.sensorAlertSent = false; }
}

window.testTelegramAlert = function() {
    const isVisitor = document.body.dataset.page === "visitor"; const apiPath = isVisitor ? 'api_telegram_alert.php' : '../api_telegram_alert.php';
    showToast("กำลังส่งข้อความทดสอบ...");
    fetch(apiPath, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sensor_error: "🛠 [TEST] ทดสอบระบบแจ้งเตือนจากหน้า Admin\n- ระบบ Telegram สามารถเชื่อมต่อและใช้งานได้ปกติ" }) })
    .then(async res => {
        const textRaw = await res.text(); 
        try { const data = JSON.parse(textRaw); if (data.status === 'success') { showToast("ส่งข้อความทดสอบสำเร็จ! เช็กมือถือได้เลย"); } else { alert("ระบบฟ้องว่า: " + data.message); } } catch (e) { alert("⚠️ เซิร์ฟเวอร์มีปัญหา (PHP Error):\n\n" + textRaw); }
    }).catch(e => { alert("เชื่อมต่อไฟล์ไม่ได้: " + e.message); });
};