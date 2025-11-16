<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análisis de Mortalidad</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f4f4f4; }
        h1 { text-align: center; }
        .container { 
            max-width: 1000px; margin: auto; background: #fff; padding: 20px; 
            border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1);
            position: relative; overflow: hidden; 
        }
        .action-buttons {
            position: absolute; top: 20px; right: 20px; z-index: 100;
            display: flex; gap: 10px;
        }
        .icon-button {
            font-size: 20px; font-weight: bold; text-decoration: none;
            color: #007bff; border: 2px solid #007bff; border-radius: 50%;
            width: 30px; height: 30px; text-align: center; line-height: 28px;
            cursor: pointer; transition: all 0.3s;
        }
        .icon-button:hover { background: #007bff; color: white; transform: scale(1.1); }
        #btnStats.open { transform: rotate(45deg); background: #007bff; color: white; }
        #btnSettings { line-height: 27px; } 
        
        #statsPanel {
            position: absolute; top: 0; right: -100%; width: 40%; min-width: 350px;
            height: 100%; background: #fdfdfd; border-left: 1px solid #ddd;
            box-shadow: -5px 0 15px rgba(0,0,0,0.1); transition: right 0.4s ease-in-out;
            z-index: 90; padding: 20px; padding-top: 80px; overflow-y: auto;
            box-sizing: border-box;
        }
        #statsPanel.open { right: 0; }
        #statsPanel h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        #stats-content { font-size: 14px; }
        #stats-content .stat-item { margin-bottom: 10px; }
        #stats-content .stat-item strong { display: inline-block; width: 130px; }
        #stats-content table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 15px; }
        #stats-content th, #stats-content td { border: 1px solid #ddd; padding: 4px 6px; text-align: left; }
        #stats-content th { background-color: #f9f9f9; }
        #download-buttons { margin-top: 20px; display: flex; flex-direction: column; gap: 10px; }
        #download-buttons button, #download-buttons a {
            display: block; width: 100%; box-sizing: border-box; padding: 10px 15px;
            text-decoration: none; background: #007bff; color: white; border: none;
            border-radius: 5px; cursor: pointer; font-size: 14px; text-align: center;
        }
        
        #settingsModal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 1000;
        }
        .modal-content {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 80%; max-width: 800px; height: 70vh;
            background: #fff; border-radius: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.3);
            display: flex; flex-direction: column;
        }
        .modal-header { padding: 15px 20px; border-bottom: 1px solid #ddd; }
        .modal-header h2 { margin: 0; }
        .modal-body { display: flex; flex-grow: 1; overflow: hidden; }
        .modal-sidebar {
            width: 40%; padding: 15px; border-right: 1px solid #eee;
            display: flex; flex-direction: column;
        }
        .modal-sidebar h4 { margin-top: 0; }
        .modal-main { width: 60%; padding: 15px; overflow-y: auto; }
        #selected-causes-list { list-style: none; padding: 0; margin: 0; overflow-y: auto; flex-grow: 1; }
        #selected-causes-list li, #cause-results-list li {
            padding: 5px; border-bottom: 1px solid #f0f0f0; font-size: 12px;
            display: flex; justify-content: space-between; align-items: center;
        }
        #cause-results-list { list-style: none; padding: 0; margin: 0; }
        #cause-results-list label { display: block; width: 100%; cursor: pointer; }
        .modal-footer { padding: 15px 20px; border-top: 1px solid #ddd; text-align: right; }
        .modal-footer button { padding: 10px 20px; cursor: pointer; }
        #filter-controls input, #filter-controls select {
            width: 100%; box-sizing: border-box; padding: 8px; margin-bottom: 10px;
        }
        .main-content { width: 100%; }
        .controls { display: flex; gap: 10px; margin-bottom: 20px; }
        .controls input { flex-grow: 1; padding: 10px; font-size: 16px; }
        .controls button { padding: 10px 15px; font-size: 16px; cursor: pointer; }
        #loading { text-align: center; font-size: 1.2em; display: none; }
        .charts { display: grid; grid-template-columns: 1fr; gap: 40px; margin-top: 20px; }
        .chart-container { position: relative; height: 50vh; width: 100%; }
        .info-banner {
            background: #e3f2fd; border-left: 4px solid #2196f3; padding: 12px;
            margin-bottom: 15px; border-radius: 4px;
        }
        /* Novedades para Tarta */
        #pie-chart-container {
            display: none; /* Oculto por defecto */
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #eee;
        }
        #pie-chart-container h3 { text-align: center; }
        #pie-controls { text-align: center; margin-bottom: 15px; font-size: 1.1em; }
        #pie-controls label { margin-right: 10px; }
        #pieYearSelector { font-size: 1em; padding: 5px; }
        .pie-chart-inner-container {
            position: relative; height: 50vh; width: 90%; max-width: 600px; margin: auto;
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="action-buttons">
            <span id="btnSettings" class="icon-button" title="Configurar Causas">⚙️</span>
            <span id="btnStats" class="icon-button" title="Ver estadísticas y descargas">+</span>
        </div>
    
        <div id="statsPanel">
            <h3>Estadísticas Detalladas</h3>
            <div id="stats-content">Busca un país para ver sus estadísticas.</div>
            <div id="download-buttons" style="display:none;">
                <a id="btnCSV" href="#">Exportar a CSV</a>
                <a id="btnExcel" href="#">Descargar como CSV (para Excel)</a>
                <button id="btnPNG1">Descargar Gráfica 1 (PNG)</button>
                <button id="btnPNG2">Descargar Gráfica 2 (PNG)</button>
            </div>
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
            <a href="dashboard.php" style="text-align:center; display:block; font-size: 14px;">Ver Dashboard Global</a>
        </div>

        <div class="main-content" id="mainContent">
            <h1>Análisis de Mortalidad por País</h1>
            
            <div class="info-banner">
                <strong>ℹ️ Sistema de Causas:</strong> Ahora se utilizan las causas globales que selecciones en el menú ⚙️ para todas las búsquedas.
            </div>
            
            <div class="controls">
                <input type="text" id="countryName" placeholder="Escribe el país, 'Global' o un continente...">
                <button id="btnBuscar">Buscar</button>
            </div>
            <div id="loading">Cargando datos...</div>
            <div id="error-message" style="color: red; text-align: center;"></div>
            
            <div class="charts">
                <div class="chart-container"><canvas id="chartMuertesVsPoblacion"></canvas></div>
                <div class="chart-container" id="chartInternetContainer"><canvas id="chartTasaVsInternet"></canvas></div>
            </div>

            <div id="pie-chart-container">
                <h3>Desglose de Causas por Año</h3>
                <div id="pie-controls">
                    <label for="pieYearSelector">Seleccionar Año:</label>
                    <select id="pieYearSelector"></select>
                </div>
                <div class="pie-chart-inner-container">
                    <canvas id="chartPie"></canvas>
                </div>
            </div>

        </div>
    </div>

    <div id="settingsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Configurar Causas de Muerte</h2>
                <p style="margin: 5px 0 0 0; font-size: 13px; color: #666;" id="modal-country-info">
                    Mostrando todas las causas globales disponibles.
                </p>
            </div>
            <div class="modal-body">
                <div class="modal-sidebar">
                    <h4>Causas Seleccionadas</h4>
                    <p style="font-size: 12px; color: #555;">Tu selección se guarda en este navegador.</p>
                    
                    <div style="padding: 10px; background: #f9f9f9; border-radius: 5px; margin-bottom: 10px;">
                        <label>
                            <input type="checkbox" id="toggleInternetGraph">
                            Mostrar gráfica de Internet
                        </label>
                    </div>
                    
                    <button id="btnDeselectAll" style="margin-bottom: 10px;">Deseleccionar Todo</button>
                    <ul id="selected-causes-list"></ul>
                </div>
                <div class="modal-main">
                    <div id="filter-controls">
                        <input type="text" id="cause-search-box" placeholder="Buscar por nombre o código...">
                        <select id="revision-filter">
                            <option value="">Todas las revisiones</option>
                            <option value="ICD-10">CIE-10</option>
                            <option value="ICD-9">CIE-9</option>
                            <option value="ICD-8">CIE-8</option>
                            <option value="ICD-7">CIE-7</option>
                        </select>
                    </div>
                    <ul id="cause-results-list"></ul>
                </div>
            </div>
            <div class="modal-footer">
                <button id="btnModalClose">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        let chart1, chart2, chartPie;
        let currentCountryName = '';
        let statsLoaded = false;
        let allCausesData = [];
        let selectedCauses = [];
        
        // --- NUEVAS GLOBALES ---
        let showInternetGraph = true;
        let currentAggregatedData = [];
        let currentBreakdownData = [];
        let causeDescriptionMap = new Map();

        document.addEventListener('DOMContentLoaded', () => {
            loadSelectedCauses();
            loadSettings();
            loadAllCauses(); // Carga todas las causas globales al inicio
            
            const urlParams = new URLSearchParams(window.location.search);
            const countryFromUrl = urlParams.get('country');
            if (countryFromUrl) {
                document.getElementById('countryName').value = countryFromUrl;
                fetchData();
            }
        });

        function loadSelectedCauses() {
            const saved = localStorage.getItem('selectedCauses');
            if (saved) {
                let tempCauses = JSON.parse(saved);
                if (tempCauses.length > 0 && !tempCauses[0].includes('::')) {
                    console.warn("Formato de causas obsoleto detectado. Limpiando localStorage.");
                    selectedCauses = [];
                    localStorage.setItem('selectedCauses', '[]');
                } else {
                    selectedCauses = tempCauses;
                }
            } else {
                selectedCauses = [];
                localStorage.setItem('selectedCauses', '[]');
            }
        }
        
        // --- NUEVA: Cargar/Guardar Ajustes (Gráfica Internet) ---
        function loadSettings() {
            const saved = localStorage.getItem('showInternetGraph');
            showInternetGraph = saved !== null ? JSON.parse(saved) : true;
            document.getElementById('toggleInternetGraph').checked = showInternetGraph;
        }

        function saveSettings() {
            localStorage.setItem('showInternetGraph', JSON.stringify(showInternetGraph));
        }

        async function loadAllCauses() {
            try {
                const response = await fetch('get_causes.php');
                allCausesData = await response.json();
                if (allCausesData.error) {
                    console.warn("Advertencia: " + allCausesData.error);
                    allCausesData = [];
                }
                
                // --- NUEVO: Crear mapa de descripciones para la tarta ---
                causeDescriptionMap.clear();
                allCausesData.forEach(c => {
                    const short_code = c.code.split('::')[1];
                    causeDescriptionMap.set(c.code, `[${c.list}] ${short_code} (${c.rev})`);
                });
                
                renderCauseLists();
            } catch (error) {
                console.error("Error al cargar la lista de causas:", error);
            }
        }
        
        // --- ELIMINADA: `loadCausesForCountry` ya no es necesaria ---

        document.getElementById('btnBuscar').addEventListener('click', fetchData);
        document.getElementById('countryName').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); fetchData(); }
        });
        document.getElementById('btnStats').addEventListener('click', toggleStatsPanel);
        document.getElementById('btnSettings').addEventListener('click', openSettingsModal);
        document.getElementById('btnModalClose').addEventListener('click', closeSettingsModal);
        document.getElementById('btnDeselectAll').addEventListener('click', () => {
            selectedCauses = [];
            localStorage.setItem('selectedCauses', '[]');
            renderCauseLists();
        });
        document.getElementById('cause-search-box').addEventListener('input', renderCauseLists);
        document.getElementById('revision-filter').addEventListener('change', renderCauseLists);
        
        // --- NUEVO: Listener para el toggle de Internet ---
        document.getElementById('toggleInternetGraph').addEventListener('change', (e) => {
            showInternetGraph = e.target.checked;
            saveSettings();
            // Re-renderizar las gráficas si ya hay datos cargados
            if (currentAggregatedData.length > 0) {
                renderCharts(currentAggregatedData, currentCountryName);
            }
        });

        function openSettingsModal() {
            document.getElementById('settingsModal').style.display = 'block';
            renderCauseLists();
        }

        function closeSettingsModal() {
            document.getElementById('settingsModal').style.display = 'none';
        }

        function renderCauseLists() {
            const resultsList = document.getElementById('cause-results-list');
            const selectedList = document.getElementById('selected-causes-list');
            const filterText = document.getElementById('cause-search-box').value.toLowerCase();
            const filterRev = document.getElementById('revision-filter').value;

            resultsList.innerHTML = '';
            selectedList.innerHTML = '';
            
            if (!allCausesData || allCausesData.length === 0) {
                resultsList.innerHTML = "<li style='padding: 20px; text-align: center; color: #888;'>Cargando causas...</li>";
                return;
            }

            allCausesData.forEach(cause => {
                const isSelected = selectedCauses.includes(cause.code);
                const matchesText = cause.desc.toLowerCase().includes(filterText) || cause.code.toLowerCase().includes(filterText);
                const matchesRev = (filterRev === "") || (cause.rev === filterRev);

                if (matchesText && matchesRev) {
                    const li = document.createElement('li');
                    const short_code = cause.code.split('::')[1];
                    li.innerHTML = `
                        <label>
                            <input type="checkbox" data-code="${cause.code}" ${isSelected ? 'checked' : ''}>
                            <strong>[${cause.list}] ${short_code}</strong> (${cause.rev}): ${cause.desc}
                        </label>
                    `;
                    resultsList.appendChild(li);
                }

                if (isSelected) {
                    const li = document.createElement('li');
                    const short_code = cause.code.split('::')[1];
                    li.innerHTML = `
                        <span><strong>[${cause.list}] ${short_code}</strong> (${cause.rev})</span>
                        <input type="checkbox" data-code="${cause.code}" checked>
                    `;
                    selectedList.appendChild(li);
                }
            });

            document.querySelectorAll('#settingsModal input[type="checkbox"]').forEach(cb => {
                cb.addEventListener('change', handleCauseSelection);
            });
        }

        function handleCauseSelection(e) {
            const code = e.target.dataset.code;
            const isChecked = e.target.checked;
            if (isChecked) {
                if (!selectedCauses.includes(code)) { selectedCauses.push(code); }
            } else {
                selectedCauses = selectedCauses.filter(c => c !== code);
            }
            localStorage.setItem('selectedCauses', JSON.stringify(selectedCauses));
            renderCauseLists();
        }

        function toggleStatsPanel() {
            const panel = document.getElementById('statsPanel');
            const button = document.getElementById('btnStats');
            const isOpen = panel.classList.toggle('open');
            button.classList.toggle('open');
            if (isOpen && !statsLoaded && currentCountryName) {
                fetchStats();
            }
        }

        async function fetchData() {
            currentCountryName = document.getElementById('countryName').value;
            if (!currentCountryName) {
                alert("Por favor, introduce un país, región o 'Global'.");
                return;
            }
            
            // --- LÓGICA DE CAUSAS SIMPLIFICADA ---
            if (selectedCauses.length === 0) {
                alert("No hay causas seleccionadas. Por favor, seleccione al menos una causa en el menú de configuración (⚙️) antes de buscar.");
                openSettingsModal();
                return;
            }

            statsLoaded = false;
            document.getElementById('loading').style.display = 'block';
            document.getElementById('loading').innerText = 'Cargando datos...';
            document.getElementById('error-message').innerText = '';
            
            if (document.getElementById('statsPanel').classList.contains('open')) {
                toggleStatsPanel();
            }
            document.getElementById('stats-content').innerHTML = "Busca un país para ver sus estadísticas.";
            document.getElementById('download-buttons').style.display = 'none';

            if (chart1) chart1.destroy();
            if (chart2) chart2.destroy();
            if (chartPie) chartPie.destroy();
            document.getElementById('pie-chart-container').style.display = 'none';

            try {
                const response = await fetch('get_data.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        country: currentCountryName,
                        causes: selectedCauses
                    })
                });
                
                if (!response.ok) throw new Error(`Error del servidor: ${response.statusText}`);
                
                const result = await response.json();
                if (result.status === 'error') throw new Error(result.message);
                if (!result.data || result.data.length === 0) {
                    throw new Error(`No se encontraron datos para '${currentCountryName}' con las ${selectedCauses.length} causas seleccionadas.`);
                }
                
                // --- NUEVO: Guardar datos globalmente ---
                currentAggregatedData = result.data;
                currentBreakdownData = result.breakdown;
                
                renderCharts(currentAggregatedData, currentCountryName);
                renderPieSection(currentBreakdownData); // <-- NUEVA LLAMADA
                
                setupDownloadLinks(currentCountryName);
                document.getElementById('download-buttons').style.display = 'flex';
                document.getElementById('stats-content').innerHTML = "Haz clic en el '+' para cargar las estadísticas.";

            } catch (error) {
                console.error('Error al buscar datos:', error);
                document.getElementById('error-message').innerText = `${error.message}`;
            } finally {
                document.getElementById('loading').style.display = 'none';
            }
        }

        async function fetchStats() {
            // ... (Esta función no necesita cambios, `get_stats.php` ya está bien)
            const statsContent = document.getElementById('stats-content');
            statsContent.innerHTML = "Calculando estadísticas...";
            statsLoaded = true;

            try {
                const response = await fetch('get_stats.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        country: currentCountryName,
                        causes: selectedCauses
                    })
                });

                if (!response.ok) throw new Error(`Error del servidor: ${response.statusText}`);
                
                const result = await response.json();
                if (result.status === 'error') throw new Error(result.message);
                
                renderStats(result.data);

            } catch (error) {
                console.error('Error al buscar stats:', error);
                statsContent.innerHTML = `<span style="color:red">Error: ${error.message}</span>`;
            }
        }

        function renderStats(data) {
            // ... (Esta función no necesita cambios)
             const statsContent = document.getElementById('stats-content');
            const summary = data.summary;
            const format = (val) => val ? parseFloat(val).toLocaleString('es-ES', { maximumFractionDigits: 0 }) : 'N/A';
            const formatPct = (val) => val ? parseFloat(val).toFixed(2) + '%' : 'N/A';
            let html = '<h3>Resumen del Período</h3>';
            html += '<div class="stat-item"><strong>Período:</strong> ' + (summary.start_year || 'N/A') + ' - ' + (summary.end_year || 'N/A') + '</div>';
            html += '<div class="stat-item"><strong>Muertes (Media):</strong> ' + format(summary.avg_deaths) + ' / año</div>';
            html += '<div class="stat-item"><strong>Muertes (Máx):</strong> ' + format(summary.max_deaths) + ' (en ' + summary.max_deaths_year + ')</div>';
            html += '<div class="stat-item"><strong>Muertes (Mín):</strong> ' + format(summary.min_deaths) + ' (en ' + summary.min_deaths_year + ')</div>';
            html += '<div class="stat-item"><strong>Uso Internet (Media):</strong> ' + formatPct(summary.avg_internet) + '</div>';
            
            let topCauseDisplay = 'N/A';
            if (summary.top_cause_list && summary.top_cause_code) {
                 topCauseDisplay = `[${summary.top_cause_list}] ${summary.top_cause_code}`;
            }
            html += '<div class="stat-item"><strong>Causa Max (Total):</strong> ' + topCauseDisplay + ' (' + format(summary.top_cause_overall_count) + ' muertes)</div>';
            
            html += '<h3>Causa Principal por Año</h3>';
            html += '<table>';
            html += '<tr><th>Año</th><th>Lista</th><th>Causa</th><th>Muertes</th></tr>';
            if (data.top_causes_by_year && data.top_causes_by_year.length > 0) {
                data.top_causes_by_year.forEach(item => {
                    html += `<tr><td>${item.Year}</td><td>${item.List}</td><td>${item.Cause}</td><td>${format(item.Cause_Total)}</td></tr>`;
                });
            } else {
                html += '<tr><td colspan="4">No hay datos de causas por año.</td></tr>';
            }
            html += '</table>';
            statsContent.innerHTML = html;
        }

        function renderCharts(data, countryName) {
            const labels = data.map(row => row.Year);
            const totalDeaths = data.map(row => row.TotalDeaths);
            const totalPopulation = data.map(row => row.TotalPopulation);
            const internetUsage = data.map(row => row.Porcentaje_Uso);
            const mortalityRate = data.map(row => (parseFloat(row.TotalPopulation) > 0) ? (parseFloat(row.TotalDeaths) / parseFloat(row.TotalPopulation)) * 100000 : 0);
            
            if (chart1) chart1.destroy();
            if (chart2) chart2.destroy();
            
            // --- GRÁFICA 1 ---
            const ctx1 = document.getElementById('chartMuertesVsPoblacion').getContext('2d');
            chart1 = new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Total Muertes (Causas Sel.)', data: totalDeaths, borderColor: 'rgb(255, 99, 132)', yAxisID: 'yDeaths' },
                        { label: 'Población Total (País)', data: totalPopulation, borderColor: 'rgb(54, 162, 235)', yAxisID: 'yPopulation' }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { 
                        title: { display: true, text: `Muertes (Total Causas Sel.) vs Población en ${countryName}` } 
                    },
                    scales: {
                        yDeaths: { type: 'linear', display: true, position: 'left', title: { display: true, text: 'Nº Muertes (Causas Sel.)' } },
                        yPopulation: { type: 'linear', display: true, position: 'right', title: { display: true, text: 'Nº Habitantes' }, grid: { drawOnChartArea: false } }
                    }
                }
            });
            
            // --- GRÁFICA 2 (Internet) ---
            const chartInternetContainer = document.getElementById('chartInternetContainer');
            const btnPNG2 = document.getElementById('btnPNG2');
            
            if (showInternetGraph) {
                chartInternetContainer.style.display = 'block';
                btnPNG2.style.display = 'block';
                const ctx2 = document.getElementById('chartTasaVsInternet').getContext('2d');
                chart2 = new Chart(ctx2, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            { label: 'Tasa Mortalidad (x 100k, Causas Sel.)', data: mortalityRate, borderColor: 'rgb(255, 159, 64)', yAxisID: 'yRate' },
                            { label: '% Uso Internet (Media País)', data: internetUsage, borderColor: 'rgb(75, 192, 192)', yAxisID: 'yInternet' }
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { 
                            title: { display: true, text: `Tasa Mortalidad (Causas Sel.) vs Uso de Internet en ${countryName}` } 
                        },
                        scales: {
                            yRate: { type: 'linear', display: true, position: 'left', title: { display: true, text: 'Tasa x 100k hab.' } },
                            yInternet: { type: 'linear', display: true, position: 'right', title: { display: true, text: '% Internet' }, grid: { drawOnChartArea: false } }
                        }
                    }
                });
            } else {
                // Ocultar la gráfica de internet
                if (chart2) chart2.destroy();
                chartInternetContainer.style.display = 'none';
                btnPNG2.style.display = 'none';
            }
        }
        
        // --- NUEVA: Renderizar sección de Tarta ---
        function renderPieSection(breakdownData) {
            const pieContainer = document.getElementById('pie-chart-container');
            const yearSelector = document.getElementById('pieYearSelector');
            
            if (!breakdownData || breakdownData.length === 0) {
                pieContainer.style.display = 'none';
                return;
            }
            
            pieContainer.style.display = 'block';
            yearSelector.innerHTML = '';
            
            // Obtener años únicos y ordenarlos (más reciente primero)
            const years = [...new Set(breakdownData.map(d => d.Year))].sort((a, b) => b - a);
            
            years.forEach(year => {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                yearSelector.appendChild(option);
            });
            
            // Quitar listeners antiguos para evitar duplicados
            const newSelector = yearSelector.cloneNode(true);
            yearSelector.parentNode.replaceChild(newSelector, yearSelector);
            
            newSelector.addEventListener('change', () => {
                drawPieChart(breakdownData, newSelector.value);
            });
            
            // Dibujar la tarta para el primer año (el más reciente)
            drawPieChart(breakdownData, years[0]);
        }
        
        // --- NUEVA: Dibujar la Tarta ---
        function drawPieChart(breakdownData, selectedYear) {
            if (chartPie) chartPie.destroy();
            
            const yearData = breakdownData.filter(d => d.Year == selectedYear);
            
            const labels = yearData.map(d => {
                const compositeCode = `${d.List}::${d.Cause}`;
                return causeDescriptionMap.get(compositeCode) || compositeCode; // Usar el mapa
            });
            const data = yearData.map(d => d.Deaths);
            const totalDeaths = data.reduce((a, b) => a + Number(b), 0);

            const ctx = document.getElementById('chartPie').getContext('2d');
            chartPie = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        // Colores automáticos (Chart.js los pondrá)
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: `Desglose de ${totalDeaths.toLocaleString('es-ES')} muertes (Causas Sel.) en ${selectedYear}`
                        },
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        const value = context.parsed;
                                        const percentage = (value / totalDeaths * 100).toFixed(2);
                                        label += `${value.toLocaleString('es-ES')} muertes (${percentage}%)`;
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }

        function setupDownloadLinks(countryName) {
            document.getElementById('btnCSV').onclick = () => downloadFile('csv');
            document.getElementById('btnExcel').onclick = () => downloadFile('csv');
            document.getElementById('btnPNG1').onclick = () => downloadChart(chart1, `grafica1_${countryName}.png`);
            
            // Asignar descarga de PNG2 solo si la gráfica es visible
            const btnPNG2 = document.getElementById('btnPNG2');
            if (showInternetGraph) {
                btnPNG2.style.display = 'block';
                btnPNG2.onclick = () => downloadChart(chart2, `grafica2_${countryName}.png`);
            } else {
                btnPNG2.style.display = 'none';
            }
        }

        async function downloadFile(format) {
             // ... (Esta función no necesita cambios)
            try {
                const response = await fetch('export.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        country: currentCountryName,
                        causes: selectedCauses
                    })
                });
                if (!response.ok) throw new Error('Error al generar el archivo');
                
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = `datos_${currentCountryName.replace(/ /g, '_')}.csv`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                a.remove();
                
            } catch (error) {
                console.error("Error al descargar:", error);
                alert("No se pudo descargar el archivo.");
            }
        }

        function downloadChart(chartInstance, filename) {
             // ... (Esta función no necesita cambios)
            const a = document.createElement('a');
            a.href = chartInstance.toBase64Image();
            a.download = filename;
            a.click();
        }
    </script>
</body>
</html>