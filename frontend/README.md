# Frontend (Vue 3)

Base inicial del frontend para la aplicacion de torneos de tenis de mesa, separada del backend Laravel.

## Stack

- Vue 3 + Vite
- Vue Router
- Pinia
- Axios
- Tailwind CSS
- Heroicons
- keycloak-js (OIDC + PKCE)

## Variables de entorno

Copiar `.env.example` a `.env` y ajustar segun entorno:

```bash
cp .env.example .env
```

Variables **obligatorias** para Keycloak:

```env
VITE_KEYCLOAK_URL=http://localhost:8180
VITE_KEYCLOAK_REALM=tdm
VITE_KEYCLOAK_CLIENT_ID=tdm-frontend
VITE_KEYCLOAK_ON_LOAD=login-required
```

Variable opcional:

- `VITE_API_URL` — si no se define, el cliente usa `http://<hostname-de-la-pagina>:8080/api/v1`. Así funciona en la PC (`localhost`) y en el celular (IP de la LAN) sin cambiar `.env`.

**Importante:** Vite lee `.env` al iniciar el dev server. Tras cambiar variables, reiniciar el contenedor frontend:

```bash
docker compose restart frontend
```

## Levantar frontend con Docker

Desde la raiz del proyecto:

```bash
docker compose up frontend
```

La app queda disponible en [http://localhost:5173](http://localhost:5173).

Keycloak debe estar accesible en [http://localhost:8180](http://localhost:8180) (servicio `keycloak` del compose).

Desde otro dispositivo en la misma Wi-Fi (reemplazar por la IP de la PC):

```text
http://192.168.x.x:5173
```

Para acceso LAN, agregar manualmente en Keycloak Admin (`tdm-frontend` → Valid redirect URIs / Web origins):

```text
http://<IP-LAN>:5173/*
http://<IP-LAN>:5173
```

## Estructura inicial

```
src/
├── layouts/
├── router/
├── stores/
├── services/
├── tournaments/
├── competitions/
├── registrations/
├── groups/
├── brackets/
└── games/
```

Cada slice ya incluye subcarpetas `components`, `views`, `stores`, `services` y `types` para crecer de forma pragmatica.
