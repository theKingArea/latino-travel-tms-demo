# Latino Travel — TMS Demo (código sanitizado)

Extracto real (sanitizado — sin tokens, claves ni credenciales verdaderas) del sistema interno que construí y mantengo para coordinar traslados y conductores de una agencia de viajes en Panamá. Recibe reservas desde 5 canales OTA distintos y desde formularios propios, las asigna a conductores, y automatiza notificaciones por correo y push.

Este repo es una **muestra de código y arquitectura para portafolio**, no el sistema completo en producción (faltan a propósito `config.php`, `mailer.php`, `push.php` y el resto de endpoints — ninguno tiene datos reales).

## Arquitectura

```
Formularios (Tally) ──┐
                       ├──▶ recibir-tally.php ──┐
OTAs (correo) ─────────┼──▶ recibir-ota.php ────┼──▶ MySQL (tabla `viajes`)
                       │    (no incluido aquí)   │
                       └──▶ ...                  │
                                                  │
                              api.php  ◀──────────┘
                                 │
                    ┌────────────┼─────────────┐
                    ▼            ▼             ▼
              admin.html   app conductores   panel de solo lectura
              (gestión)    (PIN + estado)    (público)
```

- **api.php** — API REST propia (reemplazo de un Google Apps Script + Firebase anterior). Sirve el mismo contrato `{conductores, traslados}` por GET, y recibe acciones por POST (cambiar conductor, editar viaje, completar, papelera/soft-delete, altas y bajas de conductores, envío de credenciales). Protegida con dos niveles de clave: una de solo lectura y una de administración.
- **recibir-tally.php** — Webhook que recibe las respuestas de los formularios de reserva (Tally) y las inserta en la tabla `viajes`, separando cada solicitud en sus tramos reales (llegada / tour / salida).
- **admin.html** — Panel de administración de una sola página (Tailwind + FullCalendar): calendario, lista, reportes de pago a conductores/guías, gestión de conductores, formularios embebidos y disponibilidad de tours.

## Cómo correrlo

1. Copiá `config.example.php` a `../config.php` (un nivel arriba de estos archivos) y completá tus propios datos de conexión y tu propia `ADMIN_API_KEY`.
2. Creá la tabla `viajes` y `conductores` en tu base (el esquema se puede inferir de los `SELECT`/`INSERT` en `api.php` y `recibir-tally.php`).
3. En `admin.html`, reemplazá `REEMPLAZA_CON_TU_ADMIN_API_KEY` y `REEMPLAZA_CON_TU_CLAVE_DE_LECTURA` por los mismos valores que pusiste en `config.php`, y `api.tudominio.com` por el dominio real donde subas `api.php`.
4. `mailer.php` (correos) y `push.php` (notificaciones push) son opcionales y no están incluidos — sin ellos, el sistema sigue funcionando, solo se saltea el envío de notificaciones (ver los `function_exists(...)` en `api.php`).

## Nota de seguridad (transparencia, no está "arreglado" en esta versión)

`ADMIN_API_KEY` vive hardcodeada en el `<script>` de `admin.html` (línea ~85), visible por "ver código fuente". En producción esto está mitigado con HTTP Basic Auth a nivel de servidor sobre toda la carpeta del panel (cPanel → proteger directorio), así que no es de acceso público — pero no es lo mismo que no exponer la clave en el HTML en absoluto. La mejora pendiente sería servir `admin.html` a través de un pequeño script PHP que inyecte la clave desde `config.php` en tiempo de render, en vez de tenerla en un archivo estático.

## Casos de estudio (bugs reales diagnosticados y corregidos)

**1. Clasificación de canal OTA fallaba por un falso positivo en la firma DKIM.** Una reserva de Despegar quedaba guardada como "GetYourGuide" porque el detector de canal buscaba la palabra clave `GYG` en todo el correo crudo (incluida la firma DKIM en base64, donde apareció por pura casualidad como subcadena). Solución: detección por dominio real del remitente como fuente primaria, con respaldo por coincidencia de palabra completa (no subcadena libre).

**2. Traslados de ida y vuelta con el tramo de salida invertido.** El trayecto de un traslado se calculaba una sola vez y se reutilizaba igual en todos sus tramos, así que el tramo de "salida" (hotel → aeropuerto) quedaba guardado en la dirección de "llegada" (aeropuerto → hotel). Solución: función que invierte el trayecto solo cuando tiene la forma exacta "ORIGEN A DESTINO", aplicada tanto a traslados marcados "ida y vuelta" como a solicitudes de solo salida (que no traían ese campo marcado pero son igual de direccionales).

---
Enrique — [tu contacto aquí]
