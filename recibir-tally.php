<?php
// ============================================================
// LATINO TRAVEL - RECIBIR WEBHOOK DE TALLY (v3, con tramos)
// URL a configurar en Tally: https://api.tudominio.com/recibir-tally.php
//
// FIX 2026-08-13 (Enrique) — IDA Y VUELTA no invertía el trayecto de la
// VUELTA. $trayecto se calculaba UNA sola vez (a partir del dropdown
// "TIPO DE TRASLADO", ej. "AEROPUERTO TOCUMEN A HOTEL CIUDAD" ->
// sustituido a "AEROPUERTO TOCUMEN A WAYMORE") y se guardaba IGUAL en
// todos los tramos (LLEGADA, TOUR, SALIDA), sin importar que el tramo
// SALIDA de un traslado "IDA Y VUELTA" en realidad va en la dirección
// contraria (el pasajero sale DEL hotel HACIA el aeropuerto para tomar su
// vuelo de salida, no al revés). Confirmado con un caso real: pasajera
// BENNY, MONESHA, traslado "AEROPUERTO TOCUMEN A HOTEL CIUDAD" con
// "IDA Y VUELTA" marcado — el tramo SALIDA (20/08/2026, vuelo que SALE a
// las 9:31PM) quedó guardado con trayecto "AEROPUERTO TOCUMEN A WAYMORE"
// en vez de "WAYMORE A AEROPUERTO TOCUMEN".
// Fix: se agregó invertirTrayecto(), que solo actúa sobre el tramo SALIDA
// cuando el traslado es IDA Y VUELTA, e invierte el string SOLO si tiene
// la forma simple "ORIGEN A DESTINO" (una única ocurrencia literal de
// " A "). Si no matchea esa forma exacta (ej. traslados mixtos de 3
// tramos separados por "/"), se deja el trayecto tal cual estaba —mejor
// no tocarlo que invertirlo mal—.
//
// FIX 2026-08-28 (Enrique) — la misma inversión de trayecto le faltaba a
// los traslados de SOLO SALIDA (sin tramo de LLEGADA en la misma
// solicitud). Confirmado con un caso real: pasajero Anand Ramlochan,
// solicitud de solo salida (02/09/2026, "su vuelo sale a las 9:41PM"),
// SIN el campo "TRAYECTOS" marcado como "IDA Y VUELTA" (por ser una
// solicitud de un solo tramo). El trayecto quedó guardado como
// "AEROPUERTO TOCUMEN A COURTYARD MARRIOTT..." (dirección de llegada)
// en vez de "COURTYARD MARRIOTT... A AEROPUERTO TOCUMEN" (dirección
// real, de salida), mientras que "lugar de recogida" sí quedó bien
// (el hotel) — quedando los dos campos contradictorios entre sí.
// Causa: la condición vieja solo invertía cuando $esIdaYVuelta era
// true (viene del campo "TRAYECTOS"). Una solicitud de solo salida no
// tiene por qué traer ese campo marcado así, así que nunca se invertía.
// Fix: además de por IDA Y VUELTA, invertir el tramo SALIDA siempre que
// NO haya un tramo LLEGADA en la misma solicitud ($fechaLlegada vacío)
// — es decir, toda "salida sola" se trata como lo que es: un traslado en
// dirección hotel -> aeropuerto. Probado (con logica_actual.php, sin
// tocar la BD real): el caso de Anand Ramlochan pasa a invertirse bien,
// y los casos ya correctos (BENNY MONESHA ida y vuelta; traslados de
// solo llegada) no cambian en nada — ver claude/reglas-fijas.md.
//
// PENDIENTE (reportado 2026-08-28, todavía sin resolver): "lugar de
// recogida" queda vacío en algunos formularios (no en OTA). Sospecha:
// algún formulario de Tally (Solo Tour / Traslado mixto) tiene el campo
// de lugar de recogida con una etiqueta que no matchea ninguno de los
// alias de obtenerValorCampoPorAlias() más abajo, y tampoco tiene
// "Hotel playa"/"Hotel ciudad" como respaldo. Falta confirmar con el
// datos_raw real de un caso afectado antes de tocar los alias — no
// adivinar el nombre del campo.
// ============================================================

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Método no permitido']);
  exit;
}

$cuerpoCrudo = file_get_contents('php://input');
$payload = json_decode($cuerpoCrudo, true);

if (!$payload || !isset($payload['data']['fields'])) {
  registrarLog('recibir-tally', 'POST', false, 'Payload inválido: ' . substr($cuerpoCrudo, 0, 500));
  http_response_code(400);
  echo json_encode(['error' => 'Payload inválido']);
  exit;
}

$datosFormulario = $payload['data'];
$campos = $datosFormulario['fields'];
$submissionId = $datosFormulario['submissionId'] ?? $datosFormulario['responseId'] ?? null;
$formId = $datosFormulario['formId'] ?? '';
$formName = $datosFormulario['formName'] ?? '';

if (!$submissionId) {
  registrarLog('recibir-tally', 'POST', false, 'Sin submissionId.');
  http_response_code(400);
  echo json_encode(['error' => 'Falta submissionId']);
  exit;
}

function normalizarEtiqueta(string $s): string {
  $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  return mb_strtolower(trim($s));
}

function obtenerValorCampoPorAlias(array $campos, array $aliases) {
  foreach ($aliases as $alias) {
    $valor = obtenerValorCampoUnico($campos, $alias);
    if ($valor !== null) return $valor;
  }
  return null;
}

function obtenerValorCampoUnico(array $campos, string $labelBuscado) {
  $labelNormalizado = normalizarEtiqueta($labelBuscado);
  foreach ($campos as $campo) {
    $labelCampo = normalizarEtiqueta($campo['label'] ?? '');
    if ($labelCampo !== $labelNormalizado) continue;

    $valor = $campo['value'] ?? null;
    if ($valor === null || $valor === false) return null;

    $tipo = $campo['type'] ?? '';
    if (($tipo === 'DROPDOWN' || $tipo === 'CHECKBOXES') && is_array($valor) && !empty($campo['options'])) {
      $textos = [];
      foreach ($valor as $idSeleccionado) {
        foreach ($campo['options'] as $opcion) {
          if (($opcion['id'] ?? null) === $idSeleccionado) {
            $textos[] = $opcion['text'] ?? $idSeleccionado;
            break;
          }
        }
      }
      return implode(', ', $textos);
    }
    return $valor;
  }
  return null;
}

function normalizarHora($valor) {
  if (!$valor) return null;
  if (preg_match('/^\d{1,2}:\d{2}$/', $valor)) return $valor . ':00';
  return $valor;
}
function normalizarFecha($valor) { return $valor ?: null; }

function invertirTrayecto(?string $trayecto): ?string {
  if (!$trayecto) return $trayecto;
  $partes = explode(' A ', $trayecto);
  if (count($partes) !== 2) return $trayecto;
  return trim($partes[1]) . ' A ' . trim($partes[0]);
}

$vendedor            = obtenerValorCampoPorAlias($campos, ['Vendedor/a', 'Vendedor']);
$email                = obtenerValorCampoPorAlias($campos, ['Email']);
$pasajeroPrincipal    = obtenerValorCampoPorAlias($campos, ['Pasajero principal']);
$cantidadPasajeros    = obtenerValorCampoPorAlias($campos, ['# de Pax', '# PAX']);
$telefono             = obtenerValorCampoPorAlias($campos, ['Teléfono:', 'Teléfono']);
$aerolinea            = obtenerValorCampoPorAlias($campos, ['Aerolinea']);
$aeropuerto           = obtenerValorCampoPorAlias($campos, ['Aeropuerto']);
$tourActividad        = obtenerValorCampoPorAlias($campos, ['Tour / Actividad', 'Tour']);
$hotelCiudad          = obtenerValorCampoPorAlias($campos, ['Hotel ciudad']);
$hotelPlaya           = obtenerValorCampoPorAlias($campos, ['Hotel playa']);
$lugarRecogidaExplicito = obtenerValorCampoPorAlias($campos, ['Lugar de recogida', 'Hotel o lugar de recogida']);
$conductorInicial     = obtenerValorCampoPorAlias($campos, ['Asignado a / Proveedor', 'Conductor']) ?: 'POR ASIGNAR';
$acompanantes         = obtenerValorCampoPorAlias($campos, ['Acompañantes']);
$notas                = obtenerValorCampoPorAlias($campos, ['Notas:', 'Notas', 'Notas adicionales', 'Comentarios', 'Observaciones']);
$facturacion          = obtenerValorCampoPorAlias($campos, ['Datos de facturación:', 'Datos facturación']);
$idaYVuelta           = obtenerValorCampoPorAlias($campos, ['TRAYECTOS']);

$tipoServicio = null;
$trayecto = null;
if ($formName === 'Solo Tour') {
  $tipoServicio = obtenerValorCampoPorAlias($campos, ['Tipo de servicio']);
} else {
  $trayecto = obtenerValorCampoPorAlias($campos, ['Tipo de servicio', 'TIPO DE TRASLADO']);
}
if (!$trayecto && $tourActividad) $trayecto = $tourActividad;

if ($trayecto) {
  if ($hotelPlaya)  $trayecto = str_ireplace('HOTEL PLAYA', $hotelPlaya, $trayecto);
  if ($hotelCiudad) $trayecto = str_ireplace('HOTEL CIUDAD', $hotelCiudad, $trayecto);
  if ($tourActividad) {
    $trayecto = preg_replace('/tour\s*[\/\-]?\s*actividad/i', $tourActividad, $trayecto);
  }
}

$fechaLlegada  = normalizarFecha(obtenerValorCampoPorAlias($campos, ['Fecha de llegada']));
$horaLlegada   = normalizarHora(obtenerValorCampoPorAlias($campos, ['Hora de llegada']));
$vueloLlegada  = obtenerValorCampoPorAlias($campos, ['# Vuelo y procedencia']);

$fechaSalida   = normalizarFecha(obtenerValorCampoPorAlias($campos, ['Fecha de salida']));
$horaSalida    = normalizarHora(obtenerValorCampoPorAlias($campos, ['Hora de salida']));
$vueloSalida   = obtenerValorCampoPorAlias($campos, ['# Vuelo y destino']);

$fechaTour = normalizarFecha(obtenerValorCampoPorAlias($campos, ['Fecha']));
$horaTour  = normalizarHora(obtenerValorCampoPorAlias($campos, ['Hora de recogida', 'Hora']));

$tramos = [];

if ($fechaLlegada) {
  $tramos[] = ['tramo' => 'LLEGADA', 'fecha' => $fechaLlegada, 'hora' => $horaLlegada, 'vuelo' => $vueloLlegada];
}

if ($fechaTour && $fechaTour !== $fechaLlegada) {
  $tramos[] = ['tramo' => 'TOUR', 'fecha' => $fechaTour, 'hora' => $horaTour, 'vuelo' => null];
} elseif ($fechaTour && !$fechaLlegada) {
  $tramos[] = ['tramo' => 'UNICO', 'fecha' => $fechaTour, 'hora' => $horaTour, 'vuelo' => null];
}

if ($fechaSalida) {
  $tramos[] = ['tramo' => 'SALIDA', 'fecha' => $fechaSalida, 'hora' => $horaSalida, 'vuelo' => $vueloSalida];
}

if (empty($tramos)) {
  $tramos[] = ['tramo' => 'UNICO', 'fecha' => null, 'hora' => null, 'vuelo' => null];
}

$esIdaYVuelta = $idaYVuelta && stripos($idaYVuelta, 'IDA Y VUELTA') !== false;

try {
  $pdo = obtenerConexionBD();
  $creados = 0;
  $omitidos = 0;

  foreach ($tramos as $t) {
    $idEvento = $submissionId . '_' . $t['tramo'];

    $stmtExiste = $pdo->prepare("SELECT id FROM viajes WHERE id_evento = ?");
    $stmtExiste->execute([$idEvento]);
    if ($stmtExiste->fetch()) { $omitidos++; continue; }

    if ($lugarRecogidaExplicito) {
      $lugarRecogidaTramo = $lugarRecogidaExplicito;
    } elseif ($t['tramo'] === 'LLEGADA') {
      $lugarRecogidaTramo = $aeropuerto ?: 'Aeropuerto';
    } else {
      $lugarRecogidaTramo = $hotelPlaya ?: $hotelCiudad;
    }

    $trayectoTramo = $trayecto;
    // FIX 2026-08-28: invertir el tramo SALIDA también cuando es una
    // "salida sola" (sin tramo LLEGADA en esta misma solicitud), no
    // solo cuando el campo TRAYECTOS dice literalmente "IDA Y VUELTA".
    // Ver nota de cabecera para el caso real que lo confirmó.
    if ($t['tramo'] === 'SALIDA' && ($esIdaYVuelta || !$fechaLlegada)) {
      $trayectoTramo = invertirTrayecto($trayecto);
    }

    $sql = "INSERT INTO viajes (
              id_evento, submission_id, tramo, form_id, form_name,
              vendedor, email, telefono,
              pasajero_principal, acompanantes, cantidad_pasajeros,
              tipo_servicio, trayecto, ida_y_vuelta, tour_actividad,
              fecha, hora, vuelo,
              aerolinea, aeropuerto, hotel_ciudad, hotel_playa, lugar_recogida,
              conductor, notas, facturacion,
              datos_raw
            ) VALUES (
              ?, ?, ?, ?, ?,
              ?, ?, ?,
              ?, ?, ?,
              ?, ?, ?, ?,
              ?, ?, ?,
              ?, ?, ?, ?, ?,
              ?, ?, ?,
              ?
            )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      $idEvento, $submissionId, $t['tramo'], $formId, $formName,
      $vendedor, $email, $telefono,
      $pasajeroPrincipal, $acompanantes, (int)($cantidadPasajeros ?? 0),
      $tipoServicio, $trayectoTramo, $idaYVuelta, $tourActividad,
      $t['fecha'], $t['hora'], $t['vuelo'],
      $aerolinea, $aeropuerto, $hotelCiudad, $hotelPlaya, $lugarRecogidaTramo,
      $conductorInicial, $notas, $facturacion,
      json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $creados++;
  }

  registrarLog('recibir-tally', 'POST', true, "submission_id=$submissionId, creados=$creados, omitidos=$omitidos");
  echo json_encode(['success' => true, 'tramos_creados' => $creados, 'tramos_omitidos' => $omitidos]);

} catch (Exception $e) {
  registrarLog('recibir-tally', 'POST', false, 'Error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Error al guardar el viaje: ' . $e->getMessage()]);
}