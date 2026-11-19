<?php
// ============================================================
// ARCHIVO: config/config.php
// DESCRIPCIÓN: Constantes globales de la aplicación
// ============================================================

define('APP_ENV',     'development');
define('APP_NAME',    'SistemaEncuestas');
define('APP_VERSION', '1.0.0');
define('BASE_URL',    'http://localhost/encuestas_app');

// Rutas de directorios
define('ROOT_PATH',    dirname(__DIR__));
define('CONFIG_PATH',  ROOT_PATH . '/config');
define('INCLUDES_PATH',ROOT_PATH . '/includes');
define('MODULES_PATH', ROOT_PATH . '/modules');

// Reglas de negocio institucionales
define('MAX_DIAS_PLAZO',         15);  // Plazo legal estricto de respuesta (15 días calendario)
define('MAX_ENCUESTAS_URBANA',   20);  // Máximo de encuestas en zona urbana
define('MAX_ENCUESTAS_RURAL',    10);  // Máximo de encuestas en zona rural
define('HORA_CORTE_CAMPO',       '12:00'); // Hora de repliegue de encuestadores por seguridad
define('DIAS_ACUMULACION_URBANA', 7);  // Acumulación estratégica urbana
define('DIAS_ACUMULACION_RURAL', 14);  // Acumulación estratégica rural

// Configuración de mapas y georreferenciación
// Inserte su API Key de Google Maps aquí si dispone de una.
// Si se deja vacía (''), el sistema operará en modo híbrido/autónomo sin bloquear la aplicación.
define('GOOGLE_MAPS_API_KEY', '');

// Campo Comuna/Corregimiento en ventanilla.
// false = deshabilitado: la comuna se toma del barrio si ya existe, o queda "Por asignar".
// true  = el usuario de ventanilla elige la comuna (comportamiento original).
define('COMUNA_HABILITADA', false);

// Configuración de sesión
define('SESSION_LIFETIME', 3600);
define('SESSION_NAME',    'ENCUESTAS_SESSION');

// Gestión de errores
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Zona horaria legal de Colombia
date_default_timezone_set('America/Bogota');
