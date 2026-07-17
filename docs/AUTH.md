# Autenticación y autorización Keycloak

Este documento describe la integración backend con Keycloak: **autenticación** (Slice 2.1) y **autorización por permisos** (Slice 2.2). La auditoría y la protección del resto de rutas se implementan en slices posteriores.

## Política de acceso

Decisión adoptada para la evolución de la API:

| Tipo de operación | Acceso |
|-------------------|--------|
| **Lecturas deportivas** | **Públicas** — consultas de torneos, competencias, jugadores, standings, brackets, partidos, categorías, clubes, etc. |
| **Mutaciones administrativas** | **Autenticadas y autorizadas** — creación/edición de recursos de gestión y operaciones que modifican el estado del sistema |

En la práctica:

- Un visitante anónimo puede **consultar** el estado del torneo (listados, detalle, posiciones, llaves, resultados).
- Solo un usuario con token válido y el **permiso correspondiente** puede **mutar** datos administrativos (p. ej. crear torneos, editar competencias, cargar sets).

Esta separación se aplica de forma **progresiva por slice**: en el Slice 2.2 solo un subconjunto de mutaciones está protegido; el resto sigue público hasta slices posteriores. La regla de fondo no cambia: lectura abierta, escritura controlada.

## Variables de entorno requeridas

Configurar en `backend/.env`:

```env
KEYCLOAK_ISSUER=http://localhost:8180/realms/tdm
KEYCLOAK_API_AUDIENCE=tdm-api
KEYCLOAK_FRONTEND_CLIENT_ID=tdm-frontend
KEYCLOAK_DISCOVERY_CACHE_TTL=3600
KEYCLOAK_JWKS_CACHE_TTL=3600
KEYCLOAK_CLOCK_SKEW=60
```

| Variable | Descripción |
|----------|-------------|
| `KEYCLOAK_ISSUER` | URL base del realm OIDC (sin barra final). |
| `KEYCLOAK_API_AUDIENCE` | Valor que debe aparecer en el claim `aud` del access token. |
| `KEYCLOAK_FRONTEND_CLIENT_ID` | Referencia al client SPA; no se usa aún para validar tokens en backend. |
| `KEYCLOAK_DISCOVERY_CACHE_TTL` | TTL de caché del documento `.well-known/openid-configuration`. |
| `KEYCLOAK_JWKS_CACHE_TTL` | TTL de caché del JWKS. |
| `KEYCLOAK_CLOCK_SKEW` | Segundos de tolerancia para `exp` / `nbf`. |

## Audiencia esperada

El backend valida que el access token incluya `KEYCLOAK_API_AUDIENCE` en el claim `aud` (string o array). No se usa `azp` como reemplazo de `aud`.

En Keycloak, configurar el client de la API (`tdm-api`) como audiencia del token emitido al frontend.

## Claim de roles

Los roles se **leen del token** en cada request autenticado, desde:

```json
realm_access.roles
```

No se persisten en la tabla `users`. La extracción está encapsulada en `KeycloakRoleExtractor` para extender client roles más adelante.

Roles realm previstos:

```text
admin
organizer
scorekeeper
player
```

## Permisos internos

Los roles se traducen a permisos granulares mediante `PermissionService` y `config/permissions.php`. Los permisos **no se persisten**; se calculan en cada request a partir de los roles del token.

Permisos definidos (`App\Enums\Permission`):

```text
tournaments.view | tournaments.manage
competitions.view | competitions.manage
players.view | players.manage
registrations.view | registrations.manage
groups.view | groups.manage | groups.regenerate
standings.view
matches.view | matches.create | matches.delete | matches.record_result | matches.correct_result
brackets.view | brackets.manage | brackets.advance_round
catalog.view | catalog.manage
audit.view
users.manage
```

### Mapeo rol → permisos

| Rol Keycloak | Permisos |
|--------------|----------|
| `admin` | Todos |
| `organizer` | Gestión deportiva completa excepto `audit.view`, `users.manage`, `catalog.manage`, `matches.correct_result` |
| `scorekeeper` | Todos los `*.view` + `matches.record_result` |
| `player` | Solo permisos `*.view` |

Roles desconocidos del token (p. ej. `offline_access`) se ignoran.

## Usuario local

- Identidad estable: claim `sub` → columna `users.keycloak_id`.
- Sincronización JIT en el middleware (`SyncKeycloakUserAction`).
- No se busca por email; no se guardan tokens, roles ni permisos en BD.
- Usuarios Keycloak nuevos reciben una contraseña aleatoria hasheada (columna legacy `password` sigue siendo NOT NULL).

## Contexto autenticado

Tras validar el JWT, `AuthenticateKeycloak` adjunta:

| Atributo de request | Contenido |
|---------------------|-----------|
| `keycloak_identity` | `AuthenticatedIdentity` (roles y claims internos) |
| `authenticated_context` | `AuthenticatedContext` (usuario local + permisos resueltos) |

Los middlewares y controllers reutilizan este contexto; **no se vuelve a decodificar el JWT**.

## Middleware

| Alias | Clase | Función |
|-------|-------|---------|
| `auth.keycloak` | `AuthenticateKeycloak` | Valida Bearer token, sincroniza usuario, expone contexto |
| `permission:{permiso}` | `EnsurePermission` | Exige autenticación previa y permiso concreto |

Ejemplo:

```php
Route::middleware(['auth.keycloak', 'permission:tournaments.manage'])
    ->post('tournaments', ...);
```

## Contratos de error

Autenticación (`401`):

```json
{
  "message": "No autenticado.",
  "code": "unauthenticated"
}
```

Autorización (`403`):

```json
{
  "message": "No autorizado.",
  "code": "forbidden"
}
```

Los detalles técnicos (firma, issuer, permiso faltante) se registran en logs sin exponer el token ni información sensible al cliente.

## Endpoint `/api/v1/me`

```http
GET /api/v1/me
Authorization: Bearer <access_token>
```

Respuesta exitosa:

```json
{
  "data": {
    "id": 1,
    "keycloak_id": "subject-opaco",
    "name": "Usuario de prueba",
    "email": "usuario@example.com",
    "roles": ["organizer"],
    "permissions": ["tournaments.view", "tournaments.manage"]
  }
}
```

## Autenticación vs autorización vs dominio

| Capa | Responsabilidad | Implementación |
|------|-----------------|----------------|
| **Autenticación** | Validar JWT, resolver usuario | `auth.keycloak` |
| **Autorización** | ¿Tiene permiso para intentar la operación? | `permission:*`, `PermissionService` |
| **Reglas de dominio** | ¿El estado lo permite? | Guards existentes (`CompetitionStructureGuard`, etc.) |

Los permisos **no reemplazan** los Domain Guards ni viceversa.

## Rutas protegidas (Slice 2.2)

Aplicación inicial de la política **mutaciones administrativas autenticadas y autorizadas**. Solo estas operaciones de escritura exigen token y permiso:

| Método | Ruta | Permiso |
|--------|------|---------|
| `POST` | `/api/v1/tournaments` | `tournaments.manage` |
| `PUT/PATCH` | `/api/v1/tournaments/{tournament}` | `tournaments.manage` |
| `POST` | `/api/v1/tournaments/{tournament}/competitions` | `competitions.manage` |
| `PUT/PATCH` | `/api/v1/competitions/{competition}` | `competitions.manage` |
| `POST` | `/api/v1/games/{game}/sets` | `matches.record_result` |

Además, `GET /api/v1/me` exige `auth.keycloak`.

## Rutas públicas (transitorio)

Aplicación de la política **lecturas deportivas públicas**, más mutaciones aún no incluidas en el slice actual.

Todas las operaciones `GET` del MVP siguen accesibles sin token. Además, varias **escrituras administrativas** permanecen públicas por decisión de rollout progresivo: funcionan como antes del Slice 2.2, pero representan un **riesgo temporal de seguridad** hasta protegerlas en slices posteriores.

### Riesgo temporal: escrituras aún públicas

Cualquier cliente anónimo puede invocar hoy estos endpoints. Deben tratarse como deuda conocida, no como diseño final.

| Área | Operación | Rutas actuales (sin auth) | Permiso previsto |
|------|-----------|---------------------------|------------------|
| **Inscripciones** | Alta individual | `POST /api/v1/competitions/{competition}/registrations` | `registrations.manage` |
| | Alta masiva | `POST /api/v1/competitions/{competition}/registrations/bulk` | `registrations.manage` |
| **Grupos** | Generación aleatoria | `POST /api/v1/competitions/{competition}/groups/random-generate` | `groups.regenerate` |
| | Regeneración aleatoria | `POST /api/v1/competitions/{competition}/groups/regenerate-random` | `groups.regenerate` |
| | Creación manual / asignación | `POST /api/v1/competitions/{competition}/groups`, `POST /api/v1/groups/{group}/players` | `groups.manage` |
| | Round robin | `POST /api/v1/groups/{group}/round-robin-games` | `groups.manage` |
| **Desempates** | Desempate manual | `POST /api/v1/groups/{group}/manual-tiebreaks` | `groups.manage` |
| **Estado en grupo** | Retiro / descalificación | `POST /api/v1/groups/{group}/player-status` | `groups.manage` |
| **Llaves** | Generación de bracket | `POST /api/v1/competitions/{competition}/bracket` | `brackets.manage` |
| | Avance de ronda | `POST /api/v1/brackets/{bracket}/next-round` | `brackets.advance_round` |
| **Partidos** | Creación manual | `POST /api/v1/competitions/{competition}/games` | `matches.create` |
| | Eliminación | `DELETE /api/v1/games/{game}` | `matches.delete` |

> **Nota:** la carga de sets (`POST /api/v1/games/{game}/sets`) **ya está protegida** desde el Slice 2.2 con `matches.record_result`.

Otras escrituras públicas fuera de la lista anterior (p. ej. CRUD de jugadores) también quedan pendientes de protección; la tabla anterior concentra las operaciones estructurales de mayor impacto sobre el torneo.

**Mitigación actual:** ninguna en perimetro API — la seguridad depende de no exponer la API a redes no confiables. **Resolución prevista:** aplicar `auth.keycloak` + `permission:*` en Slice 2.5+ según la matriz de permisos.

## Validación JWT (resumen)

- Librería: `firebase/php-jwt`
- Algoritmo permitido: `RS256`
- Claims validados: firma, `kid`, `iss`, `exp`, `nbf` (si existe), `aud`, `sub`
- Discovery OIDC y JWKS cacheados (sin HTTP por request)

## Tests

Helper: `Tests\Support\ActsAsKeycloakUser` (trait en `TestCase`).

```php
$this->actingAsKeycloak(['organizer']);
$this->keycloakAuthHeaders(['scorekeeper']);
```

Los tests usan JWT firmados localmente y HTTP fake para OIDC/JWKS; no dependen de Keycloak real.

## Configuración manual pendiente

- Instancia Keycloak accesible en `KEYCLOAK_ISSUER`
- Realm `tdm` con roles realm (`admin`, `organizer`, `scorekeeper`, `player`)
- Client API con audiencia `tdm-api`
- Client frontend público con PKCE (Slice 2.3)

No se incluye aún Docker ni export de realm en este repositorio.

## Postergado a slices futuros

- Protección de las escrituras listadas en **Riesgo temporal** (Slice 2.5+)
- Frontend Keycloak / guards Vue (Slice 2.3)
- Auditoría (Slice 2.4)
- Permisos por torneo/club
- `matches.correct_result` en endpoints dedicados
- CRUD de jugadores y demás mutaciones administrativas restantes
