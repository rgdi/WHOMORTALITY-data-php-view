<?php
// Conectarse a la base de datos para obtener la lista de países
require_once 'config.php';
try {
    $pdo = get_db_connection();
    $stmt = $pdo->query("SELECT Codigo_Pais, Nombre FROM Paises WHERE Nombre IS NOT NULL AND Nombre != '' ORDER BY Nombre");
    $paises = $stmt->fetchAll();
} catch (Exception $e) {
    die("Error al conectar o consultar la base de datos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Global</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Arial, sans-serif; background: #e9e9e9; }
        h1 { text-align: center; color: #333; }
        .dashboard-container {
            display: flex; flex-direction: column; align-items: center;
            gap: 20px; padding: 20px;
        }
        .country-block {
            width: 90%; max-width: 1200px; background: #fff;
            border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            padding: 15px; min-height: 400px; position: relative; overflow: hidden;
        }
        .country-block h2 { text-align: center; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; }
        .charts { display: grid; grid-template-columns: 1fr; gap: 20px; margin-top: 20px; }
        .chart-container { position: relative; height: 40vh; width: 100%; }
        .loading-message { text-align: center; font-size: 1.2em; color: #888; padding: 40px; }
        .stats-button {
            position: absolute; top: 15px; right: 15px; font-size: 24px; font-weight: bold;
            text-decoration: none; color: #007bff; border: 2px solid #007bff;
            border-radius: 50%; width: 30px; height: 30px; text-align: center;
            line-height: 28px; cursor: pointer; transition: all 0.3s; z-index: 100;
        }
        .stats-button.open { transform: rotate(45deg); background: #007bff; color: white; }
        .stats-panel {
            position: absolute; top: 0; right: -100%; width: 40%; min-width: 350px;
            height: 100%; background: #fdfdfd; border-left: 1px solid #ddd;
            box-shadow: -5px 0 15px rgba(0,0,0,0.1); transition: right 0.4s ease-in-out;
            z-index: 90; padding: 20px; padding-top: 80px; overflow-y: auto;
            box-sizing: border-box;
        }
        .stats-panel.open { right: 0; }
        .stats-panel h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .stats-content { font-size: 14px; }
        .stats-content .stat-item { margin-bottom: 10px; }
        .stats-content .stat-item strong { display: inline-block; width: 130px; }
        .stats-content table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 15px; }
        .stats-content th, .stats-content td { border: 1px solid #ddd; padding: 4px 6px; text-align: left; }
        .stats-content th { background-color: #f9f9f9; }
        
        .global-settings {
            position: fixed; top: 20px; right: 20px; z-index: 1001;
        }
        .global-settings .icon-button { background: white; }
        
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
        
        /* NUEVO: Estilos para Tarta en Dashboard */
        .pie-chart-container {
            display: none; /* Oculto por defecto */
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        .pie-chart-container h4 { text-align: center; margin-bottom: 10px; }
        .pie-controls { text-align: center; margin-bottom: 10px; }
        .pie-controls label { margin-right: 5px; }
        .pie-controls select { font-size: 0.9em; padding: 3px; }
        .pie-chart-inner-container {
            position: relative; height: 40vh; width: 80%; max-width: 500px; margin: auto;
        }
    </style>
</head>
<body>
    
    <div class="global-settings">
        <a href="index.php" class="icon-button" title="Volver al Buscador" style="margin-right: 10px;">🏠</a>
        <span id="btnSettings" class="icon-button" title="Configurar Causas">⚙️</span>
    </div>

    <h1>Dashboard de Mortalidad Global</h1>
    <div class="dashboard-container">
        <?php foreach ($paises as $pais): ?>
            <?php 
                $country_name = htmlspecialchars($pais['Nombre']);
                $country_id_safe = 'c_' . preg_replace('/[^a-zA-Z0-9]/', '', $pais['Codigo_Pais']);
            ?>
            
            <div class="country-block" 
                 data-country-name="<?php echo $country_name; ?>" 
                 data-country-id-safe="<?php echo $country_id_safe; ?>">
                
                <h2><?php echo $country_name; ?></h2>
                
                <span class="stats-button" data-target-panel="panel_<?php echo $country_id_safe; ?>">+</span>
                <div class="stats-panel" id="panel_<?php echo $country_id_safe; ?>">
                    <h3>Estadísticas: <?php echo $country_name; ?></h3>
                    <div class="stats-content">Cargando...</div>
                </div>

                <div class="main-content">
                    <div class="charts">
                        <div class="chart-container"><canvas id="chart1_<?php echo $country_id_safe; ?>"></canvas></div>
                        <div class="chart-container" id="chart2_container_<?php echo $country_id_safe; ?>"><canvas id="chart2_<?php echo $country_id_safe; ?>"></canvas></div>
                    </div>
                    <div class="loading-message">Esperando a ser visible...</div>
                    
                    <div id="pie_container_<?php echo $country_id_safe; ?>" class="pie-chart-container">
                        <h4>Desglose de Causas por Año</h4>
                        <div class="pie-controls">
                            <label>Año: <select id="pie_selector_<?php echo $country_id_safe; ?>"></select></label>
                        </div>
                        <div class="pie-chart-inner-container">
                            <canvas id="pie_chart_<?php echo $country_id_safe; ?>"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="settingsModal">
        <div class="modal-content">
            <div class="modal-header"><h2>Configurar Causas de Muerte</h2></div>
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
        // --- Variables Globales ---
        let allCausesData = [];
        let selectedCauses = [];
        let showInternetGraph = true; // NUEVO
        let causeDescriptionMap = new Map(); // NUEVO
        let chartPieInstances = {}; // NUEVO

        // --- Inicialización ---
        document.addEventListener("DOMContentLoaded", () => {
            loadSelectedCauses();
            loadSettings(); // NUEVO
            loadAllCauses(); 

            const options = { root: null, rootMargin: '0px', threshold: 0.1 };
            const observerCallback = (entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const block = entry.target;
                        observer.unobserve(block); 
                        
                        const countryName = block.dataset.countryName;
                        const idSafe = block.dataset.countryIdSafe;
                        const loadingElement = block.querySelector('.loading-message');

                        fetchAndRenderCharts(countryName, `chart1_${idSafe}`, `chart2_${idSafe}`, `chart2_container_${idSafe}`, idSafe, loadingElement);
                        
                        const statsButton = block.querySelector('.stats-button');
                        statsButton.addEventListener('click', toggleStatsPanel);
                    }
                });
            };

            const observer = new IntersectionObserver(observerCallback, options);
            const countryBlocks = document.querySelectorAll('.country-block');
            countryBlocks.forEach(block => observer.observe(block));
            
            // Listeners del modal global
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
                // Opcional: recargar la página para aplicar el cambio a todo
                // location.reload(); 
                // O simplemente se aplicará a los nuevos charts que se carguen
            });
        });
        
        // --- Lógica de Carga de Causas (LocalStorage y Servidor) ---
        
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
                    alert("Error fatal: " + allCausesData.error);
                    return;
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

        // --- Lógica del Modal (Idéntica a index.php) ---
        function openSettingsModal() { document.getElementById('settingsModal').style.display = 'block'; }
        function closeSettingsModal() { document.getElementById('settingsModal').style.display = 'none'; }
        
        function renderCauseLists() {
            const resultsList = document.getElementById('cause-results-list');
            const selectedList = document.getElementById('selected-causes-list');
            const filterText = document.getElementById('cause-search-box').value.toLowerCase();
            const filterRev = document.getElementById('revision-filter').value;
            resultsList.innerHTML = '';
            selectedList.innerHTML = '';
            
            if (!allCausesData || allCausesData.length === 0) {
                resultsList.innerHTML = "Cargando causas...";
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
                        </label>`;
                    resultsList.appendChild(li);
                }
                
                if (isSelected) {
                    const li = document.createElement('li');
                    const short_code = cause.code.split('::')[1];
                    
                    li.innerHTML = `
                        <span><strong>[${cause.list}] ${short_code}</strong> (${cause.rev})</span> 
                        <input type="checkbox" data-code="${cause.code}" checked>`;
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

        // --- FUNCIONES DE PANEL (Para múltiples paneles) ---
        function toggleStatsPanel(event) {
            // ... (Esta función no necesita cambios)
            const button = event.currentTarget;
            const panelId = button.dataset.targetPanel;
            const panel = document.getElementById(panelId);
            const block = button.closest('.country-block');
            const countryName = block.dataset.countryName;
            
            const isOpen = panel.classList.toggle('open');
            button.classList.toggle('open');
            
            if (isOpen && !button.dataset.statsLoaded) {
                button.dataset.statsLoaded = "true"; 
                const statsContent = panel.querySelector('.stats-content');
                fetchStats(countryName, statsContent);
            }
        }

        async function fetchStats(countryName, statsContentElement) {
             // ... (Esta función no necesita cambios)
            statsContentElement.innerHTML = "Calculando estadísticas...";
            try {
                const response = await fetch('get_stats.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        country: countryName,
                        causes: selectedCauses 
                    })
                });
                if (!response.ok) throw new Error(`Error del servidor: ${response.statusText}`);
                
                const result = await response.json();
                if (result.status === 'error') throw new Error(result.message);
                
                renderStats(result.data, statsContentElement); 

            } catch (error) {
                console.error('Error al buscar stats:', error);
                statsContentElement.innerHTML = `<span style="color:red">Error: ${error.message}</span>`;
            }
        }
        
        function renderStats(data, statsContentElement) {
            // ... (Esta función no necesita cambios)
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
            statsContentElement.innerHTML = html;
        }

        // --- FUNCIONES DE GRÁFICAS (Usando POST) ---
        async function fetchAndRenderCharts(countryName, canvasId1, canvasId2, canvas2ContainerId, countryIdSafe, loadingElement) {
            if (selectedCauses.length === 0) {
                loadingElement.innerText = "No hay causas seleccionadas. Configurelas (⚙️) y recargue.";
                loadingElement.style.color = 'red';
                return;
            }
            
            try {
                loadingElement.innerText = "Cargando datos...";

                const response = await fetch('get_data.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        country: countryName,
                        causes: selectedCauses 
                    })
                });

                if (!response.ok) throw new Error(`Error del servidor: ${response.statusText}`);
                
                const result = await response.json();
                if (result.status === 'error' || !result.data || result.data.length === 0) {
                    throw new Error(`No se encontraron datos para '${countryName}' con las causas seleccionadas.`);
                }
                
                loadingElement.style.display = 'none';
                
                // Renderizar Gráficas de Líneas
                renderIndividualChart(result.data, canvasId1, 'Muertes vs Población', countryName);
                
                const canvas2Container = document.getElementById(canvas2ContainerId);
                if (showInternetGraph) {
                    canvas2Container.style.display = 'block';
                    renderIndividualChart(result.data, canvasId2, 'Tasa vs Internet', countryName);
                } else {
                    canvas2Container.style.display = 'none';
                }
                
                // --- NUEVO: Renderizar Tarta ---
                renderPieSectionDashboard(countryIdSafe, result.breakdown);

            } catch (error) {
                console.error('Error al cargar país:', error);
                loadingElement.innerText = `Error: ${error.message}`;
                loadingElement.style.color = 'red';
            }
        }

        function renderIndividualChart(data, canvasId, type, countryName) {
            const labels = data.map(row => row.Year);
            const ctx = document.getElementById(canvasId).getContext('2d');
            
            if (type === 'Muertes vs Población') {
                const totalDeaths = data.map(row => row.TotalDeaths);
                const totalPopulation = data.map(row => row.TotalPopulation);
                new Chart(ctx, {
                    type: 'line',
                    data: { labels: labels, datasets: [
                        { label: 'Total Muertes (Causas Sel.)', data: totalDeaths, borderColor: 'rgb(255, 99, 132)', yAxisID: 'yDeaths' },
                        { label: 'Población Total (País)', data: totalPopulation, borderColor: 'rgb(54, 162, 235)', yAxisID: 'yPopulation' }
                    ]},
                    options: { 
                        responsive: true, maintainAspectRatio: false, 
                        plugins: { title: { display: true, text: `Muertes (Causas Sel.) vs Población` } }, 
                        scales: { 
                            yDeaths: { type: 'linear', display: true, position: 'left', title: {display: true, text: 'Nº Muertes'} }, 
                            yPopulation: { type: 'linear', display: true, position: 'right', grid: { drawOnChartArea: false }, title: {display: true, text: 'Nº Habitantes'} } 
                        }
                    }
                });
            } else if (type === 'Tasa vs Internet') {
                const internetUsage = data.map(row => row.Porcentaje_Uso);
                const mortalityRate = data.map(row => {
                    const pop = parseFloat(row.TotalPopulation);
                    const deaths = parseFloat(row.TotalDeaths);
                    return (pop > 0) ? (deaths / pop) * 100000 : 0;
                });
                new Chart(ctx, {
                    type: 'line',
                    data: { labels: labels, datasets: [
                        { label: 'Tasa Mortalidad (x 100k, Causas Sel.)', data: mortalityRate, borderColor: 'rgb(255, 159, 64)', yAxisID: 'yRate' },
                        { label: '% Uso Internet (Media País)', data: internetUsage, borderColor: 'rgb(75, 192, 192)', yAxisID: 'yInternet' }
                    ]},
                    options: { 
                        responsive: true, maintainAspectRatio: false, 
                        plugins: { title: { display: true, text: `Tasa Mortalidad (Causas Sel.) vs % Internet` } },
                        scales: { 
                            yRate: { type: 'linear', display: true, position: 'left', title: {display: true, text: 'Tasa x 100k'} }, 
                            yInternet: { type: 'linear', display: true, position: 'right', grid: { drawOnChartArea: false }, title: {display: true, text: '% Internet'} } 
                        }
                    }
                });
            }
        }
        
        // --- NUEVA: Renderizar sección de Tarta (Dashboard) ---
        function renderPieSectionDashboard(countryIdSafe, breakdownData) {
            const pieContainer = document.getElementById(`pie_container_${countryIdSafe}`);
            const yearSelector = document.getElementById(`pie_selector_${countryIdSafe}`);
            
            if (!breakdownData || breakdownData.length === 0) {
                pieContainer.style.display = 'none';
                return;
            }
            
            pieContainer.style.display = 'block';
            yearSelector.innerHTML = '';
            
            const years = [...new Set(breakdownData.map(d => d.Year))].sort((a, b) => b - a);
            
            years.forEach(year => {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                yearSelector.appendChild(option);
            });
            
            yearSelector.addEventListener('change', () => {
                drawPieChartDashboard(countryIdSafe, breakdownData, yearSelector.value);
            });
            
            drawPieChartDashboard(countryIdSafe, breakdownData, years[0]);
        }
        
        // --- NUEVA: Dibujar la Tarta (Dashboard) ---
        function drawPieChartDashboard(countryIdSafe, breakdownData, selectedYear) {
            if (chartPieInstances[countryIdSafe]) {
                chartPieInstances[countryIdSafe].destroy();
            }
            
            const yearData = breakdownData.filter(d => d.Year == selectedYear);
            
            const labels = yearData.map(d => {
                const compositeCode = `${d.List}::${d.Cause}`;
                return causeDescriptionMap.get(compositeCode) || compositeCode;
            });
            const data = yearData.map(d => d.Deaths);
            const totalDeaths = data.reduce((a, b) => a + Number(b), 0);

            const ctx = document.getElementById(`pie_chart_${countryIdSafe}`).getContext('2d');
            chartPieInstances[countryIdSafe] = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{ data: data }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: `Desglose de ${totalDeaths.toLocaleString('es-ES')} muertes en ${selectedYear}`
                        },
                        legend: {
                            display: false // Ocultar leyenda en dashboard para ahorrar espacio
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed !== null) {
                                        const value = context.parsed;
                                        const percentage = (value / totalDeaths * 100).toFixed(2);
                                        label += `${value.toLocaleString('es-ES')} (${percentage}%)`;
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>