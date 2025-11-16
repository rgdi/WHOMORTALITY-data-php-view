<?php
// ============================================================
// ARCHIVO 1: get_causes.php (CORREGIDO - Script único)
// ============================================================
?>
<?php
header('Content-Type: application/json');
require_once 'config.php';

try {
    $pdo = get_db_connection();
    
    // Obtener TODAS las causas de la base de datos, sin filtrar por país
    $stmt = $pdo->query("
        SELECT DISTINCT 
            TRIM(M.List) AS list_code,
            TRIM(M.Cause) AS cause_code,
            COUNT(DISTINCT M.Country) as num_countries,
            MIN(M.Year) as first_year,
            MAX(M.Year) as last_year
        FROM Mortalidad M
        WHERE 
            TRIM(M.List) != '' 
            AND TRIM(M.Cause) != ''
            AND LENGTH(TRIM(M.Cause)) <= 4
        GROUP BY TRIM(M.List), TRIM(M.Cause)
        ORDER BY list_code, cause_code
    ");
    
    $causes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($causes)) {
        echo json_encode(['error' => 'No se encontraron causas en Mortalidad']);
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
        
        $desc .= " ({$cause['first_year']}-{$cause['last_year']}, {$cause['num_countries']} países)";
        
        $output_causes[] = [
            'code' => $composite_code,
            'rev'  => $rev,
            'desc' => $desc,
            'list' => $cause['list_code']
        ];
    }
    
    echo json_encode($output_causes);

} catch (Exception $e) {
    echo json_encode(['error' => 'Error al consultar causas: ' . $e->getMessage()]);
}
?>