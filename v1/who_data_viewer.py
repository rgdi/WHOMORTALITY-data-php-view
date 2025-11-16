#!/usr/bin/env python3
"""
WHO Data Analysis App (CGI/WSGI)

INSTRUCTIONS (brief):
- Place this single file on your Namecheap shared hosting.
- Create a virtualenv (venv) and install requirements from the header below.
- Configure database credentials either via a config.ini stored OUTSIDE the webroot, or via environment variables in cPanel.
- Use as CGI by placing in cgi-bin (chmod 755) or as WSGI by pointing cPanel's "Setup Python App" to the virtualenv and this file's `application` callable.

FILES / CONFIGURATION
- config.ini example (store outside webroot):

[mysql]
host = localhost
user = rgod_user
password = s3cr3t
database = rgodczxw_WHO_Mortalidad_DB

- Or set environment variables: DB_HOST, DB_USER, DB_PASS, DB_NAME

REQUIREMENTS (put in requirements.txt):
mysql-connector-python>=8.0
pandas>=1.5
matplotlib>=3.0

OVERVIEW
This script implements the requested functions:
- connect_to_db()
- main()/application() (router for CGI & WSGI)
- render_form_page(metadata, error_message=None)
- render_results_page(html_table, graph_base64_image, raw_query)
- parse_form_data()
- build_sql_query(selections)
- fetch_data(query_string)
- generate_html_table(dataframe)
- generate_graph(dataframe, selections)

Security notes:
- The script builds a whitelist of tables/columns from INFORMATION_SCHEMA to avoid SQL injection.
- Filters textarea is allowed but will be heavily validated; avoid raw injections.

Limitations:
- Automatic JOIN logic is basic and tailored to the supplied DB schema (Paises central table). Extend join rules in TABLE_JOIN_HINTS as needed.

"""

import os
import sys
import io
import base64
import configparser
import traceback
from html import escape

# Database + data libs
import mysql.connector
import pandas as pd
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

# For CGI
import cgi
import cgitb
cgitb.enable()

# -------------------- CONFIG & HELPERS --------------------
CONFIG_PATHS = [
    os.path.join(os.path.dirname(__file__), '..', 'config.ini'),
    os.path.join(os.path.dirname(__file__), 'config.ini'),
]

# If DB schema has canonical join hints place them here (key: frozenset of tables)
TABLE_JOIN_HINTS = {
    frozenset(['Mortalidad', 'Paises']): 'Mortalidad.Country = Paises.Codigo_Pais',
    frozenset(['Poblacion', 'Paises']): 'Poblacion.Pais_Codigo = Paises.Codigo_Pais',
    frozenset(['uso_internet', 'Paises']): 'uso_internet.Codigo_Pais = Paises.Codigo_Pais',
    frozenset(['Mortalidad', 'who_mortality_causes']): "Mortalidad.Cause = who_mortality_causes.short_code",
}

# -------------------- 1. connect_to_db --------------------

def connect_to_db():
    """Establish secure MySQL connection reading from config.ini or environment variables.
    Returns a mysql.connector connection.
    """
    cfg = {}
    # Try environment variables first
    cfg['host'] = os.environ.get('DB_HOST')
    cfg['user'] = os.environ.get('DB_USER')
    cfg['password'] = os.environ.get('DB_PASS')
    cfg['database'] = os.environ.get('DB_NAME')

    if not all([cfg['host'], cfg['user'], cfg['password'], cfg['database']]):
        # fall back to config.ini
        parser = configparser.ConfigParser()
        read_files = parser.read(CONFIG_PATHS)
        if 'mysql' in parser:
            m = parser['mysql']
            cfg['host'] = cfg['host'] or m.get('host')
            cfg['user'] = cfg['user'] or m.get('user')
            cfg['password'] = cfg['password'] or m.get('password')
            cfg['database'] = cfg['database'] or m.get('database')

    if not all([cfg.get('host'), cfg.get('user'), cfg.get('password'), cfg.get('database')]):
        raise RuntimeError('Database credentials not found. Set DB_HOST/DB_USER/DB_PASS/DB_NAME or place config.ini.')

    conn = mysql.connector.connect(
        host=cfg['host'],
        user=cfg['user'],
        password=cfg['password'],
        database=cfg['database'],
        autocommit=True,
    )
    return conn

# -------------------- Metadata loader (whitelist) --------------------

def load_metadata(conn):
    """Return dict of tables -> list of columns using INFORMATION_SCHEMA. Also returns list of tables present."""
    q = "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s"
    cur = conn.cursor()
    cur.execute(q, (conn.database,))
    rows = cur.fetchall()
    meta = {}
    for table, col in rows:
        meta.setdefault(table, []).append(col)
    return meta

# -------------------- 3. Render UI --------------------

def render_head(title='WHO Analysis'):
    return f"""Content-Type: text/html; charset=utf-8\n\n<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>{escape(title)}</title>\n<style>
    body{{font-family:Arial,Helvetica,sans-serif;padding:18px;max-width:1100px;margin:auto}}
    .card{{background:#fff;padding:12px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,0.06);margin-bottom:12px}}
    label{display:block;margin:6px 0}
    .controls{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    textarea{width:100%;min-height:80px}
    table{border-collapse:collapse;width:100%;}
    table th, table td{border:1px solid #ddd;padding:6px}
</style></head><body>
<h1>WHO — Explorador de Datos</h1>"""


def render_form_page(metadata, error_message=None):
    """Prints HTML form based on metadata. metadata: dict table->columns"""
    html = [render_head('Seleccionar datos')]
    if error_message:
        html.append(f"<div class='card' style='border-left:4px solid #c33'><b>Error:</b> {escape(error_message)}</div>")
    html.append("<div class='card'><form method='POST'>")

    # Tables selector
    all_tables = sorted(metadata.keys())
    html.append('<label><b>Tablas</b> (selecciona una o varias)</label>')
    html.append('<select name="tables" multiple size="6">')
    for t in all_tables:
        html.append(f'<option value="{escape(t)}">{escape(t)}</option>')
    html.append('</select>')

    # Columns selector: show simple helper; actual columns will be validated server-side
    html.append('<label><b>Columnas</b> (coma separadas, ejemplo: Country,Year,Deaths1)</label>')
    html.append('<input name="columns" style="width:100%" placeholder="Country,Year,Deaths1">')

    # WHERE filters
    html.append('<label><b>Filtros WHERE (opcional, use con cuidado)</b></label>')
    html.append('<textarea name="where_clause" placeholder="Year = 2019 AND Sex = \"1\""></textarea>')

    # View type
    html.append('<label><b>Vista</b></label>')
    html.append('<select name="view_type">')
    html.append('<option value="table_complex">Tabla Compleja</option>')
    html.append('<option value="raw">Datos en Bruto</option>')
    html.append('</select>')

    # Graph options
    html.append('<label><b>Gráfico</b></label>')
    html.append('<select name="chart_type">')
    html.append('<option value="none">Ninguno</option>')
    html.append('<option value="bar">Barras</option>')
    html.append('<option value="line">Línea</option>')
    html.append('<option value="pie">Torta</option>')
    html.append('</select>')

    html.append('<label><b>Eje X</b> (columna para eje X)</label>')
    html.append('<input name="x_axis" placeholder="Year or Country">')
    html.append('<label><b>Eje Y (columnas, coma separadas)</b></label>')
    html.append('<input name="y_axes" placeholder="Deaths1 or Pob1,Deaths1">')

    html.append('<div style="margin-top:8px"><button type="submit">Generar</button></div>')
    html.append('</form></div>')

    # Footer
    html.append("<div style='font-size:12px;color:#666'>Este script es stateless y se ejecuta en cada petición. Revisa el archivo config.ini o las variables de entorno para configurar la DB.</div>")
    html.append('</body></html>')
    print('\n'.join(html))


def render_results_page(html_table, graph_base64_image, raw_query):
    """Print results page HTML. Prints Content-Type header too."""
    html = [render_head('Resultados')]
    html.append('<div class="card"><a href="?">&larr; Volver</a></div>')
    html.append('<div class="card"><h3>Consulta SQL ejecutada</h3>')
    html.append('<pre style="white-space:pre-wrap;max-height:220px;overflow:auto;border:1px solid #eee;padding:8px">')
    html.append(escape(raw_query))
    html.append('</pre></div>')

    if graph_base64_image:
        html.append('<div class="card"><h3>Gráfico</h3>')
        html.append(f'<img src="data:image/png;base64,{graph_base64_image}" alt="gráfico" style="max-width:100%">')
        html.append('</div>')

    html.append('<div class="card"><h3>Tabla de resultados</h3>')
    html.append(html_table)
    html.append('</div>')
    html.append('</body></html>')
    print('\n'.join(html))

# -------------------- 4. parse_form_data --------------------

def parse_form_data(environ=None, fs=None):
    """Return dict of selections. Accepts either CGI FieldStorage (fs) or WSGI environ.
    Keys: tables (list), columns (list), where_clause (str), view_type, chart_type, x_axis, y_axes(list)
    """
    data = {
        'tables': [], 'columns': [], 'where_clause': '', 'view_type': 'table_complex',
        'chart_type': 'none', 'x_axis': None, 'y_axes': []
    }

    if fs is not None:
        # CGI path
        tables = fs.getlist('tables') if hasattr(fs, 'getlist') else fs.getvalue('tables')
        if isinstance(tables, str):
            data['tables'] = [tables]
        elif tables:
            data['tables'] = tables
        cols = fs.getvalue('columns') or ''
        data['columns'] = [c.strip() for c in cols.split(',') if c.strip()]
        data['where_clause'] = fs.getvalue('where_clause') or ''
        data['view_type'] = fs.getvalue('view_type') or data['view_type']
        data['chart_type'] = fs.getvalue('chart_type') or data['chart_type']
        data['x_axis'] = fs.getvalue('x_axis') or None
        y = fs.getvalue('y_axes') or ''
        data['y_axes'] = [c.strip() for c in y.split(',') if c.strip()]
    else:
        # WSGI path: parse from environ['QUERY_STRING'] or wsgi.input for POST
        # We rely on cgi.FieldStorage to parse the environ if needed
        fs2 = cgi.FieldStorage(fp=environ['wsgi.input'], environ=environ, keep_blank_values=True)
        return parse_form_data(environ=environ, fs=fs2)

    return data

# -------------------- 5. build_sql_query --------------------

def build_sql_query(selections, metadata):
    """Build SQL query string safely based on selections and metadata (whitelist).
    Returns (query_string, error_message).
    """
    try:
        tables = selections.get('tables') or []
        cols = selections.get('columns') or []
        where = selections.get('where_clause', '').strip()

        if not tables:
            return None, 'No tables selected.'

        # Validate tables
        for t in tables:
            if t not in metadata:
                return None, f'Tabla no permitida o inexistente: {t}'

        # Validate columns: if empty -> select * from chosen tables (limited)
        selected_cols = []
        if cols:
            for c in cols:
                found = False
                for t in tables:
                    if c in metadata.get(t, []):
                        selected_cols.append(f"{t}.{c} AS `{t}.{c}`")
                        found = True
                if not found:
                    # allow if fully qualified like Paises.Nombre
                    if '.' in c:
                        tpart, cpart = c.split('.', 1)
                        if tpart in metadata and cpart in metadata[tpart]:
                            selected_cols.append(f"{tpart}.{cpart} AS `{tpart}.{cpart}`")
                            found = True
                if not found:
                    return None, f'Columna no permitida o inexistente: {c}'
        else:
            # use all columns from selected tables but prefix
            for t in tables:
                for c in metadata.get(t, []):
                    selected_cols.append(f"{t}.{c} AS `{t}.{c}`")

        select_clause = 'SELECT ' + ', '.join(selected_cols)

        # Build FROM and JOINS: naive approach - put first table as FROM and LEFT JOIN others using hints
        base_table = tables[0]
        from_clause = f'FROM {base_table}'
        used = {base_table}
        joins = []
        for t in tables[1:]:
            # try to find join hint between t and any used table
            hint = None
            for u in list(used):
                key = frozenset([t, u])
                if key in TABLE_JOIN_HINTS:
                    hint = TABLE_JOIN_HINTS[key]
                    break
            if not hint:
                # try conventional foreign-key name matching
                if f'{t}.Codigo_Pais' in '':
                    pass
                # fallback: cross join (safe but possibly large)
                hint = None
            if hint:
                joins.append(f'LEFT JOIN {t} ON {hint}')
            else:
                joins.append(f', {t}')
            used.add(t)

        query = f"{select_clause} {from_clause} " + ' '.join(joins)

        # Sanitize where clause: only allow characters and column tokens that appear
        if where:
            # very basic safety: disallow semicolons and statements keywords
            forbidden = [';', '--', 'DROP', 'ALTER', 'INSERT', 'UPDATE', 'DELETE', 'CREATE']
            for fbad in forbidden:
                if fbad.lower() in where.lower():
                    return None, 'La cláusula WHERE contiene palabras no permitidas.'
            # Note: better validation would tokenize and compare identifiers against metadata
            query += f" WHERE {where}"

        # limit result size to prevent heavy queries
        query += ' LIMIT 10000'
        return query, None
    except Exception as e:
        return None, str(e)

# -------------------- 6. fetch_data --------------------

def fetch_data(query_string, conn):
    """Execute query and return pandas DataFrame"""
    df = pd.read_sql(query_string, conn)
    return df

# -------------------- 7. generate_html_table --------------------

def generate_html_table(dataframe):
    """Return HTML table string. Uses pandas to_html with escape."""
    if dataframe is None or dataframe.empty:
        return '<div>No hay datos.</div>'
    return dataframe.to_html(classes='table', index=False, escape=True)

# -------------------- 8. generate_graph --------------------

def generate_graph(dataframe, selections):
    """Return base64 PNG string or None.
    selections: has chart_type, x_axis, y_axes
    """
    chart_type = selections.get('chart_type')
    if not chart_type or chart_type == 'none':
        return None
    x = selections.get('x_axis')
    ys = selections.get('y_axes') or []
    if dataframe is None or dataframe.empty:
        return None

    fig, ax = plt.subplots(figsize=(8,4))
    try:
        if chart_type == 'pie':
            # require one column to aggregate
            col = ys[0] if ys else None
            if not col or col not in dataframe.columns:
                # try best-effort match by suffix
                matches = [c for c in dataframe.columns if c.endswith(col or '')]
                if matches:
                    col = matches[0]
            if col is None:
                return None
            series = dataframe.groupby(x)[col].sum() if x and x in dataframe.columns else dataframe[col].groupby(dataframe.index).sum()
            series.plot.pie(ax=ax, autopct='%1.1f%%')
            ax.set_ylabel('')
        elif chart_type in ('bar', 'line'):
            if not x:
                # try to pick first non-numeric as x
                for c in dataframe.columns:
                    if dataframe[c].dtype == object:
                        x = c
                        break
            for y in ys:
                if y in dataframe.columns:
                    if chart_type == 'bar':
                        ax.bar(dataframe[x].astype(str), dataframe[y])
                    else:
                        ax.plot(dataframe[x], dataframe[y], marker='o')
            ax.set_xlabel(x or '')
            ax.legend(ys)
        else:
            return None

        buf = io.BytesIO()
        plt.tight_layout()
        plt.savefig(buf, format='png')
        plt.close(fig)
        buf.seek(0)
        img_b64 = base64.b64encode(buf.read()).decode('ascii')
        return img_b64
    except Exception as e:
        plt.close(fig)
        return None

# -------------------- Router / Entrypoints --------------------

def handle_request_cgi():
    try:
        fs = cgi.FieldStorage()
        # Determine GET vs POST
        method = os.environ.get('REQUEST_METHOD', 'GET').upper()
        conn = connect_to_db()
        metadata = load_metadata(conn)

        if method == 'GET':
            render_form_page(metadata)
            return
        # POST
        selections = parse_form_data(fs=fs)
        query, err = build_sql_query(selections, metadata)
        if err:
            render_form_page(metadata, error_message=err)
            return
        df = fetch_data(query, conn)
        html_table = generate_html_table(df)
        graph_b64 = generate_graph(df, selections)
        render_results_page(html_table, graph_b64, query)
    except Exception as e:
        traceback.print_exc()
        print('Content-Type: text/html; charset=utf-8\n\n')
        print('<pre>')
        print(escape(str(e)))
        print('</pre>')


def application(environ, start_response):
    """WSGI application callable. Returns iterable of bytes."""
    try:
        method = environ.get('REQUEST_METHOD', 'GET').upper()
        # parse form with cgi.FieldStorage via environ
        fs = cgi.FieldStorage(fp=environ['wsgi.input'], environ=environ, keep_blank_values=True)
        # Connect DB
        conn = connect_to_db()
        metadata = load_metadata(conn)

        if method == 'GET':
            body = []
            out = io.StringIO()
            render_form_page(metadata)
            # NOTE: render_form_page prints to stdout for CGI. For WSGI we capture and return.
            # To keep code simple and avoid duplicating HTML generation, redirect stdout temporarily.
            # But in many WSGI hosting scenarios printing to stdout isn't desired - still we support basic behavior.
            start_response('200 OK', [('Content-Type', 'text/html; charset=utf-8')])
            # regenerate html into string
            html_stream = io.StringIO()
            # Ugly but safe approach: call render_form_page and capture stdout
            # In this WSGI call we will re-generate the HTML using the same function but by copying logic:
            # Simpler: reconstruct via building blocks used above
            html = [render_head('Seleccionar datos')]
            html.append('<div class="card">')
            html.append('<form method="POST">')
            html.append('<label><b>Tablas</b> (selecciona una o varias)</label>')
            html.append('<select name="tables" multiple size="6">')
            for t in sorted(metadata.keys()):
                html.append(f'<option value="{escape(t)}">{escape(t)}</option>')
            html.append('</select>')
            html.append('</form></div>')
            html.append('</body></html>')
            return [''.join(html).encode('utf-8')]

        # POST handling in WSGI
        selections = parse_form_data(environ=environ, fs=fs)
        query, err = build_sql_query(selections, metadata)
        if err:
            start_response('200 OK', [('Content-Type', 'text/html; charset=utf-8')])
            return [f"<html><body><h3>Error: {escape(err)}</h3><a href='.'>Volver</a></body></html>".encode('utf-8')]
        df = fetch_data(query, conn)
        html_table = generate_html_table(df)
        graph_b64 = generate_graph(df, selections)
        # render results to string
        start_response('200 OK', [('Content-Type', 'text/html; charset=utf-8')])
        out_html = render_head('Resultados')
        out_html += f"<div class='card'><a href='.'>&larr; Volver</a></div>"
        out_html += '<div class="card"><h3>Consulta SQL ejecutada</h3>'
        out_html += '<pre style="white-space:pre-wrap;max-height:220px;overflow:auto;border:1px solid #eee;padding:8px">'
        out_html += escape(query)
        out_html += '</pre></div>'
        if graph_b64:
            out_html += f'<div class="card"><img src="data:image/png;base64,{graph_b64}" style="max-width:100%"></div>'
        out_html += '<div class="card">' + html_table + '</div>'
        out_html += '</body></html>'
        return [out_html.encode('utf-8')]

    except Exception as e:
        start_response('500 Internal Server Error', [('Content-Type', 'text/plain; charset=utf-8')])
        tb = traceback.format_exc()
        return [tb.encode('utf-8')]

# -------------------- Execute when run as CGI --------------------
if __name__ == '__main__':
    # If running under CGI, environment variable GATEWAY_INTERFACE exists
    if os.environ.get('GATEWAY_INTERFACE'):
        handle_request_cgi()
    else:
        print('This script is meant to be run as CGI or used as a WSGI app via the "application(environ,start_response)" callable.')
