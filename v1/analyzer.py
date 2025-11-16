#!/usr/bin/env python3
"""
WHO Mortality Database Analyzer
Aplicación CGI/WSGI para análisis de datos de mortalidad
Compatible con hosting compartido (Namecheap, cPanel)
"""

import os
import sys
import json
import cgi
import io
import base64
from datetime import datetime
import configparser
from pathlib import Path

# Añadir directorio de paquetes al path
sys.path.insert(0, os.path.join(os.path.dirname(__file__), 'venv', 'lib', 'python3.9', 'site-packages'))

try:
    import mysql.connector
    import pandas as pd
    import matplotlib
    matplotlib.use('Agg')  # Usar backend no interactivo para servidores
    import matplotlib.pyplot as plt
    import seaborn as sns
except ImportError as e:
    print("Content-Type: text/html")
    print()
    print(f"<html><body><h1>Error de Importación</h1><p>{str(e)}</p></body></html>")
    sys.exit(1)

# Configuración de seguridad
ALLOWED_TABLES = [
    'Mortalidad', 'Poblacion', 'uso_internet', 'Paises', 
    'Estado_Desarrollo', 'who_mortality_age_ranges', 'who_mortality_causes'
]

ALLOWED_COLUMNS = {
    'Mortalidad': ['Country', 'Admin1', 'Subdiv', 'Year', 'List', 'Cause', 'Sex', 
                   'Frmat', 'IM_Frmat', 'Deaths1', 'Deaths2', 'Deaths3', 'Deaths4',
                   'Deaths5', 'Deaths6', 'Deaths7', 'Deaths8', 'Deaths9', 'Deaths10',
                   'Deaths11', 'Deaths12', 'Deaths13', 'Deaths14', 'Deaths15', 'Deaths16',
                   'Deaths17', 'Deaths18', 'Deaths19', 'Deaths20', 'Deaths21', 'Deaths22',
                   'Deaths23', 'Deaths24', 'Deaths25', 'Deaths26', 'IM_deaths1', 'IM_deaths2',
                   'IM_deaths3', 'IM_deaths4'],
    'Poblacion': ['Pais_Codigo', 'Admin1', 'Subdiv', 'Anio', 'Sexo', 'Frmat', 
                  'Pob1', 'Pob2', 'Pob3', 'Pob4', 'Pob5', 'Pob6', 'Pob7', 'Pob8',
                  'Pob9', 'Pob10', 'Pob11', 'Pob12', 'Pob13', 'Pob14', 'Pob15', 'Pob16',
                  'Pob17', 'Pob18', 'Pob19', 'Pob20', 'Pob21', 'Pob22', 'Pob23', 'Pob24',
                  'Pob25', 'Pob26', 'Nacidos_Vivos'],
    'uso_internet': ['Codigo_Pais', 'Pais_Nombre', 'Indicator Name', 'Indicator Code', 
                     'Año', 'Valor %'],
    'Paises': ['Codigo_Pais', 'Codigo_Pais_3', 'Nombre', 'Country_status_id'],
    'Estado_Desarrollo': ['Codigo_Estado', 'Descripcion'],
    'who_mortality_age_ranges': ['column_code', 'age_range'],
    'who_mortality_causes': ['id', 'icd_revision', 'list_type', 'short_code', 
                             'description', 'detailed_codes', 'table_reference']
}

# Relaciones entre tablas (para JOINs automáticos)
TABLE_RELATIONS = {
    'Mortalidad': {
        'Paises': {'Mortalidad.Country': 'Paises.Codigo_Pais'},
        'who_mortality_causes': {'Mortalidad.Cause': 'who_mortality_causes.short_code'},
        'Estado_Desarrollo': {'Paises.Country_status_id': 'Estado_Desarrollo.Codigo_Estado'}
    },
    'Poblacion': {
        'Paises': {'Poblacion.Pais_Codigo': 'Paises.Codigo_Pais'},
        'Estado_Desarrollo': {'Paises.Country_status_id': 'Estado_Desarrollo.Codigo_Estado'}
    },
    'uso_internet': {
        'Paises': {'uso_internet.Codigo_Pais': 'Paises.Codigo_Pais'},
        'Estado_Desarrollo': {'Paises.Country_status_id': 'Estado_Desarrollo.Codigo_Estado'}
    }
}

class DatabaseAnalyzer:
    """Clase principal para análisis de datos de la base de datos WHO"""
    
    def __init__(self):
        self.connection = None
        self.metadata = None
        
    def connect_to_db(self):
        """Establece conexión segura con la base de datos"""
        try:
            # Intentar leer desde variables de entorno (recomendado para producción)
            db_config = {
                'host': os.environ.get('DB_HOST', 'localhost'),
                'user': os.environ.get('DB_USER', ''),
                'password': os.environ.get('DB_PASSWORD', ''),
                'database': os.environ.get('DB_NAME', 'rgodczxw_WHO_Mortalidad_DB'),
                'port': int(os.environ.get('DB_PORT', 3306))
            }
            
            # Si no hay variables de entorno, intentar con config.ini
            if not db_config['user']:
                config_path = Path(__file__).parent / 'config.ini'
                if config_path.exists():
                    config = configparser.ConfigParser()
                    config.read(config_path)
                    db_config.update({
                        'host': config['database']['host'],
                        'user': config['database']['user'],
                        'password': config['database']['password'],
                        'database': config['database']['database']
                    })
                else:
                    raise Exception("No se encontró configuración de base de datos")
            
            self.connection = mysql.connector.connect(**db_config)
            return True
            
        except Exception as e:
            print(f"Error de conexión: {str(e)}")
            return False
    
    def get_metadata(self):
        """Obtiene metadatos de las tablas disponibles"""
        if not self.connection:
            return None
            
        metadata = {}
        cursor = self.connection.cursor(dictionary=True)
        
        try:
            for table in ALLOWED_TABLES:
                cursor.execute(f"DESCRIBE {table}")
                columns = cursor.fetchall()
                metadata[table] = [col['Field'] for col in columns]
                
        except Exception as e:
            print(f"Error obteniendo metadatos: {str(e)}")
            return None
        finally:
            cursor.close()
            
        self.metadata = metadata
        return metadata
    
    def parse_form_data(self):
        """Parsea los datos del formulario POST"""
        form = cgi.FieldStorage()
        
        selections = {
            'tables': [],
            'columns': [],
            'view_type': 'raw',
            'chart_type': 'none',
            'x_axis': '',
            'y_axis': [],
            'where_clause': '',
            'limit': 1000
        }
        
        # Obtener tablas seleccionadas
        if 'tables' in form:
            selections['tables'] = form.getlist('tables')
        
        # Obtener columnas seleccionadas
        if 'columns' in form:
            selections['columns'] = form.getlist('columns')
        
        # Otros parámetros
        if 'view_type' in form:
            selections['view_type'] = form.getvalue('view_type')
        if 'chart_type' in form:
            selections['chart_type'] = form.getvalue('chart_type')
        if 'x_axis' in form:
            selections['x_axis'] = form.getvalue('x_axis')
        if 'y_axis' in form:
            selections['y_axis'] = form.getlist('y_axis')
        if 'where_clause' in form:
            selections['where_clause'] = form.getvalue('where_clause', '')
        if 'limit' in form:
            try:
                selections['limit'] = int(form.getvalue('limit', 1000))
            except ValueError:
                selections['limit'] = 1000
        
        return selections
    
    def validate_selections(self, selections):
        """Valida las selecciones contra la lista blanca"""
        # Validar tablas
        for table in selections['tables']:
            if table not in ALLOWED_TABLES:
                return False, f"Tabla no permitida: {table}"
        
        # Validar columnas
        for col in selections['columns']:
            table, column = col.split('.', 1)
            if table not in ALLOWED_TABLES or column not in ALLOWED_COLUMNS.get(table, []):
                return False, f"Columna no permitida: {col}"
        
        return True, "Validación exitosa"
    
    def build_sql_query(self, selections):
        """Construye consulta SQL segura"""
        if not selections['tables'] or not selections['columns']:
            return "", ""
        
        # Construir SELECT
        select_parts = []
        for col in selections['columns']:
            select_parts.append(col)
        
        # Construir FROM y JOINs
        from_table = selections['tables'][0]
        join_clauses = []
        
        # Añadir JOINs automáticos
        for i, table in enumerate(selections['tables'][1:], 1):
            if from_table in TABLE_RELATIONS and table in TABLE_RELATIONS[from_table]:
                relation = TABLE_RELATIONS[from_table][table]
                for left_col, right_col in relation.items():
                    join_clauses.append(f"JOIN {table} ON {left_col} = {right_col}")
        
        # Añadir JOINs con tablas de dimensiones si es necesario
        for table in selections['tables']:
            if table == 'Mortalidad' and any('Paises.' in col for col in selections['columns']):
                if 'Paises' not in selections['tables']:
                    join_clauses.append("JOIN Paises ON Mortalidad.Country = Paises.Codigo_Pais")
            elif table == 'Mortalidad' and any('Estado_Desarrollo.' in col for col in selections['columns']):
                if 'Estado_Desarrollo' not in selections['tables']:
                    join_clauses.append("JOIN Paises ON Mortalidad.Country = Paises.Codigo_Pais")
                    join_clauses.append("JOIN Estado_Desarrollo ON Paises.Country_status_id = Estado_Desarrollo.Codigo_Estado")
        
        # Construir WHERE
        where_clause = ""
        if selections['where_clause']:
            # Validar y sanitizar WHERE clause básico
            # NOTA: Esto es una validación simple. Para producción, usar una biblioteca de SQL parsing
            allowed_chars = set('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_=.<>!"\' ()')
            if all(c in allowed_chars for c in selections['where_clause']):
                where_clause = f"WHERE {selections['where_clause']}"
        
        # Construir consulta final
        query = f"SELECT {', '.join(select_parts)} FROM {from_table}"
        if join_clauses:
            query += " " + " ".join(join_clauses)
        if where_clause:
            query += " " + where_clause
        
        query += f" LIMIT {selections['limit']}"
        
        # Consulta para contar total
        count_query = f"SELECT COUNT(*) as total FROM {from_table}"
        if join_clauses:
            count_query += " " + " ".join(join_clauses)
        if where_clause:
            count_query += " " + where_clause
        
        return query, count_query
    
    def fetch_data(self, query):
        """Ejecuta consulta y devuelve DataFrame"""
        try:
            df = pd.read_sql(query, self.connection)
            return df
        except Exception as e:
            print(f"Error ejecutando consulta: {str(e)}")
            return pd.DataFrame()
    
    def generate_html_table(self, dataframe):
        """Convierte DataFrame a tabla HTML segura"""
        if dataframe.empty:
            return "<p>No se encontraron datos</p>"
        
        # Configurar opciones de visualización
        pd.set_option('display.max_columns', None)
        pd.set_option('display.max_rows', None)
        pd.set_option('display.width', None)
        
        html = dataframe.to_html(
            classes='table table-striped table-bordered table-hover',
            table_id='dataTable',
            index=False,
            escape=True,  # Escapar HTML para prevenir XSS
            max_rows=1000
        )
        
        return html
    
    def generate_graph(self, dataframe, selections):
        """Genera gráfico según selecciones"""
        if dataframe.empty or selections['chart_type'] == 'none':
            return ""
        
        try:
            plt.figure(figsize=(12, 8))
            plt.style.use('seaborn-v0_8')
            
            # Configurar colores
            colors = ['#2E86AB', '#A23B72', '#F18F01', '#C73E1D', '#6A994E', '#7209B7']
            
            if selections['chart_type'] == 'bar':
                # Gráfico de barras
                if selections['x_axis'] and selections['y_axis']:
                    x_col = selections['x_axis']
                    y_cols = selections['y_axis']
                    
                    if x_col in dataframe.columns:
                        for i, y_col in enumerate(y_cols):
                            if y_col in dataframe.columns:
                                plt.bar(dataframe[x_col], dataframe[y_col], 
                                       label=y_col, alpha=0.7, color=colors[i % len(colors)])
                        
                        plt.xlabel(x_col)
                        plt.ylabel(' / '.join(y_cols))
                        plt.title('Gráfico de Barras')
                        plt.legend()
                        plt.xticks(rotation=45)
            
            elif selections['chart_type'] == 'line':
                # Gráfico de líneas
                if selections['x_axis'] and selections['y_axis']:
                    x_col = selections['x_axis']
                    y_cols = selections['y_axis']
                    
                    if x_col in dataframe.columns:
                        for i, y_col in enumerate(y_cols):
                            if y_col in dataframe.columns:
                                plt.plot(dataframe[x_col], dataframe[y_col], 
                                       marker='o', label=y_col, color=colors[i % len(colors)])
                        
                        plt.xlabel(x_col)
                        plt.ylabel(' / '.join(y_cols))
                        plt.title('Gráfico de Líneas')
                        plt.legend()
                        plt.xticks(rotation=45)
            
            elif selections['chart_type'] == 'pie':
                # Gráfico de torta (solo para una variable)
                if selections['x_axis'] and len(selections['y_axis']) == 1:
                    x_col = selections['x_axis']
                    y_col = selections['y_axis'][0]
                    
                    if x_col in dataframe.columns and y_col in dataframe.columns:
                        # Agrupar datos
                        grouped = dataframe.groupby(x_col)[y_col].sum()
                        
                        plt.pie(grouped.values, labels=grouped.index, autopct='%1.1f%%',
                               colors=colors, startangle=90)
                        plt.title(f'Distribución de {y_col} por {x_col}')
            
            plt.tight_layout()
            
            # Guardar en buffer
            buffer = io.BytesIO()
            plt.savefig(buffer, format='png', dpi=150, bbox_inches='tight')
            buffer.seek(0)
            
            # Codificar en base64
            image_base64 = base64.b64encode(buffer.getvalue()).decode('utf-8')
            plt.close()
            
            return image_base64
            
        except Exception as e:
            print(f"Error generando gráfico: {str(e)}")
            return ""

# Funciones de renderizado HTML
def render_form_page(metadata, error_message=None):
    """Renderiza la página del formulario"""
    print("Content-Type: text/html; charset=utf-8")
    print()
    
    html = f"""
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WHO Mortality Database Analyzer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {{
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }}
        .header {{
            background: linear-gradient(135deg, #2E86AB 0%, #A23B72 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }}
        .form-section {{
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }}
        .checkbox-grid {{
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 0.5rem;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
        }}
        .form-check {{
            margin-bottom: 0.5rem;
        }}
        .btn-primary {{
            background: linear-gradient(135deg, #2E86AB 0%, #A23B72 100%);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
        }}
        .btn-primary:hover {{
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }}
        .error-message {{
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }}
        .info-box {{
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }}
    </style>
</head>
<body>
    <div class="header text-center">
        <div class="container">
            <h1><i class="bi bi-graph-up"></i> WHO Mortality Database Analyzer</h1>
            <p class="lead">Análisis interactivo de datos de mortalidad global</p>
        </div>
    </div>

    <div class="container">
    """
    
    if error_message:
        html += f'<div class="error-message"><i class="bi bi-exclamation-triangle"></i> {error_message}</div>'
    
    html += """
        <div class="info-box">
            <i class="bi bi-info-circle"></i> <strong>Instrucciones:</strong> Selecciona las tablas y columnas que deseas analizar. 
            Puedes generar tablas complejas con JOINs automáticos o visualizaciones de datos.
        </div>
        
        <div class="form-section">
            <form method="POST" id="analyzerForm">
                <div class="row">
                    <div class="col-md-6">
                        <h4><i class="bi bi-table"></i> Tablas Disponibles</h4>
                        <div class="checkbox-grid">
    """
    
    # Agregar checkboxes de tablas
    for table in ALLOWED_TABLES:
        html += f"""
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="tables" value="{table}" id="table_{table}">
                                <label class="form-check-label" for="table_{table}">
                                    <strong>{table}</strong>
                                </label>
                            </div>
        """
    
    html += """
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h4><i class="bi bi-list-columns"></i> Columnas Disponibles</h4>
                        <div id="columnsContainer" class="checkbox-grid">
                            <p class="text-muted">Selecciona primero las tablas para ver las columnas</p>
                        </div>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="row">
                    <div class="col-md-3">
                        <label for="viewType" class="form-label"><i class="bi bi-eye"></i> Tipo de Vista</label>
                        <select class="form-select" name="view_type" id="viewType">
                            <option value="raw">Datos en Bruto</option>
                            <option value="complex">Tabla Compleja (JOINs)</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="chartType" class="form-label"><i class="bi bi-graph-up"></i> Tipo de Gráfico</label>
                        <select class="form-select" name="chart_type" id="chartType">
                            <option value="none">Ninguno</option>
                            <option value="bar">Barras</option>
                            <option value="line">Línea</option>
                            <option value="pie">Torta</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="xAxis" class="form-label"><i class="bi bi-axis"></i> Eje X</label>
                        <select class="form-select" name="x_axis" id="xAxis">
                            <option value="">Selecciona columna</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="yAxis" class="form-label"><i class="bi bi-graph-up"></i> Eje Y</label>
                        <select class="form-select" name="y_axis" id="yAxis" multiple>
                            <option value="">Selecciona columnas</option>
                        </select>
                        <small class="text-muted">Mantén Ctrl para seleccionar múltiples</small>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <label for="whereClause" class="form-label"><i class="bi bi-funnel"></i> Filtro WHERE (opcional)</label>
                        <textarea class="form-control" name="where_clause" id="whereClause" rows="2" 
                                  placeholder="Ej: Year = '2020' AND Sex = '1'"></textarea>
                        <small class="text-muted">Solo condiciones simples permitidas</small>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="limit" class="form-label"><i class="bi bi-list-ol"></i> Límite de Registros</label>
                        <input type="number" class="form-control" name="limit" id="limit" 
                               value="1000" min="10" max="10000">
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-play-circle"></i> Generar Análisis
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="text-center text-muted">
            <small>WHO Mortality Database Analyzer v1.0 | Compatible con hosting compartido</small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Datos de columnas por tabla
        const tableColumns = """
    
    # Agregar datos de columnas como JSON
    columns_json = {}
    for table, columns in ALLOWED_COLUMNS.items():
        columns_json[table] = [f"{table}.{col}" for col in columns]
    
    html += json.dumps(columns_json, indent=2)
    
    html += """;
        
        // Actualizar columnas cuando se seleccionan tablas
        document.addEventListener('DOMContentLoaded', function() {
            const tableCheckboxes = document.querySelectorAll('input[name="tables"]');
            const columnsContainer = document.getElementById('columnsContainer');
            const xAxisSelect = document.getElementById('xAxis');
            const yAxisSelect = document.getElementById('yAxis');
            
            function updateColumns() {
                const selectedTables = Array.from(tableCheckboxes)
                    .filter(cb => cb.checked)
                    .map(cb => cb.value);
                
                columnsContainer.innerHTML = '';
                xAxisSelect.innerHTML = '<option value="">Selecciona columna</option>';
                yAxisSelect.innerHTML = '<option value="">Selecciona columnas</option>';
                
                selectedTables.forEach(table => {
                    if (tableColumns[table]) {
                        tableColumns[table].forEach(column => {
                            // Checkbox para columnas
                            const div = document.createElement('div');
                            div.className = 'form-check';
                            div.innerHTML = `
                                <input class="form-check-input" type="checkbox" name="columns" value="${column}" id="col_${column}">
                                <label class="form-check-label" for="col_${column}">
                                    ${column}
                                </label>
                            `;
                            columnsContainer.appendChild(div);
                            
                            // Opciones para selects
                            const option1 = document.createElement('option');
                            option1.value = column;
                            option1.textContent = column;
                            xAxisSelect.appendChild(option1);
                            
                            const option2 = document.createElement('option');
                            option2.value = column;
                            option2.textContent = column;
                            yAxisSelect.appendChild(option2);
                        });
                    }
                });
                
                if (selectedTables.length === 0) {
                    columnsContainer.innerHTML = '<p class="text-muted">Selecciona primero las tablas para ver las columnas</p>';
                }
            }
            
            tableCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateColumns);
            });
        });
    </script>
</body>
</html>
    """
    
    print(html)

def render_results_page(html_table, graph_base64, raw_query, dataframe_info):
    """Renderiza la página de resultados"""
    print("Content-Type: text/html; charset=utf-8")
    print()
    
    html = f"""
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados del Análisis - WHO Database</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        body {{
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }}
        .header {{
            background: linear-gradient(135deg, #2E86AB 0%, #A23B72 100%);
            color: white;
            padding: 1.5rem 0;
            margin-bottom: 2rem;
        }}
        .results-section {{
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }}
        .query-box {{
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }}
        .stats-box {{
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }}
        .chart-container {{
            text-align: center;
            margin: 2rem 0;
        }}
        .chart-container img {{
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }}
        .btn-back {{
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
        }}
        .btn-back:hover {{
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }}
        .table-responsive {{
            max-height: 600px;
            overflow-y: auto;
        }}
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2><i class="bi bi-graph-up"></i> Resultados del Análisis</h2>
                </div>
                <div class="col-md-4 text-end">
                    <button onclick="history.back()" class="btn btn-back text-white">
                        <i class="bi bi-arrow-left"></i> Volver al Formulario
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="results-section">
            <h4><i class="bi bi-info-circle"></i> Información de la Consulta</h4>
            
            <div class="stats-box">
                <div class="row">
                    <div class="col-md-3">
                        <strong>Registros:</strong> {dataframe_info['rows']:,}
                    </div>
                    <div class="col-md-3">
                        <strong>Columnas:</strong> {dataframe_info['columns']}
                    </div>
                    <div class="col-md-3">
                        <strong>Tiempo:</strong> {dataframe_info['execution_time']:.3f}s
                    </div>
                    <div class="col-md-3">
                        <strong>Generado:</strong> {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
                    </div>
                </div>
            </div>
            
            <div class="query-box">
                <strong>Consulta SQL ejecutada:</strong><br>
                <code>{raw_query}</code>
            </div>
    """
    
    if graph_base64:
        html += f"""
            <div class="chart-container">
                <h4><i class="bi bi-bar-chart"></i> Visualización de Datos</h4>
                <img src="data:image/png;base64,{graph_base64}" alt="Gráfico generado">
            </div>
        """
    
    html += f"""
            <div class="table-responsive">
                <h4><i class="bi bi-table"></i> Datos Obtenidos</h4>
                {html_table}
            </div>
        </div>
        
        <div class="text-center text-muted">
            <small>WHO Mortality Database Analyzer v1.0 | Datos generados el {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}</small>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {{
            $('#dataTable').DataTable({{
                responsive: true,
                pageLength: 50,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Todos"]],
                language: {{
                    url: "//cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json"
                }}
            }});
        }});
    </script>
</body>
</html>
    """
    
    print(html)

def main():
    """Función principal - router de la aplicación"""
    analyzer = DatabaseAnalyzer()
    
    # Detectar método de petición
    if os.environ.get('REQUEST_METHOD', 'GET') == 'POST':
        # Procesar formulario POST
        if not analyzer.connect_to_db():
            render_form_page(None, "Error de conexión a la base de datos")
            return
        
        try:
            # Parsear datos del formulario
            selections = analyzer.parse_form_data()
            
            # Validar selecciones
            is_valid, message = analyzer.validate_selections(selections)
            if not is_valid:
                metadata = analyzer.get_metadata()
                render_form_page(metadata, message)
                return
            
            # Construir y ejecutar consulta
            start_time = datetime.now()
            query, count_query = analyzer.build_sql_query(selections)
            
            if not query:
                metadata = analyzer.get_metadata()
                render_form_page(metadata, "No se pudo construir la consulta")
                return
            
            # Obtener datos
            dataframe = analyzer.fetch_data(query)
            
            if dataframe.empty:
                metadata = analyzer.get_metadata()
                render_form_page(metadata, "La consulta no devolvió datos")
                return
            
            execution_time = (datetime.now() - start_time).total_seconds()
            
            # Generar tabla HTML
            html_table = analyzer.generate_html_table(dataframe)
            
            # Generar gráfico si se solicitó
            graph_base64 = ""
            if selections['chart_type'] != 'none':
                graph_base64 = analyzer.generate_graph(dataframe, selections)
            
            # Información del DataFrame
            dataframe_info = {
                'rows': len(dataframe),
                'columns': len(dataframe.columns),
                'execution_time': execution_time
            }
            
            # Renderizar página de resultados
            render_results_page(html_table, graph_base64, query, dataframe_info)
            
        except Exception as e:
            metadata = analyzer.get_metadata()
            render_form_page(metadata, f"Error procesando la solicitud: {str(e)}")
            
        finally:
            if analyzer.connection:
                analyzer.connection.close()
    
    else:
        # Mostrar formulario inicial (GET)
        if analyzer.connect_to_db():
            metadata = analyzer.get_metadata()
            analyzer.connection.close()
        else:
            metadata = None
        
        render_form_page(metadata)

# Punto de entrada para CGI/WSGI
if __name__ == "__main__":
    main()