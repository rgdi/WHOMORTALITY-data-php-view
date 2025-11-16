import os
import glob
import csv
import pymysql
import sys

# --- 1. CONFIGURACIÓN ---

DB_HOST = 'localhost'
DB_USER = 'rgodczxw_WHO_Mortalidad'
DB_PASS = '55o&N7vtRx=D'
DB_NAME = 'rgodczxw_WHO_Mortalidad_DB'  # La base de datos que creaste
DB_PORT = 3306                 # Puerto de MariaDB/MySQL (default: 3306)

# Carpeta donde tienes TODOS los archivos .csv listos
CARPETA_CSV = '/home/rgodczxw/whomortalitydata'

# Nombre de la tabla de destino para los datos de mortalidad
TABLE_NAME_MORTALIDAD = 'Mortalidad'

# Tamaño del lote: cuántas filas insertar antes de hacer "commit"
TAMANO_LOTE = 1000


def importar_mortalidad_linea_por_linea(csv_dir, db_config):
    """
    Importa SÓLO los archivos .csv de mortalidad a la base de datos
    usando INSERT por lotes (muy lento).
    """
    print("Iniciando conexión con la base de datos...")
    
    # Mapeo de archivos CSV a la tabla de mortalidad
    # Usamos la variable de la configuración
    mapeo_archivos_tabla = {
        'Mortlcd07.csv': TABLE_NAME_MORTALIDAD,
        'Mortlcd08.csv': TABLE_NAME_MORTALIDAD,
        'Mortlcd09.csv': TABLE_NAME_MORTALIDAD,
    }

    # Busca los archivos Morticd10_part...
    # (Asume que están en minúsculas o mayúsculas según tu S.O.)
    archivos_mort10 = glob.glob(os.path.join(csv_dir, 'Morticd10_part*.csv'))
    if not archivos_mort10:
        archivos_mort10 = glob.glob(os.path.join(csv_dir, 'morticd10_part*.csv'))
        
    for f in archivos_mort10:
        mapeo_archivos_tabla[os.path.basename(f)] = TABLE_NAME_MORTALIDAD
        
    if not archivos_mort10:
        print("Advertencia: No se encontraron archivos 'Morticd10_part*.csv'.")

    try:
        connection = pymysql.connect(
            host=db_config['host'],
            user=db_config['user'],
            password=db_config['pass'],
            database=db_config['name'],
            port=db_config['port']
        )
    except pymysql.err.OperationalError as e:
        print(f"¡Error de conexión! Revisa tus variables DB_HOST, DB_USER, DB_PASS.")
        print(f"Detalle: {e}")
        return
    except Exception as e:
        print(f"¡Error! No se pudo conectar a la base de datos: {e}")
        return

    print("¡Conexión exitosa!")
    print("Iniciando importación LENTA (sólo mortalidad).")
    print("Esto puede tardar MUCHAS HORAS...")

    try:
        with connection.cursor() as cursor:
            # El bucle ahora iterará sobre los archivos de mortalidad
            for csv_file, table_name in mapeo_archivos_tabla.items():
                
                csv_path_full = os.path.join(csv_dir, csv_file)
                
                if not os.path.exists(csv_path_full):
                    print(f"\n-> Archivo no encontrado, saltando: '{csv_file}'")
                    continue
                    
                print(f"\n-> Abriendo archivo '{csv_file}' para importar en '{table_name}'...")
                
                try:
                    # 'utf-8' es estándar, pero si falla, prueba 'latin-1'
                    with open(csv_path_full, mode='r', encoding='utf-8') as f:
                        reader = csv.reader(f)
                        
                        # Leemos la cabecera para saber el número de columnas
                        header = next(reader) 
                        num_cols = len(header)
                        
                        # Creamos el query de INSERT usando la variable table_name
                        placeholders = ', '.join(['%s'] * num_cols)
                        sql_insert = f"INSERT INTO {table_name} VALUES ({placeholders})"
                        
                        print(f"   (Query: INSERT INTO {table_name} VALUES (...) con {num_cols} columnas)")

                        filas_en_lote = []
                        contador_total = 0
                        
                        for row in reader:
                            # Convertir strings vacíos '' a None (NULL en SQL)
                            processed_row = [None if val == '' else val for val in row]
                            
                            filas_en_lote.append(processed_row)
                            
                            if len(filas_en_lote) >= TAMANO_LOTE:
                                # Ejecutar el lote
                                cursor.executemany(sql_insert, filas_en_lote)
                                connection.commit()
                                contador_total += len(filas_en_lote)
                                filas_en_lote = []
                                print(f"   ... {contador_total} filas insertadas.", end='\r')
                        
                        # Insertar el último lote restante
                        if filas_en_lote:
                            cursor.executemany(sql_insert, filas_en_lote)
                            connection.commit()
                            contador_total += len(filas_en_lote)
                        
                        print(f"   -> ¡Éxito! Total de {contador_total} filas importadas de '{csv_file}'.")

                except Exception as e:
                    print(f"   -> ¡¡ERROR durante el procesamiento de '{csv_file}'!!")
                    print(f"   -> Detalle: {e}")
                    print("   -> Saltando este archivo.")
                    connection.rollback() # Revertir cualquier lote parcial

    except Exception as e:
        print(f"¡Error durante la importación!: {e}")
    finally:
        connection.close()
        print("\nConexión a la base de datos cerrada.")
        print("==============================================")
        print(" Proceso finalizado.")
        print("==============================================")


def main():
    print("==============================================")
    print(" Asistente de importación WHO (Solo Mortalidad)")
    print("==============================================")
    
    db_config = {
        'host': DB_HOST,
        'user': DB_USER,
        'pass': DB_PASS,
        'name': DB_NAME,
        'port': DB_PORT
    }

    importar_mortalidad_linea_por_linea(CARPETA_CSV, db_config)


if __name__ == "__main__":
    main()
