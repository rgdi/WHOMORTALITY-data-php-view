<?php
// ============================================================
// ARCHIVO 4: export.php (CORREGIDO)
// ============================================================
?>
<?php
header('Content-Type: text/csv; charset=utf-8');
require_once 'config.php';
require_once 'regions.php';

$json_data = json_decode(file_get_contents('php://input'), true);
$searchName = $json_data['country'] ?? '';
$causes_list = $json_data['causes'] ?? [];

if (empty($searchName) || empty($causes_list)) {
    header("HTTP/1.1 400 Bad Request");
    die('Error: No se especificó el país o región.');
}

$deaths_sum_list = [];
$pob_sum_list = [];
for ($i = 1; $i <= 26; $i++) {
    $deaths_sum_list[] = "COALESCE(M.Deaths{$i}, 0)";
    $pob_sum_list[] = "COALESCE(Pob{$i}, 0)";
}
$deaths_sum_sql = "SUM(" . implode(' + ', $deaths_sum_list) . ")";
$pob_sum_sql = "SUM(" . implode(' + ', $pob_sum_list) . ")";

$filename = "datos_" . preg_replace('/[^a-z0-9]+/', '_', strtolower($searchName)) . ".csv";
header('Content-Disposition: attachment; filename=' . $filename);

try {
    $pdo = get_db_connection();
    
    $country_codes = [];
    $is_global = false;
    $searchNameLower = strtolower($searchName);
    
    $params = []; $params_pop = []; $params_inet = [];

    if ($searchNameLower === 'global') { $is_global = true; } 
    else {
        $country_codes = get_country_codes_for_region($searchNameLower);
        if ($country_codes === null) {
            $stmt = $pdo->prepare("SELECT TRIM(Codigo_Pais) AS Codigo_Pais FROM Paises WHERE Nombre LIKE ? LIMIT 1");
            $stmt->execute(["%$searchName%"]);
            $country = $stmt->fetch();
            if ($country) { $country_codes = [$country['Codigo_Pais']]; }
            else { throw new Exception("País o Región '$searchName' no encontrado."); }
        }
    }

    $sql_country_filter_mort = "";
    $sql_country_filter_pop = "";
    $sql_country_filter_inet = "";

    if (!$is_global) {
        $country_placeholders = implode(',', array_fill(0, count($country_codes), '?'));
        $sql_country_filter_mort = "AND TRIM(M.Country) IN ({$country_placeholders})";
        $sql_country_filter_pop = "AND TRIM(Pais_Codigo) IN ({$country_placeholders})";
        $sql_country_filter_inet = "AND TRIM(Codigo_Pais) IN ({$country_placeholders})";
        
        $params = $country_codes;
        $params_pop = $country_codes;
        $params_inet = $country_codes;
    }
    
    $cause_conditions = [];
    $cause_params = []; 
    foreach ($causes_list as $composite_cause) {
        $parts = explode('::', $composite_cause);
        if (count($parts) == 2) {
            $list_part = trim($parts[0]);
            $cause_part = trim($parts[1]);
            $cause_conditions[] = "(TRIM(M.List) = ? AND TRIM(M.Cause) = ?)";
            $cause_params[] = $list_part;
            $cause_params[] = $cause_part;
        }
    }
    if (empty($cause_conditions)) {
         throw new Exception("No se proporcionaron causas válidas.");
    }
    $cause_sql_clause = " AND (" . implode(' OR ', $cause_conditions) . ") ";

    $sql = "
        SELECT
            T_Mortalidad.Year,
            T_Mortalidad.TotalDeaths,
            T_Poblacion.TotalPopulation,
            T_Internet.Porcentaje_Uso_Avg AS Porcentaje_Uso,
            (T_Mortalidad.TotalDeaths / T_Poblacion.TotalPopulation) * 100000 AS Tasa_Mortalidad_x_100k
        FROM
            (
                SELECT M.Year, 
                       {$deaths_sum_sql} AS TotalDeaths
                FROM Mortalidad AS M
                WHERE 
                    1=1
                    {$sql_country_filter_mort} 
                    {$cause_sql_clause}
                GROUP BY M.Year
            ) AS T_Mortalidad
        LEFT JOIN
            (
                SELECT Anio, 
                       {$pob_sum_sql} AS TotalPopulation
                FROM Poblacion
                WHERE 
                    Sexo IN ('1', '2')
                    {$sql_country_filter_pop} 
                GROUP BY Anio
            ) AS T_Poblacion ON T_Mortalidad.Year = T_Poblacion.Anio
        LEFT JOIN
            (
                SELECT `Año` AS Anio, AVG(CAST(REPLACE(`Valor %`, ',', '.') AS DECIMAL(10,2))) AS Porcentaje_Uso_Avg
                FROM uso_internet
                WHERE 1=1
                  {$sql_country_filter_inet} 
                GROUP BY `Año`
            ) AS T_Internet ON T_Mortalidad.Year = T_Internet.Anio
        
        ORDER BY T_Mortalidad.Year;
    ";

    $params_mort = array_merge($params, $cause_params);
    $all_params = array_merge($params_mort, $params_pop, $params_inet);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($all_params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $output = fopen('php://output', 'w');
    if (!empty($results)) { fputcsv($output, array_keys($results[0])); }
    else { fputcsv($output, ['Year', 'TotalDeaths', 'TotalPopulation', 'Porcentaje_Uso', 'Tasa_Mortalidad_x_100k']); }
    foreach ($results as $row) { fputcsv($output, $row); }
    fclose($output);
    exit;

} catch (Exception $e) {
    die('Error al generar el archivo: ' . $e->getMessage());
}
?>