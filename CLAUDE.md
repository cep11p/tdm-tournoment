# CLAUDE.md

Instrucciones permanentes para Claude Code en este repositorio.

## 1. Contexto

TDM es una aplicación de gestión de torneos de tenis de mesa para ámbitos de club: torneos, competencias (singles, dobles, equipos), inscripciones, fase de grupos, llave eliminatoria y carga de resultados por sets.

- `backend/`: API Laravel 13 (PHP 8.3), versionada bajo `/api/v1`.
- `frontend/`: SPA Vue 3 + Vite + Pinia + Tailwind 4.
- Autenticación con Keycloak (realm `tdm`); entorno local con `docker compose` (servicios `app`, `nginx`, `db`, `redis`, `keycloak`, `frontend`).

TDM todavía no está en producción: no mantener compatibilidad histórica innecesaria con modelos legacy ni con datos de prueba (sin capas de compatibilidad, migraciones de datos o fallbacks para estructuras ya reemplazadas). Los nombres públicos vigentes en rutas, permisos y auditoría se respetan igual (ver sección 6).

La documentación del proyecto y los mensajes al usuario están en español.

## 2. Documentación de referencia

Consultar antes de trabajar en el área correspondiente:

- [README.md](README.md): arranque local, URLs y usuarios demo.
- [docs/DOMAIN.md](docs/DOMAIN.md): fuente de verdad del modelo competitivo, reglas de negocio, invariantes y contratos de endpoints.
- [docs/AUTH.md](docs/AUTH.md): Keycloak, roles, permisos, matriz de acceso y contratos 401/403/422.
- [docs/AUDIT.md](docs/AUDIT.md): estrategia de auditoría, códigos de acción y transacciones.

Si el código y la documentación difieren, señalarlo en lugar de asumir cuál es correcto.

## 3. Metodología

- Investigar antes de modificar: leer el código afectado y los patrones vecinos.
- Respetar el alcance solicitado; no hacer refactorizaciones oportunistas.
- Reutilizar lo existente (Actions, Support, Resources, services, componentes compartidos) antes de crear algo nuevo.
- Preferir la solución simple y mantenible; no introducir abstracciones sin necesidad concreta.
- Consultar antes de decidir reglas funcionales ambiguas.
- Requieren aprobación previa: nuevas dependencias (composer/npm) y cambios en contratos públicos de la API (rutas, payloads, forma de respuestas, códigos de error).
- Actualizar la documentación de `docs/` cuando un cambio altere reglas, contratos o auditoría documentados, limitando la edición a las secciones directamente afectadas.

## 4. Backend Laravel

Patrones habituales (guía, no prohibiciones absolutas):

- **Controladores delgados** en `app/Http/Controllers/Api/V1`: reciben el Form Request, invocan una Action y devuelven un API Resource.
- **Form Requests** (`app/Http/Requests/<Dominio>`) para validación de entrada.
- **Actions** (`app/Actions/<Dominio>`, clases `final` invocables) para lógica de negocio. Las operaciones con varias escrituras usan `DB::transaction`.
- **Support** (`app/Support/<Dominio>`) para reglas reutilizables: guards, resolvers, calculadores, payloads. Los guards de dominio rechazan con `ValidationException` (422) y mensajes en español.
- **API Resources** (`app/Http/Resources/<Dominio>`) para dar forma a las respuestas.
- **Enums** en `app/Enums` para estados y códigos estables.
- **Auditoría**: explícita desde la Action mediante `AuditLogger`, dentro de la misma transacción y tras completar los cambios. Seguir [docs/AUDIT.md](docs/AUDIT.md) para códigos, `logName` y estructura de `properties`.
- **Autorización**: rutas protegidas con `auth.keycloak` y `permission:<permiso>` (`App\Enums\Permission`, `config/permissions.php`). Los permisos no reemplazan los guards de dominio.
- Formato de código: Laravel Pint (`vendor/bin/pint`), aplicado solo a los archivos tocados.

## 5. Frontend Vue

- Vue 3 con `<script setup>` y JavaScript (sin TypeScript).
- Organización por dominio en `src/<dominio>/` (`views`, `components`, `services`, `stores`, `utils`, `constants`).
- Comunicación con la API solo a través de los services del dominio, que usan `services/httpClient`.
- Reutilizar componentes y utilidades compartidas (`src/components`, `src/shared`, `src/composables`), por ejemplo `BaseModal`, `extractApiErrorMessage`, `useTransientMessage`, `usePermissions`.
- Controles de escritura visibles según permisos de `/me`; las vistas de lectura siguen accesibles.
- Tailwind con soporte de modo oscuro (`dark:`) en todo componente nuevo o modificado.
- Íconos de `@heroicons/vue/24/outline`.
- UX consistente con las vistas existentes y terminología funcional en español (inscripción, participante, pareja, equipo, enfrentamiento, partido, llave).
- Nunca mostrar al usuario nombres técnicos como `CompetitionEntry`, `CompetitionEntryMember` o `TeamTie`.

## 6. Modelo competitivo vigente

Resumen; el detalle está en [docs/DOMAIN.md](docs/DOMAIN.md).

- `Competition` (singles, dobles o equipos) pertenece a un torneo.
- `CompetitionEntry` es el participante inscripto (jugador, pareja o equipo); sus jugadores son `CompetitionEntryMember`.
- `GroupEntry` vincula una inscripción a un grupo y registra su estado deportivo en el grupo.
- `Game` enfrenta dos inscripciones y se resuelve por sets.
- En equipos, `TeamTie` es el enfrentamiento entre dos equipos, compuesto por `TeamTieGame` según un `TeamTieFormat`.

`Registration` y `GroupPlayer` ya no existen como modelos persistentes. Los nombres históricos que sobreviven en rutas (`/registrations`, `/groups/{group}/players`), permisos (`registrations.*`), códigos de auditoría (`registration.*`), clases o enums deben respetarse; no renombrarlos sin aprobación.

## 7. Pruebas y validaciones

- Backend: PHPUnit con SQLite en memoria. `tests/Feature` para endpoints y flujos; `tests/Unit` para lógica aislada (Support, enums, calculadores).
  - `docker compose exec app php artisan test --filter=<Nombre>` para lo relacionado al cambio.
  - Autenticación en tests con `actingAsKeycloak([...])` / `keycloakAuthHeaders([...])` (`tests/Support`).
- Frontend: tests de utilidades con `node --test` (ver scripts `test:*` en `frontend/package.json`); `npm run build` como validación de compilación.
- Agregar o ajustar cobertura cuando cambie el comportamiento.
- Informar exactamente qué se ejecutó y con qué resultado. No afirmar que una prueba pasó si no se ejecutó.

## 8. Seguridad operativa y Git

- No hacer commit ni push sin autorización explícita.
- No ejecutar operaciones destructivas sin autorización: `migrate:fresh`/`reset`, `docker compose down -v`, borrado de volúmenes, `git reset --hard`, `git clean`, force push, etc.
- No mostrar, copiar ni exponer secretos. No modificar `.env` ni archivos de entorno fuera del alcance solicitado.
- Conservar los cambios locales existentes del working tree que no forman parte de la tarea.

## 9. Formato de entrega

Al finalizar una implementación, informar:

1. Resumen de cambios.
2. Archivos modificados.
3. Decisiones técnicas relevantes.
4. Pruebas ejecutadas y resultados.
5. Riesgos o pendientes.
6. Estado de Git (`git status`).
7. Confirmación de si se hizo commit y push (por defecto, no).
