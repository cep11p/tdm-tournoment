# Autenticación y autorización Keycloak

Este documento describe la integración backend con Keycloak: **autenticación** (Slice 2.1), **autorización por permisos** (Slice 2.2) y **protección completa de mutaciones deportivas** (Slice 2.4).

## Política de acceso

Decisión adoptada para la evolución de la API:

| Tipo de operación | Acceso |
|-------------------|--------|
| **Lecturas deportivas** | **Públicas** — consultas de torneos, competencias, jugadores, standings, brackets, partidos, categorías, clubes, etc. |
| **Mutaciones administrativas** | **Autenticadas y autorizadas** — creación/edición de recursos de gestión y operaciones que modifican el estado del sistema |

En la práctica:

- Un visitante anónimo puede **consultar** el estado del torneo (listados, detalle, posiciones, llaves, resultados).
- Solo un usuario con token válido y el **permiso correspondiente** puede **mutar** datos (gestión de jugadores, inscripciones, grupos, llaves, partidos, torneos y competencias).

Desde el Slice 2.4, **ninguna mutación bajo `/api/v1` queda anónima**.

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

## Matriz de acceso API (Slice 2.4)

**La API ya no posee operaciones de escritura anónimas** bajo `/api/v1`. Todas las mutaciones exigen `auth.keycloak` y el permiso correspondiente (o grupo compuesto equivalente).

Excepciones deliberadas fuera de `/api/v1`: ninguna mutación deportiva. El endpoint de health `/up` no forma parte de la API versionada.

| Área | Lecturas públicas | Mutaciones protegidas | Permiso |
| ---- | ----------------- | --------------------- | ------- |
| **Autenticación** | — | `GET /api/v1/me` | Solo autenticación (`auth.keycloak`) |
| **Torneos** | `GET /tournaments`, `GET /tournaments/{tournament}` | `POST /tournaments`, `PUT/PATCH /tournaments/{tournament}` | `tournaments.manage` |
| **Competencias** | `GET /tournaments/{t}/competitions`, `GET /competitions/{c}`, `GET /competitions/{c}/standings` | `POST /tournaments/{t}/competitions`, `PUT/PATCH /competitions/{c}` | `competitions.manage` |
| **Jugadores** | `GET /players`, `GET /players/{player}` | `POST /players`, `PUT/PATCH /players/{player}`, `DELETE /players/{player}` | `players.manage` |
| **Inscripciones** | `GET /competitions/{c}/registrations` | `POST /competitions/{c}/registrations`, `POST .../registrations/bulk` | `registrations.manage` |
| **Grupos** | `GET /competitions/{c}/groups`, `GET /groups/{g}/players`, `GET /groups/{g}/standings` | Crear grupo, asignar jugador, round-robin, desempate, estado | `groups.manage` |
| **Grupos (regeneración)** | — | `POST /competitions/{c}/groups/regenerate-random` | `groups.regenerate` |
| **Grupos (generación inicial)** | — | `POST /competitions/{c}/groups/random-generate` | `groups.manage` |
| **Llaves** | `GET /competitions/{c}/bracket` | `POST /competitions/{c}/bracket` | `brackets.manage` |
| **Llaves (rondas)** | — | `POST /brackets/{b}/next-round` | `brackets.advance_round` |
| **Partidos** | `GET /competitions/{c}/games`, `GET /games/{game}` | `POST /competitions/{c}/games` | `matches.create` |
| **Partidos (eliminación)** | — | `DELETE /games/{game}` | `matches.delete` |
| **Partidos (resultados)** | — | `POST /games/{game}/sets` | `matches.record_result` |
| **Catálogo** | `GET /categories`, `GET /clubs` | — (sin CRUD en este slice) | — |

Grupos compuestos en `bootstrap/app.php` (Slice 2.2, conservados): `auth.tournaments.manage`, `auth.competitions.manage`, `auth.matches.record_result`. El resto de mutaciones usan `auth.keycloak` + `permission:*` inline en rutas explícitas.

### Diferencia 401 / 403 / 422

| Código | Cuándo | Ejemplo |
|--------|--------|---------|
| **401** | Sin token o token inválido | `POST /players` sin `Authorization` |
| **403** | Autenticado pero sin permiso | `scorekeeper` intenta crear jugador |
| **422** | Permiso OK, regla de dominio falla | `organizer` inscribe con competencia bloqueada |

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
- **Botones protegidos** según permisos de `/me`:
  - Torneos: `tournaments.manage`
  - Competencias: `competitions.manage`
  - Jugadores: `players.manage` (crear, editar, activar/desactivar, eliminar)
  - Inscripciones: `registrations.manage`
  - Grupos: `groups.manage` (estructura, round-robin, desempates, estados)
  - Regeneración de grupos: `groups.regenerate`
  - Llave: `brackets.manage`, avance de ronda: `brackets.advance_round`
  - Carga de resultados: `matches.record_result`

Las vistas de lectura permanecen accesibles; solo se ocultan controles de escritura. Los datos deportivos siguen visibles aunque el usuario no pueda editarlos.

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
| Rol `organizer` | Gestión deportiva completa (jugadores, inscripciones, grupos, llaves, partidos, resultados). |
| Rol `scorekeeper` | Consulta todo; carga resultados; no modifica estructuras ni jugadores. |
| Rol `player` | Consulta datos; no ve acciones de edición ni carga de resultados. |
| Ruta sin permiso (URL directa) | `ForbiddenView`. |
| Logout | Sesión Keycloak terminada; store limpio. |

## Postergado a slices futuros

- Auditoría
- Permisos por torneo/club
- `matches.correct_result` en endpoints dedicados
- CRUD visual de catálogo (categorías/clubes)
- Pantallas públicas de consulta sin login
- Administración de usuarios
- Vínculo usuario-jugador
