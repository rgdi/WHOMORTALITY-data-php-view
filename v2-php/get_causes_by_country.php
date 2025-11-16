<?php
// ============================================================
// ARCHIVO 2: get_causes_by_country.php (NUEVO - Causas por país)
// ============================================================
?>
<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'regions.php';

$country = $_GET['country'] ?? '';

if (empty($country)) {
    echo json_encode(['error' => 'Falta el parámetro country']);
    exit;
}

try {
    $pdo = get_db_connection();
    
    // Obtener código del país
    $country_codes = [];
    $is_global = false;
    $searchNameLower = strtolower($country);
    
    if ($searchNameLower === 'global') {
        $is_global = true;
    } else {
        $country_codes = get_country_codes_for_region($searchNameLower);
        if ($country_codes === null) {
            $stmt = $pdo->prepare("SELECT TRIM(Codigo_Pais) AS Codigo_Pais FROM Paises WHERE Nombre LIKE ? LIMIT 1");
            $stmt->execute(["%$country%"]);
            $pais = $stmt->fetch();
            if ($pais) {
                $country_codes = [$pais['Codigo_Pais']];
            } else {
                throw new Exception("País '$country' no encontrado.");
            }
        }
    }
    
    // Construir filtro de países
    $sql_country_filter = "";
    $params = [];
    
    if (!$is_global) {
        $country_placeholders = implode(',', array_fill(0, count($country_codes), '?'));
        $sql_country_filter = "AND TRIM(M.Country) IN ({$country_placeholders})";
        $params = $country_codes;
    }
    
    // Buscar causas disponibles para este país/región
    $sql = "
        SELECT DISTINCT 
            TRIM(M.List) AS list_code,
            TRIM(M.Cause) AS cause_code,
            MIN(M.Year) as first_year,
            MAX(M.Year) as last_year,
            COUNT(*) as num_records
        FROM Mortalidad M
        WHERE 
            TRIM(M.List) != '' 
            AND TRIM(M.Cause) != ''
            AND LENGTH(TRIM(M.Cause)) <= 4
            {$sql_country_filter}
        GROUP BY TRIM(M.List), TRIM(M.Cause)
        ORDER BY list_code, cause_code
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $causes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($causes)) {
        echo json_encode(['error' => "No hay causas disponibles para '$country'"]);
        exit;
    }
    
    // Enriquecer con descripciones
    $stmt_desc = $pdo->query("
        SELECT 
            TRIM(short_code) AS code,
            TRIM(description) AS description,
            TRIM(icd_revision) AS revision
        FROM who_mortality_causes
    ");
    $descriptions = [];
    foreach ($stmt_desc->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $descriptions[$row['code']] = [
            'desc' => $row['description'],
            'rev' => $row['revision']
        ];
    }
    
    $output_causes = [];
    foreach ($causes as $cause) {
        $composite_code = $cause['list_code'] . '::' . $cause['cause_code'];
        
        $desc = "Causa {$cause['cause_code']}";
        $rev = "Unknown";
        
        if (isset($descriptions[$cause['cause_code']])) {
            $desc = $descriptions[$cause['cause_code']]['desc'];
            $rev = $descriptions[$cause['cause_code']]['rev'];
        }
        
        $desc .= " ({$cause['first_year']}-{$cause['last_year']})";
        
        $output_causes[] = [
            'code' => $composite_code,
            'rev'  => $rev,
            'desc' => $desc,
            'list' => $cause['list_code']
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'country' => $country,
        'causes' => $output_causes
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>