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
- Standings por grupo y standings global de competencia.
- Creación de bracket eliminatorio.
- Generación de siguientes rondas del bracket.
- Creación manual de partidos.
- Carga de resultados por sets.
- Determinación automática del ganador.

Queda **fuera del MVP**:

- Dobles.
- Ranking y estadísticas avanzadas.
- Desempates complejos más allá de las reglas implementadas.
- Edición de sets individuales.
- Walkover y abandono.
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

Un torneo puede contener una o más competencias.

---

### Competition

Representa una competencia concreta dentro de un torneo.

| Campo           | Tipo     | Descripción                                         |
|-----------------|----------|-----------------------------------------------------|
| id              | bigint   | Identificador único.                                |
| tournament_id   | bigint   | FK a Tournament.                                    |
| name            | string   | Nombre descriptivo (ej: "Singles Primera").         |
| type            | enum     | `singles` (en MVP solo singles).                    |
| category        | string   | División (ej: primera, amateur, libre).             |
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

| Campo       | Tipo     | Descripción                  |
|-------------|----------|------------------------------|
| id          | bigint   | Identificador único.         |
| first_name  | string   | Nombre.                      |
| last_name   | string   | Apellido.                    |
| nickname    | string   | Apodo (nullable).            |

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

---

### GroupPlayer

Representa la asignación de un jugador a un grupo.

| Campo      | Tipo   | Descripción          |
|------------|--------|----------------------|
| id         | bigint | Identificador único. |
| group_id   | bigint | FK a Group.          |
| player_id  | bigint | FK a Player.         |

Restricción única: `(group_id, player_id)`.

Regla adicional implementada:

- un jugador no puede estar en más de un grupo dentro de la misma competencia.

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
| byes_count           | int    | Cantidad de BYEs incluidos al completar la llave.                                        |

`Bracket.qualifiers_per_group` registra cuántos clasificados por grupo se usaron al generar el cuadro. La configuración activa vive en `Competition.qualified_per_group`; el campo del bracket es histórico.

Restricción única: `competition_id` (una competencia tiene un solo bracket).

El nombre del bracket **no representa la ronda inicial**. La ronda inicial se deriva de `bracket_size` y de los partidos generados (campo `round` del primer `bracket_round`).

Ejemplo:

```text
name = Llave - Singles Club
bracket_size = 32
ronda inicial = 16avos de final
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
  ├── hasMany Registration
  ├── hasMany Group
  ├── hasMany Bracket
  └── hasMany Game

Player
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
  └── hasMany Game

GroupPlayer
  ├── belongsTo Group
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
17. El bracket usa `Competition.qualified_per_group` para determinar cuántos jugadores clasifican de cada grupo.
18. El total de clasificados ya no necesita ser exactamente 2, 4 u 8. Si no es potencia de 2, el sistema calcula la siguiente potencia de 2 y completa la llave con BYEs (hasta 64 clasificados).
19. Los BYEs favorecen a los mejores seeds: se emparejan contra `player2_id = null`, quedan finalizados con `is_bye = true` y sin sets.
20. `Game.is_bye` indica avance automático. Ejemplo: 30 clasificados → bracket de 32 → 2 BYEs.
21. No se puede cambiar `qualified_per_group` si la competencia ya tiene un bracket generado.
22. La siguiente ronda del bracket se genera con `winner_id` de la ronda actual (incluye ganadores de partidos BYE).
23. No puede generarse siguiente ronda si la actual está incompleta.
24. No puede generarse una ronda ya creada ni avanzar cuando el bracket ya terminó.
25. No se puede cambiar el formato por fase (`*_best_of`) si la competencia ya tiene partidos generados.

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
10. Crear Bracket eliminatorio (usa `Competition.qualified_per_group`; guarda snapshot en `Bracket.qualifiers_per_group`)
11. Cargar sets de games del bracket
12. Generar siguiente ronda del bracket
13. Repetir hasta la final
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

### Competencias

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/tournaments/{tournament}/competitions` | Crear competencia |
| GET | `/api/v1/tournaments/{tournament}/competitions` | Listar competencias del torneo |
| GET | `/api/v1/competitions/{competition}` | Ver competencia |

### Jugadores e inscripciones

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/players` | Crear jugador |
| GET | `/api/v1/players` | Listar jugadores |
| GET | `/api/v1/players/{player}` | Ver jugador |
| POST | `/api/v1/competitions/{competition}/registrations` | Inscribir jugador |
| GET | `/api/v1/competitions/{competition}/registrations` | Listar inscripciones |

### Grupos y standings

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/competitions/{competition}/groups` | Crear grupo |
| GET | `/api/v1/competitions/{competition}/groups` | Listar grupos |
| POST | `/api/v1/groups/{group}/players` | Asignar jugador a grupo |
| GET | `/api/v1/groups/{group}/players` | Listar jugadores del grupo |
| POST | `/api/v1/groups/{group}/round-robin-games` | Generar round robin |
| GET | `/api/v1/groups/{group}/standings` | Standings del grupo |
| GET | `/api/v1/competitions/{competition}/standings` | Standings global de competencia |

### Games y sets

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/competitions/{competition}/games` | Crear game manual |
| GET | `/api/v1/competitions/{competition}/games` | Listar games de la competencia |
| GET | `/api/v1/games/{game}` | Ver game consolidado |
| POST | `/api/v1/games/{game}/sets` | Registrar set |
| DELETE | `/api/v1/games/{game}` | Borrar game |

### Bracket

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/competitions/{competition}/bracket` | Crear bracket eliminatorio |
| POST | `/api/v1/brackets/{bracket}/next-round` | Generar siguiente ronda |

---

## 9. Estados de entidades

| Entidad     | Estados posibles                       |
|-------------|----------------------------------------|
| Tournament  | `draft` → `in_progress` → `finished`  |
| Competition | Sin estado propio en el MVP            |
| Game        | `pending` → `in_progress` → `finished`|

`Tournament.status` se valida por enum.
No hay reglas de transición de estado implementadas más allá de aceptar valores válidos del enum.

---

## 10. Lo que queda fuera del MVP (futuro)

- Dobles.
- Edición de sets individuales.
- Walkover y abandono.
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
- No hay lógica de desempate avanzada en standings.
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

## 14. Filosofía del dominio

El dominio prioriza:

- claridad de reglas,
- simplicidad operativa,
- modelado explícito,
- evolución incremental.

Se evita incorporar complejidad anticipada que todavía no aporta valor al MVP.
