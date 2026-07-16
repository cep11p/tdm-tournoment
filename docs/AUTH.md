# Autenticación Keycloak — Slice 2.1

Este documento describe la base de autenticación backend integrada con Keycloak. La **autorización por permisos** y la protección del resto de rutas se implementan en el **Slice 2.2**.

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
| `KEYCLOAK_FRONTEND_CLIENT_ID` | Referencia al client SPA; no se usa aún para validar tokens en este slice. |
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

## Usuario local

- Identidad estable: claim `sub` → columna `users.keycloak_id`.
- Sincronización JIT en el middleware (`SyncKeycloakUserAction`).
- No se busca por email; no se guardan tokens ni roles en BD.
- Usuarios Keycloak nuevos reciben una contraseña aleatoria hasheada (columna legacy `password` sigue siendo NOT NULL).

## Endpoint protegido

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
    "roles": ["organizer"]
  }
}
```

Errores de autenticación (`401`):

```json
{
  "message": "No autenticado.",
  "code": "unauthenticated"
}
```

## Autenticación vs autorización

| Capa | Responsabilidad | Slice |
|------|-----------------|-------|
| **Autenticación** | Validar JWT, resolver usuario, exponer roles del token | 2.1 |
| **Autorización** | Decidir si el usuario puede ejecutar la operación | 2.2 |
| **Reglas de dominio** | Guards existentes (`CompetitionStructureGuard`, etc.) | Ya implementadas |

## Rutas públicas (transitorio)

Hasta el Slice 2.2, **todas las rutas existentes del MVP siguen públicas**. Solo `/api/v1/me` exige `auth.keycloak`.

## Validación JWT (resumen)

- Librería: `firebase/php-jwt`
- Algoritmo permitido: `RS256`
- Claims validados: firma, `kid`, `iss`, `exp`, `nbf` (si existe), `aud`, `sub`
- Discovery OIDC y JWKS cacheados (sin HTTP por request)

## Configuración manual pendiente

- Instancia Keycloak accesible en `KEYCLOAK_ISSUER`
- Realm `tdm` (o equivalente) con roles realm (`admin`, `organizer`, `scorekeeper`, `player`)
- Client API con audiencia `tdm-api`
- Client frontend público con PKCE (Slice 2.3)

No se incluye aún Docker ni export de realm en este repositorio.
