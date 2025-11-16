<?php
// ============================================================
// ARCHIVO 3: get_stats.php (CORREGIDO)
// ============================================================
?>
<?php
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
    
    $params_mort = []; $params_pop = []; $params_inet = []; 

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
        
        $params_mort = $country_codes;
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
    
    $params_mort = array_merge($params_mort, $cause_params);

    $data = [
        'summary' => [],
        'top_causes_by_year' => []
    ];

    $base_sql_summary = "
        SELECT
            T_Mortalidad.Year,
            T_Mortalidad.TotalDeaths,
            T_Internet.Porcentaje_Uso_Avg AS Porcentaje_Uso
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
    ";
    
    $all_params = array_merge($params_mort, $params_pop, $params_inet);
    $stmt_base = $pdo->prepare($base_sql_summary);
    $stmt_base->execute($all_params);
    $base_results = $stmt_base->fetchAll(PDO::FETCH_ASSOC);

    if (empty($base_results)) {
        throw new Exception("No se encontraron datos de resumen para '$searchName'.");
    }

    $years = array_column($base_results, 'Year');
    $all_deaths = array_column($base_results, 'TotalDeaths');
    $all_internet = array_column($base_results, 'Porcentaje_Uso');
    
    $all_deaths_filtered = array_filter($all_deaths, function($v) { return $v !== null; });
    if (count($all_deaths_filtered) === 0) {
        throw new Exception("No se encontraron datos de Mortalidad para '$searchName' con esas causas.");
    }
    
    $data['summary']['start_year'] = min($years);
    $data['summary']['end_year'] = max($years);
    $data['summary']['avg_deaths'] = array_sum($all_deaths_filtered) / count($all_deaths_filtered);
    $data['summary']['max_deaths'] = max($all_deaths_filtered);
    $data['summary']['min_deaths'] = min($all_deaths_filtered);
    $all_internet_filtered = array_filter($all_internet, function($v) { return $v !== null; });
    $data['summary']['avg_internet'] = count($all_internet_filtered) > 0 ? array_sum($all_internet_filtered) / count($all_internet_filtered) : 0;
    
    $max_deaths_key = array_search($data['summary']['max_deaths'], $all_deaths);
    $data['summary']['max_deaths_year'] = $years[$max_deaths_key];
    $min_deaths_key = array_search($data['summary']['min_deaths'], $all_deaths);
    $data['summary']['min_deaths_year'] = $years[$min_deaths_key];

    $sql_top_causes = "
        WITH RankedCauses AS (
            SELECT
                M.Year,
                TRIM(M.List) AS List, 
                TRIM(M.Cause) AS Cause,
                {$deaths_sum_sql} AS Cause_Total,
                ROW_NUMBER() OVER(PARTITION BY M.Year ORDER BY {$deaths_sum_sql} DESC) as rn
            FROM
                Mortalidad AS M
            WHERE 
                1=1
                {$sql_country_filter_mort} 
                {$cause_sql_clause}
            GROUP BY
                M.Year, TRIM(M.List), TRIM(M.Cause)
        )
        SELECT Year, List, Cause, Cause_Total
        FROM RankedCauses
        WHERE rn = 1
        ORDER BY Year DESC;
    ";
    
    $stmt_top_causes = $pdo->prepare($sql_top_causes);
    $stmt_top_causes->execute($params_mort);
    $data['top_causes_by_year'] = $stmt_top_causes->fetchAll(PDO::FETCH_ASSOC);
    
    $sql_top_overall = "
        SELECT TRIM(M.List) AS List, TRIM(M.Cause) AS Cause, 
               {$deaths_sum_sql} AS Total_Count
        FROM Mortalidad AS M
        WHERE 
            1=1
            {$sql_country_filter_mort} 
            {$cause_sql_clause}
        GROUP BY TRIM(M.List), TRIM(M.Cause)
        ORDER BY Total_Count DESC
        LIMIT 1;
    ";
    
    $stmt_top_overall = $pdo->prepare($sql_top_overall);
    $stmt_top_overall->execute($params_mort);
    $top_overall_result = $stmt_top_overall->fetch(PDO::FETCH_ASSOC);

    if ($top_overall_result) {
        $data['summary']['top_cause_list'] = $top_overall_result['List'];
        $data['summary']['top_cause_code'] = $top_overall_result['Cause'];
        $data['summary']['top_cause_overall_count'] = $top_overall_result['Total_Count'];
    }

    echo json_encode(['status' => 'success', 'data' => $data]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage() . ' (en get_stats.php)']);
}
?>
