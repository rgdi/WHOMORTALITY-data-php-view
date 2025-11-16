<?php
/* --------------------------------------------------------------------- */
/* 1. CONFIGURACIÓN DE LA BASE DE DATOS                                  */
/* --------------------------------------------------------------------- */
/* Actualiza estos valores con los de tu base de datos de Namecheap      */
/* --------------------------------------------------------------------- */

define('DB_HOST', 'localhost'); // Déjalo como 'localhost' a menos que Namecheap te diga lo contrario
define('DB_USER', 'rgodczxw_WHO_Mortalidad');       // Tu usuario de la base de datos (ej. rgodczxw_user)
define('DB_PASS', '55o&N7vtRx=D');     // Tu contraseña de la base de datos
define('DB_NAME', 'rgodczxw_WHO_Mortalidad_DB'); // El nombre de tu base de datos

/**
 * Función para conectar a la DB
 */
function get_db_connection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        throw new PDOException($e->getMessage(), (int)$e->getCode());
    }
}
?>