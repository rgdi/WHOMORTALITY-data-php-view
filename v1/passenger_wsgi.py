import sys
import os
import imp
import io
import traceback

# --- 1. Cargar tu script 'analyzer.py' ---
# Passenger ya ha activado el VENV, así que esto debería funcionar
# directamente y encontrar 'analyzer.py' y sus dependencias.
try:
    analyzer_module = imp.load_source('analyzer', 'analyzer.py')
except Exception as e:
    # Si 'analyzer.py' falla al cargarse (ej. error de sintaxis),
    # guardamos el error para mostrarlo.
    analyzer_module = None
    startup_error = traceback.format_exc()


# --- 2. El Envoltorio (Wrapper) WSGI ---
# Esta es la función 'application' que Passenger busca.
def application(environ, start_response):
    
    # Si el script ni siquiera pudo importarse, muestra ese error
    if analyzer_module is None:
        status = '500 Internal Server Error'
        headers = [('Content-Type', 'text/html; charset=utf-8')]
        start_response(status, headers)
        error_html = f"<html><body><h1>Error Crítico al Cargar Módulo</h1>"
        error_html += f"<p>No se pudo importar 'analyzer.py'.</p>"
        error_html += f"<pre>{startup_error.replace('<', '&lt;')}</pre>"
        error_html += "</body></html>"
        return [error_html.encode('utf-8')]

    # Si el script se importó bien, lo ejecutamos:

    # Redirigir stdout para capturar los 'print()' de tu script
    _stdout = sys.stdout
    sys.stdout = io.StringIO()
    
    # Simular el entorno CGI que 'analyzer.py' espera
    os.environ['REQUEST_METHOD'] = environ.get('REQUEST_METHOD', 'GET')
    os.environ['QUERY_STRING'] = environ.get('QUERY_STRING', '')
    os.environ['CONTENT_TYPE'] = environ.get('CONTENT_TYPE', '')
    os.environ['CONTENT_LENGTH'] = environ.get('CONTENT_LENGTH', '0')
    sys.stdin = environ.get('wsgi.input', io.StringIO())

    status = '200 OK'
    headers = [('Content-Type', 'text/html; charset=utf-8')] # Por defecto

    try:
        # --- ¡Aquí se ejecuta tu script! ---
        analyzer_module.main()
        
        # Obtenemos la salida (el HTML que imprimió tu script)
        output = sys.stdout.getvalue()
        
        # Separar encabezados (Content-Type) del cuerpo (HTML)
        try:
            header_part, body_part = output.split('\n\n', 1)
            headers = [] # Usar los headers de tu script
            for line in header_part.splitlines():
                if ':' in line:
                    key, value = line.split(':', 1)
                    headers.append((key.strip(), value.strip()))
            body = body_part.encode('utf-8')
        except ValueError:
            # Si no hubo headers, enviar todo como cuerpo
            body = output.encode('utf-8')

    except Exception as e:
        # Si tu script 'analyzer.py' falla durante la ejecución
        status = '500 Internal Server Error'
        tb_html = traceback.format_exc().replace('\n', '<br>').replace(' ', '&nbsp;')
        
        error_html = f"<html><body>"
        error_html += f"<h1>Error en la Aplicación (analyzer.py)</h1>"
        error_html += f"<p>La función main() falló.</p>"
        error_html += f"<pre>{tb_html}</pre>"
        error_html += "</body></html>"
        body = error_html.encode('utf-8')
    
    finally:
        # Restaurar la salida estándar
        sys.stdout = _stdout
        sys.stdin = sys.__stdin__

    start_response(status, headers)
    return [body]