import csv

# --- Configuración ---
csv_file_name = 'icd10_2019.csv'      # El nombre de tu archivo CSV
sql_output_file = 'import_icd10_detailed.sql' # El archivo SQL que se va a crear

# --- Nuevos Ajustes para tu Tabla ---
table_name = 'who_mortality_causes'
icd_revision = 'ICD-10'
list_type = 'Detailed 2019'
table_reference = 'Table 9 (Detailed)' # Referencia al documento PDF
# ------------------------------------

print(f"Iniciando la conversión de {csv_file_name} a {sql_output_file}...")
print(f"Tabla destino: {table_name}")

try:
    with open(csv_file_name, mode='r', encoding='utf-8') as infile:
        # Usamos DictReader para leer usando los nombres de las columnas
        reader = csv.DictReader(infile)
        
        with open(sql_output_file, mode='w', encoding='utf-8') as outfile:
            count = 0
            for row in reader:
                # Obtenemos los datos de las columnas por su nombre
                code = row['sub-code']
                title = row['definition']

                # --- IMPORTANTE: Manejo de comillas simples ---
                # (ej. "Non-Hodgkin's lymphoma" se convierte en "Non-Hodgkin''s lymphoma")
                sanitized_title = title.replace("'", "''")

                # --- Crea el nuevo comando INSERT ---
                sql_command = (
                    f"INSERT INTO {table_name} "
                    f"(icd_revision, list_type, short_code, description, detailed_codes, table_reference) "
                    f"VALUES ('{icd_revision}', '{list_type}', '{code}', '{sanitized_title}', NULL, '{table_reference}');\n"
                )
                
                # Escribe el comando en el archivo SQL
                outfile.write(sql_command)
                count += 1

    print(f"¡Éxito! Se han generado {count} comandos INSERT en el archivo '{sql_output_file}'.")

except FileNotFoundError:
    print(f"ERROR: No se encontró el archivo '{csv_file_name}'. Asegúrate de que esté en el mismo directorio.")
except KeyError as e:
    print(f"ERROR: No se encontró la columna {e}. Asegúrate de que el CSV tenga las columnas 'sub-code' y 'definition'.")
except Exception as e:
    print(f"Ha ocurrido un error inesperado: {e}")