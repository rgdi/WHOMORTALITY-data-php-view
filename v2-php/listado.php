<?php
// Conectarse a la base de datos
require_once 'config.php';
try {
    $pdo = get_db_connection();
    // Obtener todos los países ordenados por nombre
    $stmt = $pdo->query("SELECT Nombre FROM Paises WHERE Nombre IS NOT NULL AND Nombre != '' ORDER BY Nombre");
    $paises = $stmt->fetchAll();
} catch (Exception $e) {
    die("Error al conectar o consultar la base de datos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listado de Países</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f9f9f9; }
        h1 { text-align: center; }
        .container { max-width: 1200px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        ul {
            /* Muestra la lista en 5 columnas */
            column-count: 5;
            list-style: none;
            padding: 0;
            column-gap: 20px;
        }
        li { margin-bottom: 8px; }
        a {
            text-decoration: none;
            color: #007bff;
            font-size: 14px;
        }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Listado de Países</h1>
        <p>Haz clic en un país para ver su análisis. Se abrirá en una nueva pestaña usando la página principal.</p>
        <ul>
            <?php foreach ($paises as $pais): ?>
                <li>
                    <a href="index.php?country=<?php echo urlencode($pais['Nombre']); ?>" target="_blank">
                        <?php echo htmlspecialchars($pais['Nombre']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</body>
</html>