<?php
// ============================================================
// ARCHIVO 2: get_data.php (CORREGIDO Y AMPLIADO)
// - Devuelve datos agregados ('data') y desglosados ('breakdown')
// - Corregido AVG de Internet para continentes
// ============================================================

header('Content-Type: application/json');
require_once 'config.php';
require_once 'regions.php'; 

$json_data = json_decode(file_get_contents('php://input'), true);
$searchName = $json_data['country'] ?? '';
$causes_list = $json_data['causes'] ?? [];

if (empty($searchName) || empty($causes_list)) {
    echo json_encode(['status' => 'error', 'message' => 'Falta el país o la lista de causas.']);
    exit;
}

$deaths_sum_list = [];
$pob_sum_list = [];
for ($i = 1; $i <= 26; $i++) {
    $deaths_sum_list[] = "COALESCE(M.Deaths{$i}, 0)";
    $pob_sum_list[] = "COALESCE(Pob{$i}, 0)";
}
$deaths_sum_sql = "SUM(" . implode(' + ', $deaths_sum_list) . ")";
$pob_sum_sql = "SUM(" . implode(' + ', $pob_sum_list) . ")";

try {
    $pdo = get_db_connection();
    
    $country_codes = [];
    $is_global = false;
    $searchNameLower = strtolower($searchName);
    
    $params = [];
    $params_pop = [];
    $params_inet = [];

    if ($searchNameLower === 'global') {
        $is_global = true;
    } else {
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
    
    // CONSULTA 1: Datos agregados por año (para gráficas de líneas)
    $sql_aggregated = "
        SELECT
            T_Mortalidad.Year,
            T_Mortalidad.TotalDeaths,
            T_Poblacion.TotalPopulation,
            T_Internet.Porcentaje_Uso_Avg AS Porcentaje_Uso
        FROM
            (
                SELECT
                    M.Year, 
                    {$deaths_sum_sql} AS TotalDeaths
                FROM
                    Mortalidad AS M
                WHERE
                    1=1
                    {$sql_country_filter_mort} 
                    {$cause_sql_clause}
                GROUP BY
                    M.Year
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
                SELECT 
                    `Año` AS Anio, 
                    COALESCE(AVG(CAST(REPLACE(`Valor %`, ',', '.') AS DECIMAL(10,2))), 0) AS Porcentaje_Uso_Avg
                FROM uso_internet
                WHERE 1=1
                  {$sql_country_filter_inet} 
                GROUP BY `Año`
            ) AS T_Internet ON T_Mortalidad.Year = T_Internet.Anio
        
        ORDER BY T_Mortalidad.Year;
    ";
    
    $params_mort = array_merge($params, $cause_params);
    $all_params = array_merge($params_mort, $params_pop, $params_inet);

    $stmt = $pdo->prepare($sql_aggregated);
    $stmt->execute($all_params);
    $results_aggregated = $stmt->fetchAll();
    
    if (empty($results_aggregated)) {
         throw new Exception("No se encontraron datos para '$searchName' con las causas seleccionadas.");
    }

    // CONSULTA 2: Desglose por causa y año (para gráfica de tarta)
    $sql_breakdown = "
        SELECT
            M.Year,
            TRIM(M.List) AS List,
            TRIM(M.Cause) AS Cause,
            {$deaths_sum_sql} AS Deaths
        FROM
            Mortalidad AS M
        WHERE
            1=1
            {$sql_country_filter_mort} 
            {$cause_sql_clause}
        GROUP BY
            M.Year, TRIM(M.List), TRIM(M.Cause)
        HAVING
            Deaths > 0
        ORDER BY M.Year, Deaths DESC;
    ";
    
    $stmt_breakdown = $pdo->prepare($sql_breakdown);
    $stmt_breakdown->execute($params_mort); // Solo necesita params de mortalidad
    $results_breakdown = $stmt_breakdown->fetchAll();

    echo json_encode([
        'status' => 'success', 
        'data' => $results_aggregated,
        'breakdown' => $results_breakdown
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>