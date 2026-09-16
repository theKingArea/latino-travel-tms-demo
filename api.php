<?php
// ============================================================
// LATINO TRAVEL - API PARA ADMIN Y CONDUCTORES
// Sirve exactamente el mismo contrato que antes usaba
// Firebase/Apps Script: GET devuelve {conductores, traslados},
// POST recibe acciones (cambiar conductor, iniciar/completar,
// subir factura, altas/bajas de conductores, y ahora también
// Papelera: ELIMINAR_VIAJE es un soft-delete (columna
// fecha_eliminado), RESTAURAR_VIAJE lo recupera, y GET ?papelera=1
// lista los viajes eliminados.
// ============================================================
require_once __DIR__ . '/../config.php';
// ── Módulo de correos de asignación/reasignación de conductor (2026-08-12) ──
// AISLADO a propósito: si mailer.php todavía no existe en el servidor, o le
// falta PHPMailer, o faltan las constantes SMTP en config.php, esto NUNCA
// debe tumbar el resto de la API (admin.html, app de conductores, panel de
// solo lectura dependen todos de este archivo). Por eso el require es
// condicional, y más abajo la función también se llama de forma defensiva.
if (file_exists(__DIR__ . '/mailer.php')) {
  require_once __DIR__ . '/mailer.php';
}
// ── Módulo de notificaciones push a la app de conductores (2026-08-21) ──
// Mismo patrón que mailer.php: AISLADO a propósito. Si push.php no existe
// todavía en el servidor, o falta la librería (vendor/autoload.php, se
// instala con `composer require minishlink/web-push`), o faltan las
// constantes VAPID_* en config.php, esto NUNCA debe tumbar el resto de la
// API. El require es condicional, y cada función se llama más abajo de
// forma defensiva (function_exists + try/catch no bloqueante).
if (file_exists(__DIR__ . '/push.php')) {
  require_once __DIR__ . '/push.php';
}
// URL del Apps Script que sube fotos a Google Drive (subir-fotos-drive.gs).
// Pega aquí la URL /exec que ya tienes desplegada.
define('GOOGLE_DRIVE_UPLOAD_URL', 'https://script.google.com/macros/s/TU_ID_DE_DEPLOYMENT/exec');
// URL de la app de conductores (login por PIN), usada en el correo de credenciales (2026-08-21).
define('URL_APP_CONDUCTORES', 'https://tudominio.com/conductores/');
// ── FIX DE SEGURIDAD (2026-08-06) ──────────────────────────
// Antes, el GET normal (traslados) y ?accion=listar_conductores no pedían
// NADA: cualquiera con la URL bajaba toda la base de viajes (nombres,
// teléfonos, vuelos, notas, links a Drive) y, peor, el listado de
// conductores CON SU PIN (la clave que usan para entrar a la app).
// Ahora ambos exigen una clave de lectura. Los tres consumidores que
// hacen GET (admin.html, index.html de conductores, panel de solo
// lectura) deben mandar "?clave=" con este mismo valor, o el adminKey
// completo si ya lo tienen (admin.html).
define('LT_VIEW_KEY', 'REEMPLAZA_CON_TU_CLAVE_DE_LECTURA');
function claveDeLecturaValida() {
  $clave = (string)($_GET['clave'] ?? '');
  $adminKey = (string)($_GET['adminKey'] ?? ($_SERVER['HTTP_X_ADMIN_API_KEY'] ?? ''));
  return ($clave !== '' && hash_equals(LT_VIEW_KEY, $clave))
      || ($adminKey !== '' && hash_equals(ADMIN_API_KEY, $adminKey));
}
// ─────────────────────────────────────────────────────────────
// ── CORS: a diferencia de Apps Script, aquí sí soportamos CORS real ──
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  manejarGet();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  manejarPost();
} else {
  http_response_code(405);
  echo json_encode(['error' => 'Método no permitido']);
}
// ══════════════════════════════════════════════════════════
// GET: devuelve { conductores: [...], traslados: [...] }
// o, con ?accion=listar_conductores, el detalle completo de
// conductores (para la pestaña de administración).
// ══════════════════════════════════════════════════════════
function manejarGet() {
  try {
    $pdo = obtenerConexionBD();
    if (($_GET['accion'] ?? '') === 'listar_conductores') {
      // Requiere clave: este listado incluye el PIN de cada conductor.
      if (!claveDeLecturaValida()) {
        registrarLog('api.php', 'GET', false, 'listar_conductores sin clave válida');
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        return;
      }
      manejarListarConductores($pdo);
      return;
    }
    if (($_GET['papelera'] ?? '') === '1') {
      manejarPapelera($pdo);
      return;
    }
    // Requiere clave: este es el listado completo de viajes (nombres,
    // teléfonos, vuelos, notas, links a Drive). Antes no pedía nada.
    if (!claveDeLecturaValida()) {
      registrarLog('api.php', 'GET', false, 'GET traslados sin clave válida');
      http_response_code(403);
      echo json_encode(['error' => 'No autorizado']);
      return;
    }
    $stmt = $pdo->query("SELECT * FROM viajes WHERE fecha_eliminado IS NULL ORDER BY fecha IS NULL, fecha, hora IS NULL, hora");
    $filas = $stmt->fetchAll();
    $traslados = array_map('mapearFilaATraslado', $filas);
    $stmtC = $pdo->query("SELECT nombre FROM conductores WHERE activo = 1 ORDER BY nombre");
    $conductores = $stmtC->fetchAll();
    registrarLog('api.php', 'GET', true, 'Filas devueltas: ' . count($traslados));
    echo json_encode(['conductores' => $conductores, 'traslados' => $traslados], JSON_UNESCAPED_UNICODE);
  } catch (Exception $e) {
    registrarLog('api.php', 'GET', false, $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo cargar la información.']);
  }
}
/**
 * Devuelve el detalle completo de la tabla `conductores` (activos e
 * inactivos), con la cantidad de viajes que tiene asignados cada uno,
 * para poder decidir en el panel si se puede eliminar o solo desactivar.
 */
function manejarListarConductores($pdo) {
  try {
    $sql = "SELECT c.nombre, c.activo, c.tipo_conductor, c.tipo_vehiculo, c.pin, c.email,
              (SELECT COUNT(*) FROM viajes v WHERE UPPER(TRIM(v.conductor)) = UPPER(TRIM(c.nombre))) AS viajes_asignados
            FROM conductores c
            ORDER BY c.nombre";
    $stmt = $pdo->query($sql);
    $conductores = $stmt->fetchAll();
    registrarLog('api.php', 'GET', true, 'listar_conductores: ' . count($conductores));
    echo json_encode(['conductores' => $conductores], JSON_UNESCAPED_UNICODE);
  } catch (Exception $e) {
    registrarLog('api.php', 'GET', false, $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo cargar la lista de conductores.']);
  }
}
/**
 * Devuelve los viajes en la papelera (fecha_eliminado NO nulo), con la
 * fecha en que se eliminaron. Requiere adminKey por GET porque muestra
 * datos de viajes borrados.
 */
function manejarPapelera($pdo) {
  if (($_GET['adminKey'] ?? '') !== ADMIN_API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    return;
  }
  try {
    $stmt = $pdo->query("SELECT * FROM viajes WHERE fecha_eliminado IS NOT NULL ORDER BY fecha_eliminado DESC");
    $filas = $stmt->fetchAll();
    $traslados = array_map(function ($fila) {
      $t = mapearFilaATraslado($fila);
      $t['fechaEliminado'] = $fila['fecha_eliminado'] ? date('d/m/Y H:i', strtotime($fila['fecha_eliminado'])) : '';
      return $t;
    }, $filas);
    registrarLog('api.php', 'GET', true, 'papelera: ' . count($traslados));
    echo json_encode(['traslados' => $traslados], JSON_UNESCAPED_UNICODE);
  } catch (Exception $e) {
    registrarLog('api.php', 'GET', false, $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo cargar la papelera.']);
  }
}
/** Convierte una fila de la tabla viajes al formato que esperan los frontends. */
function mapearFilaATraslado($fila) {
  $trayectoMostrar = construirTrayectoMostrar($fila);
  $pasajeros = $fila['pasajero_principal'] ?? '';
  if (!empty($fila['acompanantes'])) {
    $acompanantesLinea = str_replace(["\r\n", "\r", "\n"], ', ', trim($fila['acompanantes']));
    $pasajeros = trim($pasajeros . ($pasajeros && $acompanantesLinea ? ', ' : '') . $acompanantesLinea);
  }
  return [
    'idEvento'         => $fila['id_evento'],
    'fecha'            => $fila['fecha'] ? date('d/m/Y', strtotime($fila['fecha'])) : '',
    'hora'             => $fila['hora'] ? substr($fila['hora'], 0, 5) : '',
    'vuelo'            => $fila['vuelo'] ?: '',
    'trayecto'         => $trayectoMostrar,
    'lugarRecogida'    => $fila['lugar_recogida'] ?: '',
    'pasajeroPrincipal'=> $fila['pasajero_principal'] ?: '',
    'telefono'         => $fila['telefono'] ?: '',
    'pasajeros'        => $pasajeros ?: 'SIN ESPECIFICAR',
    'cantidadPasajeros'=> (int)$fila['cantidad_pasajeros'],
    'conductor'        => $fila['conductor'] ?: 'POR ASIGNAR',
    'vendedor'         => $fila['vendedor'] ?: '',
    'estado'           => $fila['estado'],
    'fotoDespachoUrl'  => $fila['foto_despacho_url'] ?: '',
    'facturaUrl'       => $fila['factura_url'] ?: '',
    'notas'            => $fila['notas'] ?: '',
    // Notas para conductor: campo NUEVO, distinto de `notas` (que es interno
    // administrativo). Este sí se manda a la app de conductores.
    'notasConductor'   => $fila['notas_conductor'] ?: '',
    'horaCompletado'   => $fila['hora_completado'] ? date('d/m/Y H:i', strtotime($fila['hora_completado'])) : '',
    // Guía/Asistencia: nombre se carga desde Editar viaje; el monto y el
    // estado de pago (independiente del pago al conductor) se manejan en
    // reportes.php, no en api.php.
    'guiaNombre'       => $fila['guia_nombre'] ?: ''
  ];
}
/**
 * Arma el texto de trayecto que se muestra en los paneles y en la app de
 * conductores. Devuelve el texto base tal cual está guardado (trayecto o,
 * si está vacío, tour_actividad) — SIN agregar ningún prefijo de
 * "Llegada —"/"Salida —"/"Tour/Actividad:". Antes se agregaba ese prefijo
 * según el tramo, pero como el campo se puede editar desde el panel admin
 * y el texto editado (ya con el prefijo puesto) se volvía a guardar tal
 * cual, el prefijo se apilaba cada vez que se editaba el mismo viaje
 * ("Llegada — Llegada — Llegada — ..."). Se simplificó para que nunca más
 * pueda pasar.
 */
function construirTrayectoMostrar($fila) {
  return $fila['trayecto'] ?: $fila['tour_actividad'] ?: 'Traslado';
}
// ══════════════════════════════════════════════════════════
// POST: recibe acciones del admin o de la app de conductores
// ══════════════════════════════════════════════════════════
/** Busca la sesión activa (no vencida) de un conductor por su token. Devuelve el nombre o null. */
function obtenerConductorPorToken($pdo, $token) {
  if (!$token) return null;
  $stmt = $pdo->prepare("SELECT conductor_nombre FROM sesiones_conductor WHERE token = ? AND expira_en > NOW()");
  $stmt->execute([$token]);
  $fila = $stmt->fetch();
  return $fila ? $fila['conductor_nombre'] : null;
}
/**
 * Guarda (o actualiza) la suscripción push que el navegador del conductor
 * generó al aceptar las notificaciones. Requiere tabla `push_subscripciones`
 * (ver SQL entregado aparte) y la función `guardarSuscripcionPush()` de
 * push.php — si push.php no está subido todavía, avisa con un error claro
 * en vez de romper con un error de función no definida.
 */
function manejarGuardarPushSubscripcion($pdo, $datos) {
  $conductorToken = obtenerConductorPorToken($pdo, $datos['token'] ?? '');
  if (!$conductorToken) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sesión inválida o vencida. Vuelve a ingresar tu PIN.']);
    return;
  }
  if (!function_exists('guardarSuscripcionPush')) {
    echo json_encode(['success' => false, 'error' => 'Notificaciones push no configuradas todavía en el servidor']);
    return;
  }
  $sub = $datos['subscription'] ?? null;
  $endpoint = trim((string)($sub['endpoint'] ?? ''));
  $p256dh = trim((string)($sub['keys']['p256dh'] ?? ''));
  $auth = trim((string)($sub['keys']['auth'] ?? ''));
  if ($endpoint === '' || $p256dh === '' || $auth === '') {
    echo json_encode(['success' => false, 'error' => 'Suscripción incompleta']);
    return;
  }
  guardarSuscripcionPush($pdo, $conductorToken, $endpoint, $p256dh, $auth);
  registrarLog('api.php', 'POST', true, "GUARDAR_PUSH_SUBSCRIPCION conductor=$conductorToken");
  echo json_encode(['success' => true]);
}
function manejarPost() {
  $cuerpo = file_get_contents('php://input');
  $datos = json_decode($cuerpo, true);
  if (!$datos) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Cuerpo inválido']);
    return;
  }
  $accion = $datos['accion'] ?? '';
  // ── Acciones sobre la tabla `conductores` (altas/bajas). No tocan
  //    viajes, así que se resuelven antes de exigir idEvento. ──
  if (in_array($accion, ['AGREGAR_CONDUCTOR', 'ELIMINAR_CONDUCTOR', 'TOGGLE_ACTIVO_CONDUCTOR', 'EDITAR_EMAIL_CONDUCTOR', 'ENVIAR_CREDENCIALES_CONDUCTOR', 'ENVIAR_CREDENCIALES_TODOS'], true)) {
    try {
      $pdo = obtenerConexionBD();
      manejarAccionConductor($pdo, $accion, $datos);
    } catch (Exception $e) {
      registrarLog('api.php', 'POST', false, $e->getMessage());
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
    }
    return;
  }
  // ── Guardar suscripción push del conductor (app de conductores). No es
  //    sobre un viaje puntual, así que también se resuelve antes de exigir
  //    idEvento. Se autentica con el token de sesión del conductor (igual
  //    que SUBIR_FACTURA / nuevoEstado), no con adminKey. ──
  if ($accion === 'GUARDAR_PUSH_SUBSCRIPCION') {
    try {
      $pdo = obtenerConexionBD();
      manejarGuardarPushSubscripcion($pdo, $datos);
    } catch (Exception $e) {
      registrarLog('api.php', 'POST', false, $e->getMessage());
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
    }
    return;
  }
  if (empty($datos['idEvento'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Falta idEvento']);
    return;
  }
  try {
    $pdo = obtenerConexionBD();
    $stmtBuscar = $pdo->prepare("SELECT * FROM viajes WHERE id_evento = ?");
    $stmtBuscar->execute([$datos['idEvento']]);
    $viaje = $stmtBuscar->fetch();
    if (!$viaje) {
      echo json_encode(['success' => false, 'error' => 'Viaje no encontrado']);
      return;
    }
    // ── Acción: eliminar viaje (panel admin) — manda a la Papelera,
    //    no borra físicamente. Se recupera con RESTAURAR_VIAJE. ──
    if (($datos['accion'] ?? '') === 'ELIMINAR_VIAJE') {
      if (($datos['adminKey'] ?? '') !== ADMIN_API_KEY) {
        registrarLog('api.php', 'POST', false, 'Intento ELIMINAR_VIAJE sin adminKey válida');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return;
      }
      $stmt = $pdo->prepare("UPDATE viajes SET fecha_eliminado = NOW() WHERE id_evento = ?");
      $stmt->execute([$datos['idEvento']]);
      registrarLog('api.php', 'POST', true, "ELIMINAR_VIAJE (a papelera) idEvento={$datos['idEvento']}");
      echo json_encode(['success' => true]);
      return;
    }
    // ── Acción: restaurar viaje desde la Papelera (panel admin) ──
    if (($datos['accion'] ?? '') === 'RESTAURAR_VIAJE') {
      if (($datos['adminKey'] ?? '') !== ADMIN_API_KEY) {
        registrarLog('api.php', 'POST', false, 'Intento RESTAURAR_VIAJE sin adminKey válida');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return;
      }
      $stmt = $pdo->prepare("UPDATE viajes SET fecha_eliminado = NULL WHERE id_evento = ?");
      $stmt->execute([$datos['idEvento']]);
      registrarLog('api.php', 'POST', true, "RESTAURAR_VIAJE idEvento={$datos['idEvento']}");
      echo json_encode(['success' => true]);
      return;
    }
    // ── Acción: editar cualquier campo del viaje (panel admin) ──
    if (($datos['accion'] ?? '') === 'EDITAR_VIAJE') {
      if (($datos['adminKey'] ?? '') !== ADMIN_API_KEY) {
        registrarLog('api.php', 'POST', false, 'Intento EDITAR_VIAJE sin adminKey válida');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return;
      }
      $camposPermitidos = ['fecha', 'hora', 'trayecto', 'lugar_recogida', 'vuelo',
                            'pasajero_principal', 'telefono', 'cantidad_pasajeros', 'vendedor',
                            'conductor', 'notas', 'notas_conductor', 'guia_nombre'];
      $sets = [];
      $valores = [];
      foreach ($camposPermitidos as $campo) {
        if (array_key_exists($campo, $datos)) {
          $sets[] = "$campo = ?";
          $valor = $datos[$campo];
          $valores[] = ($valor === '' ? null : $valor);
        }
      }
      if (empty($sets)) {
        echo json_encode(['success' => false, 'error' => 'No se envió ningún campo para editar']);
        return;
      }
      // Guardamos el conductor y la nota ANTERIORES antes del UPDATE: el modal
      // "Editar viaje" también puede cambiar el conductor (junto con el resto de
      // los campos), es un segundo camino además del botón rápido
      // CAMBIAR_CONDUCTOR — los dos deben disparar el correo/push de
      // asignación/reasignación. La nota anterior sirve para saber si
      // notas_conductor realmente CAMBIÓ (y no solo se reenvió igual).
      $conductorAnteriorEdit = $viaje['conductor'] ?? '';
      $notasConductorAnteriorEdit = (string)($viaje['notas_conductor'] ?? '');
      $valores[] = $datos['idEvento'];
      $sql = "UPDATE viajes SET " . implode(', ', $sets) . " WHERE id_evento = ?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($valores);
      registrarLog('api.php', 'POST', true, "EDITAR_VIAJE idEvento={$datos['idEvento']}");

      // Vista "actualizada" del viaje en memoria (refleja los campos que se
      // acaban de editar), usada para el correo de asignación y para los
      // avisos push de reasignación / nota nueva.
      $viajeActualizado = $viaje;
      foreach ($camposPermitidos as $campo) {
        if (array_key_exists($campo, $datos)) {
          $viajeActualizado[$campo] = ($datos[$campo] === '' ? null : $datos[$campo]);
        }
      }

      // Si el conductor fue uno de los campos editados en este modal, disparar el
      // mismo correo que dispara CAMBIAR_CONDUCTOR. Módulo opcional y aislado, igual
      // que en el otro bloque: nunca bloquea ni rompe la respuesta si falla.
      if (array_key_exists('conductor', $datos)) {
        $nuevoConductorEdit = trim($datos['conductor'] ?? '');
        if ($nuevoConductorEdit !== '' && function_exists('procesarNotificacionesCambioConductor')) {
          try {
            procesarNotificacionesCambioConductor($pdo, $viajeActualizado, $conductorAnteriorEdit, $nuevoConductorEdit);
          } catch (\Throwable $e) {
            registrarLog('api.php', 'POST', false, 'Correo de asignación (vía EDITAR_VIAJE) falló (no bloqueante): ' . $e->getMessage());
          }
        }
        // Push de reasignación (2026-08-21): mismo disparador que el correo,
        // pero solo si el conductor realmente cambió (evita reenviar el aviso
        // si se guarda el modal sin tocar el conductor).
        if ($nuevoConductorEdit !== '' && strtoupper(trim($nuevoConductorEdit)) !== strtoupper(trim($conductorAnteriorEdit)) && function_exists('procesarPushCambioConductor')) {
          try {
            procesarPushCambioConductor($pdo, $viajeActualizado, $conductorAnteriorEdit, $nuevoConductorEdit);
          } catch (\Throwable $e) {
            registrarLog('api.php', 'POST', false, 'Push de reasignación (vía EDITAR_VIAJE) falló (no bloqueante): ' . $e->getMessage());
          }
        }
      }

      // Push de "se agregó una nota" (2026-08-21): solo si notas_conductor vino
      // en el request Y cambió de verdad respecto al valor anterior, y solo si
      // quedó texto (una nota borrada no dispara aviso). Le avisa al conductor
      // que queda asignado DESPUÉS de este guardado (por si también se cambió
      // el conductor en el mismo guardado).
      if (array_key_exists('notas_conductor', $datos)) {
        $notaNuevaEdit = trim((string)($datos['notas_conductor'] ?? ''));
        if ($notaNuevaEdit !== '' && $notaNuevaEdit !== trim($notasConductorAnteriorEdit) && function_exists('procesarPushNotaAgregada')) {
          try {
            $conductorDestinoNota = array_key_exists('conductor', $datos) ? trim($datos['conductor']) : $conductorAnteriorEdit;
            if ($conductorDestinoNota !== '') {
              procesarPushNotaAgregada($pdo, $viajeActualizado, $conductorDestinoNota);
            }
          } catch (\Throwable $e) {
            registrarLog('api.php', 'POST', false, 'Push de nota (vía EDITAR_VIAJE) falló (no bloqueante): ' . $e->getMessage());
          }
        }
      }

      echo json_encode(['success' => true]);
      return;
    }
    // ── Acción: completar viaje manualmente desde el panel admin, sin foto ──
    // Para conductores que todavía no usan la app de conductores: el admin
    // marca el viaje como COMPLETADO directamente desde admin.html. A
    // propósito NO pide token de conductor ni foto (a diferencia de
    // `nuevoEstado`, que es el camino que usa la app) — solo adminKey.
    // Deja el viaje en el mismo estado final que dejaría la app
    // (estado=COMPLETADO + hora_completado), para que se vea igual en
    // reportes y estadísticas. Es una acción separada de EDITAR_VIAJE (en
    // vez de sumar 'estado' a $camposPermitidos) para no permitir setear
    // cualquier estado a mano sin las validaciones de transición.
    if (($datos['accion'] ?? '') === 'COMPLETAR_VIAJE_MANUAL') {
      if (($datos['adminKey'] ?? '') !== ADMIN_API_KEY) {
        registrarLog('api.php', 'POST', false, 'Intento COMPLETAR_VIAJE_MANUAL sin adminKey válida');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return;
      }
      if ($viaje['estado'] === 'COMPLETADO') {
        echo json_encode(['success' => false, 'error' => 'Este viaje ya está COMPLETADO']);
        return;
      }
      $stmt = $pdo->prepare("UPDATE viajes SET estado = 'COMPLETADO', hora_completado = NOW() WHERE id_evento = ?");
      $stmt->execute([$datos['idEvento']]);
      registrarLog('api.php', 'POST', true, "COMPLETAR_VIAJE_MANUAL idEvento={$datos['idEvento']}");
      echo json_encode(['success' => true]);
      return;
    }
    // ── Acción: cambiar conductor (panel admin) ──
    if (($datos['accion'] ?? '') === 'CAMBIAR_CONDUCTOR') {
      if (($datos['adminKey'] ?? '') !== ADMIN_API_KEY) {
        registrarLog('api.php', 'POST', false, 'Intento CAMBIAR_CONDUCTOR sin adminKey válida');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return;
      }
      $nuevoConductor = trim($datos['nuevoConductor'] ?? '');
      if ($nuevoConductor === '') {
        echo json_encode(['success' => false, 'error' => 'Falta nuevoConductor']);
        return;
      }
      // Guardamos quién era el conductor ANTES del cambio (para saber si es
      // primera asignación o reasignación, y para poder avisarle a él también).
      $conductorAnterior = $viaje['conductor'] ?? '';
      $stmt = $pdo->prepare("UPDATE viajes SET conductor = ? WHERE id_evento = ?");
      $stmt->execute([$nuevoConductor, $datos['idEvento']]);
      registrarLog('api.php', 'POST', true, "CAMBIAR_CONDUCTOR idEvento={$datos['idEvento']} -> $nuevoConductor");
      // Correo de asignación/reasignación al conductor (2026-08-12). Módulo
      // opcional y aislado: si la función no existe (mailer.php no está
      // subido todavía) o falla por cualquier motivo, se loguea y se sigue
      // de largo — el cambio de conductor YA se guardó arriba y la
      // respuesta de éxito no depende de esto.
      $viaje['conductor'] = $nuevoConductor;
      if (function_exists('procesarNotificacionesCambioConductor')) {
        try {
          procesarNotificacionesCambioConductor($pdo, $viaje, $conductorAnterior, $nuevoConductor);
        } catch (\Throwable $e) {
          registrarLog('api.php', 'POST', false, 'Correo de asignación falló (no bloqueante): ' . $e->getMessage());
        }
      }
      // Push de asignación/reasignación (2026-08-21). Mismo criterio que el
      // correo: no bloqueante, y solo si el conductor realmente cambió.
      if (strtoupper(trim($nuevoConductor)) !== strtoupper(trim($conductorAnterior)) && function_exists('procesarPushCambioConductor')) {
        try {
          procesarPushCambioConductor($pdo, $viaje, $conductorAnterior, $nuevoConductor);
        } catch (\Throwable $e) {
          registrarLog('api.php', 'POST', false, 'Push de reasignación falló (no bloqueante): ' . $e->getMessage());
        }
      }
      echo json_encode(['success' => true]);
      return;
    }
    // ── Acción: subir factura (app de conductores) ──
    if (($datos['accion'] ?? '') === 'SUBIR_FACTURA') {
      $conductorToken = obtenerConductorPorToken($pdo, $datos['token'] ?? '');
      if (!$conductorToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sesión inválida o vencida. Vuelve a ingresar tu PIN.']);
        return;
      }
      if (strtoupper(trim($conductorToken)) !== strtoupper(trim($viaje['conductor']))) {
        registrarLog('api.php', 'POST', false, "Conductor {$conductorToken} intentó tocar viaje de {$viaje['conductor']}");
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Este viaje no está asignado a tu usuario.']);
        return;
      }
      $url = null;
      if (!empty($datos['foto'])) {
        $url = guardarFotoLocal($datos['foto'], 'factura', $viaje['id_evento'], $viaje['conductor']);
      }
      $stmt = $pdo->prepare("UPDATE viajes SET factura_url = ? WHERE id_evento = ?");
      $stmt->execute([$url, $datos['idEvento']]);
      registrarLog('api.php', 'POST', true, "SUBIR_FACTURA idEvento={$datos['idEvento']}");
      echo json_encode(['success' => true, 'facturaUrl' => $url]);
      return;
    }
    // ── Cambio de estado (app de conductores: iniciar / completar) ──
    if (!empty($datos['nuevoEstado'])) {
      $conductorToken = obtenerConductorPorToken($pdo, $datos['token'] ?? '');
      if (!$conductorToken) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sesión inválida o vencida. Vuelve a ingresar tu PIN.']);
        return;
      }
      if (strtoupper(trim($conductorToken)) !== strtoupper(trim($viaje['conductor']))) {
        registrarLog('api.php', 'POST', false, "Conductor {$conductorToken} intentó tocar viaje de {$viaje['conductor']}");
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Este viaje no está asignado a tu usuario.']);
        return;
      }
      $nuevoEstado = strtoupper(trim($datos['nuevoEstado']));
      $estadoActual = $viaje['estado'];
      if ($estadoActual === 'COMPLETADO') {
        echo json_encode(['success' => false, 'error' => 'No se puede modificar un viaje COMPLETADO']);
        return;
      }
      if ($estadoActual === 'INICIADO' && $nuevoEstado === 'PENDIENTE') {
        echo json_encode(['success' => false, 'error' => 'No se puede volver a PENDIENTE']);
        return;
      }
      $fotoUrl = null;
      if (!empty($datos['foto']) && $nuevoEstado === 'INICIADO') {
        $fotoUrl = guardarFotoLocal($datos['foto'], 'despacho', $viaje['id_evento'], $viaje['conductor']);
      }
      if ($nuevoEstado === 'COMPLETADO') {
        $stmt = $pdo->prepare("UPDATE viajes SET estado = ?, hora_completado = NOW() WHERE id_evento = ?");
        $stmt->execute([$nuevoEstado, $datos['idEvento']]);
      } elseif ($fotoUrl) {
        $stmt = $pdo->prepare("UPDATE viajes SET estado = ?, foto_despacho_url = ? WHERE id_evento = ?");
        $stmt->execute([$nuevoEstado, $fotoUrl, $datos['idEvento']]);
      } else {
        $stmt = $pdo->prepare("UPDATE viajes SET estado = ? WHERE id_evento = ?");
        $stmt->execute([$nuevoEstado, $datos['idEvento']]);
      }
      registrarLog('api.php', 'POST', true, "ESTADO idEvento={$datos['idEvento']} -> $nuevoEstado");
      echo json_encode(['success' => true]);
      return;
    }
    echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);
  } catch (Exception $e) {
    registrarLog('api.php', 'POST', false, $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
  }
}
/**
 * Maneja las acciones de administración de la tabla `conductores`:
 * agregar, eliminar (solo si no tiene viajes asignados) y
 * activar/desactivar. Todas requieren ADMIN_API_KEY.
 */
function manejarAccionConductor($pdo, $accion, $datos) {
  if (($datos['adminKey'] ?? '') !== ADMIN_API_KEY) {
    registrarLog('api.php', 'POST', false, "Intento $accion sin adminKey válida");
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    return;
  }
  if ($accion === 'AGREGAR_CONDUCTOR') {
    $nombre = strtoupper(trim($datos['nombre'] ?? ''));
    if ($nombre === '') {
      echo json_encode(['success' => false, 'error' => 'Falta el nombre del conductor']);
      return;
    }
    $tipoConductor = in_array($datos['tipo_conductor'] ?? '', ['interno', 'externo'], true)
      ? $datos['tipo_conductor'] : 'externo';
    $tipoVehiculo = ($tipoConductor === 'interno' && in_array($datos['tipo_vehiculo'] ?? '', ['sedan_van', 'hiace_bus'], true))
      ? $datos['tipo_vehiculo'] : null;
    $stmtExiste = $pdo->prepare("SELECT COUNT(*) FROM conductores WHERE UPPER(TRIM(nombre)) = ?");
    $stmtExiste->execute([$nombre]);
    if ((int)$stmtExiste->fetchColumn() > 0) {
      echo json_encode(['success' => false, 'error' => 'Ya existe un conductor con ese nombre']);
      return;
    }
    // `pin` es único en la tabla (lo usa el conductor para entrar a su
    // app). Si el admin escribió uno se valida que no esté en uso; si lo
    // dejó vacío se genera un PIN de 4 dígitos al azar que no choque.
    $pin = trim($datos['pin'] ?? '');
    if ($pin !== '') {
      $stmtPin = $pdo->prepare("SELECT COUNT(*) FROM conductores WHERE pin = ?");
      $stmtPin->execute([$pin]);
      if ((int)$stmtPin->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'Ese PIN ya lo usa otro conductor. Probá con otro.']);
        return;
      }
    } else {
      $intentos = 0;
      $existePin = true;
      do {
        $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $stmtPin = $pdo->prepare("SELECT COUNT(*) FROM conductores WHERE pin = ?");
        $stmtPin->execute([$pin]);
        $existePin = (int)$stmtPin->fetchColumn() > 0;
        $intentos++;
      } while ($existePin && $intentos < 30);
      if ($existePin) {
        echo json_encode(['success' => false, 'error' => 'No se pudo generar un PIN único. Intenta de nuevo.']);
        return;
      }
    }
    // Email opcional (2026-08-21): si viene y no es válido, se avisa en vez
    // de guardar basura silenciosamente. No es obligatorio — un conductor
    // sin email simplemente no puede recibir el correo de credenciales.
    $email = trim($datos['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['success' => false, 'error' => 'Ese email no parece válido']);
      return;
    }
    $stmt = $pdo->prepare("INSERT INTO conductores (nombre, pin, activo, tipo_conductor, tipo_vehiculo, email) VALUES (?, ?, 1, ?, ?, ?)");
    $stmt->execute([$nombre, $pin, $tipoConductor, $tipoVehiculo, $email === '' ? null : $email]);
    registrarLog('api.php', 'POST', true, "AGREGAR_CONDUCTOR nombre=$nombre pin=$pin");
    echo json_encode(['success' => true, 'pin' => $pin]);
    return;
  }
  if ($accion === 'ELIMINAR_CONDUCTOR') {
    $nombre = trim($datos['nombre'] ?? '');
    if ($nombre === '') {
      echo json_encode(['success' => false, 'error' => 'Falta el nombre del conductor']);
      return;
    }
    $stmtViajes = $pdo->prepare("SELECT COUNT(*) FROM viajes WHERE UPPER(TRIM(conductor)) = UPPER(TRIM(?))");
    $stmtViajes->execute([$nombre]);
    $totalViajes = (int)$stmtViajes->fetchColumn();
    if ($totalViajes > 0) {
      echo json_encode([
        'success' => false,
        'error' => "No se puede eliminar: tiene $totalViajes viaje(s) asignado(s). Reasígnalos primero o desactívalo."
      ]);
      return;
    }
    $stmt = $pdo->prepare("DELETE FROM conductores WHERE UPPER(TRIM(nombre)) = UPPER(TRIM(?))");
    $stmt->execute([$nombre]);
    registrarLog('api.php', 'POST', true, "ELIMINAR_CONDUCTOR nombre=$nombre");
    echo json_encode(['success' => true]);
    return;
  }
  if ($accion === 'TOGGLE_ACTIVO_CONDUCTOR') {
    $nombre = trim($datos['nombre'] ?? '');
    if ($nombre === '') {
      echo json_encode(['success' => false, 'error' => 'Falta el nombre del conductor']);
      return;
    }
    $nuevoActivo = !empty($datos['activo']) ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE conductores SET activo = ? WHERE UPPER(TRIM(nombre)) = UPPER(TRIM(?))");
    $stmt->execute([$nuevoActivo, $nombre]);
    registrarLog('api.php', 'POST', true, "TOGGLE_ACTIVO_CONDUCTOR nombre=$nombre -> $nuevoActivo");
    echo json_encode(['success' => true]);
    return;
  }
  // ── Editar el email de un conductor (2026-08-21). Se puede dejar vacío
  //    para borrarlo. Sirve tanto para cargarlo por primera vez como para
  //    corregirlo — no hay un modal de "editar conductor" completo, esto
  //    es la única excepción, con un prompt() simple del lado del panel. ──
  if ($accion === 'EDITAR_EMAIL_CONDUCTOR') {
    $nombre = trim($datos['nombre'] ?? '');
    if ($nombre === '') {
      echo json_encode(['success' => false, 'error' => 'Falta el nombre del conductor']);
      return;
    }
    $email = trim($datos['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['success' => false, 'error' => 'Ese email no parece válido']);
      return;
    }
    $stmt = $pdo->prepare("UPDATE conductores SET email = ? WHERE UPPER(TRIM(nombre)) = UPPER(TRIM(?))");
    $stmt->execute([$email === '' ? null : $email, $nombre]);
    registrarLog('api.php', 'POST', true, "EDITAR_EMAIL_CONDUCTOR nombre=$nombre");
    echo json_encode(['success' => true]);
    return;
  }
  // ── Mandar (o reenviar) el correo de credenciales — link de la app +
  //    PIN — a UN conductor puntual. Requiere que ya tenga email cargado
  //    (columna `email` de `conductores`). Reusa enviarCorreo() de
  //    mailer.php, mismo módulo aislado que los correos de asignación:
  //    si mailer.php o PHPMailer no están configurados, devuelve un error
  //    claro en vez de romper. (2026-08-21) ──
  if ($accion === 'ENVIAR_CREDENCIALES_CONDUCTOR') {
    $nombre = trim($datos['nombre'] ?? '');
    if ($nombre === '') {
      echo json_encode(['success' => false, 'error' => 'Falta el nombre del conductor']);
      return;
    }
    if (!function_exists('correoAsignacionDisponible') || !correoAsignacionDisponible()) {
      echo json_encode(['success' => false, 'error' => 'El envío de correos no está configurado todavía en el servidor (falta PHPMailer o las constantes SMTP en config.php)']);
      return;
    }
    $stmt = $pdo->prepare("SELECT nombre, pin, email FROM conductores WHERE UPPER(TRIM(nombre)) = UPPER(TRIM(?))");
    $stmt->execute([$nombre]);
    $conductor = $stmt->fetch();
    if (!$conductor) {
      echo json_encode(['success' => false, 'error' => 'Conductor no encontrado']);
      return;
    }
    if (empty($conductor['email'])) {
      echo json_encode(['success' => false, 'error' => 'Este conductor no tiene email cargado']);
      return;
    }
    $cuerpo = construirCuerpoCorreoCredenciales($conductor['nombre'], $conductor['pin'], URL_APP_CONDUCTORES);
    $ok = enviarCorreo($conductor['email'], $conductor['nombre'], 'Tu acceso a la app de conductores — Latino Travel', $cuerpo);
    registrarLog('api.php', 'POST', $ok, "ENVIAR_CREDENCIALES_CONDUCTOR nombre=$nombre email={$conductor['email']}");
    if (!$ok) {
      echo json_encode(['success' => false, 'error' => 'No se pudo enviar el correo (revisar log del servidor)']);
      return;
    }
    echo json_encode(['success' => true]);
    return;
  }
  // ── Mandar el correo de credenciales a TODOS los conductores activos
  //    que tengan email cargado. Devuelve un resumen para mostrar en el
  //    panel (cuántos se mandaron, cuántos no tenían email, cuántos
  //    fallaron el envío puntual). (2026-08-21) ──
  if ($accion === 'ENVIAR_CREDENCIALES_TODOS') {
    if (!function_exists('correoAsignacionDisponible') || !correoAsignacionDisponible()) {
      echo json_encode(['success' => false, 'error' => 'El envío de correos no está configurado todavía en el servidor (falta PHPMailer o las constantes SMTP en config.php)']);
      return;
    }
    $stmtTodos = $pdo->query("SELECT nombre, pin, email FROM conductores WHERE activo = 1");
    $activos = $stmtTodos->fetchAll();
    $totalActivos = count($activos);
    $enviados = 0;
    $fallidos = [];
    $sinEmail = 0;
    foreach ($activos as $c) {
      if (empty($c['email'])) {
        $sinEmail++;
        continue;
      }
      $cuerpo = construirCuerpoCorreoCredenciales($c['nombre'], $c['pin'], URL_APP_CONDUCTORES);
      $ok = enviarCorreo($c['email'], $c['nombre'], 'Tu acceso a la app de conductores — Latino Travel', $cuerpo);
      if ($ok) {
        $enviados++;
      } else {
        $fallidos[] = $c['nombre'];
      }
    }
    registrarLog('api.php', 'POST', true, "ENVIAR_CREDENCIALES_TODOS activos=$totalActivos enviados=$enviados sinEmail=$sinEmail fallidos=" . implode(',', $fallidos));
    echo json_encode([
      'success' => true,
      'totalActivos' => $totalActivos,
      'enviados' => $enviados,
      'sinEmail' => $sinEmail,
      'fallidos' => $fallidos,
    ]);
    return;
  }
  echo json_encode(['success' => false, 'error' => 'Acción de conductor no reconocida']);
}
/**
 * Sube la foto (base64) a Google Drive llamando al Apps Script ya
 * desplegado (subir-fotos-drive.gs), y devuelve la URL de Drive.
 * El PHP nunca escribe la imagen en el disco del hosting.
 */
function guardarFotoLocal($base64Data, $prefijo, $idEvento, $conductor) {
  $conductorSeguro = preg_replace('/[^A-Za-z0-9_\-]/', '_', $conductor ?: 'SIN_CONDUCTOR');
  $idEventoSeguro = preg_replace('/[^A-Za-z0-9_\-]/', '_', $idEvento);
  $nombreArchivo = $prefijo . '_' . $idEventoSeguro . '_' . time();
  $payload = json_encode([
    'foto' => $base64Data,
    'nombreArchivo' => $nombreArchivo,
    'conductor' => $conductorSeguro
  ]);
  $ch = curl_init(GOOGLE_DRIVE_UPLOAD_URL);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
  ]);
  $respuesta = curl_exec($ch);
  $errorCurl = curl_error($ch);
  curl_close($ch);
  if ($respuesta === false) {
    throw new Exception("No se pudo conectar con Drive: " . $errorCurl);
  }
  $datos = json_decode($respuesta, true);
  if (!$datos || empty($datos['success'])) {
    throw new Exception("Drive rechazó la subida: " . ($datos['error'] ?? substr($respuesta, 0, 300)));
  }
  return $datos['url'];
}