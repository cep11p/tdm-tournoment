# Sistema de Torneos de Tenis de Mesa — Dominio MVP

## 1. Objetivo del sistema

Construir un MVP para gestionar torneos de tenis de mesa en ámbitos pequeños, domésticos o de club.

El sistema debe permitir:

- Crear torneos.
- Crear competencias dentro de un torneo (ej: "Singles Primera").
- Registrar jugadores.
- Inscribir jugadores a competencias.
- Crear partidos manualmente.
- Cargar resultados por sets.
- Calcular automáticamente el ganador del partido.

El sistema **no** es una plataforma federativa completa. La prioridad es que funcione de forma simple y real.

---

## 2. Alcance del MVP

El MVP cubre:

- Gestión de torneos.
- Gestión de competencias.
- Registro e inscripción de jugadores.
- Creación manual de partidos.
- Carga de resultados por sets.
- Determinación automática del ganador.

Queda **fuera del MVP**:

- Dobles.
- Grupos y fases (Stage).
- Llaves eliminatorias automáticas.
- Ranking y estadísticas.
- Desempates complejos.
- Clasificación automática.
- Multi-organización.
- Pagos y licencias federativas.
- Edición de sets individuales.
- Scheduling y conflictos de mesa.

En el MVP no se valida diferencia mínima de dos puntos.
Solo se requiere alcanzar `points_per_set`.

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
| sets_to_win     | int      | Sets que debe ganar un jugador para ganar el game. |
| points_per_set  | int      | Puntos necesarios para ganar un set (ej: 11).       |

`sets_to_win` es configurable y define el formato del partido:

| sets_to_win | Formato habitual |
|-------------|------------------|
| 2           | Mejor de 3       |
| 3           | Mejor de 5       |
| 4           | Mejor de 7       |

Los valores anteriores representan configuraciones habituales.

El sistema permite cualquier valor mayor o igual a 1.

Ejemplos:

- `sets_to_win = 1` → partido a un único set.
- `sets_to_win = 2` → mejor de 3.
- `sets_to_win = 3` → mejor de 5.
- `sets_to_win = 4` → mejor de 7.
- `sets_to_win = 5` → mejor de 9.

La lógica del sistema siempre utiliza el valor configurado en la Competition para determinar cuándo un jugador gana el Game.

La competencia es dueña de las reglas del partido. Los games leen `sets_to_win` y `points_per_set` desde su competencia.

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

### Game

Representa un partido entre dos jugadores dentro de una competencia.

| Campo          | Tipo       | Descripción                                        |
|----------------|------------|----------------------------------------------------|
| id             | bigint     | Identificador único.                               |
| competition_id | bigint     | FK a Competition.                                  |
| player1_id     | bigint     | FK a Player.                                       |
| player2_id     | bigint     | FK a Player.                                       |
| winner_id      | bigint     | FK a Player (nullable, se completa al terminar).   |
| status         | enum       | `pending`, `in_progress`, `finished`.              |
| finished_at    | timestamp  | Momento de cierre (nullable).                      |
| round          | string     | Ronda descriptiva opcional (ej: "Final").          |
| table_number   | int        | Número de mesa (nullable, sin lógica de scheduling). |

Un partido se crea manualmente. El ganador se calcula a partir de los sets cargados.

El estado del partido es controlado por el sistema:

- `pending`: el partido fue creado pero aún no tiene sets cargados.
- `in_progress`: existe al menos un set cargado y todavía no hay ganador.
- `finished`: uno de los jugadores alcanzó `sets_to_win`.

El estado no debe modificarse manualmente.

Un partido puede existir sin sets cargados. En ese caso:

- `winner_id` debe ser `null`
- `status` debe ser `pending`
- `finished_at` debe ser `null`

**No se persiste** en Game:

- marcador resumido (ej: `2-1`)
- cantidad de sets ganados (se calcula dinámicamente)

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

El ganador de cada set se deriva dinámicamente de `player1_score` vs `player2_score`. No se persiste `winner_id` en GameSet.

Reglas del set:

- Un set no puede finalizar empatado.
- El ganador del set debe tener mayor score que el rival.
- El ganador del set debe alcanzar al menos `points_per_set`.

---

## 4. Relaciones

```
Tournament
  └── hasMany Competition

Competition
  ├── belongsTo Tournament
  ├── hasMany Registration
  └── hasMany Game

Player
  └── hasMany Registration

Registration
  ├── belongsTo Competition
  └── belongsTo Player

Game
  ├── belongsTo Competition
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
7. Un partido no puede marcarse como `finished` sin un ganador válido.
8. El sistema determina automáticamente el ganador al cargar un set que lo define.
9. No se pueden registrar sets en un partido ya finalizado.
10. No se editan GameSets en el MVP; opcionalmente se puede borrar el Game completo.

---

## 6. Lógica de cálculo del ganador

Al cargar un set, el sistema debe:

1. Validar reglas del set según la Competition.
2. Persistir el GameSet (append-only).
3. Contar los sets ganados por `player1` y por `player2`.
4. Si alguno alcanzó `sets_to_win`:
   - Setear `winner_id` con el id del ganador.
   - Setear `status = finished`.
   - Setear `finished_at = now()`.
5. Si ninguno alcanzó `sets_to_win` aún:
   - Mantener `winner_id = null`.
   - Setear `status = in_progress`.

El ganador de un set es el jugador con mayor score en ese set.

---

## 7. Flujo del sistema

```
1. Crear Tournament
2. Crear Competition (vinculada al Tournament)
3. Registrar Players
4. Inscribir Players a la Competition (Registration)
5. Crear Game manualmente (player1_id, player2_id, competition_id)
6. Cargar GameSets (set_number, player1_score, player2_score)
7. El sistema calcula el ganador automáticamente
8. Consultar Game consolidado (sets + sets_won + winner)
```

---

## 8. API MVP (Games)

| Método   | Ruta                                         | Descripción                    |
|----------|----------------------------------------------|--------------------------------|
| POST     | `/api/v1/competitions/{competition}/games`     | Crear game manual              |
| GET      | `/api/v1/competitions/{competition}/games`     | Listar games de la competencia |
| GET      | `/api/v1/games/{game}`                         | Resultado consolidado          |
| POST     | `/api/v1/games/{game}/sets`                    | Registrar set (append-only)    |
| DELETE   | `/api/v1/games/{game}`                         | Borrar game completo           |

`POST /games/{game}/sets` devuelve el `GameResource` consolidado.

---

## 9. Estados del torneo y la competencia

| Entidad     | Estados posibles                       |
|-------------|----------------------------------------|
| Tournament  | `draft` → `in_progress` → `finished`  |
| Competition | No posee estado propio en el MVP.      |
| Game        | `pending` → `in_progress` → `finished`|

En el MVP una Competition no tiene estados propios.

La disponibilidad de una Competition se deriva del estado del Tournament al que pertenece.

Por ejemplo:

- Tournament `draft` → la Competition se considera en preparación.
- Tournament `in_progress` → la Competition se considera activa.
- Tournament `finished` → la Competition se considera finalizada.

No existe una máquina de estados independiente para Competition en el MVP.

---

## 10. Lo que queda fuera del MVP (para versiones futuras)

- Dobles (requiere modelar pareja e inscripción en par).
- Fases y grupos (Stage, Group, GroupPlayer).
- Llaves eliminatorias automáticas (Bracket, AdvancementRule).
- Ranking de jugadores.
- Estadísticas avanzadas.
- Edición de GameSets.
- Walkover y abandono.
- `win_by_two` y reglas ITTF completas.
- Scheduling y conflictos de mesa.
- Notificaciones.
- Multi-tenant u organizaciones.
- App móvil.

---

## 11 Convenciones generales

Todas las entidades persistidas incluyen:

- `created_at`
- `updated_at`

siguiendo la convención estándar de Laravel/Eloquent.

---

## 12 Decisiones simplificadas del MVP

Para priorizar simplicidad y velocidad de desarrollo:

- Los partidos se crean manualmente.
- No existe generación automática de llaves.
- No existe generación automática de grupos.
- No existe clasificación automática.
- No existen rankings.
- No existe validación oficial completa de reglas ITTF.
- No existe control de saque.
- No existe diferencia mínima de dos puntos en sets.
- No existe walkover.
- No existe abandono de partido.
- Un Game siempre tiene exactamente dos jugadores.
- El MVP soporta únicamente competencias singles.
- GameSet es append-only; no hay edición de sets individuales.

---

## 13 Invariantes del dominio

El sistema debe garantizar siempre que:

- Un Game pertenece a una única Competition.
- Un Game tiene exactamente dos jugadores distintos.
- Ambos jugadores del Game están inscriptos en la Competition.
- `winner_id` debe ser uno de los jugadores del Game.
- Un Game terminado debe tener ganador y `finished_at`.
- Un Game sin sets no puede tener ganador.
- Un Registration vincula exactamente un Player con una Competition.
- No puede haber dos GameSets con el mismo `set_number` en un Game.

---

## 14 Filosofía del dominio

El dominio prioriza:

- claridad de reglas,
- simplicidad operativa,
- modelado explícito,
- evolución incremental.

Se evita incorporar complejidad anticipada que todavía no aporte valor al MVP.
