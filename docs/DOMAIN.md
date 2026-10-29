# Sistema de Torneos de Tenis de Mesa — Dominio MVP

## 1. Objetivo del sistema

Construir un MVP para gestionar torneos de tenis de mesa en ámbitos pequeños, domésticos o de club.

El sistema permite:

- Crear torneos.
- Crear competencias dentro de un torneo (ej: "Singles Primera"), en modalidad singles, dobles o equipos.
- Registrar jugadores.
- Inscribir participantes a competencias (jugadores, parejas o equipos según la modalidad).
- Organizar fase de grupos.
- Generar cuadro eliminatorio desde resultados de grupos.
- Crear partidos manuales cuando se requiera.
- Cargar resultados por sets.
- Calcular automáticamente el ganador del partido.

El sistema **no** es una plataforma federativa completa. La prioridad es que funcione de forma simple y real.

---

## 2. Alcance del MVP

El MVP cubre:

- Gestión de torneos.
- Gestión de competencias singles, dobles y por equipos.
- Registro de jugadores e inscripción de participantes (jugador, pareja o equipo).
- Enfrentamientos por equipos compuestos por varios partidos individuales o de dobles.
- Gestión de grupos y asignación de participantes.
- Generación de partidos round robin por grupo.
- Standings por grupo con mini tabla automática y desempate manual cuando corresponde.
- Standings global de competencia.
- Retiro y descalificación de participantes dentro de un grupo.
- Creación de bracket eliminatorio.
- Generación de siguientes rondas del bracket.
- Creación manual de partidos.
- Carga de resultados por sets.
- Determinación automática del ganador.

Queda **fuera del MVP**:

- Estadísticas avanzadas.
- Walkover formal como estado propio de partido.
- Abandono puntual de un solo partido como flujo separado.
- Reactivación de participantes retirados/descalificados.
- Edición de sets individuales.
- Validación ITTF completa (`win_by_two`, saque, etc.).
- Scheduling y conflictos de mesa.
- Multi-organización.
- Pagos y licencias federativas.

En el MVP no se valida diferencia mínima de dos puntos de forma aislada.
El sistema valida que el marcador registrado represente el **momento exacto de cierre** del set (ver regla 10).

---

## 3. Entidades del dominio

### Tournament

Representa un torneo general.

| Campo        | Tipo     | Descripción                              |
|--------------|----------|------------------------------------------|
| id           | bigint   | Identificador único.                     |
| name         | string   | Nombre del torneo.                       |
| location     | string   | Lugar donde se realiza.                  |
| start_date   | date     | Fecha de inicio.                         |
| end_date     | date     | Fecha de fin (nullable).                 |
| status       | enum     | `draft`, `in_progress`, `finished`.      |
| closed_at    | timestamp| Nullable. Momento del cierre administrativo explícito. |

Un torneo puede contener una o más competencias.

Un torneo puede tener un catálogo de mesas físicas (`PlayingTable`). La asignación de partidos a mesas y el scheduling automático quedan fuera de esta etapa.

#### PlayingTable

Representa una mesa física del recinto, perteneciente a un `Tournament`.

| Campo         | Tipo     | Descripción |
|---------------|----------|-------------|
| id            | bigint   | Identificador único. |
| tournament_id | bigint   | FK a Tournament. |
| number        | int      | Número de mesa dentro del torneo. Unique junto con `tournament_id`. |
| name          | string   | Nombre opcional (ej. "Mesa Central"). Si es null, el display es `Mesa {number}`. |
| active        | boolean  | Si la mesa está disponible para asignación. Default: true. |
| sort_order    | int      | Orden de listado. En el seed demo coincide con `number`. |

Display (`PlayingTable::displayName()`):

- si `name` tiene texto → `name`
- si no → `Mesa {number}`

Restricciones:

- `unique(tournament_id, number)`: el mismo número no se repite dentro de un torneo; sí puede repetirse entre torneos.
- `games.playing_table_id` es unique y nullable: una mesa no puede estar asignada a dos `Game` a la vez; muchos partidos pueden no tener mesa (`NULL` permitido en MariaDB y SQLite).

La unidad asignable es siempre `Game` (singles, doubles o rubber de `TeamTie`). `TeamTie` no tiene `playing_table_id`.

Asignar `playing_table_id` **no** cambia `Game.status`. El primer set sigue siendo lo que pasa el partido a `in_progress`.

Invariante de consistencia (aún no enforced en persistencia): la mesa y el `Game` deben pertenecer al mismo torneo (`Game → Competition → Tournament`). Esa validación corresponde a la action de asignación, no a un observer.

`games.table_number` es un stub legacy (nullable, sin catálogo ni unicidad). No se usa para la relación nueva y no se migra automáticamente.

Administración del catálogo (`TABLES-2`):

- Lectura pública: `GET /api/v1/tournaments/{tournament}/playing-tables`, ordenada por `sort_order` y `number`.
- Mutaciones protegidas con `tournaments.manage`: crear, actualizar y eliminar mesas.
- `number` es unique por torneo. El mensaje de validación es «Ya existe una mesa con ese número en el torneo.»
- `name` es opcional: trim; string vacío → `null`. El `display_name` sigue siendo `name` o `Mesa {number}`.
- `active` se persiste y las mesas inactivas siguen listándose. El guard que impide asignar un `Game` a una mesa inactiva corresponde a TABLES-3.
- `DELETE` solo si ningún `Game` referencia la mesa. Si hay partido asociado responde 422; no se usa `nullOnDelete` como flujo normal.
- Con torneo `finished`, GET sigue permitido; POST/PATCH/DELETE se bloquean con `TournamentLifecycleGuard`.
- La asignación de `Game` a mesa, el tablero operativo y el scheduling automático quedan fuera de esta etapa.

#### Cierre administrativo del torneo

`Tournament.status = finished` representa un **cierre administrativo explícito**, no la finalización deportiva automática de las competencias.

- El único camino válido para finalizar es `POST /api/v1/tournaments/{tournament}/close`.
- El PATCH genérico **no** puede establecer `finished`.
- Tras el cierre se bloquean mutaciones deportivas/estructurales; los datos descriptivos del torneo siguen editables.
- No hay reapertura en el MVP.

Requisitos de cierre:

- al menos una competencia;
- toda competencia **utilizada** (con inscripciones o partidos) debe estar deportivamente `completed` con campeón resoluble;
- no deben quedar partidos `pending` o `in_progress` (en competencias por equipos: enfrentamientos `pending`, `ready` o `in_progress`);
- las competencias **sin uso** (0 inscripciones y 0 partidos) no bloquean el cierre.

La finalización deportiva de cada competencia sigue siendo derivada (`status_summary.code = completed`) y **no** requiere botón manual.

---

### Competition

Representa una competencia concreta dentro de un torneo.

| Campo           | Tipo     | Descripción                                         |
|-----------------|----------|-----------------------------------------------------|
| id              | bigint   | Identificador único.                                |
| tournament_id   | bigint   | FK a Tournament.                                    |
| name            | string   | Nombre descriptivo (ej: "Singles Primera").         |
| type            | enum     | `singles`, `doubles` o `team`.                      |
| team_size       | int      | Solo `team`: cantidad exacta de integrantes por equipo (2 a 20). Obligatorio en `team`, prohibido en otras modalidades. |
| team_tie_format_id | bigint | Solo `team`: FK a `TeamTieFormat` activo. Obligatorio en `team`, prohibido en otras modalidades. |
| category        | string   | División legacy (ej: primera). Se mantiene por compatibilidad. |
| category_id     | bigint   | FK nullable a Category. Preferido en formularios nuevos.       |
| format          | enum     | `groups_knockout` (fase de grupos + eliminatoria) o `knockout_direct` (eliminación directa). `manual` es un valor legacy que se interpreta como `groups_knockout`. |
| sets_to_win              | int      | **Legacy (columna DB).** No se expone ni configura por API. Se rellena al crear desde `group_stage_best_of` solo por compatibilidad de esquema. |
| points_per_set           | int      | Puntos necesarios para ganar un set (ej: 11).       |
| qualified_per_group      | int      | Cuántos participantes clasifican desde cada grupo hacia la fase eliminatoria (default: 2). |
| group_stage_best_of      | int      | Mejor de N en fase de grupos (default: 5).          |
| knockout_stage_best_of   | int      | Mejor de N en rondas eliminatorias tempranas (default: 5). |
| semifinal_best_of        | int      | Mejor de N en semifinal (default: 7).               |
| final_best_of            | int      | Mejor de N en final (default: 7).                     |
| third_place_mode         | enum     | `shared`, `playoff`, `none`. Default en competencias nuevas: `shared`. Filas existentes (migración): `none`. |

`third_place_mode` define cómo se determina el podio más allá de campeón y subcampeón:

| Valor | Comportamiento |
|-------|----------------|
| `shared` | Los dos perdedores de semifinales reales comparten el 3.º puesto (derivado, sin partido extra). |
| `none` | Solo campeón y subcampeón. |
| `playoff` | Al completar semifinales elegibles, se crean Final y partido por tercer puesto en paralelo. Ambos pueden jugarse en cualquier orden. La competencia queda `completed` cuando ambos terminan. El 3.º y 4.º puesto se resuelven con el ganador y perdedor de ese partido. |

Con **2 o 3 participantes** no existen dos semifinales reales elegibles: `playoff` se comporta efectivamente como `none` (solo Final define `completed`).

El partido por tercer puesto usa el mismo formato de sets que semifinales (`semifinal_best_of`).

Corregir una semifinal queda **bloqueado temporalmente** una vez generado el partido por tercer puesto, hasta implementar propagación dual (Etapa 5).

`qualified_per_group` define cuántos participantes de cada grupo avanzan al cuadro eliminatorio. El valor se configura al crear o editar la competencia (no puede modificarse una vez generado el bracket).

La **configuración de formato por fase** vive en los campos `*_best_of`. Al crear partidos, el sistema congela en cada `Game` un snapshot con `best_of` y `sets_to_win` calculado:

```
sets_to_win = intdiv(best_of, 2) + 1
```

| best_of | sets_to_win | Ejemplo |
|---------|-------------|---------|
| 1       | 1           | Mejor de 1 → gana con 1 set |
| 3       | 2           | Mejor de 3 → gana con 2 sets |
| 5       | 3           | Mejor de 5 → gana con 3 sets |
| 7       | 4           | Mejor de 7 → gana con 4 sets |

Mapeo de ronda eliminatoria → campo de competencia:

| Ronda | Campo |
|-------|-------|
| Fase de grupos | `group_stage_best_of` |
| 16avos / 8vos / Cuartos | `knockout_stage_best_of` |
| Semifinal | `semifinal_best_of` |
| Final | `final_best_of` |
| Tercer puesto (`playoff`) | `semifinal_best_of` |

`sets_to_win` a nivel competencia es **legacy**: la columna permanece en base de datos (NOT NULL) pero no forma parte de la API ni del formulario. Al crear una competencia, el sistema la rellena internamente como `intdiv(group_stage_best_of, 2) + 1`. La regla efectiva del partido vive en el snapshot de `Game` (`best_of`, `sets_to_win`). Solo `RecordGameSetAction` consulta `Competition.sets_to_win` como fallback para partidos legacy sin snapshot.

No se puede cambiar el formato por fase si la competencia ya tiene partidos generados.

Los jugadores **no** se inscriben al torneo en general, sino a cada competencia concreta.

#### Modalidades

| Modalidad | Participante | Partidos |
|-----------|--------------|----------|
| `singles` | Un jugador. | Cada `Game` enfrenta a dos jugadores. |
| `doubles` | Una pareja (exactamente 2 jugadores). | Cada `Game` enfrenta a dos parejas. Mismo flujo de grupos, standings y llave que singles. |
| `team` | Un equipo con nombre y exactamente `team_size` jugadores. | Los participantes se enfrentan en `TeamTie` (enfrentamiento por equipos), compuestos por varios `Game` según el `TeamTieFormat`. |

En `team`:

- no se crean partidos manuales ni se eliminan partidos individuales: los partidos se gestionan dentro de su enfrentamiento;
- el formato de enfrentamiento y `team_size` no pueden modificarse una vez generados enfrentamientos.

#### Estado calculado de competencia

La API expone `status_summary` en `CompetitionResource`. Es un **estado calculado** (no persistido en BD) que orienta la UI sobre el avance de la competencia y la próxima acción sugerida.

Se calcula en `CompetitionStatusResolver` a partir de:

- existencia de grupos
- partidos de grupo (`group_id` not null, `bracket_id` null) o enfrentamientos de grupo en `team`
- empates manuales pendientes o desactualizados que afectan la clasificación
- existencia de bracket
- partidos o enfrentamientos eliminatorios y final

En `knockout_direct` no hay fase de grupos: el estado previo a la llave depende solo de la cantidad de inscriptos.

**No reemplaza** las validaciones de las Actions (generar bracket, cargar sets, etc.). Solo informa.

Códigos disponibles:

| code | Significado |
|------|-------------|
| `awaiting_registrations` | `knockout_direct` con menos de 2 inscriptos |
| `no_groups` | Sin grupos configurados |
| `group_stage_pending` | Hay grupos pero no partidos de grupo |
| `group_stage_in_progress` | Partidos de grupo pendientes o en curso |
| `group_stage_attention_required` | Grupos finalizados, pero hay desempates manuales pendientes o desactualizados que deben resolverse antes de generar la llave |
| `ready_for_bracket` | Lista para generar la llave (grupos finalizados, o al menos 2 inscriptos en `knockout_direct`) |
| `knockout_in_progress` | Llave generada, eliminatoria en curso |
| `completed` | Final disputada con ganador |

Estructura expuesta:

```json
{
  "code": "ready_for_bracket",
  "label": "Lista para generar llave",
  "description": "...",
  "next_action": "Generar llave eliminatoria"
}
```

#### Resultado calculado de competencia

La API expone `result_summary` en `CompetitionResource`. Es un resultado calculado, no persistido en BD.

Se calcula en `CompetitionResultResolver` desde la final terminada del bracket (un `Game` en singles/dobles, un `TeamTie` en equipos):

- final de la llave principal
- `status = finished`
- `winner_entry_id` definido

El campeón es la inscripción ganadora de la final.
El subcampeón es la otra inscripción de la final.

Campos adicionales (backward compatible):

```json
{
  "champion": { "id": 1, "name": "..." },
  "runner_up": { "id": 2, "name": "..." },
  "final_game_id": 99,
  "final_team_tie_id": null,
  "third_place_mode": "shared",
  "third_place": [],
  "fourth_place": null,
  "third_place_game_id": null,
  "third_place_team_tie_id": null
}
```

- `final_game_id` / `final_team_tie_id`: se completa uno u otro según la modalidad.
- `third_place_mode`: refleja la configuración de la competencia.
- `third_place`: array de participantes. Con `shared` y semifinales elegibles, contiene los dos perdedores (orden: `bracket_match` 1, luego 2). Con `playoff`, contiene al ganador del partido por tercer puesto una vez terminado. Con `none`, array vacío.
- `fourth_place`: con `playoff`, el perdedor del partido por tercer puesto una vez terminado; en otro caso `null`.
- `third_place_game_id` / `third_place_team_tie_id`: con `playoff`, el partido o enfrentamiento por tercer puesto si existe.

La elegibilidad del tercer puesto compartido se calcula en `BracketPodiumSupport` a partir del bracket eliminatorio (`bracket_size >= 4`, exactamente dos semifinales reales terminadas con dos perdedores distintos). No usa standings de grupos.

Si la competencia no está finalizada, falta final, falta ganador o falta rival, `result_summary` devuelve `null`.

#### Clasificación final persistida

Además del resultado calculado, cuando la competencia queda deportivamente completa (al registrar o corregir resultados) y al cerrar el torneo, el sistema consolida la clasificación final de la competencia en `CompetitionFinalStanding` (una fila por inscripción: posición, rango de posición, origen —final, semifinal, fase de grupos, etc.— y snapshot del nombre visible). Esa clasificación es la base para otorgar puntos de ranking.

---

### Player

Representa a una persona que participa como jugador.

| Campo        | Tipo     | Descripción                                      |
|--------------|----------|--------------------------------------------------|
| id           | bigint   | Identificador único.                             |
| first_name   | string   | Nombre.                                          |
| last_name    | string   | Apellido.                                        |
| nickname     | string   | Apodo (nullable, único).                         |
| category_id  | bigint   | FK nullable a Category (categoría principal).    |
| club_id      | bigint   | FK nullable a Club.                              |
| active       | boolean  | Default `true`. Inactivos no se inscriben.       |

La categoría del jugador es la **categoría principal actual**. No hay historial de cambios en el MVP.

**Auditoría:** `active = false` vía PATCH se registra como desactivación (`player.deactivated`). `DELETE /players/{id}` elimina físicamente solo jugadores sin historial (inscripciones, grupos, partidos) y produce `player.deleted`. Ver [AUDIT.md](./AUDIT.md).

---

### Category

Catálogo de categorías/divisions normalizadas.

| Campo  | Tipo    | Descripción                    |
|--------|---------|--------------------------------|
| id     | bigint  | Identificador único.           |
| name   | string  | Nombre visible (ej: Primera).  |
| slug   | string  | Identificador único (primera). |
| active | boolean | Default `true`.                |

Categorías iniciales: `primera`, `segunda`, `tercera`, `cuarta`, `libre`.

---

### Club

Catálogo de clubes o instituciones.

| Campo  | Tipo    | Descripción          |
|--------|---------|----------------------|
| id     | bigint  | Identificador único. |
| name   | string  | Nombre del club.     |
| active | boolean | Default `true`.      |

Un jugador puede no tener club (`club_id = null`).

---

### Inscripción: CompetitionEntry y CompetitionEntryMember

La **inscripción** (concepto funcional, expuesto en la API como `registrations`) se modela con dos entidades técnicas:

- `CompetitionEntry`: el participante inscripto en una competencia (un jugador en singles, una pareja en dobles, un equipo en `team`). Es la unidad que se asigna a grupos, se enfrenta en partidos y enfrentamientos, clasifica y gana.
- `CompetitionEntryMember`: cada jugador que integra esa inscripción.

`CompetitionEntry`:

| Campo          | Tipo   | Descripción |
|----------------|--------|-------------|
| id             | bigint | Identificador único. |
| competition_id | bigint | FK a Competition. |
| status         | enum   | `active`, `withdrawn`, `disqualified` (default `active`). Hoy toda inscripción se crea `active` y ninguna operación la cambia; las bajas deportivas se registran en `GroupEntry`. |
| display_name   | string | Nullable. Nombre del equipo en `team`; único por competencia. En singles/dobles el nombre visible se deriva de los integrantes. |

`CompetitionEntryMember`:

| Campo                | Tipo      | Descripción |
|----------------------|-----------|-------------|
| id                   | bigint    | Identificador único. |
| competition_entry_id | bigint    | FK a CompetitionEntry. |
| competition_id       | bigint    | Competencia (desnormalizada; debe coincidir con la de la inscripción). |
| player_id            | bigint    | FK a Player. |
| member_order         | int       | Orden del integrante dentro de la inscripción (1-based). |
| checked_in_at        | timestamp | Nullable. Presencia confirmada (check-in) del integrante. |

**Reglas:**

- La cantidad de integrantes es exacta según la modalidad: 1 en singles, 2 en dobles, `team_size` en equipos.
- Los integrantes de una inscripción son jugadores distintos, existentes y activos.
- Un jugador no puede estar en dos inscripciones de la misma competencia. Restricción única: `(competition_id, player_id)` en `CompetitionEntryMember`.
- En `team` el nombre del equipo es obligatorio y único dentro de la competencia; en singles/dobles no se envía nombre.
- Las inscripciones no pueden modificarse una vez generada la llave eliminatoria, ni (en `team`) una vez generados enfrentamientos.
- La inscripción masiva solo está disponible en singles.
- El check-in es informativo: registra la presencia de cada integrante y solo se permite en inscripciones activas.

**Auditoría:** la inscripción individual produce `registration.created`. La inscripción masiva (`POST .../registrations/bulk`) produce **una sola** actividad `registration.bulk_created` con contadores, sin logs por jugador. Ver [AUDIT.md](./AUDIT.md).

---

### Group

Representa un grupo dentro de una competencia.

| Campo          | Tipo   | Descripción                    |
|----------------|--------|--------------------------------|
| id             | bigint | Identificador único.           |
| competition_id | bigint | FK a Competition.              |
| name           | string | Nombre del grupo (ej: "Grupo A"). |

**Regla:** el nombre de grupo es único por competencia.
Restricción única: `(competition_id, name)`.

**Auditoría:** la creación manual vía API (`POST .../groups`) produce `group.created`. La generación inicial aleatoria produce **una sola** actividad agregada `groups.generated` (contadores de grupos, jugadores y partidos), sin logs por grupo, asignación o partido interno. La regeneración produce `groups.regenerated`. Ver [AUDIT.md](./AUDIT.md).

---

### GroupEntry

Representa la participación de una inscripción (`CompetitionEntry`) dentro de un grupo. Reemplaza a la antigua asignación jugador–grupo (`group_players`, eliminada).

| Campo                | Tipo       | Descripción                                              |
|----------------------|------------|----------------------------------------------------------|
| id                   | bigint     | Identificador único.                                     |
| group_id             | bigint     | FK a Group.                                              |
| competition_id       | bigint     | Competencia (desnormalizada; debe coincidir con la de la inscripción). |
| competition_entry_id | bigint     | FK a CompetitionEntry.                                   |
| status               | enum       | `active`, `withdrawn`, `disqualified` (default: `active`). |
| status_reason        | enum       | Nullable. Motivo de la baja (ver abajo).                 |
| status_notes         | text       | Nullable. Notas libres sobre la baja.                  |
| status_changed_at    | timestamp  | Nullable. Momento del último cambio de estado.           |

Restricciones únicas: `(group_id, competition_entry_id)` y `(competition_id, competition_entry_id)`.

Reglas adicionales implementadas:

- una inscripción no puede estar en más de un grupo dentro de la misma competencia;
- solo se puede pasar de `active` a `withdrawn` o `disqualified` (no hay reactivación en el MVP);
- no se puede cambiar el estado si la competencia ya tiene bracket;
- en `team`, no se puede cambiar el estado si el grupo ya tiene enfrentamientos generados.

**Auditoría:** la asignación manual vía API (`POST .../groups/{id}/players`) produce `group.player_assigned` con el estado inicial (`active`). El código de auditoría conserva el nombre histórico. Ver [AUDIT.md](./AUDIT.md).

**Motivos de baja (`status_reason`):**

| Valor                 | Descripción              |
|-----------------------|--------------------------|
| `personal`            | Motivos personales.      |
| `injury`              | Lesión.                  |
| `no_show`             | No se presentó.          |
| `organizer_decision`  | Decisión organizativa.   |
| `other`               | Otro.                    |

---

### GroupManualTiebreak

Representa un desempate manual persistido para un grupo.

| Campo      | Tipo      | Descripción                                |
|------------|-----------|--------------------------------------------|
| id         | bigint    | Identificador único.                       |
| group_id   | bigint    | FK a Group.                                |
| reason     | enum      | Motivo del desempate (ver abajo).          |
| notes      | text      | Nullable. Notas libres.                    |
| applied_at | timestamp | Momento en que se aplicó o actualizó.      |

**Motivos (`reason`):**

| Valor                 | Descripción                    |
|-----------------------|--------------------------------|
| `draw`                | Sorteo.                        |
| `organizer_decision`  | Decisión organizativa.         |
| `agreement`           | Acuerdo entre participantes.       |
| `other`               | Otro.                          |

El orden de las inscripciones empatadas se guarda en `GroupManualTiebreakEntry`.

---

### GroupManualTiebreakEntry

Representa la posición de una inscripción dentro de un desempate manual.

| Campo                    | Tipo   | Descripción                          |
|--------------------------|--------|--------------------------------------|
| id                       | bigint | Identificador único.                 |
| group_manual_tiebreak_id | bigint | FK a GroupManualTiebreak.            |
| competition_entry_id     | bigint | FK a CompetitionEntry.               |
| position                 | int    | Posición dentro del empate (1-based). |

Restricciones únicas por tiebreak: `(group_manual_tiebreak_id, competition_entry_id)` y `(group_manual_tiebreak_id, position)`.

---

### Bracket

Representa el cuadro eliminatorio de una competencia.

| Campo                | Tipo   | Descripción                                  |
|----------------------|--------|----------------------------------------------|
| id                   | bigint | Identificador único.                         |
| competition_id       | bigint | FK a Competition.                            |
| name                 | string | Nombre del cuadro. Se genera automáticamente como `Llave - {nombre de competencia}` si no se envía uno custom. |
| qualifiers_per_group | int    | Snapshot del valor `qualified_per_group` de la competencia al momento de crear el cuadro. |
| bracket_size         | int    | Tamaño de la llave (siguiente potencia de 2 ≥ clasificados).                             |
| byes_count           | int    | Cantidad de BYEs incluidos al completar la llave hasta `bracket_size`. En `groups_knockout` con `qualified_per_group = 3`, corresponde típicamente a un BYE por cada 1° de grupo. |

`Bracket.qualifiers_per_group` registra cuántos clasificados por grupo se usaron al generar el cuadro. La configuración activa vive en `Competition.qualified_per_group`; el campo del bracket es histórico.

Restricción única: `competition_id` (una competencia tiene un solo bracket).

El nombre del bracket **no representa la ronda inicial**. La ronda inicial depende de `qualified_per_group`, de `bracket_size` y de los partidos generados (campo `round` del primer `bracket_round`).

- Con `qualified_per_group = 2` y total de clasificados = potencia de 2, la primera ronda suele ser la ronda principal del cuadro (ej. cuartos, semifinales).
- Con `qualified_per_group = 3`, la primera ronda del bracket incluye **play-in** (2° vs 3° de grupos distintos); los BYEs a 1° de grupo pueden registrarse en la misma ronda o en la siguiente, según el árbol generado.

Ejemplo (`groups_knockout`, 4 grupos × 3 clasificados):

```text
name = Llave - Singles Club
bracket_size = 16
byes_count = 4
clasificados = 12
play-in = 4 partidos (2° vs 3° inter-grupo)
BYEs = 4 (uno por cada 1° de grupo)
ronda principal posterior = 8vos de final (8 participantes)
```

En `groups_knockout`, al generar la llave se persiste el origen grupal de cada clasificado en `BracketEntryOrigin` (inscripción, grupo, nombre del grupo y posición en el grupo). Se usa para mostrar la procedencia en la llave y en la impresión.

En `team` la llave se compone de `TeamTie` en lugar de `Game` (ver [TeamTie](#teamtie)).

---

### Game

Representa un partido entre dos inscripciones (jugadores en singles, parejas en dobles) dentro de una competencia. En `team`, cada partido interno de un enfrentamiento también es un `Game` (ver [TeamTieGame](#teamtiegame)).

| Campo          | Tipo       | Descripción                                        |
|----------------|------------|----------------------------------------------------|
| id             | bigint     | Identificador único.                               |
| competition_id | bigint     | FK a Competition.                                  |
| group_id       | bigint     | FK a Group (nullable).                             |
| bracket_id     | bigint     | FK a Bracket (nullable).                           |
| entry1_id      | bigint     | FK a CompetitionEntry (lado 1).                    |
| entry2_id      | bigint     | FK a CompetitionEntry (lado 2; nullable en partidos BYE). |
| winner_entry_id | bigint    | FK a CompetitionEntry (nullable, se completa al terminar). |
| status         | enum       | `pending`, `in_progress`, `finished`, `not_needed` (solo partidos internos de un enfrentamiento por equipos que ya quedó decidido). |
| is_bye         | boolean    | `true` si el partido es avance automático (BYE).   |
| best_of        | int        | Snapshot: mejor de N (nullable en BYE).            |
| sets_to_win    | int        | Snapshot: sets necesarios para ganar (nullable en BYE). |
| finished_at    | timestamp  | Momento de cierre (nullable).                      |
| round          | string     | Ronda descriptiva (ej: "Semifinal", "Final").      |
| bracket_round  | int        | Número de ronda en bracket (nullable).             |
| bracket_match  | int        | Número de partido dentro de la ronda (nullable).   |
| bracket_purpose | enum      | `main` (llave principal) o `third_place` (partido por tercer puesto). Default: `main`. El tercer puesto usa `bracket_round = null` y no participa en el árbol principal. |
| table_number   | int        | **Legacy.** Número suelto de mesa (nullable, sin catálogo ni scheduling). No usar para asignaciones nuevas; la relación vigente es `playing_table_id`. |
| playing_table_id | bigint   | FK nullable a `PlayingTable`. Unique: a lo sumo un `Game` ocupa una mesa. |

Las inscripciones de un partido pertenecen a la misma competencia que el partido (FK compuesta `(entry_id, competition_id)`).

Un partido puede pertenecer al flujo manual, a grupos, a bracket o a un enfrentamiento por equipos.

Al crearse, cada partido real (no BYE) guarda `best_of` y `sets_to_win` según la fase/ronda vigente en la competencia. Ese snapshot no cambia aunque se edite la competencia después. Los partidos BYE mantienen ambos campos en `null` y no admiten sets.

**Auditoría:** la creación manual vía API produce `game.created`. La eliminación manual produce `game.deleted` con snapshot del partido (incl. sets) antes del borrado. Los partidos creados automáticamente (round robin, generación/regeneración de grupos, llave, avance de ronda) **no** generan `game.created` individual. Ver [AUDIT.md](./AUDIT.md).

---

### GameSet

Representa un set dentro de un partido. Es **append-only** en el MVP: no se editan sets individuales.

| Campo           | Tipo   | Descripción                       |
|-----------------|--------|-----------------------------------|
| id              | bigint | Identificador único.              |
| game_id         | bigint | FK a Game.                        |
| set_number      | int    | Número de set (1, 2, 3...).       |
| player1_score   | int    | Puntos del lado 1 (`entry1_id`) en este set. |
| player2_score   | int    | Puntos del lado 2 (`entry2_id`) en este set. |

Restricción única: `(game_id, set_number)`.

El ganador de cada set se deriva dinámicamente de `player1_score` vs `player2_score`. Los nombres de columna son históricos: representan los lados del partido, no jugadores individuales.

---

### TeamTieFormat

Define el formato de un enfrentamiento por equipos. Es un catálogo: la API solo expone lectura (`GET /team-tie-formats`) y cada competencia `team` referencia un formato activo.

| Campo              | Tipo    | Descripción |
|--------------------|---------|-------------|
| id                 | bigint  | Identificador único. |
| name               | string  | Nombre del formato. |
| description        | text    | Nullable. |
| victories_required | int     | Partidos que debe ganar un equipo para ganar el enfrentamiento. |
| active             | boolean | Solo formatos activos pueden asignarse a competencias. |

Cada formato tiene `TeamTieFormatSlot` ordenados (`slot_order`, único por formato), cada uno con su `modality`: `singles` o `doubles`.

Reglas del formato: al menos un slot; `victories_required` ≥ 1, no mayor a la cantidad de slots y mayor que la mitad de los slots (mayoría posible).

---

### TeamTie

Representa un enfrentamiento entre dos equipos (`CompetitionEntry` de una competencia `team`), en fase de grupos o en la llave.

| Campo              | Tipo      | Descripción |
|--------------------|-----------|-------------|
| id                 | bigint    | Identificador único. |
| competition_id     | bigint    | FK a Competition. |
| group_id           | bigint    | FK a Group (nullable). |
| bracket_id         | bigint    | FK a Bracket (nullable). |
| entry1_id / entry2_id | bigint | Equipos enfrentados (`entry2_id` nullable en BYE). |
| winner_entry_id    | bigint    | Equipo ganador (nullable). |
| team_tie_format_id | bigint    | Formato usado. |
| victories_required / format_name | int / string | Snapshot del formato al crear el enfrentamiento. |
| status             | enum      | `pending`, `ready`, `in_progress`, `finished`, `cancelled`. |
| group_round / group_match | int | Posición en el fixture del grupo. |
| bracket_round / bracket_match / bracket_purpose / round | | Posición en la llave (mismo esquema que `Game`). |
| finished_at        | timestamp | Nullable. |

Al crearse, el enfrentamiento genera un `TeamTieGame` (con su `Game`) por cada slot del formato.

Resultado:

- Se recalcula cada vez que se registra o corrige el resultado de uno de sus partidos.
- Cuenta los partidos internos finalizados en orden de slot; el primer equipo que alcanza `victories_required` gana el enfrentamiento (`status = finished`, `winner_entry_id`).
- Una vez decidido, los partidos internos restantes que siguen `pending` y sin sets pasan a `not_needed` (no admiten sets). Si una corrección deshace la definición, vuelven a `pending`.
- Sin definición, el enfrentamiento queda `in_progress` si alguno de sus partidos tiene actividad; si no, `pending`.
- Un BYE en la llave es un enfrentamiento `is_bye = true`, `finished`, sin rival y sin partidos internos.

Los valores `ready` y `cancelled` existen en el enum y se consideran en algunas validaciones, pero hoy ninguna operación los asigna.

---

### TeamTieGame

Representa un partido dentro de un enfrentamiento por equipos (un slot del formato).

| Campo       | Tipo   | Descripción |
|-------------|--------|-------------|
| id          | bigint | Identificador único. |
| team_tie_id | bigint | FK a TeamTie. |
| game_id     | bigint | FK única a `Game`: cada partido interno es un `Game` (con `entry1_id`/`entry2_id` del enfrentamiento) y se juega y registra con las mismas reglas de sets. |
| slot_order  | int    | Orden dentro del enfrentamiento. Único por enfrentamiento. |
| modality    | enum   | `singles` o `doubles`. |

**Alineación (lineup):** `TeamTieGameMember` vincula a cada lado del partido los integrantes del equipo que lo juegan (`side`: `entry1`/`entry2`, `player_order`). Por lado se requiere 1 jugador en singles y 2 en dobles, integrantes del equipo correspondiente y sin repetir en el mismo lado. La alineación no puede modificarse si el enfrentamiento terminó o fue cancelado, ni si el partido ya comenzó, tiene sets o es `not_needed`.

---

## 4. Relaciones

```
Tournament
  ├── hasMany Competition
  └── hasMany PlayingTable

PlayingTable
  ├── belongsTo Tournament
  └── hasMany Game

Competition
  ├── belongsTo Tournament
  ├── belongsTo Category (nullable, category_id)
  ├── belongsTo TeamTieFormat (nullable, solo team)
  ├── hasMany CompetitionEntry
  ├── hasMany CompetitionEntryMember
  ├── hasMany Group
  ├── hasMany Bracket
  ├── hasMany Game
  ├── hasMany TeamTie
  └── hasMany CompetitionFinalStanding

CompetitionEntry
  ├── belongsTo Competition
  ├── hasMany CompetitionEntryMember
  ├── hasMany GroupEntry
  └── (participa en Game y TeamTie como entry1 / entry2 / winner_entry)

CompetitionEntryMember
  ├── belongsTo CompetitionEntry
  ├── belongsTo Competition
  └── belongsTo Player

Player
  ├── belongsTo Category (nullable)
  ├── belongsTo Club (nullable)
  └── hasMany CompetitionEntryMember

Group
  ├── belongsTo Competition
  ├── hasMany GroupEntry
  ├── hasMany GroupManualTiebreak
  ├── hasMany Game
  └── hasMany TeamTie

GroupEntry
  ├── belongsTo Group
  ├── belongsTo Competition
  └── belongsTo CompetitionEntry

GroupManualTiebreak
  ├── belongsTo Group
  └── hasMany GroupManualTiebreakEntry

GroupManualTiebreakEntry
  ├── belongsTo GroupManualTiebreak
  └── belongsTo CompetitionEntry

Bracket
  ├── belongsTo Competition
  ├── hasMany Game
  ├── hasMany TeamTie
  └── hasMany BracketEntryOrigin

BracketEntryOrigin
  ├── belongsTo Bracket
  ├── belongsTo CompetitionEntry
  └── belongsTo Group

Game
  ├── belongsTo Competition
  ├── belongsTo Group (nullable)
  ├── belongsTo Bracket (nullable)
  ├── belongsTo PlayingTable (nullable)
  ├── belongsTo CompetitionEntry (entry1)
  ├── belongsTo CompetitionEntry (entry2, nullable)
  ├── belongsTo CompetitionEntry (winner_entry, nullable)
  ├── hasOne TeamTieGame (si es partido interno de un enfrentamiento)
  └── hasMany GameSet

GameSet
  └── belongsTo Game

TeamTieFormat
  ├── hasMany TeamTieFormatSlot
  ├── hasMany Competition
  └── hasMany TeamTie

TeamTie
  ├── belongsTo Competition
  ├── belongsTo Group (nullable)
  ├── belongsTo Bracket (nullable)
  ├── belongsTo TeamTieFormat
  ├── belongsTo CompetitionEntry (entry1, entry2, winner_entry)
  └── hasMany TeamTieGame

TeamTieGame
  ├── belongsTo TeamTie
  ├── belongsTo Game
  └── hasMany TeamTieGameMember

TeamTieGameMember
  ├── belongsTo TeamTieGame
  └── belongsTo CompetitionEntryMember

CompetitionFinalStanding
  ├── belongsTo Competition
  └── belongsTo CompetitionEntry

Category
  ├── hasMany Player
  └── hasMany Competition

Club
  └── hasMany Player
```

---

## 5. Reglas de negocio

1. Un jugador debe existir (y estar activo) antes de inscribirse a una competencia.
2. Un jugador no puede estar en dos inscripciones de la misma competencia (ni como integrante de dos parejas o equipos).
3. Un partido pertenece a una competencia y enfrenta a exactamente dos inscripciones distintas (salvo BYE).
4. Las inscripciones de un Game deben pertenecer a la misma Competition.
5. El ganador del partido se calcula a partir de los GameSets cargados.
6. Un lado gana el partido cuando acumula el `sets_to_win` del **Game** (snapshot). Si el partido no tiene snapshot (datos legacy), se usa `Competition.sets_to_win`.
7. No se pueden registrar sets en un partido ya finalizado, BYE o `not_needed`.
7b. Un partido finalizado puede corregirse excepcionalmente mediante reemplazo completo del resultado (`POST /games/{game}/corrections`), solo con permiso `matches.correct_result`, motivo obligatorio y restricciones de grupo/llave (ver §20).
8. No se puede registrar un set empatado.
9. El ganador del set debe alcanzar al menos `points_per_set`.
10. El marcador del set debe representar un **resultado final válido** (no basta con diferencia mínima de puntos):
    - si el ganador llega exactamente a `points_per_set`, el perdedor debe tener como máximo `points_per_set - 2`;
    - si el ganador supera `points_per_set` (deuce), la diferencia debe ser exactamente 2.
11. No puede repetirse `set_number` dentro del mismo game.
12. Un grupo necesita al menos 2 participantes para generar round robin.
13. En singles y dobles, generar el round robin de un grupo es idempotente: solo crea los cruces que faltan y no duplica partidos existentes. En `team`, los enfrentamientos de un grupo se generan una sola vez (si ya existen, se rechaza).
14. Una inscripción no puede estar en dos grupos de la misma competencia.
15. Solo puede existir un bracket por competencia.
16. Para crear bracket, todos los partidos de grupos deben estar finalizados.
17. El bracket usa `Competition.qualified_per_group` para determinar cuántos participantes clasifican de cada grupo, considerando solo participantes elegibles según standings.
18. El total de clasificados ya no necesita ser exactamente 2, 4 u 8. Si no es potencia de 2, el sistema calcula la siguiente potencia de 2 ≥ total de clasificados y completa la llave con BYEs (hasta 64 clasificados).
19. En competencias `groups_knockout`, los BYEs se asignan según **procedencia grupal**, no por un ranking global de victorias. Con `qualified_per_group = 3`, los **primeros de cada grupo** tienen prioridad de BYE. Con `qualified_per_group = 2`, los BYEs restantes (si los hubiera) se resuelven dentro del algoritmo de emparejamiento grupal, no por `(won, lost, nombre)`.
20. `Game.is_bye` indica avance automático: `entry2_id = null`, partido finalizado sin sets, `winner_entry_id = entry1_id`. Los BYEs a primeros de grupo quedan registrados como partidos `is_bye = true` (en `team`, como enfrentamientos `is_bye = true`).
21. No se puede cambiar `qualified_per_group` si la competencia ya tiene un bracket generado.
22. La siguiente ronda del bracket se genera con `winner_entry_id` de la ronda actual (incluye ganadores de BYE).
23. No puede generarse siguiente ronda si la actual está incompleta.
24. No puede generarse una ronda ya creada ni avanzar cuando el bracket ya terminó.
25. No se puede cambiar el formato por fase (`*_best_of`) si la competencia ya tiene partidos generados.
26. Los standings de grupo se calculan en runtime (no se persisten como tabla fija).
27. Solo participantes `active` en `group_entries` participan del orden automático por victorias y de la elegibilidad para clasificar.
28. Participantes `withdrawn` o `disqualified` permanecen visibles en standings, al final de la tabla, y no son elegibles para clasificación.
29. Si un participante pasa a `withdrawn` o `disqualified`, los partidos del grupo ya finalizados no se modifican; sus partidos `pending` o `in_progress` se cierran a favor del rival sin registrar sets. En `team` no se puede cambiar el estado una vez generados los enfrentamientos del grupo.
30. No se puede cambiar el estado de un participante de grupo si ya existe bracket.
31. No se puede definir desempate manual si ya existe bracket.
32. Un desempate manual solo se acepta si el conjunto de participantes enviado coincide exactamente con un empate pendiente actual del grupo.
33. Si cambian los resultados y un desempate manual guardado ya no coincide con ningún empate pendiente, queda como **stale** (desactualizado).
34. El bracket usa el mismo cálculo de standings por grupo que la API/UI, incluyendo desempates manuales aplicados.
35. Solo participantes con `eligible_for_qualification = true` pueden clasificar al bracket.
36. Si un empate manual pendiente afecta el corte de clasificación del grupo, o si el grupo tiene desempates manuales desactualizados (stale), se bloquea la generación del bracket hasta resolverlo.
37. Si hay menos clasificados elegibles que `qualified_per_group`, el bracket se genera con los disponibles; los BYEs y el play-in se ajustan a la cantidad real de clasificados por posición grupal.
38. En competencias `groups_knockout`, la llave eliminatoria se genera de forma **consciente de la procedencia grupal**. Queda **reemplazada** la regla anterior de combinar todos los clasificados y ordenarlos globalmente por `(won desc, lost asc, nombre)` antes de armar la llave.
39. En competencias `knockout_direct` (sin fase de grupos), el emparejamiento inicial sigue el **orden de inscripción** y el esquema estándar 1 vs N dentro del `bracket_size` calculado; no aplica el draw grupal.
40. Mientras no exista bracket, los grupos admiten cambios tardíos: crear grupos, asignar, quitar y mover participantes. Al asignar un participante a un grupo con fixture, se completan automáticamente sus partidos faltantes (singles/dobles). Al quitar o mover, se eliminan los partidos que dejan de corresponder solo si están `pending`, sin sets y sin ganador; si alguno ya comenzó o terminó, la operación se rechaza.
41. En `team`, los cambios tardíos de grupos solo se permiten mientras ningún partido de la competencia haya comenzado; asignar no genera enfrentamientos automáticamente, y quitar o mover participantes no está disponible.
42. Las inscripciones no se pueden modificar una vez generada la llave ni, en `team`, una vez generados enfrentamientos.
43. En `team`, un enfrentamiento lo gana el primer equipo que alcanza `victories_required` partidos internos ganados.

---

## 6. Lógica de cálculo del ganador del game

Al registrar un set (`POST /games/{game}/sets`), el sistema:

1. Valida `set_number` y scores (rechaza `set_number` mayor a `Game.best_of` cuando está definido).
2. Rechaza sets en partidos BYE, ya finalizados o `not_needed`.
3. Rechaza set empatado.
4. Rechaza score ganador por debajo de `points_per_set` (desde `Competition`).
5. Rechaza marcadores que no representen un cierre válido del set (ver regla 10).
6. Rechaza duplicado de `set_number` en el mismo game.
7. Persiste el set (append-only).
8. Recalcula sets ganados por cada lado.
9. Usa `Game.sets_to_win` (fallback: `Competition.sets_to_win` legacy).
10. Si un lado alcanza ese umbral:
   - setea `winner_entry_id`,
   - setea `status = finished`,
   - setea `finished_at = now()`.
11. Si nadie lo alcanza:
   - mantiene `winner_entry_id = null`,
   - setea `status = in_progress`,
   - mantiene `finished_at = null`.
12. Si el partido es interno de un enfrentamiento por equipos, recalcula el resultado del `TeamTie` (ver [TeamTie](#teamtie)).

---

## 7. Flujo completo del sistema

```
1. Crear Tournament
2. Crear Competition (vinculada al Tournament)
3. Registrar Players
4. Inscribir participantes a la Competition (jugador, pareja o equipo)
5. Crear Groups en la Competition
6. Asignar participantes a cada Group
7. Generar Round Robin de cada Group (partidos en singles/dobles; enfrentamientos en team)
8. Cargar sets hasta finalizar los partidos de grupos (en team: definir alineaciones y cargar los partidos internos)
9. Consultar standings de grupos / competencia
10. Resolver desempates manuales pendientes (si los hubiera)
11. Marcar retiros/descalificaciones de participantes (si corresponde)
12. Crear Bracket eliminatorio (usa `Competition.qualified_per_group`; guarda snapshot en `Bracket.qualifiers_per_group`)
13. Cargar sets de los partidos del bracket
14. Generar siguiente ronda del bracket
15. Repetir hasta la final
```

En `knockout_direct` se omiten los pasos 5 a 11: la llave se genera directamente con los inscriptos.

En singles y dobles también se pueden crear partidos manuales directamente en la competencia.

---

## 8. Endpoints principales del MVP

### Torneos

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/tournaments` | Crear torneo |
| GET | `/api/v1/tournaments` | Listar torneos |
| GET | `/api/v1/tournaments/{tournament}` | Ver torneo |
| PUT/PATCH | `/api/v1/tournaments/{tournament}` | Actualizar torneo |
| POST | `/api/v1/tournaments/{tournament}/close` | Cerrar torneo administrativamente |

### Competencias

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/tournaments/{tournament}/competitions` | Crear competencia |
| GET | `/api/v1/tournaments/{tournament}/competitions` | Listar competencias del torneo |
| GET | `/api/v1/competitions/{competition}` | Ver competencia |

### Jugadores e inscripciones

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/v1/categories` | Listar categorías activas |
| GET | `/api/v1/clubs` | Listar clubes activos |
| POST | `/api/v1/players` | Crear jugador |
| GET | `/api/v1/players` | Listar jugadores (`q`, `category_id`, `club_id`, `include_inactive`, `sort`, `page`, `per_page`) |
| GET | `/api/v1/players/{player}` | Ver jugador |
| PATCH | `/api/v1/players/{player}` | Actualizar jugador |
| DELETE | `/api/v1/players/{player}` | Eliminar jugador (solo sin historial) |
| POST | `/api/v1/competitions/{competition}/registrations` | Inscribir participante (`player_id` en singles; `player_ids` en dobles; `name` + `player_ids` en equipos) |
| POST | `/api/v1/competitions/{competition}/registrations/bulk` | Inscripción masiva (`player_ids`, solo singles) |
| GET | `/api/v1/competitions/{competition}/registrations` | Listar inscripciones |

#### Filtros de jugadores (`GET /players`)

| Parámetro | Descripción |
|-----------|-------------|
| `q` | Búsqueda por nombre, apellido o apodo |
| `category_id` | Filtra por categoría principal |
| `club_id` | Filtra por club |
| `include_inactive` | Incluye jugadores inactivos |
| `sort` | `-id` (default), `id`, `last_name`, `-last_name` |
| `page` / `per_page` | Paginación (sin `page` devuelve todos los resultados) |

#### Inscripción masiva

`POST /competitions/{competition}/registrations/bulk`

```json
{ "player_ids": [1, 2, 3] }
```

Respuesta:

```json
{
  "message": "Inscripción masiva procesada.",
  "created": 2,
  "skipped": 1,
  "total": 3
}
```

- Solo disponible en singles: cada jugador genera su propia inscripción.
- Omite jugadores ya inscriptos (`skipped`).
- Rechaza jugadores inactivos o inexistentes.
- **No bloquea** por diferencia de categoría entre jugador y competencia.
- La advertencia por categoría distinta es **solo visual** en la UI antes de confirmar.

#### Compatibilidad de categoría en inscripción (UI)

| Estado | Criterio |
|--------|----------|
| Compatible | Activo, no inscripto, misma categoría |
| Categoría distinta | Activo, no inscripto, categoría diferente (advertencia, inscripción permitida) |
| Sin categoría | Activo, no inscripto, `category_id` null (inscripción permitida) |
| No disponible | Ya inscripto, inactivo u otra restricción real |

**Postergado:** historial `player_categories`, políticas configurables (`strict`/`warning`/`open`), inscripción por filtro completo.

### Grupos y standings

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/competitions/{competition}/groups` | Crear grupo |
| GET | `/api/v1/competitions/{competition}/groups` | Listar grupos |
| POST | `/api/v1/groups/{group}/players` | Asignar participante a grupo (`player_id` en singles o `competition_entry_id`) |
| GET | `/api/v1/groups/{group}/players` | Listar participantes del grupo |
| DELETE | `/api/v1/groups/{group}/players/{competitionEntry}` | Quitar participante del grupo (singles/dobles) |
| POST | `/api/v1/groups/{group}/move-player` | Mover participante a otro grupo (singles/dobles) |
| POST | `/api/v1/groups/{group}/round-robin-games` | Generar round robin (en team genera enfrentamientos) |
| GET | `/api/v1/groups/{group}/team-ties` | Listar enfrentamientos del grupo (team) |
| GET | `/api/v1/groups/{group}/standings` | Standings del grupo (incluye `meta` de desempates) |
| POST | `/api/v1/groups/{group}/manual-tiebreaks` | Aplicar desempate manual |
| POST | `/api/v1/groups/{group}/player-status` | Marcar participante como retirado o descalificado |
| GET | `/api/v1/competitions/{competition}/standings` | Standings global de competencia |

### Games y sets

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/competitions/{competition}/games` | Crear game manual (no disponible en team) |
| GET | `/api/v1/competitions/{competition}/games` | Listar games de la competencia |
| GET | `/api/v1/games/{game}` | Ver game consolidado |
| POST | `/api/v1/games/{game}/sets` | Registrar set |
| POST | `/api/v1/games/{game}/corrections` | Corregir resultado de partido finalizado (reemplazo completo) |
| DELETE | `/api/v1/games/{game}` | Borrar game |

### Bracket

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/v1/competitions/{competition}/bracket` | Ver bracket de la competencia |
| POST | `/api/v1/competitions/{competition}/bracket` | Crear bracket eliminatorio |
| POST | `/api/v1/brackets/{bracket}/next-round` | Generar siguiente ronda |

### Enfrentamientos por equipos

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/v1/team-tie-formats` | Listar formatos de enfrentamiento |
| GET | `/api/v1/team-ties/{team_tie}` | Ver enfrentamiento con sus partidos internos |
| POST | `/api/v1/team-tie-games/{team_tie_game}/lineup` | Definir alineación de un partido interno (`entry1_player_ids`, `entry2_player_ids`) |

#### Payloads relevantes de fase de grupos

**Desempate manual** — `POST /groups/{group}/manual-tiebreaks`

```json
{
  "entry_ids": [3, 1, 2],
  "reason": "organizer_decision",
  "notes": "Opcional"
}
```

En singles también se acepta `player_ids` en lugar de `entry_ids`.

**Estado de participante en grupo** — `POST /groups/{group}/player-status`

```json
{
  "competition_entry_id": 5,
  "status": "withdrawn",
  "reason": "no_show",
  "notes": "Opcional"
}
```

En singles también se acepta `player_id` en lugar de `competition_entry_id`; en dobles y equipos `competition_entry_id` es obligatorio.

---

## 9. Estados de entidades

| Entidad     | Estados posibles                       |
|-------------|----------------------------------------|
| Tournament  | `draft` → `in_progress` → `finished` (solo vía `/close`)  |
| Competition | Sin estado propio persistido (estado calculado en `status_summary`) |
| Game        | `pending` → `in_progress` → `finished`; `not_needed` solo en partidos internos de un enfrentamiento ya decidido (reversible a `pending`) |
| TeamTie     | `pending` → `in_progress` → `finished` (recalculado desde sus partidos; `ready` y `cancelled` existen pero hoy no se asignan) |
| GroupEntry  | `active` → `withdrawn` / `disqualified` (sin reactivación) |

`Tournament.status` se valida por enum. `finished` solo puede alcanzarse mediante `POST /tournaments/{tournament}/close`. No hay reglas de transición automáticas más allá de aceptar valores válidos del enum en creación/edición parcial (`draft`, `in_progress`).

---

## 10. Lo que queda fuera del MVP (futuro)

- Edición de sets individuales.
- Walkover formal como estado propio de partido.
- Abandono puntual de un solo partido como flujo separado.
- Reactivación de participantes retirados/descalificados.
- Modificación automática de un bracket ya creado por una baja posterior.
- Eliminación o reversión de desempates manuales desde la UI.
- Auditoría avanzada de quién aplicó cambios de estado o desempates.
- Estadísticas avanzadas.
- Reglas ITTF completas (`win_by_two`, saque, etc.).
- Scheduling automático de mesas.
- Multi-tenant / organizaciones.
- Pagos y licencias.

---

## 11. Convenciones generales

Todas las entidades persistidas incluyen:

- `created_at`
- `updated_at`

siguiendo la convención estándar de Laravel/Eloquent.

---

## 12. Decisiones simplificadas del MVP

Para priorizar simplicidad y velocidad de desarrollo:

- Competencias `singles`, `doubles` o `team`.
- Formatos de competencia `groups_knockout` y `knockout_direct`.
- Los games pueden crearse manualmente (excepto en `team`).
- Los sets son append-only.
- No hay edición de sets individuales.
- No hay una configuración de reglas ITTF (`win_by_two` u otras): la diferencia de dos puntos no se valida como regla aislada, sino implícitamente al exigir que el marcador del set represente su cierre exacto (ver regla 10 en §5).
- Los standings de grupo se calculan en runtime con mini tabla y desempate manual persistido.
- No hay scheduling automático de mesas.

---

## 13. Invariantes del dominio

El sistema garantiza que:

- Un CompetitionEntry pertenece a una única Competition y tiene exactamente la cantidad de integrantes que exige la modalidad.
- Un jugador no integra dos CompetitionEntry de una misma competencia.
- Un Game pertenece a una única Competition.
- Un Game enfrenta a dos CompetitionEntry distintas (salvo BYE).
- Las CompetitionEntry de un Game, TeamTie, GroupEntry o clasificación final pertenecen a la misma Competition (FK compuesta).
- `winner_entry_id`, si existe, corresponde a uno de los lados del game.
- Un Game terminado tiene `winner_entry_id` y `finished_at`.
- No hay dos GameSets con el mismo `set_number` en un mismo game.
- Un Group pertenece a una Competition.
- El nombre de Group es único por Competition.
- Una CompetitionEntry no puede pertenecer a dos grupos de la misma Competition.
- Un Bracket pertenece a una Competition y es único por Competition.
- Cada TeamTieGame está asociado a un único Game, y su `slot_order` es único dentro del enfrentamiento.

---

## 14. Fase de grupos

La fase de grupos organiza la competencia antes del cuadro eliminatorio.

Flujo implementado:

1. Crear grupos dentro de una competencia (manualmente o por generación aleatoria, con cabezas de serie opcionales).
2. Asignar participantes inscriptos a cada grupo (`group_entries`).
3. Generar el round robin por grupo (`POST /groups/{group}/round-robin-games`): partidos en singles/dobles, enfrentamientos por equipos en `team`.
4. Cargar resultados por sets hasta finalizar los partidos del grupo.
5. Consultar standings por grupo.
6. Resolver desempates manuales pendientes (si los hubiera).
7. Gestionar bajas de participantes (retiro/descalificación) cuando corresponda.
8. Generar el bracket cuando todos los grupos cumplen las condiciones.

Hasta que se genera el bracket, los grupos admiten cambios tardíos (agregar grupos, asignar, quitar o mover participantes) con sincronización segura del fixture; ver reglas 40 y 41 en §5.

La fase de grupos solo existe en `groups_knockout`; en `knockout_direct` no se pueden crear grupos.

**Auditoría:** la generación aleatoria inicial (`POST .../groups/random-generate`) produce una actividad agregada `groups.generated`. El round robin por grupo produce `groups.round_robin_generated` (una por ejecución). No hay logs individuales por partido creado en esos flujos. Ver [AUDIT.md](./AUDIT.md).

Cada grupo tiene su propia tabla de posiciones. La competencia puede tener varios grupos en paralelo.

---

## 15. Cálculo de standings por grupo

Los standings se calculan en runtime (`GroupStandingsCalculator` en singles/dobles, `TeamGroupStandingsCalculator` en `team`). No se persiste una tabla de posiciones fija. Cada fila corresponde a una inscripción (`CompetitionEntry`).

### Entrada del cálculo

- participantes del grupo y su `status` en `group_entries`;
- partidos del grupo en estado `finished` con `winner_entry_id` definido (en `team`, enfrentamientos finalizados y sus partidos internos oficiales);
- sets de esos partidos;
- desempates manuales persistidos (`group_manual_tiebreaks`).

### Orden automático

1. **Partidos ganados totales** (en `team`: **enfrentamientos ganados**). Solo participantes `active` compiten por posiciones superiores.
2. Si hay empate en victorias totales, se resuelve con una **mini tabla** entre los empatados.

### Mini tabla (empate doble, triple o múltiple)

Cuando dos o más participantes activos comparten la misma cantidad de victorias totales:

1. Se consideran **solo** los partidos finalizados **entre los participantes empatados**.
2. Los partidos contra participantes fuera del grupo empatado **no** afectan la mini tabla.
3. En empate múltiple **no** se usa únicamente el resultado directo entre dos participantes; se evalúa el subconjunto completo.

Criterios de la mini tabla en singles/dobles, en este orden:

1. Partidos ganados entre empatados (`mini_won`).
2. Diferencia de sets entre empatados (`set_diff`).
3. Diferencia de puntos entre empatados (`point_diff`).

Criterios de la mini tabla en `team`, en este orden:

1. Enfrentamientos ganados entre empatados.
2. Diferencia de partidos internos ganados/perdidos entre empatados.
3. Diferencia de sets entre empatados.
4. Diferencia de puntos entre empatados.

Si después de agotar esos criterios persiste el empate, el sistema marca el conjunto de participantes como **pendiente de desempate manual**.

### Participantes inactivos

Participantes `withdrawn` o `disqualified`:

- conservan sus estadísticas (`won`, `lost`) de partidos ya finalizados;
- **no** participan del orden automático por victorias;
- aparecen **al final** de la tabla, ordenados por nombre;
- tienen `eligible_for_qualification = false`.

### Respuesta API (`GET /groups/{group}/standings`)

**Por fila (`data`):**

| Campo | Descripción |
|-------|-------------|
| `competition_entry_id` | ID de la inscripción. |
| `display_name` | Nombre visible del participante (jugador, pareja o equipo). |
| `members` | Integrantes de la inscripción. |
| `player_id` / `player_name` | Jugador y nombre (compatibilidad singles). |
| `played` | Partidos jugados (`won + lost`); en `team`, enfrentamientos. |
| `won` | Partidos ganados; en `team`, enfrentamientos ganados. |
| `lost` | Partidos perdidos; en `team`, enfrentamientos perdidos. |
| `rubbers_won`, `rubbers_lost`, `rubber_difference`, `sets_won`, `sets_lost`, `set_difference`, `points_for`, `points_against`, `point_difference` | Solo se calculan en `team` (partidos internos, sets y puntos); en singles/dobles se devuelven en 0. |
| `requires_manual_tiebreak` | El participante está en un empate sin resolver automáticamente. |
| `manual_tiebreak_applied` | Su posición fue definida por desempate manual. |
| `manual_position` | Posición dentro del empate manual (si aplica). |
| `eligible_for_qualification` | Si puede clasificar al bracket. |
| `group_player_status` | `active`, `withdrawn` o `disqualified`. |

**Meta (`meta`):**

| Campo | Descripción |
|-------|-------------|
| `requires_manual_tiebreak` | Hay al menos un empate pendiente en el grupo. |
| `manual_tiebreak_groups` | Empates pendientes (participantes involucrados). |
| `has_manual_tiebreaks` | Existe al menos un desempate manual aplicado. |
| `manual_tiebreaks` | Desempates aplicados (id, participantes, reason, notes, applied_at). |
| `stale_manual_tiebreaks` | Desempates guardados que ya no coinciden con un empate pendiente actual. |

---

## 16. Desempate manual

Cuando la mini tabla no puede desempatar automáticamente, el organizador puede persistir un orden manual.

### Endpoint

```http
POST /api/v1/groups/{group}/manual-tiebreaks
```

### Payload

```json
{
  "entry_ids": [3, 1, 2],
  "reason": "draw",
  "notes": "Sorteo en mesa 1"
}
```

| Campo | Requerido | Descripción |
|-------|-----------|-------------|
| `entry_ids` | Sí (o `player_ids` en singles) | Orden final del empate, por inscripción. Debe incluir exactamente a los participantes del empate pendiente (mismo conjunto, cualquier orden). En singles se acepta `player_ids` como alternativa; en dobles y equipos solo `entry_ids`. |
| `reason` | Sí | `draw`, `organizer_decision`, `agreement`, `other`. |
| `notes` | No | Texto libre (máx. según validación). |

### Reglas

- Solo se acepta si hay un empate pendiente actual en el grupo.
- El conjunto de participantes enviado debe coincidir **exactamente** con uno de los grupos en `meta.manual_tiebreak_groups` (sin importar el orden al comparar).
- Si ya existía un desempate para el mismo conjunto de participantes, se **actualiza** (reason, notes, orden y `applied_at`).
- No se puede aplicar si la competencia ya tiene bracket.
- Si los resultados cambian y el desempate guardado ya no resuelve un empate pendiente actual, pasa a `meta.stale_manual_tiebreaks` hasta que se vuelva a aplicar uno válido o desaparezca el empate. Mientras un grupo tenga desempates stale, la generación del bracket queda bloqueada.
- Resolver un desempate que afectaba el corte de clasificación **desbloquea** la generación del bracket (si el resto de condiciones se cumple).

### UI

`GroupStandingsView` muestra empates pendientes, desempates stale y permite aplicar el desempate manual mediante paneles en la misma vista. No hay eliminación de desempates desde la UI.

---

## 17. Retiro y descalificación de participantes en grupo

El estado del participante dentro del grupo vive en `group_entries`, no en el jugador global ni en la inscripción.

### Endpoint

```http
POST /api/v1/groups/{group}/player-status
```

### Payload

```json
{
  "competition_entry_id": 5,
  "status": "withdrawn",
  "reason": "no_show",
  "notes": "No se presentó el sábado"
}
```

| Campo | Requerido | Valores |
|-------|-----------|---------|
| `competition_entry_id` | Sí (o `player_id` en singles) | Inscripción del participante en el grupo. En singles se acepta `player_id` como alternativa. |
| `status` | Sí | `withdrawn` o `disqualified`. |
| `reason` | No | `personal`, `injury`, `no_show`, `organizer_decision`, `other`. |
| `notes` | No | Texto libre. |

### Reglas al marcar baja

- Solo se permite pasar de `active` a `withdrawn` o `disqualified`.
- **No hay reactivación** en el MVP.
- **No se permite** si ya existe bracket.
- En `team`, **no se permite** si el grupo ya tiene enfrentamientos generados.
- No se puede aplicar a un participante que ya no está activo.
- Los partidos **ya finalizados** del participante **no se modifican**.
- Los partidos `pending` o `in_progress` del participante en ese grupo se cierran **a favor del rival**:
  - `status = finished`
  - `winner_entry_id = rival`
  - `finished_at = now()`
  - **sin registrar sets**
- El participante permanece visible en standings, al final, con `eligible_for_qualification = false`.
- El bracket lo excluye al tomar clasificados.

### Lo que esto no es

- **No** existe walkover formal como estado propio de partido.
- **No** existe abandono puntual de un solo partido como flujo separado.
- Cerrar partidos pendientes por baja **no** es lo mismo que un walkover configurable partido a partido.

### UI

`GroupDetailView` permite marcar participantes activos como retirados o descalificados mediante un modal de confirmación. En la UI actual la acción solo se ofrece mientras el grupo no tenga partidos/enfrentamientos generados y no exista bracket (el backend, en singles/dobles, también la acepta con partidos generados).

---

## 18. Clasificación al bracket

`CreateBracketKnockoutAction` usa el mismo cálculo de standings que la API (singles/dobles o `team`) para determinar **quién clasifica** y en **qué orden dentro de cada grupo**. A partir de ahí, el emparejamiento eliminatorio en competencias `groups_knockout` es **consciente de la procedencia grupal**.

> **Regla reemplazada:** ya no se combinan todos los clasificados ni se ordenan globalmente por `(won desc, lost asc, nombre)` para generar la llave. Ese criterio global puede seguir existiendo en otros contextos (ej. standings globales de competencia), pero **no gobierna el draw eliminatorio** en `groups_knockout`.

### Reglas de clasificación

- Por cada grupo se toman hasta `Competition.qualified_per_group` participantes **elegibles** (`eligible_for_qualification = true`), en el orden calculado por standings del grupo.
- Participantes retirados o descalificados **no clasifican**, aunque figuren en posiciones altas antes de moverse al final de la tabla.
- Si hay **menos elegibles** que `qualified_per_group`, el bracket se genera con los disponibles; play-in y BYEs se recalculan según las posiciones grupalmente ocupadas.
- El tamaño de llave (`bracket_size`) es la **siguiente potencia de 2 ≥ total de clasificados** (máximo 64).
- Todos los partidos de **todos** los grupos deben estar finalizados.
- Si un grupo tiene empate manual pendiente que **cruza el corte de clasificación**, o desempates manuales stale, se bloquea la generación del bracket con error explícito.

### Metadata de origen grupal

Al generar la llave, el origen de cada clasificado se persiste en `BracketEntryOrigin`:

| Campo | Descripción |
|-------|-------------|
| `group_id` / `group_name` | Grupo de procedencia (con snapshot del nombre). |
| `group_position` | Posición en el grupo (1°, 2°, 3°, …) según standings elegibles. |
| `competition_entry_id` | Participante clasificado. Único por bracket. |

Esta metadata guía el emparejamiento eliminatorio y se muestra en la llave. **No** implica reordenar clasificados entre grupos por récord global.

### Draw eliminatorio (`groups_knockout`)

#### Cuando `qualified_per_group = 2`

- Si la cantidad total de clasificados es una **potencia de 2**, se genera una llave directa sin play-in.
- Los **1° de grupo** se cruzan contra **2° de otros grupos**.
- **Restricción dura:** en la primera ronda eliminatoria no se enfrentan dos participantes del **mismo grupo**.
- Se intenta **separar** en el árbol, en la medida posible, a participantes provenientes del mismo grupo en rondas posteriores.

Ejemplo típico (2 grupos, 2 clasificados por grupo → 4 participantes):

```text
Semifinal 1: A1 vs B2
Semifinal 2: B1 vs A2
```

#### Cuando `qualified_per_group = 3`

- Los **1° de cada grupo** tienen **prioridad de BYE** cuando el total de clasificados no completa una potencia de 2, o cuando el diseño del cuadro lo requiere.
- Los **2° y 3°** disputan una **ronda previa (play-in)** antes de integrarse plenamente al cuadro principal.
- Cada partido de play-in es **2° vs 3° de grupos distintos** (nunca del mismo grupo).
- **Restricción dura:** en play-in y en la primera ronda “real” posterior no se enfrentan dos participantes del **mismo grupo**.
- **Restricción blanda:** separar lo más posible a participantes del mismo grupo dentro del árbol eliminatorio.
- Con **3 a 9 grupos**, el cuadro se arma con **plantillas oficiales Q3** predefinidas por cantidad de grupos; fuera de ese rango se usa el armado algorítmico descrito abajo.

Flujo general:

```text
1. Extraer clasificados por grupo (1°, 2°, 3°).
2. Calcular bracket_size = siguiente potencia de 2 ≥ total clasificados.
3. Asignar BYEs a 1° de grupo (byes_count = cantidad de grupos con 1° clasificado, cuando aplica).
4. Generar partidos play-in: emparejar 2° con 3° de otro grupo.
5. Integrar ganadores de play-in con 1° (BYE o esperando rival) en la ronda principal del cuadro.
6. Avanzar rondas con winner_entry_id hasta la final.
```

Los partidos BYE usan `is_bye = true`. Los partidos de play-in son partidos reales (`is_bye = false`) con carga de sets.

### Ejemplos

#### 4 grupos × 3 clasificados

| Concepto | Valor |
|----------|-------|
| Clasificados | 12 |
| `bracket_size` | 16 |
| `byes_count` | 4 (uno por cada 1° de grupo) |
| Play-in | 4 partidos: 2° vs 3° de grupos distintos |
| Ronda principal | 8 participantes (4 ganadores play-in + 4 primeros con BYE) → equivalente a 8vos de final |

Esquema:

```text
Play-in (4 partidos):
  A2 vs B3, B2 vs C3, C2 vs D3, D2 vs A3   (permutación válida inter-grupo)

BYEs:
  A1, B1, C1, D1

Ronda principal (8 participantes):
  ganadores play-in + 1° de grupo → cuadro de 8
```

#### 8 grupos × 3 clasificados

| Concepto | Valor |
|----------|-------|
| Clasificados | 24 |
| `bracket_size` | 32 |
| `byes_count` | 8 (uno por cada 1° de grupo) |
| Play-in | 8 partidos: 2° vs 3° de grupos distintos |
| Ronda principal | 16 participantes → 16avos de final |

Esquema:

```text
Play-in (8 partidos):
  emparejamientos 2° vs 3° cruzados entre los 8 grupos (sin mismo grupo)

BYEs:
  un BYE por cada 1° de grupo (8 BYEs)

Ronda principal (16 participantes):
  8 ganadores play-in + 8 primeros con BYE → cuadro de 16
```

### Bloqueos con bracket existente

Si ya existe bracket:

- no se puede cambiar el estado de participantes de grupo;
- no se puede aplicar desempate manual;
- no se pueden crear grupos ni asignar, quitar o mover participantes;
- no se pueden modificar las inscripciones;
- no se recalcula ni modifica automáticamente la llave por bajas posteriores.

### Eliminación directa (`knockout_direct`)

Competencias sin fase de grupos **no** usan draw grupal ni admiten grupos. El emparejamiento inicial sigue el **orden de inscripción** y el esquema estándar 1 vs N dentro del `bracket_size` calculado. Se requieren al menos 2 inscriptos.

### Llave en competencias por equipos

En `team` cada cruce de la llave es un `TeamTie` (con sus partidos internos según el formato) en lugar de un `Game`. Se aplican las mismas reglas de clasificación, draw, BYE, avance de ronda y tercer puesto.

---

## 19. Frontend / UX

Vistas principales relacionadas con la fase de grupos:

### `GroupStandingsView`

- Muestra la tabla de posiciones del grupo.
- Indica la situación de clasificación de cada participante (`Clasifica`, `Eliminado`, `No clasifica` si no es elegible, `Desempate` si hay empate pendiente, y estados provisorios mientras el grupo no terminó).
- Muestra badges de participantes retirados/descalificados.
- Muestra aviso y paneles cuando hay empates manuales pendientes.
- Permite resolver desempates manuales (solo lectura + formulario en esta vista).
- Muestra aviso si hay desempates stale.
- Deshabilita desempate manual si ya existe bracket.

### `GroupDetailView`

- Lista participantes del grupo, standings y partidos (o enfrentamientos en `team`) del grupo.
- Permite asignar participantes y generar el round robin.
- Mientras no haya bracket, permite quitar o mover participantes entre grupos.
- Permite cargar resultados de partidos (en `team`, cada enfrentamiento enlaza a su detalle, donde se definen alineaciones y se cargan los partidos internos).
- Permite marcar participantes activos como retirados/descalificados (modal), solo mientras el grupo no tenga partidos/enfrentamientos generados ni exista bracket.
- Muestra badges de estado y elegibilidad de clasificación.
- Enlaza a la impresión de la planilla del grupo.

### `CompetitionDetailView`

Panel de control de la fase de grupos. Por cada grupo muestra:

- empate manual pendiente;
- desempate manual aplicado;
- desempates stale;
- partidos o enfrentamientos pendientes;
- participantes con baja;
- grupo listo;
- una acción principal según el estado del grupo (**Resolver empate**, **Ver partidos**/**Ver enfrentamientos**, **Ver posiciones** en un modal rápido, o **Abrir grupo**) y acceso directo al grupo.

Antes de generar la llave, la vista muestra una estimación de clasificados (`cantidad de grupos × qualified_per_group`), tamaño de llave y pases directos; no filtra por elegibilidad. La elegibilidad real se aplica al generar el bracket.

**Nota:** `status_summary` a nivel competencia es orientativo. Indica `group_stage_attention_required` cuando un desempate pendiente afecta la clasificación o hay desempates stale; los empates que no afectan el corte solo se ven en el panel por grupo, que complementa esa información.

---

## 20. Corrección excepcional de resultados finalizados

Operación administrativa para corregir errores reales de carga **sin** modificar la carga normal append-only de sets.

### Endpoint

```http
POST /api/v1/games/{game}/corrections
```

Permiso: `matches.correct_result` (solo rol `admin`).

### Payload

```json
{
  "reason": "El árbitro informó que el segundo set fue cargado incorrectamente.",
  "sets": [
    { "player1_score": 11, "player2_score": 8 },
    { "player1_score": 9, "player2_score": 11 },
    { "player1_score": 11, "player2_score": 7 }
  ]
}
```

El cliente **no** envía `set_number`; el servidor asigna numeración correlativa `1..n` según el orden del array.

### Reglas

- Solo partidos **finalizados**, **no BYE**, con al menos un set cargado.
- Reemplazo **completo** del resultado: se eliminan los sets anteriores y se crean los nuevos.
- Motivo obligatorio (`min: 10`, `max: 500`).
- Mismas validaciones de marcador por set que la carga normal.
- No se permiten sets posteriores al set decisivo.
- El partido corregido debe quedar `finished` con un ganador válido.

### Restricciones por fase

| Contexto | Restricción |
|----------|-------------|
| Partido de grupo | Bloqueado si la competencia ya tiene bracket generado |
| Partido de llave | Si existe una ronda posterior, solo se permite propagar a la **ronda inmediata** cuando el partido destino está `pending`, sin sets, sin ganador y contiene al participante anterior en el slot esperado. Bloqueado si la llave avanzó más de una ronda o el destino ya comenzó. En semifinales con `third_place_mode = playoff`, una corrección puede propagar atómicamente el **ganador** a la Final y el **perdedor** al partido por tercer puesto, con las mismas reglas de seguridad en **cada** destino. Si la Final o el partido por tercer puesto (o ambos) ya comenzaron, tienen sets o ganador, la corrección se rechaza por completo. |
| Competencia | Bloqueada si ya existe una final terminada con ganador |

### Impacto

- **Grupos:** standings se recalculan automáticamente; desempates manuales previos pueden quedar **stale**; no se eliminan automáticamente.
- **Llave:** si la ronda inmediata está generada y pendiente, el ganador corregido puede propagarse automáticamente al slot correspondiente (`entry1_id` o `entry2_id`). En playoff, el perdedor corregido puede propagarse al partido por tercer puesto (SF1 → `entry1_id`, SF2 → `entry2_id`). La operación es **atómica**: o se actualizan source y todos los destinos seguros, o no cambia nada. No hay cascada multi-ronda ni reapertura de partidos ya jugados (reapertura administrativa fuera de alcance).
- **Equipos:** corregir un partido interno recalcula el resultado del enfrentamiento (partidos `not_needed` pueden reabrirse). Si el enfrentamiento está en la llave y cambia su ganador, la corrección se propaga a la ronda inmediata con las mismas restricciones de seguridad (no BYE, enfrentamiento finalizado, sin final terminada, sin avance de más de una ronda).
- **Auditoría:** una actividad `game.result_corrected` por operación exitosa.

### BYEs

Los partidos `is_bye = true` **no** son corregibles manualmente.

---

## 21. Fuera de alcance actual (no implementado)

Explicitamente **no** está implementado hoy:

- walkover formal como estado propio de partido;
- abandono puntual de un solo partido;
- reactivación de participante retirado/descalificado;
- modificación automática de bracket ya creado por baja posterior;
- eliminación/reversión de desempates manuales desde UI;
- auditoría avanzada de quién aplicó cambios de estado o desempates;
- endpoint para listar o borrar desempates manuales;
- notificaciones automáticas al organizador por empates pendientes (solo UI al consultar).

---

## 22. Filosofía del dominio

El dominio prioriza:

- claridad de reglas,
- simplicidad operativa,
- modelado explícito,
- evolución incremental.

Se evita incorporar complejidad anticipada que todavía no aporta valor al MVP.
