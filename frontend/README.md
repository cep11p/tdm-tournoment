# Frontend (Vue 3)

Base inicial del frontend para la aplicacion de torneos de tenis de mesa, separada del backend Laravel.

## Stack

- Vue 3 + Vite
- Vue Router
- Pinia
- Axios
- Tailwind CSS
- Heroicons

## Variables de entorno

Copiar `.env.example` a `.env` y ajustar segun entorno:

```bash
cp .env.example .env
```

Variable requerida:

- `VITE_API_URL` (ejemplo: `http://localhost:8080/api/v1`)

## Levantar frontend con Docker

Desde la raiz del proyecto:

```bash
docker compose up frontend
```

La app queda disponible en [http://localhost:5173](http://localhost:5173).

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
