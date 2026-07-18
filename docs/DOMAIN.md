# Sistema de Torneos de Tenis de Mesa — Dominio MVP

## 1. Objetivo del sistema

Construir un MVP para gestionar torneos de tenis de mesa en ámbitos pequeños, domésticos o de club.

El sistema permite:

- Crear torneos.
- Crear competencias dentro de un torneo (ej: "Singles Primera").
- Registrar jugadores.
- Inscribir jugadores a competencias.
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
- Gestión de competencias.
- Registro e inscripción de jugadores.
- Gestión de grupos y asignación de jugadores.
- Generación de partidos round robin por grupo.
- Standings por grupo con mini tabla automática y desempate manual cuando corresponde.
- Standings global de competencia.
- Retiro y descalificación de jugadores dentro de un grupo.
- Creación de bracket eliminatorio.
- Generación de siguientes rondas del bracket.
- Creación manual de partidos.
- Carga de resultados por sets.
- Determinación automática del ganador.

Queda **fuera del MVP**:

- Dobles.
- Ranking y estadísticas avanzadas.
- Walkover formal como estado propio de partido.
- Abandono puntual de un solo partido como flujo separado.
- Reactivación de jugadores retirados/descalificados.
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

#### Cierre administrativo del torneo

`Tournament.status = finished` representa un **cierre administrativo explícito**, no la finalización deportiva automática de las competencias.

- El único camino válido para finalizar es `POST /api/v1/tournaments/{tournament}/close`.
- El PATCH genérico **no** puede establecer `finished`.
- Tras el cierre se bloquean mutaciones deportivas/estructurales; los datos descriptivos del torneo siguen editables.
- No hay reapertura en el MVP.

Requisitos de cierre:

- al menos una competencia;
- toda competencia **utilizada** (con inscripciones o partidos) debe estar deportivamente `completed` con campeón resoluble;
- no deben quedar partidos `pending` o `in_progress`;
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
| type            | enum     | `singles` (en MVP solo singles).                    |
| category        | string   | División legacy (ej: primera). Se mantiene por compatibilidad. |
| category_id     | bigint   | FK nullable a Category. Preferido en formularios nuevos.       |
| format          | enum     | `manual` (en MVP solo manual).                      |
| sets_to_win              | int      | **Legacy (columna DB).** No se expone ni configura por API. Se rellena al crear desde `group_stage_best_of` solo por compatibilidad de esquema. |
| points_per_set           | int      | Puntos necesarios para ganar un set (ej: 11).       |
| qualified_per_group      | int      | Cuántos jugadores clasifican desde cada grupo hacia la fase eliminatoria (default: 2). |
| group_stage_best_of      | int      | Mejor de N en fase de grupos (default: 5).          |
| knockout_stage_best_of   | int      | Mejor de N en rondas eliminatorias tempranas (default: 5). |
| semifinal_best_of        | int      | Mejor de N en semifinal (default: 7).               |
| final_best_of            | int      | Mejor de N en final (default: 7).                     |

`qualified_per_group` define cuántos jugadores de cada grupo avanzan al cuadro eliminatorio. El valor se configura al crear o editar la competencia (no puede modificarse una vez generado el bracket).

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

`sets_to_win` a nivel competencia es **legacy**: la columna permanece en base de datos (NOT NULL) pero no forma parte de la API ni del formulario. Al crear una competencia, el sistema la rellena internamente como `intdiv(group_stage_best_of, 2) + 1`. La regla efectiva del partido vive en el snapshot de `Game` (`best_of`, `sets_to_win`). Solo `RecordGameSetAction` consulta `Competition.sets_to_win` como fallback para partidos legacy sin snapshot.

No se puede cambiar el formato por fase si la competencia ya tiene partidos generados.

Los jugadores **no** se inscriben al torneo en general, sino a cada competencia concreta.

#### Estado calculado de competencia

La API expone `status_summary` en `CompetitionResource`. Es un **estado calculado** (no persistido en BD) que orienta la UI sobre el avance de la competencia y la próxima acción sugerida.

Se calcula en `CompetitionStatusResolver` a partir de:

- existencia de grupos
- partidos de grupo (`group_id` not null, `bracket_id` null)
- existencia de bracket
- partidos eliminatorios y final (`round = 'Final'`)

**No reemplaza** las validaciones de las Actions (generar bracket, cargar sets, etc.). Solo informa.

**Limitación:** no conoce empates manuales pendientes ni desempates desactualizados por grupo. Una competencia puede aparecer como `ready_for_bracket` aunque un grupo todavía requiera desempate manual. Para ese detalle, consultar standings por grupo o el panel de fase de grupos en la UI.

Códigos disponibles:

| code | Significado |
|------|-------------|
| `no_groups` | Sin grupos configurados |
| `group_stage_pending` | Hay grupos pero no partidos de grupo |
| `group_stage_in_progress` | Partidos de grupo pendientes o en curso |
| `ready_for_bracket` | Grupos finalizados, sin llave |
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

Se calcula en `CompetitionResultResolver` desde la final terminada del bracket:

- `round = 'Final'`
- `status = finished`
- `winner_id` definido

El campeón es el `winner_id` de la final.
El subcampeón es el otro jugador de la final.

Si la competencia no está finalizada, falta final, falta ganador o falta rival, `result_summary` devuelve `null`.

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

### Registration

Representa la inscripción de un jugador a una competencia.

| Campo          | Tipo   | Descripción                    |
|----------------|--------|--------------------------------|
| id             | bigint | Identificador único.           |
| competition_id | bigint | FK a Competition.              |
| player_id      | bigint | FK a Player.                   |

**Regla:** un jugador no puede inscribirse dos veces en la misma competencia.
Restricción única: `(competition_id, player_id)`.

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

### GroupPlayer

Representa la asignación de un jugador a un grupo.

| Campo               | Tipo       | Descripción                                              |
|---------------------|------------|----------------------------------------------------------|
| id                  | bigint     | Identificador único.                                     |
| group_id            | bigint     | FK a Group.                                              |
| player_id           | bigint     | FK a Player.                                             |
| status              | enum       | `active`, `withdrawn`, `disqualified` (default: `active`). |
| status_reason       | enum       | Nullable. Motivo de la baja (ver abajo).                 |
| status_notes        | text       | Nullable. Notas libres sobre la baja.                  |
| status_changed_at   | timestamp  | Nullable. Momento del último cambio de estado.           |

Restricción única: `(group_id, player_id)`.

Reglas adicionales implementadas:

- un jugador no puede estar en más de un grupo dentro de la misma competencia;
- solo se puede pasar de `active` a `withdrawn` o `disqualified` (no hay reactivación en el MVP);
- no se puede cambiar el estado si la competencia ya tiene bracket.

**Auditoría:** la asignación manual vía API (`POST .../groups/{id}/players`) produce `group.player_assigned` con el estado inicial (`active`). Ver [AUDIT.md](./AUDIT.md).

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
| `agreement`           | Acuerdo entre jugadores.       |
| `other`               | Otro.                          |

El orden de los jugadores empatados se guarda en `GroupManualTiebreakPlayer`.

---

### GroupManualTiebreakPlayer

Representa la posición de un jugador dentro de un desempate manual.

| Campo                    | Tipo   | Descripción                          |
|--------------------------|--------|--------------------------------------|
| id                       | bigint | Identificador único.                 |
| group_manual_tiebreak_id | bigint | FK a GroupManualTiebreak.            |
| player_id                | bigint | FK a Player.                         |
| position                 | int    | Posición dentro del empate (1-based). |

Restricciones únicas por tiebreak: `(group_manual_tiebreak_id, player_id)` y `(group_manual_tiebreak_id, position)`.

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
ronda principal posterior = 8vos de final (8 jugadores)
```

---

### Game

Representa un partido entre dos jugadores dentro de una competencia.

| Campo          | Tipo       | Descripción                                        |
|----------------|------------|----------------------------------------------------|
| id             | bigint     | Identificador único.                               |
| competition_id | bigint     | FK a Competition.                                  |
| group_id       | bigint     | FK a Group (nullable).                             |
| bracket_id     | bigint     | FK a Bracket (nullable).                           |
| player1_id     | bigint     | FK a Player.                                       |
| player2_id     | bigint     | FK a Player (nullable en partidos BYE).            |
| winner_id      | bigint     | FK a Player (nullable, se completa al terminar).   |
| status         | enum       | `pending`, `in_progress`, `finished`.              |
| is_bye         | boolean    | `true` si el partido es avance automático (BYE).   |
| best_of        | int        | Snapshot: mejor de N (nullable en BYE).            |
| sets_to_win    | int        | Snapshot: sets necesarios para ganar (nullable en BYE). |
| finished_at    | timestamp  | Momento de cierre (nullable).                      |
| round          | string     | Ronda descriptiva (ej: "Semifinal", "Final").      |
| bracket_round  | int        | Número de ronda en bracket (nullable).             |
| bracket_match  | int        | Número de partido dentro de la ronda (nullable).   |
| table_number   | int        | Número de mesa (nullable, sin scheduling automático). |

Un partido puede pertenecer al flujo manual, a grupos o a bracket.

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
| player1_score   | int    | Puntos del jugador 1 en este set. |
| player2_score   | int    | Puntos del jugador 2 en este set. |

Restricción única: `(game_id, set_number)`.

El ganador de cada set se deriva dinámicamente de `player1_score` vs `player2_score`.

---

## 4. Relaciones

```
Tournament
  └── hasMany Competition

Competition
  ├── belongsTo Tournament
  ├── belongsTo Category (nullable, category_id)
  ├── hasMany Registration
  ├── hasMany Group
  ├── hasMany Bracket
  └── hasMany Game

Player
  ├── belongsTo Category (nullable)
  ├── belongsTo Club (nullable)
  ├── hasMany Registration
  ├── hasMany GroupPlayer
  ├── hasMany Game (player1)
  ├── hasMany Game (player2)
  └── hasMany Game (winner)

Registration
  ├── belongsTo Competition
  └── belongsTo Player

Group
  ├── belongsTo Competition
  ├── hasMany GroupPlayer
  ├── hasMany GroupManualTiebreak
  └── hasMany Game

GroupPlayer
  ├── belongsTo Group
  └── belongsTo Player

GroupManualTiebreak
  ├── belongsTo Group
  └── hasMany GroupManualTiebreakPlayer

GroupManualTiebreakPlayer
  ├── belongsTo GroupManualTiebreak
  └── belongsTo Player

Bracket
  ├── belongsTo Competition
  └── hasMany Game

Game
  ├── belongsTo Competition
  ├── belongsTo Group (nullable)
  ├── belongsTo Bracket (nullable)
  ├── belongsTo Player (player1)
  ├── belongsTo Player (player2)
  ├── belongsTo Player (winner)
  └── hasMany GameSet

GameSet
  └── belongsTo Game

Category
  ├── hasMany Player
  └── hasMany Competition

Club
  └── hasMany Player
```

---

## 5. Reglas de negocio

1. Un jugador debe existir antes de inscribirse a una competencia.
2. Un jugador no puede inscribirse dos veces en la misma competencia.
3. Un partido pertenece a una competencia y tiene exactamente dos jugadores distintos.
4. Los jugadores de un Game deben estar previamente inscriptos en la Competition.
5. El ganador del partido se calcula a partir de los GameSets cargados.
6. Un jugador gana el partido cuando acumula el `sets_to_win` del **Game** (snapshot). Si el partido no tiene snapshot (datos legacy), se usa `Competition.sets_to_win`.
7. No se pueden registrar sets en un partido ya finalizado.
7b. Un partido finalizado puede corregirse excepcionalmente mediante reemplazo completo del resultado (`POST /games/{game}/corrections`), solo con permiso `matches.correct_result`, motivo obligatorio y restricciones de grupo/llave (ver §22).
8. No se puede registrar un set empatado.
9. El ganador del set debe alcanzar al menos `points_per_set`.
10. El marcador del set debe representar un **resultado final válido** (no basta con diferencia mínima de puntos):
    - si el ganador llega exactamente a `points_per_set`, el perdedor debe tener como máximo `points_per_set - 2`;
    - si el ganador supera `points_per_set` (deuce), la diferencia debe ser exactamente 2.
11. No puede repetirse `set_number` dentro del mismo game.
12. Un grupo necesita al menos 2 jugadores para generar round robin.
13. No se regenera round robin en un grupo que ya tiene partidos.
14. Un jugador no puede estar en dos grupos de la misma competencia.
15. Solo puede existir un bracket por competencia.
16. Para crear bracket, todos los partidos de grupos deben estar finalizados.
17. El bracket usa `Competition.qualified_per_group` para determinar cuántos jugadores clasifican de cada grupo, considerando solo jugadores elegibles según standings.
18. El total de clasificados ya no necesita ser exactamente 2, 4 u 8. Si no es potencia de 2, el sistema calcula la siguiente potencia de 2 ≥ total de clasificados y completa la llave con BYEs (hasta 64 clasificados).
19. En competencias `groups_knockout`, los BYEs se asignan según **procedencia grupal**, no por un ranking global de victorias. Con `qualified_per_group = 3`, los **primeros de cada grupo** tienen prioridad de BYE. Con `qualified_per_group = 2`, los BYEs restantes (si los hubiera) se resuelven dentro del algoritmo de emparejamiento grupal, no por `(won, lost, nombre)`.
20. `Game.is_bye` indica avance automático: `player2_id = null`, partido finalizado sin sets, `winner_id = player1_id`. Los BYEs a primeros de grupo quedan registrados como partidos `is_bye = true`.
21. No se puede cambiar `qualified_per_group` si la competencia ya tiene un bracket generado.
22. La siguiente ronda del bracket se genera con `winner_id` de la ronda actual (incluye ganadores de partidos BYE).
23. No puede generarse siguiente ronda si la actual está incompleta.
24. No puede generarse una ronda ya creada ni avanzar cuando el bracket ya terminó.
25. No se puede cambiar el formato por fase (`*_best_of`) si la competencia ya tiene partidos generados.
26. Los standings de grupo se calculan en runtime (no se persisten como tabla fija).
27. Solo jugadores `active` en `group_players` participan del orden automático por victorias y de la elegibilidad para clasificar.
28. Jugadores `withdrawn` o `disqualified` permanecen visibles en standings, al final de la tabla, y no son elegibles para clasificación.
29. Si un jugador pasa a `withdrawn` o `disqualified`, los partidos del grupo ya finalizados no se modifican; los partidos `pending` o `in_progress` del jugador se cierran a favor del rival sin registrar sets.
30. No se puede cambiar el estado de un jugador de grupo si ya existe bracket.
31. No se puede definir desempate manual si ya existe bracket.
32. Un desempate manual solo se acepta si el conjunto de `player_ids` coincide exactamente con un empate pendiente actual del grupo.
33. Si cambian los resultados y un desempate manual guardado ya no coincide con ningún empate pendiente, queda como **stale** (desactualizado).
34. El bracket usa el mismo cálculo de standings por grupo que la API/UI, incluyendo desempates manuales aplicados.
35. Solo jugadores con `eligible_for_qualification = true` pueden clasificar al bracket.
36. Si un empate manual pendiente afecta el corte de clasificación del grupo, se bloquea la generación del bracket hasta resolverlo.
37. Si hay menos clasificados elegibles que `qualified_per_group`, el bracket se genera con los disponibles; los BYEs y el play-in se ajustan a la cantidad real de clasificados por posición grupal.
38. En competencias `groups_knockout`, la llave eliminatoria se genera de forma **consciente de la procedencia grupal**. Queda **reemplazada** la regla anterior de combinar todos los clasificados y ordenarlos globalmente por `(won desc, lost asc, nombre)` antes de armar la llave.
39. En competencias `knockout_direct` (sin fase de grupos), el emparejamiento inicial sigue el **orden de inscripción** y el esquema estándar 1 vs N dentro del `bracket_size` calculado; no aplica el draw grupal.

---

## 6. Lógica de cálculo del ganador del game

Al registrar un set (`POST /games/{game}/sets`), el sistema:

1. Valida `set_number` y scores (rechaza `set_number` mayor a `Game.best_of` cuando está definido).
2. Rechaza sets en partidos BYE o ya finalizados.
3. Rechaza set empatado.
4. Rechaza score ganador por debajo de `points_per_set` (desde `Competition`).
5. Rechaza marcadores que no representen un cierre válido del set (ver regla 10).
6. Rechaza duplicado de `set_number` en el mismo game.
7. Persiste el set (append-only).
8. Recalcula sets ganados por cada jugador.
9. Usa `Game.sets_to_win` (fallback: `Competition.sets_to_win` legacy).
10. Si un jugador alcanza ese umbral:
   - setea `winner_id`,
   - setea `status = finished`,
   - setea `finished_at = now()`.
11. Si nadie lo alcanza:
   - mantiene `winner_id = null`,
   - setea `status = in_progress`,
   - mantiene `finished_at = null`.

---

## 7. Flujo completo del sistema

```
1. Crear Tournament
2. Crear Competition (vinculada al Tournament)
3. Registrar Players
4. Inscribir Players a la Competition
5. Crear Groups en la Competition
6. Asignar Players a cada Group
7. Generar Round Robin de cada Group
8. Cargar sets hasta finalizar los games de grupos
9. Consultar standings de grupos / competencia
10. Resolver desempates manuales pendientes (si los hubiera)
11. Marcar retiros/descalificaciones de jugadores (si corresponde)
12. Crear Bracket eliminatorio (usa `Competition.qualified_per_group`; guarda snapshot en `Bracket.qualifiers_per_group`)
13. Cargar sets de games del bracket
14. Generar siguiente ronda del bracket
15. Repetir hasta la final
```

También se pueden crear games manuales directamente en la competencia.

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
| POST | `/api/v1/competitions/{competition}/registrations` | Inscribir jugador |
| POST | `/api/v1/competitions/{competition}/registrations/bulk` | Inscripción masiva (`player_ids`) |
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
| POST | `/api/v1/groups/{group}/players` | Asignar jugador a grupo |
| GET | `/api/v1/groups/{group}/players` | Listar jugadores del grupo |
| POST | `/api/v1/groups/{group}/round-robin-games` | Generar round robin |
| GET | `/api/v1/groups/{group}/standings` | Standings del grupo (incluye `meta` de desempates) |
| POST | `/api/v1/groups/{group}/manual-tiebreaks` | Aplicar desempate manual |
| POST | `/api/v1/groups/{group}/player-status` | Marcar jugador como retirado o descalificado |
| GET | `/api/v1/competitions/{competition}/standings` | Standings global de competencia |

### Games y sets

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/competitions/{competition}/games` | Crear game manual |
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

#### Payloads relevantes de fase de grupos

**Desempate manual** — `POST /groups/{group}/manual-tiebreaks`

```json
{
  "player_ids": [3, 1, 2],
  "reason": "organizer_decision",
  "notes": "Opcional"
}
```

**Estado de jugador en grupo** — `POST /groups/{group}/player-status`

```json
{
  "player_id": 5,
  "status": "withdrawn",
  "reason": "no_show",
  "notes": "Opcional"
}
```

---

## 9. Estados de entidades

| Entidad     | Estados posibles                       |
|-------------|----------------------------------------|
| Tournament  | `draft` → `in_progress` → `finished` (solo vía `/close`)  |
| Competition | Sin estado propio en el MVP            |
| Game        | `pending` → `in_progress` → `finished`|

`Tournament.status` se valida por enum. `finished` solo puede alcanzarse mediante `POST /tournaments/{tournament}/close`. No hay reglas de transición automáticas más allá de aceptar valores válidos del enum en creación/edición parcial (`draft`, `in_progress`).

---

## 10. Lo que queda fuera del MVP (futuro)

- Dobles.
- Edición de sets individuales.
- Walkover formal como estado propio de partido.
- Abandono puntual de un solo partido como flujo separado.
- Reactivación de jugadores retirados/descalificados.
- Modificación automática de un bracket ya creado por una baja posterior.
- Eliminación o reversión de desempates manuales desde la UI.
- Auditoría avanzada de quién aplicó cambios de estado o desempates.
- Ranking de jugadores.
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

- Competencias exclusivamente `singles`.
- Formato de competencia `manual`.
- Los games pueden crearse manualmente.
- Los sets son append-only.
- No hay edición de sets individuales.
- No se valida diferencia mínima de dos puntos.
- Los standings de grupo se calculan en runtime con mini tabla y desempate manual persistido.
- No hay scheduling automático de mesas.

---

## 13. Invariantes del dominio

El sistema garantiza que:

- Un Registration vincula exactamente un Player con una Competition.
- No hay dos registrations del mismo jugador en una misma competencia.
- Un Game pertenece a una única Competition.
- Un Game tiene dos jugadores distintos.
- Ambos jugadores del Game deben estar inscriptos en la Competition.
- `winner_id`, si existe, corresponde a uno de los jugadores del game.
- Un Game terminado tiene `winner_id` y `finished_at`.
- No hay dos GameSets con el mismo `set_number` en un mismo game.
- Un Group pertenece a una Competition.
- El nombre de Group es único por Competition.
- Un jugador no puede pertenecer a dos grupos de la misma Competition.
- Un Bracket pertenece a una Competition y es único por Competition.

---

## 14. Fase de grupos

La fase de grupos organiza la competencia antes del cuadro eliminatorio.

Flujo implementado:

1. Crear grupos dentro de una competencia.
2. Asignar jugadores inscriptos a cada grupo (`group_players`).
3. Generar partidos round robin por grupo (`POST /groups/{group}/round-robin-games`).
4. Cargar resultados por sets hasta finalizar los partidos del grupo.
5. Consultar standings por grupo.
6. Resolver desempates manuales pendientes (si los hubiera).
7. Gestionar bajas de jugadores (retiro/descalificación) cuando corresponda.
8. Generar el bracket cuando todos los grupos cumplen las condiciones.

**Auditoría:** la generación aleatoria inicial (`POST .../groups/random-generate`) produce una actividad agregada `groups.generated`. El round robin por grupo produce `groups.round_robin_generated` (una por ejecución). No hay logs individuales por partido creado en esos flujos. Ver [AUDIT.md](./AUDIT.md).

Cada grupo tiene su propia tabla de posiciones. La competencia puede tener varios grupos en paralelo.

---

## 15. Cálculo de standings por grupo

Los standings se calculan en runtime mediante `GroupStandingsCalculator`. No se persiste una tabla de posiciones fija.

### Entrada del cálculo

- jugadores del grupo y su `status` en `group_players`;
- partidos del grupo en estado `finished` con `winner_id` definido;
- sets de esos partidos;
- desempates manuales persistidos (`group_manual_tiebreaks`).

### Orden automático

1. **Partidos ganados totales** (solo jugadores `active` compiten por posiciones superiores).
2. Si hay empate en victorias totales, se resuelve con una **mini tabla** entre los empatados.

### Mini tabla (empate doble, triple o múltiple)

Cuando dos o más jugadores activos comparten la misma cantidad de victorias totales:

1. Se consideran **solo** los partidos finalizados **entre los jugadores empatados**.
2. Los partidos contra jugadores fuera del grupo empatado **no** afectan la mini tabla.
3. En empate múltiple **no** se usa únicamente el resultado directo entre dos jugadores; se evalúa el subconjunto completo.

Criterios de la mini tabla, en este orden:

1. Partidos ganados entre empatados (`mini_won`).
2. Diferencia de sets entre empatados (`set_diff`).
3. Diferencia de puntos entre empatados (`point_diff`).

Si después de agotar esos criterios persiste el empate, el sistema marca el grupo de jugadores como **pendiente de desempate manual**.

### Jugadores inactivos

Jugadores `withdrawn` o `disqualified`:

- conservan sus estadísticas (`won`, `lost`) de partidos ya finalizados;
- **no** participan del orden automático por victorias;
- aparecen **al final** de la tabla, ordenados por nombre;
- tienen `eligible_for_qualification = false`.

### Respuesta API (`GET /groups/{group}/standings`)

**Por fila (`data`):**

| Campo | Descripción |
|-------|-------------|
| `player_id` | ID del jugador. |
| `player_name` | Nombre completo. |
| `played` | Partidos jugados (`won + lost`). |
| `won` | Partidos ganados. |
| `lost` | Partidos perdidos. |
| `requires_manual_tiebreak` | El jugador está en un empate sin resolver automáticamente. |
| `manual_tiebreak_applied` | Su posición fue definida por desempate manual. |
| `manual_position` | Posición dentro del empate manual (si aplica). |
| `eligible_for_qualification` | Si puede clasificar al bracket. |
| `group_player_status` | `active`, `withdrawn` o `disqualified`. |

**Meta (`meta`):**

| Campo | Descripción |
|-------|-------------|
| `requires_manual_tiebreak` | Hay al menos un empate pendiente en el grupo. |
| `manual_tiebreak_groups` | Empates pendientes (`player_ids`, `player_names`). |
| `has_manual_tiebreaks` | Existe al menos un desempate manual aplicado. |
| `manual_tiebreaks` | Desempates aplicados (id, jugadores, reason, notes, applied_at). |
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
  "player_ids": [3, 1, 2],
  "reason": "draw",
  "notes": "Sorteo en mesa 1"
}
```

| Campo | Requerido | Descripción |
|-------|-----------|-------------|
| `player_ids` | Sí | Orden final del empate. Debe incluir exactamente a los jugadores del empate pendiente (mismo conjunto, cualquier orden). |
| `reason` | Sí | `draw`, `organizer_decision`, `agreement`, `other`. |
| `notes` | No | Texto libre (máx. según validación). |

### Reglas

- Solo se acepta si hay un empate pendiente actual en el grupo.
- El conjunto de `player_ids` debe coincidir **exactamente** con uno de los grupos en `meta.manual_tiebreak_groups` (sin importar el orden al comparar).
- Si ya existía un desempate para el mismo conjunto de jugadores, se **actualiza** (reason, notes, orden y `applied_at`).
- No se puede aplicar si la competencia ya tiene bracket.
- Si los resultados cambian y el desempate guardado ya no resuelve un empate pendiente actual, pasa a `meta.stale_manual_tiebreaks` hasta que se vuelva a aplicar uno válido o desaparezca el empate.
- Resolver un desempate que afectaba el corte de clasificación **desbloquea** la generación del bracket (si el resto de condiciones se cumple).

### UI

`GroupStandingsView` muestra empates pendientes, desempates stale y permite aplicar el desempate manual mediante paneles en la misma vista. No hay eliminación de desempates desde la UI.

---

## 17. Retiro y descalificación de jugadores en grupo

El estado del jugador dentro del grupo vive en `group_players`, no en el jugador global.

### Endpoint

```http
POST /api/v1/groups/{group}/player-status
```

### Payload

```json
{
  "player_id": 5,
  "status": "withdrawn",
  "reason": "no_show",
  "notes": "No se presentó el sábado"
}
```

| Campo | Requerido | Valores |
|-------|-----------|---------|
| `player_id` | Sí | ID del jugador en el grupo. |
| `status` | Sí | `withdrawn` o `disqualified`. |
| `reason` | No | `personal`, `injury`, `no_show`, `organizer_decision`, `other`. |
| `notes` | No | Texto libre. |

### Reglas al marcar baja

- Solo se permite pasar de `active` a `withdrawn` o `disqualified`.
- **No hay reactivación** en el MVP.
- **No se permite** si ya existe bracket.
- No se puede aplicar a un jugador que ya no está activo.
- Los partidos **ya finalizados** del jugador **no se modifican**.
- Los partidos `pending` o `in_progress` del jugador en ese grupo se cierran **a favor del rival**:
  - `status = finished`
  - `winner_id = rival`
  - `finished_at = now()`
  - **sin registrar sets**
- El jugador permanece visible en standings, al final, con `eligible_for_qualification = false`.
- El bracket lo excluye al tomar clasificados.

### Lo que esto no es

- **No** existe walkover formal como estado propio de partido.
- **No** existe abandono puntual de un solo partido como flujo separado.
- Cerrar partidos pendientes por baja **no** es lo mismo que un walkover configurable partido a partido.

### UI

`GroupDetailView` permite marcar jugadores activos como retirados o descalificados mediante un modal de confirmación. La acción queda bloqueada si ya hay bracket.

---

## 18. Clasificación al bracket

`CreateBracketKnockoutAction` usa el mismo `GroupStandingsCalculator` que la API de standings para determinar **quién clasifica** y en **qué orden dentro de cada grupo**. A partir de ahí, el emparejamiento eliminatorio en competencias `groups_knockout` es **consciente de la procedencia grupal**.

> **Regla reemplazada:** ya no se combinan todos los clasificados ni se ordenan globalmente por `(won desc, lost asc, nombre)` para generar la llave. Ese criterio global puede seguir existiendo en otros contextos (ej. standings globales de competencia), pero **no gobierna el draw eliminatorio** en `groups_knockout`.

### Reglas de clasificación

- Por cada grupo se toman hasta `Competition.qualified_per_group` jugadores **elegibles** (`eligible_for_qualification = true`), en el orden calculado por standings del grupo.
- Jugadores retirados o descalificados **no clasifican**, aunque figuren en posiciones altas antes de moverse al final de la tabla.
- Si hay **menos elegibles** que `qualified_per_group`, el bracket se genera con los disponibles; play-in y BYEs se recalculan según las posiciones grupalmente ocupadas.
- El tamaño de llave (`bracket_size`) es la **siguiente potencia de 2 ≥ total de clasificados** (máximo 64).
- Todos los partidos de **todos** los grupos deben estar finalizados.
- Si un grupo tiene empate manual pendiente que **cruza el corte de clasificación**, se bloquea la generación del bracket con error explícito.

### Metadata de origen grupal

Al generar la llave, cada clasificado conserva metadata de origen (conceptual; puede materializarse en la lógica de draw sin persistirse como entidad aparte):

| Campo | Descripción |
|-------|-------------|
| `group` | Grupo de procedencia (`Group`). |
| `group_position` | Posición en el grupo (1°, 2°, 3°, …) según standings elegibles. |
| `player` | Jugador clasificado. |
| `qualification_order` | Orden de clasificación dentro del grupo (1 = mejor posición elegible). |

Esta metadata guía el emparejamiento eliminatorio. **No** implica reordenar clasificados entre grupos por récord global.

### Draw eliminatorio (`groups_knockout`)

#### Cuando `qualified_per_group = 2`

- Si la cantidad total de clasificados es una **potencia de 2**, se genera una llave directa sin play-in.
- Los **1° de grupo** se cruzan contra **2° de otros grupos**.
- **Restricción dura:** en la primera ronda eliminatoria no se enfrentan dos jugadores del **mismo grupo**.
- Se intenta **separar** en el árbol, en la medida posible, a jugadores provenientes del mismo grupo en rondas posteriores.

Ejemplo típico (2 grupos, 2 clasificados por grupo → 4 jugadores):

```text
Semifinal 1: A1 vs B2
Semifinal 2: B1 vs A2
```

#### Cuando `qualified_per_group = 3`

- Los **1° de cada grupo** tienen **prioridad de BYE** cuando el total de clasificados no completa una potencia de 2, o cuando el diseño del cuadro lo requiere.
- Los **2° y 3°** disputan una **ronda previa (play-in)** antes de integrarse plenamente al cuadro principal.
- Cada partido de play-in es **2° vs 3° de grupos distintos** (nunca del mismo grupo).
- **Restricción dura:** en play-in y en la primera ronda “real” posterior no se enfrentan dos jugadores del **mismo grupo**.
- **Restricción blanda:** separar lo más posible a jugadores del mismo grupo dentro del árbol eliminatorio.

Flujo general:

```text
1. Extraer clasificados por grupo (1°, 2°, 3°).
2. Calcular bracket_size = siguiente potencia de 2 ≥ total clasificados.
3. Asignar BYEs a 1° de grupo (byes_count = cantidad de grupos con 1° clasificado, cuando aplica).
4. Generar partidos play-in: emparejar 2° con 3° de otro grupo.
5. Integrar ganadores de play-in con 1° (BYE o esperando rival) en la ronda principal del cuadro.
6. Avanzar rondas con winner_id hasta la final.
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
| Ronda principal | 8 jugadores (4 ganadores play-in + 4 primeros con BYE) → equivalente a 8vos de final |

Esquema:

```text
Play-in (4 partidos):
  A2 vs B3, B2 vs C3, C2 vs D3, D2 vs A3   (permutación válida inter-grupo)

BYEs:
  A1, B1, C1, D1

Ronda principal (8 jugadores):
  ganadores play-in + 1° de grupo → cuadro de 8
```

#### 8 grupos × 3 clasificados

| Concepto | Valor |
|----------|-------|
| Clasificados | 24 |
| `bracket_size` | 32 |
| `byes_count` | 8 (uno por cada 1° de grupo) |
| Play-in | 8 partidos: 2° vs 3° de grupos distintos |
| Ronda principal | 16 jugadores → 16avos de final |

Esquema:

```text
Play-in (8 partidos):
  emparejamientos 2° vs 3° cruzados entre los 8 grupos (sin mismo grupo)

BYEs:
  un BYE por cada 1° de grupo (8 BYEs)

Ronda principal (16 jugadores):
  8 ganadores play-in + 8 primeros con BYE → cuadro de 16
```

### Bloqueos con bracket existente

Si ya existe bracket:

- no se puede cambiar el estado de jugadores de grupo;
- no se puede aplicar desempate manual;
- no se recalcula ni modifica automáticamente la llave por bajas posteriores.

### Eliminación directa (`knockout_direct`)

Competencias sin fase de grupos **no** usan draw grupal. El emparejamiento inicial sigue el **orden de inscripción** y el esquema estándar 1 vs N dentro del `bracket_size` calculado.

---

## 19. Frontend / UX

Vistas principales relacionadas con la fase de grupos:

### `GroupStandingsView`

- Muestra la tabla de posiciones del grupo.
- Indica clasificación (`Clasifica` / `Eliminado`) según `eligible_for_qualification`.
- Muestra badges de jugadores retirados/descalificados.
- Muestra aviso y paneles cuando hay empates manuales pendientes.
- Permite resolver desempates manuales (solo lectura + formulario en esta vista).
- Muestra aviso si hay desempates stale.
- Deshabilita desempate manual si ya existe bracket.

### `GroupDetailView`

- Lista jugadores del grupo, standings y partidos del grupo.
- Permite asignar jugadores y generar round robin.
- Permite cargar resultados de partidos.
- Permite marcar jugadores activos como retirados/descalificados (modal).
- Muestra badges de estado y elegibilidad de clasificación.

### `CompetitionDetailView`

Panel de control de la fase de grupos. Por cada grupo muestra:

- empate manual pendiente;
- desempate manual aplicado;
- desempates stale;
- partidos pendientes;
- jugadores con baja;
- grupo listo;
- links a **Ver posiciones** y **Ver grupo**.

Los clasificados visibles en la competencia filtran por `eligible_for_qualification !== false`.

**Nota:** `status_summary` a nivel competencia es orientativo y puede decir `ready_for_bracket` aunque un grupo requiera desempate manual. El panel por grupo complementa esa información.

---

## 22. Corrección excepcional de resultados finalizados

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
| Partido de llave | Si existe una ronda posterior, solo se permite propagar a la **ronda inmediata** cuando el partido destino está `pending`, sin sets, sin ganador y contiene al ganador anterior en el slot esperado. Bloqueado si la llave avanzó más de una ronda o el destino ya comenzó. |
| Competencia | Bloqueada si ya existe una final terminada con ganador |

### Impacto

- **Grupos:** standings se recalculan automáticamente; desempates manuales previos pueden quedar **stale**; no se eliminan automáticamente.
- **Llave:** si la ronda inmediata está generada y pendiente, el ganador corregido puede propagarse automáticamente al slot correspondiente (`player1_id` o `player2_id`). No hay cascada multi-ronda.
- **Auditoría:** una actividad `game.result_corrected` por operación exitosa.

### BYEs

Los partidos `is_bye = true` **no** son corregibles manualmente.

---

## 20. Fuera de alcance actual (no implementado)

Explicitamente **no** está implementado hoy:

- walkover formal como estado propio de partido;
- abandono puntual de un solo partido;
- reactivación de jugador retirado/descalificado;
- modificación automática de bracket ya creado por baja posterior;
- eliminación/reversión de desempates manuales desde UI;
- auditoría avanzada de quién aplicó cambios de estado o desempates;
- endpoint para listar o borrar desempates manuales;
- notificaciones automáticas al organizador por empates pendientes (solo UI al consultar).

---

## 21. Filosofía del dominio

El dominio prioriza:

- claridad de reglas,
- simplicidad operativa,
- modelado explícito,
- evolución incremental.

Se evita incorporar complejidad anticipada que todavía no aporta valor al MVP.
