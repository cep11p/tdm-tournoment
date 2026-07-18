# Torneos TDM

Aplicación de gestión de torneos de tenis de mesa (backend Laravel + frontend Vue 3 + Keycloak).

## Arranque rápido (desarrollo local)

```bash
cp .env.example .env
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env

docker compose up -d
```

Si es la primera vez con Laravel:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Tras cambiar variables Keycloak o `.env` del frontend:

```bash
docker compose restart frontend
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
```

## URLs

| Servicio | URL |
|----------|-----|
| Frontend | http://localhost:5173 |
| API | http://localhost:8080 |
| Keycloak | http://localhost:8180 |
| Keycloak Admin | http://localhost:8180/admin |

## Usuarios demo (solo desarrollo)

Contraseña = username en todos los casos:

| Usuario | Rol |
|---------|-----|
| `admin` | admin |
| `organizer` | organizer |
| `scorekeeper` | scorekeeper |
| `player` | player |

Consola admin Keycloak: `admin` / `admin` (bootstrap, solo dev).

## Keycloak

Keycloak se levanta con `docker compose` e importa el realm `tdm` desde `docker/keycloak/import/tdm-realm.json`.

- Realm: `tdm`
- Client frontend: `tdm-frontend`
- Client API (audiencia): `tdm-api`

Ver [docs/AUTH.md](docs/AUTH.md) para detalle de issuer público vs URL OIDC interna.

### Reset del realm en desarrollo

`--import-realm` no sobrescribe un realm existente. Para forzar re-import:

```bash
docker compose down
docker volume rm tdm-tournoment_keycloak_data
docker compose up -d
```

(`docker compose down -v` elimina también el volumen de MariaDB.)

## Documentación

- [docs/AUTH.md](docs/AUTH.md) — autenticación y autorización
- [frontend/README.md](frontend/README.md) — frontend Vue
- [backend/README.md](backend/README.md) — backend Laravel

## Alcance

Esta configuración (`start-dev`, `sslRequired: none`, usuarios demo, admin/admin) es **solo para desarrollo local**. No usar en producción.
