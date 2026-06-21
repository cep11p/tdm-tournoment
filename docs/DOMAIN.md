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
| sets_to_win          | int      | Sets que debe ganar un jugador para ganar el game.  |
| points_per_set       | int      | Puntos necesarios para ganar un set (ej: 11).       |
| qualified_per_group  | int      | Cuántos jugadores clasifican desde cada grupo hacia la fase eliminatoria (default: 2). |

`qualified_per_group` define cuántos jugadores de cada grupo avanzan al cuadro eliminatorio. El valor se configura al crear o editar la competencia (no puede modificarse una vez generado el bracket).

`sets_to_win` es configurable y define el formato del partido:

| sets_to_win | Formato habitual |
|-------------|------------------|
| 1           | Mejor de 1       |
| 2           | Mejor de 3       |
| 3           | Mejor de 5       |
| 4           | Mejor de 7       |

La lógica del sistema siempre utiliza el valor configurado en la Competition para determinar cuándo un jugador gana el Game.

Los jugadores **no** se inscriben al torneo en general, sino a cada competencia concreta.

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
| name                 | string | Nombre del cuadro (ej: "Eliminatoria").      |
| qualifiers_per_group | int    | Snapshot del valor `qualified_per_group` de la competencia al momento de crear el cuadro. |
| bracket_size         | int    | Tamaño de la llave (siguiente potencia de 2 ≥ clasificados).                             |
| byes_count           | int    | Cantidad de BYEs incluidos al completar la llave.                                        |

`Bracket.qualifiers_per_group` registra cuántos clasificados por grupo se usaron al generar el cuadro. La configuración activa vive en `Competition.qualified_per_group`; el campo del bracket es histórico.

Restricción única: `competition_id` (una competencia tiene un solo bracket).

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
| finished_at    | timestamp  | Momento de cierre (nullable).                      |
| round          | string     | Ronda descriptiva (ej: "Semifinal", "Final").      |
| bracket_round  | int        | Número de ronda en bracket (nullable).             |
| bracket_match  | int        | Número de partido dentro de la ronda (nullable).   |
| table_number   | int        | Número de mesa (nullable, sin scheduling automático). |

Un partido puede pertenecer al flujo manual, a grupos o a bracket.

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
6. Un jugador gana el partido cuando acumula `sets_to_win` sets ganados.
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

---

## 6. Lógica de cálculo del ganador del game

Al registrar un set (`POST /games/{game}/sets`), el sistema:

1. Valida `set_number` y scores.
2. Rechaza set empatado.
3. Rechaza score ganador por debajo de `points_per_set`.
4. Rechaza marcadores que no representen un cierre válido del set (ver regla 10).
5. Rechaza duplicado de `set_number` en el mismo game.
6. Persiste el set (append-only).
7. Recalcula sets ganados por cada jugador.
8. Si un jugador alcanza `sets_to_win`:
   - setea `winner_id`,
   - setea `status = finished`,
   - setea `finished_at = now()`.
9. Si nadie alcanza `sets_to_win`:
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
