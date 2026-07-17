# Auditoría de operaciones deportivas críticas

Este documento describe la estrategia de auditoría explícita implementada en el Slice 2.5A.

## Objetivo

Registrar **una actividad legible por acción funcional confirmada**, incluso cuando la operación cree, actualice o elimine muchos registros internamente. La auditoría cubre operaciones deportivas críticas que modifican el estado del torneo.

## Paquete

- **Paquete:** [`spatie/laravel-activitylog`](https://github.com/spatie/laravel-activitylog) v4.12.x
- **Compatibilidad:** PHP 8.3, Laravel 13
- **Tabla:** `activity_log` (migraciones publicadas desde el vendor)

## Estrategia: registro explícito desde Actions

Toda auditoría pasa por `App\Support\Audit\AuditLogger`, invocado **desde las Actions** al finalizar los cambios de dominio dentro de la misma transacción.

Componentes:

| Componente | Responsabilidad |
|------------|-----------------|
| `AuditAction` | Códigos estables de operación |
| `AuditEntry` | DTO con action, logName, subject, context, old, new, summary, reason |
| `AuditContext` | Resuelve usuario, Keycloak ID, IP y user agent |
| `AuditContextBuilder` | Construye contexto deportivo (torneo, competencia, grupo, etc.) |
| `AuditLogger` | Wrapper sobre Spatie; persiste `properties` con contrato uniforme |

## Por qué no hay traits automáticos (`LogsActivity`)

Los modelos deportivos (`Group`, `GroupPlayer`, `Game`, `GameSet`, `Bracket`, `Registration`, `GroupManualTiebreak`) **no** usan `LogsActivity` de Spatie porque:

- Una operación funcional puede tocar decenas de filas; el trait generaría ruido (N actividades por partido, por jugador, etc.).
- El contrato de auditoría requiere metadata agregada (`summary`, contadores, old/new semánticos) que el trait no produce.
- Las validaciones fallidas no deben auditarse; los traits no distinguen intentos de éxito confirmado.

## Estructura de `properties`

Todas las actividades guardan:

```json
{
  "schema_version": 1,
  "context": {
    "tournament_id": null,
    "tournament_name": null,
    "competition_id": null,
    "competition_name": null,
    "group_id": null,
    "group_name": null,
    "bracket_id": null,
    "game_id": null
  },
  "old": {},
  "new": {},
  "summary": {},
  "reason": null,
  "actor": {
    "keycloak_id": null
  },
  "request": {
    "ip_address": null,
    "user_agent": null
  }
}
```

**No se guardan:** tokens, headers completos, claims JWT completos, modelos Eloquent serializados ni payloads gigantes.

El campo `description` de Spatie almacena el **código estable** (`AuditAction`), no texto descriptivo en español. La UI futura traducirá esos códigos.

## Códigos de acción

| Código | Operación |
|--------|-----------|
| `groups.regenerated` | Regeneración aleatoria de grupos |
| `bracket.created` | Generación de llave eliminatoria |
| `bracket.round_advanced` | Avance de ronda en llave |
| `game.set_recorded` | Registro de un set |
| `groups.player_status_changed` | Cambio de estado de jugador en grupo |
| `groups.manual_tiebreak_applied` | Desempate manual en grupo |

## Operaciones incluidas

| Action | log_name | Subject |
|--------|----------|---------|
| `RegenerateRandomGroupsForCompetitionAction` | `groups` | `Competition` |
| `CreateBracketKnockoutAction` | `bracket` | `Competition` |
| `GenerateBracketNextRoundAction` | `bracket` | `Bracket` |
| `RecordGameSetAction` | `games` | `Game` |
| `SetGroupPlayerStatusAction` | `groups` | `Group` |
| `ApplyGroupManualTiebreakAction` | `groups` | `Group` |

## Operaciones excluidas (por ahora)

- CRUD de torneos, competencias y jugadores
- Inscripciones
- Lecturas (GET)
- Intentos fallidos (422, 401, etc.)
- Observers genéricos y auditoría automática por modelos
- Endpoint `/audit-logs` y pantalla de auditoría
- Permiso `audit.view` (definido a futuro; ver [AUTH.md](./AUTH.md))
- Retención automática programada
- Exportación y correlación por batch

## Actor Keycloak

- **Usuario local:** `Auth::user()` (sincronizado por `AuthenticateKeycloak`).
- **Keycloak ID:** claim `sub` desde `AuthenticatedContext` (sin re-decodificar el JWT).
- **Fallback:** `User.keycloak_id` cuando no hay contexto HTTP.
- **Fuera de HTTP** (seeders, jobs, tests directos): valores `null`; el logger no falla.

## IP y user agent

Obtenidos de `request()->ip()` y `request()->userAgent()` cuando hay request activo. Fuera de HTTP: `null`.

## Transacciones

La actividad se escribe:

1. Dentro de la misma `DB::transaction` que los cambios de dominio.
2. Después de completar correctamente los cambios.
3. Inmediatamente antes del `return`.

Si la transacción hace rollback, **no** queda fila en `activity_log`. No se auditan intentos fallidos.

## Consultas futuras

Ejemplos previstos para la UI de auditoría (sin endpoint implementado aún):

```php
use Spatie\Activitylog\Models\Activity;
use App\Enums\AuditAction;

// Listado general
Activity::query()
    ->with(['causer', 'subject'])
    ->latest()
    ->paginate(50);

// Por competencia
Activity::query()
    ->where('properties->context->competition_id', $competitionId)
    ->latest()
    ->paginate();

// Por actor
Activity::causedBy($user)->latest()->paginate();

// Por acción
Activity::query()
    ->where('description', AuditAction::GROUPS_REGENERATED->value)
    ->latest()
    ->paginate();
```

## Retención

- **Config:** `config/activitylog.php` → `delete_records_older_than_days` (default conservador: 3650 días vía `ACTIVITY_LOGGER_DELETE_OLDER_THAN_DAYS`).
- **Estado:** no hay job ni comando programado que ejecute limpieza automática.
- **Decisión pendiente:** definir política de retención operativa y, si aplica, schedule del comando `activitylog:clean` de Spatie.

## Configuración relevante

| Clave | Valor |
|-------|-------|
| `default_log_name` | `default` |
| `default_auth_driver` | `null` (usa guard Laravel actual) |
| `activity_model` | `Spatie\Activitylog\Models\Activity` |
| `database_connection` | conexión por defecto del proyecto |
| `table_name` | `activity_log` |

## Documentación relacionada

- [AUTH.md](./AUTH.md) — autenticación Keycloak y permiso futuro `audit.view`
