# Auditoría de operaciones críticas

Este documento describe la estrategia de auditoría explícita (Slices 2.5A–2.5C) y la consulta protegida de actividades (Slice 2.5B).

## Objetivo

Registrar **una actividad legible por acción funcional confirmada**, incluso cuando la operación cree, actualice o elimine muchos registros internamente. La auditoría cubre operaciones administrativas y deportivas que modifican el estado del torneo.

## Paquete

- **Paquete:** [`spatie/laravel-activitylog`](https://github.com/spatie/laravel-activitylog) v4.12.x
- **Compatibilidad:** PHP 8.3, Laravel 13
- **Tabla:** `activity_log` (migraciones publicadas desde el vendor)

## Estrategia: registro explícito desde Actions

Toda auditoría pasa por `App\Support\Audit\AuditLogger`, invocado **desde las Actions** al finalizar los cambios de dominio dentro de la misma transacción.

Componentes:

| Componente | Responsabilidad |
|------------|-----------------|
| `AuditAction` | Códigos estables de operación y labels |
| `AuditEntry` | DTO con action, logName, subject, context, old, new, summary, reason |
| `AuditContext` | Resuelve usuario, Keycloak ID, IP y user agent |
| `AuditContextBuilder` | Construye contexto deportivo y administrativo (torneo, competencia, jugador, etc.) |
| `AuditChangeResolver` | Resuelve `old`/`new` desde campos dirty con normalización |
| `AuditLogger` | Wrapper sobre Spatie; persiste `properties` con contrato uniforme |
| `ListAuditLogsAction` | Consulta paginada con filtros |
| `AuditLogSubjectPresenter` | Resuelve subject público con fallback histórico |

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
    "registration_id": null,
    "player_id": null,
    "player_name": null,
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

El campo `description` de Spatie almacena el **código estable** (`AuditAction`). La API devuelve `action_label` traducido desde backend.

## Códigos de acción

| Código | Label |
|--------|-------|
| `tournament.created` | Creación de torneo |
| `tournament.updated` | Actualización de torneo |
| `tournament.closed` | Cierre de torneo |
| `competition.created` | Creación de competencia |
| `competition.updated` | Actualización de competencia |
| `player.created` | Creación de jugador |
| `player.updated` | Actualización de jugador |
| `player.deactivated` | Desactivación de jugador |
| `player.deleted` | Eliminación de jugador |
| `registration.created` | Inscripción de jugador |
| `registration.bulk_created` | Inscripción masiva |
| `groups.generated` | Generación de grupos |
| `group.created` | Creación de grupo |
| `group.player_assigned` | Asignación de jugador a grupo |
| `groups.round_robin_generated` | Generación de todos contra todos |
| `groups.regenerated` | Regeneración de grupos |
| `bracket.created` | Generación de llave |
| `bracket.round_advanced` | Avance de ronda |
| `game.created` | Creación de partido |
| `game.deleted` | Eliminación de partido |
| `game.set_recorded` | Registro de set |
| `game.result_corrected` | Corrección de resultado |
| `groups.player_status_changed` | Cambio de estado de jugador |
| `groups.manual_tiebreak_applied` | Desempate manual |

## Log names (módulos)

| log_name | Uso |
|----------|-----|
| `tournaments` | CRUD de torneos |
| `competitions` | CRUD de competencias |
| `players` | CRUD y baja de jugadores |
| `registrations` | Inscripciones individual y masiva |
| `groups` | Grupos y operaciones de fase de grupos |
| `bracket` | Llave eliminatoria |
| `games` | Partidos y resultados |

## Operaciones incluidas

### Administración (Slice 2.5C-1)

| Action | log_name | Subject |
|--------|----------|---------|
| `CreateTournamentAction` | `tournaments` | `Tournament` |
| `UpdateTournamentAction` | `tournaments` | `Tournament` |
| `CloseTournamentAction` | `tournaments` | `Tournament` |
| `CreateCompetitionAction` | `competitions` | `Competition` |
| `UpdateCompetitionAction` | `competitions` | `Competition` |
| `CreatePlayerAction` | `players` | `Player` |
| `UpdatePlayerAction` | `players` | `Player` |
| `DeletePlayerAction` | `players` | `Player` |
| `RegisterPlayerToCompetitionAction` | `registrations` | `Competition` |
| `BulkRegisterPlayersToCompetitionAction` | `registrations` | `Competition` |

`PersistRegistrationAction` persiste inscripciones sin auditar; la usa el flujo individual y el bulk.

### Deportivas (Slices 2.5A–2.5B)

| Action | log_name | Subject |
|--------|----------|---------|
| `GenerateRandomGroupsForCompetitionAction` | `groups` | `Competition` |
| `RegenerateRandomGroupsForCompetitionAction` | `groups` | `Competition` |
| `CreateGroupAction` | `groups` | `Group` |
| `AssignPlayerToGroupAction` | `groups` | `Group` |
| `GenerateGroupRoundRobinGamesAction` | `groups` | `Group` |
| `CreateManualGameAction` | `games` | `Game` |
| `DeleteManualGameAction` | `games` | `Game` |
| `CreateBracketKnockoutAction` | `bracket` | `Competition` |
| `GenerateBracketNextRoundAction` | `bracket` | `Bracket` |
| `RecordGameSetAction` | `games` | `Game` |
| `CorrectFinishedGameResultAction` | `games` | `Game` |
| `SetGroupPlayerStatusAction` | `groups` | `Group` |
| `ApplyGroupManualTiebreakAction` | `groups` | `Group` |

`CreateGameAction` y `DeleteGameAction` persisten partidos **sin auditar**. `BuildGroupRoundRobinGamesAction` genera partidos round robin **sin auditar**; solo `GenerateGroupRoundRobinGamesAction` (endpoint HTTP) registra `groups.round_robin_generated`. Los invocadores automáticos de grupos usan el builder silencioso.

### Granularidad agregada (Slice 2.5C-2)

| Operación funcional | Actividad | No genera |
|---------------------|-----------|-----------|
| Generación inicial aleatoria de grupos | `groups.generated` (1) | `group.created`, `group.player_assigned`, `groups.round_robin_generated`, `game.created` |
| Regeneración de grupos | `groups.regenerated` (1) | logs hijos por grupo/jugador/partido |
| Round robin por grupo | `groups.round_robin_generated` (1) | `game.created` por partido |
| Llave y avance de ronda | `bracket.created`, `bracket.round_advanced` | `game.created` |
| Creación manual de partido (HTTP) | `game.created` (1) | — |
| Eliminación manual de partido (HTTP) | `game.deleted` (1) | — |

## Consulta de auditoría (Slice 2.5B)

### Permiso requerido

- **`audit.view`** — asignado únicamente al rol `admin` (ver [AUTH.md](./AUTH.md)).
- No se usa el rol directamente en backend ni frontend; solo el permiso.

### Endpoints

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/v1/audit-logs` | Listado paginado |
| `GET` | `/api/v1/audit-logs/{activity}` | Detalle de una actividad |

Middleware efectivo:

```text
auth.keycloak
permission:audit.view
```

### Filtros del listado

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `page` | integer ≥ 1 | Página (default: 1) |
| `per_page` | integer 1–100 | Tamaño (default: 25) |
| `action` | `AuditAction` | Filtra por `description` |
| `log_name` | `tournaments`, `competitions`, `players`, `registrations`, `groups`, `bracket`, `games` | Módulo |
| `actor_id` | integer | Usuario local (`causer`) |
| `tournament_id` | integer | `properties.context.tournament_id` |
| `competition_id` | integer | `properties.context.competition_id` |
| `group_id` | integer | `properties.context.group_id` |
| `game_id` | integer | `properties.context.game_id` |
| `subject_type` | alias público | Ver tabla de aliases |
| `subject_id` | integer | ID del subject |
| `from` | date | Inicio del día (`APP_TIMEZONE`) |
| `to` | date | Fin del día (`APP_TIMEZONE`) |
| `search` | string ≤ 150 | Búsqueda limitada |

Orden estable: `created_at desc`, `id desc`.

### Aliases públicos de subject

| Alias | Modelo interno |
|-------|----------------|
| `tournament` | `App\Models\Tournament` |
| `competition` | `App\Models\Competition` |
| `player` | `App\Models\Player` |
| `group` | `App\Models\Group` |
| `bracket` | `App\Models\Bracket` |
| `game` | `App\Models\Game` |
| `unknown` | Tipo no reconocido |

Las inscripciones usan **`Competition`** como subject (no existe alias `registration`).

No se exponen nombres PHP como contrato de API.

### Búsqueda limitada

La búsqueda (`search`) opera sobre:

- `description` (código de acción)
- `causer.name`
- `causer.email`
- `properties.context.tournament_name`
- `properties.context.competition_name`
- `properties.context.group_name`
- `properties.context.player_name`

**No** busca en `old`, `new`, `summary` completo ni JSON arbitrario.

### Subject eliminado

Si la entidad fue eliminada después del registro:

1. El listado/detalle tolera `subject = null`.
2. El label prioriza: modelo vivo → nombres en `properties.context`/`summary` → `"Tipo #ID"` → `"Entidad eliminada"`.
3. El campo `subject.exists` indica si el modelo sigue disponible.

### Contrato de listado

Campos expuestos:

```json
{
  "id": 25,
  "action": "groups.regenerated",
  "action_label": "Regeneración de grupos",
  "category_label": "Grupos",
  "log_name": "groups",
  "occurred_at": "2026-07-16T23:45:00-03:00",
  "actor": {
    "id": 8,
    "name": "Carlos Pérez",
    "email": "carlos@example.com",
    "keycloak_id": "..."
  },
  "subject": {
    "type": "competition",
    "id": 3,
    "label": "Primera Caballeros",
    "exists": true
  },
  "context": { "...": "..." },
  "summary": {}
}
```

**No incluye:** `old`, `new`, `reason`, IP, user agent ni `properties` completo.

### Contrato de detalle

Incluye todo lo anterior más:

```json
{
  "old": {},
  "new": {},
  "reason": null,
  "request": {
    "ip_address": "127.0.0.1",
    "user_agent": "..."
  },
  "schema_version": 1
}
```

### Ejemplo de respuesta paginada

```json
{
  "data": [],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 0
  }
}
```

Los intentos fallidos no deben auditarse.

### Ejemplo: `tournament.closed`

```json
{
  "old": {
    "status": "in_progress",
    "closed_at": null
  },
  "new": {
    "status": "finished",
    "closed_at": "2026-07-18T21:00:00-03:00"
  },
  "summary": {
    "tournament_id": 1,
    "tournament_name": "Apertura 2026",
    "competitions_count": 3,
    "completed_competitions": 2,
    "unused_competitions": 1,
    "games_count": 48,
    "results": [
      {
        "competition_id": 1,
        "competition_name": "Primera",
        "champion_id": 10,
        "champion_name": "Juan Pérez",
        "runner_up_id": 11,
        "runner_up_name": "Pedro Gómez"
      }
    ]
  }
}
```

### Ejemplo: `game.result_corrected`

```json
{
  "reason": "El árbitro informó que el segundo set fue cargado incorrectamente.",
  "old": {
    "status": "finished",
    "winner_id": 12,
    "winner_name": "Juan Pérez",
    "finished_at": "2026-07-17T15:00:00-03:00",
    "sets": [
      { "set_number": 1, "player1_score": 11, "player2_score": 9 },
      { "set_number": 2, "player1_score": 11, "player2_score": 7 }
    ],
    "sets_won": { "player1": 2, "player2": 0 }
  },
  "new": {
    "status": "finished",
    "winner_id": 15,
    "winner_name": "Pedro Gómez",
    "finished_at": "2026-07-17T16:00:00-03:00",
    "sets": [
      { "set_number": 1, "player1_score": 11, "player2_score": 8 },
      { "set_number": 2, "player1_score": 9, "player2_score": 11 },
      { "set_number": 3, "player1_score": 11, "player2_score": 7 }
    ],
    "sets_won": { "player1": 2, "player2": 1 }
  },
  "summary": {
    "winner_changed": true,
    "old_winner_id": 12,
    "new_winner_id": 15,
    "sets_count_before": 2,
    "sets_count_after": 3,
    "propagation": {
      "applied": true,
      "destination_game_id": 88,
      "destination_round": "Semifinal",
      "destination_bracket_round": 2,
      "destination_bracket_match": 1,
      "slot": "player1_id",
      "old_player_id": 12,
      "new_player_id": 15,
      "before": {
        "player1_id": 12,
        "player2_id": 20,
        "status": "pending"
      },
      "after": {
        "player1_id": 15,
        "player2_id": 20,
        "status": "pending"
      }
    }
  }
}
```

Si no hubo ronda siguiente generada:

```json
"propagation": { "applied": false }
```

Si el ganador no cambió pero existía destino:

```json
"propagation": { "applied": false, "reason": "winner_unchanged" }
```

## Políticas de auditoría administrativa (Slice 2.5C-1)

### Updates sin cambios (no-op)

Si un update no modifica ningún campo auditables (`getDirty()` vacío), **no se genera actividad**. La petición HTTP sigue siendo exitosa.

### Inscripción masiva agregada

`registration.bulk_created` produce **una sola actividad** por petición bulk exitosa, aunque cree decenas de filas. No se emiten `registration.created` individuales durante bulk.

Metadata de bulk:

- Siempre: `requested_count`, `created_count`, `skipped_count`
- Si `total ≤ 20`: `created_player_ids`, `skipped_player_ids`
- Si `total > 20`: `sample_created_names`, `sample_skipped_names` (máx. 5 cada una)

Un bulk idempotente (`created = 0`, `skipped = N`) **sí audita** con contadores.

### Desactivación vs eliminación de jugador

- **`active = false`** vía PATCH → `player.deactivated` (prioritario aunque haya otros cambios).
- **`DELETE /players/{id}`** → `player.deleted` (eliminación física; solo jugadores huérfanos).

### Datos sensibles excluidos

El modelo `Player` no tiene email, teléfono ni documento. La auditoría registra nombre, apodo, categoría, club y estado activo.

No se auditan: `sets_to_win` (derivado legacy en competencias), `status_summary`, timestamps.

### Subject eliminado

Tras `player.deleted`, el morph en `activity_log` conserva `subject_type`/`subject_id`; el presenter usa `properties.context.player_name` como fallback histórico.

Tras `game.deleted`, el partido ya no existe pero la actividad conserva `subject_type`/`subject_id`. El presenter prioriza nombres de jugadores en `context`/`summary` (`"Juan Pérez vs Pedro Gómez"`) antes de `"Partido #ID"`. El detalle expone `old.sets` en formato compacto.

## Operaciones excluidas (por ahora)

- Desasignación de jugadores de grupos; eliminación de grupos
- Motivo obligatorio en eliminación de partidos
- Lecturas (GET)
- Intentos fallidos (422, 401, etc.)
- Observers genéricos y auditoría automática por modelos (`LogsActivity`)
- Exportación, borrado y edición de auditorías
- Retención automática programada
- Correlación por `batch_uuid`
- Estadísticas y dashboard
- Filtro `player_id` en listado de auditoría
- `player.reactivated`; propagación automática de ganadores tras corrección

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

## Frontend

- Ruta: `/audit-logs`
- Permiso UI: `audit.view`
- Navegación: ítem **Auditoría** visible solo para admin
- Detalle: modal con valores old/new, motivo, IP y user agent

## Documentación relacionada

- [AUTH.md](./AUTH.md) — autenticación Keycloak y permiso `audit.view`
