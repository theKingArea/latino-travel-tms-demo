<?php
// ============================================================
// config.example.php — PLANTILLA de configuración.
//
// Copiá este archivo como `config.php` (ese nombre SÍ está en
// .gitignore, nunca se sube al repo) y completá tus propios
// valores reales. api.php, recibir-tally.php, recibir-ota.php,
// etc. hacen `require_once __DIR__ . '/../config.php'` esperando
// encontrar estas constantes y esta función ya definidas.
// ============================================================

// ── Conexión a la base de datos (MySQL / MariaDB) ──
define('DB_HOST', 'localhost');
define('DB_NAME', 'nombre_de_tu_base');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_password');

function obtenerConexionBD() {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  return $pdo;
}

// ── Clave de administración ──
// La usan api.php (acciones que modifican datos) y admin.html
// (hardcodeada en su <script> — ver nota de seguridad en el README).
// Generá una clave larga y aleatoria propia, no reuses esta.
define('ADMIN_API_KEY', 'GENERA_UNA_CLAVE_LARGA_Y_ALEATORIA_PROPIA');

// ── Registro de actividad (log simple a archivo o tabla) ──
// api.php y recibir-tally.php llaman a registrarLog($origen, $metodo, $ok, $detalle)
// en cada operación. Implementación mínima de ejemplo:
function registrarLog($origen, $metodo, $ok, $detalle = '') {
  $linea = sprintf("[%s] %s %s %s - %s\n", date('c'), $origen, $metodo, $ok ? 'OK' : 'ERROR', $detalle);
  @file_put_contents(__DIR__ . '/log_actividad.txt', $linea, FILE_APPEND);
}

// ── SMTP (opcional — solo si subís mailer.php con PHPMailer) ──
// define('SMTP_HOST', 'smtp.tuproveedor.com');
// define('SMTP_PORT', 587);
// define('SMTP_USER', 'notificaciones@tudominio.com');
// define('SMTP_PASS', 'tu_password_de_aplicacion');

// ── Notificaciones push / VAPID (opcional — solo si subís push.php) ──
// define('VAPID_PUBLIC_KEY', '...');
// define('VAPID_PRIVATE_KEY', '...');
