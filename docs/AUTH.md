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
- Client frontend público con PKCE (ver sección Frontend abajo)

No se incluye aún Docker ni export de realm en este repositorio.

---

## Frontend (Slice 2.3)

Integración Vue con **Authorization Code Flow + PKCE (S256)** mediante `keycloak-js` (v26.2.4). Los tokens permanecen en memoria administrados por el adaptador; **no** se guardan en `localStorage`, `sessionStorage` ni cookies propias.

### Diferencia backend vs frontend administrativo

| Capa | Comportamiento |
|------|----------------|
| **Backend** | Muchas lecturas deportivas siguen **públicas** (sin token). |
| **Frontend administrativo** | Con `VITE_KEYCLOAK_ON_LOAD=login-required`, **toda la SPA exige login** aunque algunas lecturas backend no lo requieran. |

La UI **no calcula permisos desde roles**: `GET /api/v1/me` es la fuente de verdad para roles y permisos mostrados en navegación y botones.

### Variables de entorno (`frontend/.env`)

Copiar desde `frontend/.env.example`:

```env
VITE_KEYCLOAK_URL=http://localhost:8180
VITE_KEYCLOAK_REALM=tdm
VITE_KEYCLOAK_CLIENT_ID=tdm-frontend
VITE_KEYCLOAK_ON_LOAD=login-required
VITE_API_URL=http://localhost:8080/api/v1
```

| Variable | Requerida | Descripción |
|----------|-----------|-------------|
| `VITE_KEYCLOAK_URL` | Sí | URL base del servidor Keycloak. |
| `VITE_KEYCLOAK_REALM` | Sí | Realm OIDC. |
| `VITE_KEYCLOAK_CLIENT_ID` | Sí | Client ID público del frontend (`tdm-frontend`). |
| `VITE_KEYCLOAK_REDIRECT_URI` | No | Default: `window.location.origin`. Permite localhost o IP LAN sin cambiar `.env`. |
| `VITE_KEYCLOAK_SILENT_CHECK_SSO_REDIRECT_URI` | No | Solo para SSO silencioso futuro. |
| `VITE_KEYCLOAK_ON_LOAD` | No | `login-required` (default) o `check-sso`. |
| `VITE_API_URL` | No | Si no se define, usa `http://<mismo-host-que-la-página>:8080/api/v1`. |

**Importante:** toda URL desde la que se acceda al frontend (`http://localhost:5173`, `http://192.168.x.x:5173`, etc.) debe estar registrada en Keycloak como **Valid redirect URI**, **Web origin** y **Post logout redirect URI**.

### Cliente Keycloak `tdm-frontend`

Configuración manual en el admin de Keycloak:

| Parámetro | Valor |
|-----------|-------|
| Client type | `public` |
| Standard Flow | enabled |
| PKCE | `S256` |
| Direct Access Grants | disabled |
| Valid redirect URIs | `http://localhost:5173/*`, `http://<IP-LAN>:5173/*` |
| Web origins | `http://localhost:5173`, `http://<IP-LAN>:5173` |
| Post logout redirect URIs | `http://localhost:5173/*`, `http://<IP-LAN>:5173/*` |

### Audiencia API en el token del frontend

El access token emitido para `tdm-frontend` debe incluir `tdm-api` en el claim `aud`. Configurar un **Audience mapper** o client scope que agregue la audiencia del client `tdm-api` al token del SPA. Sin esto, el backend rechazará el JWT aunque Keycloak autentique correctamente.

### Bootstrap de la aplicación

1. Crear Pinia y montar la app con `AuthLoadingView` hasta completar auth.
2. Inicializar Keycloak (`pkceMethod: 'S256'`, `checkLoginIframe: false`).
3. Si hay sesión, cargar `GET /api/v1/me`.
4. Solo entonces mostrar `AppLayout` y el router administrativo.

Estados visuales: “Iniciando sesión…”, “Cargando perfil…”, error de configuración, error de conexión con botón reintentar.

`checkLoginIframe: false` evita iframes silenciosos que suelen fallar en desarrollo por cookies de terceros; el SSO silencioso requeriría página dedicada y `VITE_KEYCLOAK_SILENT_CHECK_SSO_REDIRECT_URI`.

### Store, interceptores y permisos en UI

- **Store Pinia** (`stores/auth.js`): `isReady`, perfil, roles, permisos desde `/me`, `hasPermission`, login/logout.
- **Interceptor Axios**: Bearer token tras `updateToken(30)`; refresh concurrente deduplicado; `401` → limpiar sesión y login Keycloak (sin loops); `403` → conservar sesión, mensaje “No tenés permiso para realizar esta acción.”
- **Guards de router**: rutas de escritura con `meta.permission`; acceso denegado → `/forbidden`.
- **Navegación**: ítems filtrados por permiso (`tournaments.view`, `players.view`).
- **Botones protegidos** (solo operaciones ya protegidas en backend):
  - Torneos: `tournaments.manage`
  - Competencias: `competitions.manage`
  - Carga de resultados: `matches.record_result`

**No se ocultan** acciones de inscripciones, grupos ni llaves: esas escrituras backend siguen públicas (deuda conocida). Los datos deportivos permanecen visibles aunque el usuario no pueda editarlos.

### Logout

`keycloak.logout({ redirectUri })` tras limpiar el store local. No basta con borrar estado en Pinia ni redirigir localmente.

### Comportamiento ante errores (manual)

| Escenario | Comportamiento esperado |
|-----------|-------------------------|
| Faltan variables Keycloak | Mensaje claro en pantalla de carga; la app administrativa no se muestra. |
| Keycloak no responde | Error de conexión recuperable con reintentar. |
| `/me` no responde (red) | Error recuperable; sesión Keycloak conservada; botón reintentar. |
| `/me` responde `401` | Limpiar sesión local; redirect a login Keycloak. |
| Mutación responde `403` | Mensaje de permiso; **no** logout. |
| Rol `organizer` | Ve crear/editar torneos y competencias; puede cargar resultados. |
| Rol `scorekeeper` | No ve crear/editar torneos ni competencias; sí carga resultados. |
| Rol `player` | Consulta datos; no ve acciones de edición ni carga de resultados. |
| Ruta sin permiso (URL directa) | `ForbiddenView`. |
| Logout | Sesión Keycloak terminada; store limpio. |

## Postergado a slices futuros

- Protección de las escrituras listadas en **Riesgo temporal** (Slice 2.5+)
- Auditoría (Slice 2.4)
- Permisos por torneo/club
- `matches.correct_result` en endpoints dedicados
- CRUD de jugadores y demás mutaciones administrativas restantes
- Pantallas públicas de consulta sin login
- Ocultar acciones de inscripciones/grupos/llave en UI (cuando backend las proteja)
