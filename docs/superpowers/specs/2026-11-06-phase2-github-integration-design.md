# Fase 2 — Integración con GitHub (diseño)

Fecha: 2026-11-06. Estado: **aprobado por el usuario el 2026-11-06** con los
ajustes ya incorporados (salida `dist/live.png`, estado de tres valores para
contribuciones privadas, PR/reviews como actividad visible, compatibilidad con
Quick Start / Advanced Setup de la Fase 3).

La Fase 1 fue aprobada visualmente por el usuario el 2026-11-06 (CONTINUITY.md
se actualizará al aprobar este documento). El usuario ha confirmado que la
implementación se delega a agentes Opus o modelos más básicos y que el agente
principal revisa; el plan se divide por tanto en tareas pequeñas y
autocontenidas con puntos de revisión explícitos.

## 1. Intención y porqué

La Fase 1 produce la imagen aprobada a partir de un fixture simulado. La Fase 2
sustituye ese fixture por estadísticas reales obtenidas con la API GraphQL
oficial de GitHub, manteniendo intacto el diseño aprobado y el pipeline
PHP → `build/data.json` → renderer TypeScript → Playwright.

El objetivo inmediato es que el usuario ejecute la herramienta en su máquina,
con un token personal en `.env`, contra su propia cuenta, y compruebe que las
cifras coinciden con lo que GitHub muestra en su perfil, incluidos los recuentos
anonimizados de contribuciones privadas que ya tiene habilitados. La herramienta
debe seguir siendo reutilizable por cualquier otra persona con su propio token y
su propio `config`.

## 2. Objetivos, no-objetivos y anti-objetivos

### Objetivos

1. Modo `--live` que consulta GitHub GraphQL y genera `dist/live.png` (ruta por
   defecto; `dist/constellation.png` sigue siendo la imagen demo comprometida)
   con datos reales: calendario diario de los últimos 12 meses, Pull Requests,
   Code Reviews, Active Days, Longest Streak y ranking de lenguajes.
2. Dos escenarios soportados por el mismo código: cuenta propia autenticada y
   cuenta pública de otra persona. Solo cambia el `username` configurado.
3. Distinguir métricas disponibles de restringidas y reflejarlo en la imagen y
   en la salida de la CLI.
4. Modo `--demo` intacto para desarrollo y pruebas.
5. Los cuatro ajustes menores aprobados en la revisión visual (sección 7).
6. Pruebas unitarias con cliente HTTP simulado, una prueba de integración
   opcional contra la API real y pruebas de renderer/visuales actualizadas.
7. Documentación de permisos, privacidad y limitaciones.

### No-objetivos (fases posteriores o nunca)

- GitHub Actions, publicación automática, cualquier cosa de la Fase 3.
- API REST, caché persistente de respuestas, modo offline con datos antiguos.
- Métricas nuevas (commits, issues, estrellas, porcentajes de lenguajes).
- Repositorios de organizaciones, cuentas de empresa, GitHub Enterprise Server.
- Rediseño visual más allá de los ajustes de la sección 7.
- Soporte de proxies, tokens de GitHub App o OAuth.

### Anti-objetivos (fracaso aunque "funcione")

- Inventar cifras o sustituir silenciosamente datos reales por el fixture demo
  cuando la API falle. Un fallo de API termina con exit 1 y sin imagen nueva.
- Que el token aparezca en `data.json`, en HTML, en logs, en mensajes de error
  o en argumentos de proceso.
- Pedir el scope `repo` o cualquier permiso que dé acceso a código privado.
- Guardar o mostrar nombres de repositorios privados, ramas, títulos de PR o
  cualquier metadato interno. Las consultas no piden nombres de repositorio.
- Afirmar en la imagen que PR o reviews incluyen actividad privada.

## 3. Hechos verificados de la API de GitHub

Estos hechos condicionan el diseño. Fuente: referencia GraphQL de GitHub
(`User.contributionsCollection`, `ContributionsCollection.restrictedContributionsCount`)
y la documentación del ajuste de perfil "Include private contributions on my
profile".

- `contributionsCollection(from, to)` acepta un rango máximo de un año.
  `contributionCalendar.weeks[].contributionDays[]` devuelve `date` y
  `contributionCount` por día; las semanas devueltas pueden incluir días fuera
  del rango pedido, que hay que filtrar.
- Si el propietario de la cuenta ha activado la visibilidad de contribuciones
  privadas, el calendario diario incluye esos recuentos para **cualquier**
  consultante autenticado, no solo para el propietario. Eso cubre el escenario
  "cuenta pública de otro usuario". La afirmación previa de que solo el
  propietario podía verlos era incorrecta.
- `restrictedContributionsCount` es el número de contribuciones que el
  consultante no puede ver; solo es distinto de cero cuando el propietario
  comparte sus recuentos privados. `hasAnyRestrictedContributions` lo
  acompaña como booleano.
- `totalPullRequestContributions` y `totalPullRequestReviewContributions`
  cuentan solo PR y reviews en repositorios que el token puede ver. Con un
  token sin scope `repo`, las PR y reviews privadas **no** entran en esos
  totales; quedan dentro del recuento restringido. Por tanto esas dos métricas
  son "actividad visible", no "toda la actividad".
- Token mínimo recomendado: token clásico con solo `read:user`. Es el scope
  que la referencia cita para incluir las contribuciones privadas e internas
  como recuentos. Los tokens de grano fino no están verificados para
  `contributionsCollection`; la documentación los mencionará como no probados.
- La API GraphQL siempre requiere un token, incluso para datos públicos.
- Límite de tasa: 5 000 puntos por hora por token. Esta herramienta hace entre
  2 y unas 12 peticiones por ejecución, así que no se diseña caché.

## 4. Restricciones heredadas de la Fase 1

- Stack fijo: PHP 8.3, Symfony HttpClient 7.4 (ya instalado, sin usar),
  PHPUnit 12.5, TypeScript 5.9, Vitest 4.1, Playwright 1.65. Nada de Laravel,
  React, Vue ni Tailwind.
- `ContributionCalendar` exige un día por cada fecha del período, sin huecos ni
  duplicados, y como máximo 366 días. `ContributionMetrics::calculate` y
  `LanguageRanking::calculate` se reutilizan sin cambios: el primero recibe el
  calendario, el segundo una lista de repositorios con `visibility`, `isFork`,
  `isArchived` y `languages[{name,color}]`.
- El renderer recalcula `totalContributions`, `activeDays` y `longestStreak`
  desde `days` y rechaza el archivo si no coinciden. Eso es una verificación
  gratuita que se conserva.
- `NodeExporter` ejecuta Node sin shell con `--input/--output`; no cambia.
- `.gitignore` ya ignora `.env`, `.env.*` (salvo `.env.example`) y
  `config/local.json`. Ese es el sitio previsto para la configuración personal.

### 4.1 Compatibilidad con la Fase 3 (sin implementarla)

El usuario ha fijado que, al terminar, la herramienta tendrá dos modalidades:
**Quick Start** (un workflow reutilizable de GitHub Actions; sin instalar PHP ni
Node, sin clonar ni hacer fork; usando el `GITHUB_TOKEN` automático cuando
baste y un token personal en Secrets cuando no) y **Advanced Setup**
(ejecución local con `.env`, como en esta fase). Nada de eso se construye
ahora, pero la Fase 2 no debe cerrar la puerta. Restricciones que se derivan:

- El token se lee siempre de la variable de entorno `GITHUB_TOKEN`; `.env` es
  solo una forma de poblarla en local. En Actions no habrá `.env`.
- Todo lo que hoy se configura en `config/*.json` (username, name, role, stack,
  output, colores) debe poder venir también por opciones de CLI en la Fase 3.
  En esta fase basta con que `Configuration` siga siendo un objeto inmutable
  construido desde un array, sin acoplarse al archivo.
- La CLI no debe depender del directorio de trabajo ni de rutas relativas al
  proyecto para leer `.env` o escribir la salida: `Application` ya recibe
  `projectRoot` explícito; se mantiene.
- No se asume que el token es un PAT clásico: el cliente solo exige "Bearer
  <token>". Si el `GITHUB_TOKEN` automático de Actions resulta insuficiente
  para los recuentos privados, es un hallazgo de la Fase 3, y el estado
  `unknown` de `privateContributions` cubre ese caso sin inventar nada.

## 5. Decisiones tomadas

Cada decisión indica si la fijó el usuario o es una elección del diseño.

### 5.1 CLI

| Decisión | Quién |
|---|---|
| `--demo` y `--live` son flags mutuamente excluyentes y uno es obligatorio. Sin ninguno, error con ayuda. | diseño |
| En `--live` el login consultado es `username` del `config`. No existe `--user`: para consultar a otra persona se usa otro archivo de configuración con su `username`, `name`, `role` y `stack`, de modo que la imagen nunca mezcla el nombre de una persona con las estadísticas de otra. | diseño |
| Se añade `config/live.example.json` como plantilla; el usuario copia a `config/local.json` (ya ignorado) y ejecuta `php bin/constellation --live --config=config/local.json`. | diseño |
| La salida por defecto en `config/live.example.json` es `dist/live.png` (ignorada por `.gitignore`, que solo conserva `dist/constellation.png`). Así una ejecución real nunca sobrescribe la imagen demo comprometida ni acaba publicada por accidente. El usuario puede elegir otra ruta con `--output` o en su config. | diseño |
| `name`, `role` y `stack` siguen viniendo del `config`, no de GitHub. El usuario controla el texto mostrado. | diseño |
| La CLI imprime al final un resumen de verificación en texto plano: login, período, total del calendario, recuento restringido, PR, reviews, días activos, racha, repositorios escaneados, top lenguajes y puntos de rate limit restantes. Nunca el token. | diseño |
| Cualquier fallo de red, autenticación, rate limit, GraphQL o validación termina con exit 1, mensaje en STDERR y sin tocar la imagen anterior. No existe fallback a demo. | usuario |

### 5.2 Token y entorno

| Decisión | Quién |
|---|---|
| El token se lee de `GITHUB_TOKEN`. En modo `--live` se carga `<raíz>/.env` si existe, sin sobrescribir variables ya presentes en el proceso. En modo `--demo` no se lee nada. | usuario (ubicación) / diseño (precedencia) |
| Se añade la dependencia `symfony/dotenv ^7.4` para parsear `.env`. Misma familia que HttpClient, evita un parser casero. Se usa solo `Dotenv::parse()`: el token se guarda en un objeto en memoria y **nunca** se exporta a `$_ENV`, `$_SERVER` ni `putenv`. | diseño |
| `NodeExporter` pasa a `proc_open` un entorno explícito del que se eliminan `GITHUB_TOKEN` y cualquier variable cuyo nombre contenga `TOKEN`, `SECRET` o `PASSWORD`, para que el proceso Node/Chromium no herede credenciales. | diseño |
| El token se valida solo como "no vacío, sin espacios ni caracteres de control". No se valida prefijo. | diseño |
| El token viaja únicamente en la cabecera `Authorization: Bearer`. Las excepciones se construyen sin incluir cabeceras ni cuerpos de petición. Las pruebas comprueban que ningún mensaje de error contiene el token. | usuario |
| `.env.example` documenta `GITHUB_TOKEN` y el scope mínimo `read:user`. | diseño |

### 5.3 Período

| Decisión | Quién |
|---|---|
| Período móvil: `end` = hoy en UTC, `start` = el mismo día del año anterior más un día, de modo que cubre 365 días (366 si incluye 29 de febrero, dentro del límite de `ContributionCalendar`). | diseño |
| `from` = `start T00:00:00Z`, `to` = `end T23:59:59Z`. Los días devueltos fuera de `[start, end]` se descartan antes de construir el calendario. | diseño |
| El subtítulo de la imagen muestra el período real con el formato ya existente ("Nov 2025 – Nov 2026"). El primer mes puede ser parcial; la constelación ya soporta semanas parciales. | diseño |
| El resumen de la CLI y `data.json` registran `generatedAt` (ISO 8601 UTC) para que el usuario sepa cuándo se tomó la foto. | diseño |

### 5.4 Consultas GraphQL

Dos consultas, ambas sobre `user(login:)` para que cuenta propia y ajena
compartan código. No se usa `viewer`.

**Consulta A — contribuciones** (una petición):

```graphql
query($login: String!, $from: DateTime!, $to: DateTime!) {
  rateLimit { remaining resetAt }
  user(login: $login) {
    login
    contributionsCollection(from: $from, to: $to) {
      contributionCalendar {
        totalContributions
        weeks { contributionDays { date contributionCount } }
      }
      totalCommitContributions
      totalIssueContributions
      totalPullRequestContributions
      totalPullRequestReviewContributions
      totalRepositoryContributions
      restrictedContributionsCount
      hasAnyRestrictedContributions
    }
  }
}
```

**Consulta B — repositorios para lenguajes** (paginada, 100 por página):

```graphql
query($login: String!, $after: String) {
  user(login: $login) {
    repositories(
      first: 100, after: $after,
      ownerAffiliations: [OWNER], privacy: PUBLIC, isFork: false,
      orderBy: { field: NAME, direction: ASC }
    ) {
      pageInfo { hasNextPage endCursor }
      nodes {
        isArchived isFork visibility
        languages(first: 20, orderBy: { field: SIZE, direction: DESC }) {
          nodes { name color }
        }
      }
    }
  }
}
```

| Decisión | Quién |
|---|---|
| No se piden `name`, `nameWithOwner`, `description` ni URLs de repositorio. Así no hay nada que filtrar ni que pueda acabar en un archivo. | usuario (privacidad) / diseño (mecanismo) |
| Solo repositorios con `ownerAffiliations: OWNER`. Los repositorios de organizaciones quedan fuera del ranking en V1 porque sus lenguajes no representan necesariamente los que el usuario usa personalmente. Incluir organizaciones podrá ser una opción configurable en versiones futuras. | usuario |
| `privacy: PUBLIC` e `isFork: false` en la consulta, y además `LanguageRanking` vuelve a filtrar `visibility`, `isFork`, `isArchived`. Doble barrera. | diseño |
| Máximo 20 páginas (2 000 repositorios). Más allá, error explícito en vez de ranking truncado en silencio. | diseño |
| Hasta 20 lenguajes por repositorio (la Fase 1 ya cuenta lenguajes secundarios). | diseño |
| `user` nulo (login inexistente) → error "usuario no encontrado". `login` devuelto distinto del pedido (ignorando mayúsculas) → error. | diseño |
| GitHub devuelve `color: null` para lenguajes sin color asignado. `RepositoriesQuery` sustituye `null` por el gris neutro `#8b949e` (el que usa la propia interfaz de GitHub) antes de pasar los datos a `LanguageRanking`, que no cambia. El color es presentación, no una estadística; el recuento del lenguaje no se altera ni se omite. | diseño |

### 5.5 Cliente HTTP y errores

| Decisión | Quién |
|---|---|
| `POST https://api.github.com/graphql`, cabeceras `Authorization`, `User-Agent: git-constellation`, `Accept: application/json`, timeout 30 s, respuesta máxima 5 MB. | diseño |
| Reintentos: hasta 2 reintentos con espera creciente solo para errores de transporte y HTTP 5xx. Ninguno para 4xx. | diseño |
| Mapeo de errores a mensajes claros: 401 → token inválido o ausente; 403/429 con `x-ratelimit-remaining: 0` → límite de tasa con hora de reinicio; otros 4xx → mensaje con código; cuerpo no JSON → error de formato. | diseño |
| Si la respuesta trae `errors` (aunque también traiga `data`), se falla. No se aceptan datos parciales. Los mensajes de `errors[].message` se reenvían al usuario; GitHub no incluye el token en ellos. | usuario (no datos parciales) |
| El cliente recibe `HttpClientInterface` por constructor para poder inyectar `MockHttpClient` en pruebas. | diseño |

### 5.6 Mapeo a métricas

| Campo de salida | Origen | Incluye actividad privada |
|---|---|---|
| `days[]` | calendario diario filtrado al período | sí, como recuentos, si el propietario lo comparte |
| `metrics.totalContributions` | suma de `days[]` (debe coincidir con `contributionCalendar.totalContributions`; si no, error) | sí, igual que arriba |
| `metrics.activeDays`, `metrics.longestStreak` | `ContributionMetrics::calculate` sobre `days[]` | sí, igual que arriba |
| `metrics.pullRequests` | `totalPullRequestContributions` | no (solo repos visibles para el token) |
| `metrics.codeReviews` | `totalPullRequestReviewContributions` | no (solo repos visibles para el token) |
| `languages[]` | `LanguageRanking::calculate` sobre consulta B | no (solo públicos) |
| `privateContributions` | estado de tres valores, regla más abajo | — |
| `source.restrictedContributions` | `restrictedContributionsCount` | — |
| `source.visibleTotals` | los cinco totales visibles (commits, issues, PR, reviews, repositorios creados) | no |

**Estado de contribuciones privadas (tres valores).** El usuario ha pedido no
asumir que toda la actividad es pública solo porque `restrictedContributionsCount`
sea cero. La consulta A pide, además de los campos ya listados,
`totalCommitContributions`, `totalIssueContributions` y
`totalRepositoryContributions`. Con ello:

- `included`: `restrictedContributionsCount > 0`, o
  `hasAnyRestrictedContributions` es `true`, o
  `contributionCalendar.totalContributions` supera la suma de los cinco totales
  visibles. Cualquiera de las tres señales basta.
- `excluded`: el recuento restringido es 0, `hasAnyRestrictedContributions` es
  `false` **y** el total del calendario es exactamente igual a la suma de los
  cinco totales visibles. Es decir, los datos no contienen ningún recuento que
  no esté explicado por actividad visible. No es una suposición: es una
  comprobación aritmética sobre la respuesta.
- `unknown`: cualquier otro caso (falta algún campo, el calendario es menor que
  la suma visible, o la aritmética no cuadra de ninguna forma prevista).

**Corrección del usuario (2026-11-06, prevalece sobre lo anterior):** la señal
aritmética (`calendario > suma visible`) **no** basta para `included`. Solo
`restrictedContributionsCount > 0` o `hasAnyRestrictedContributions` marcan
`included`. Un calendario mayor que la suma visible sin señal restringida es
`unknown`. Además, un token con scope `repo` hace que la ejecución se rechace
con error, no con advertencia.

Subtítulo de la imagen según el estado: `included` → "private counts
anonymized"; `excluded` → "public activity only"; `unknown` → "visible activity
only" (texto neutro, sin afirmar que sea toda ni que sea solo pública).

**PR y Code Reviews.** Son recuentos de la actividad visible para el token y
pueden excluir actividad privada. Esto se refleja en tres sitios: el resumen de
la CLI (línea "Pull Requests (visible to token): N"), el atributo `title` de
las dos tarjetas en el HTML ("Visible to the token; may exclude private
activity"), y la tabla de limitaciones del README y de `docs/phase-2.md`. No se
añade texto visible nuevo a las tarjetas, por respeto al diseño aprobado.

**Incertidumbre pendiente de verificar con la API real.** Los hechos de la
sección 3 proceden de la documentación, no de una ejecución. Queda una duda
concreta para el escenario principal (el propietario consulta su propia cuenta
con un token `read:user` sin `repo`): no está confirmado si GitHub trata sus
contribuciones privadas como "restringidas" para ese token (recuento > 0) o
como accesibles (recuento 0 aunque el calendario las incluya). El spike previo
(sección 6) comprueba también si `totalContributions` del calendario es igual a
la suma de los cinco totales visibles más `restrictedContributionsCount`; si
esa identidad se cumple, la regla de tres estados es exacta y se fija tal cual.
Si no se cumple, se documenta la discrepancia y el estado por defecto para ese
caso pasa a `unknown`. El resultado del spike queda anotado en `docs/phase-2.md`.

### 5.7 Contrato `data.json`

Se publica `schemaVersion: 2`. Cambios respecto a v1:

- `mode`: `"demo" | "live"`.
- `generatedAt`: cadena ISO 8601 UTC. Presente en ambos modos.
- `source`: objeto con `provider: "github"`, `login`, `restrictedContributions`
  (entero ≥ 0), `visibleTotals` (objeto con `commits`, `issues`,
  `pullRequests`, `reviews`, `repositories`, enteros ≥ 0) y
  `repositoriesScanned` (entero ≥ 0). En demo, `source` es `null`.
- `privateContributions`: `"included" | "excluded" | "unknown"`. Sustituye al
  booleano `privateContributionsIncluded` de v1. En demo es obligatoriamente
  `"excluded"`; `"included"` y `"unknown"` solo se aceptan en `mode: "live"`.
- Todo lo demás (profile, theme, period, days, metrics, languages) no cambia.
- El renderer rechaza `schemaVersion` distinto de 2. `RendererData::fromDemo`
  también emite v2 para que haya un único contrato.

`RendererData` gana `fromLive(Configuration, LiveSnapshot): array`. Ambas
fábricas comparten el ensamblado común.

### 5.8 Estructura de código

Nuevo espacio `src/GitHub/`:

- `GraphQlClient`: envío de consultas, cabeceras, reintentos, mapeo de errores.
- `GitHubApiException` (extiende `RuntimeException`): única excepción pública
  del cliente, con un `kind` (auth, rateLimit, notFound, transport, response).
- `ContributionsQuery`: texto de la consulta A y parseo de su respuesta a
  `ContributionCalendar` + contadores.
- `RepositoriesQuery`: texto de la consulta B, paginación y parseo a la lista de
  repositorios que entiende `LanguageRanking`.
- `LiveStatistics`: orquesta ambas consultas y devuelve `LiveSnapshot`.

Nuevo en `src/Data/`: `LiveSnapshot` (readonly: login, calendar, pullRequests,
codeReviews, restrictedContributions, hasRestricted, languages,
repositoriesScanned, generatedAt) y `Period` (cálculo del período móvil a
partir de un `DateTimeImmutable` inyectable, para pruebas deterministas).

Nuevo en `src/Config/`: `Environment` (carga opcional de `.env`, lectura y
validación de `GITHUB_TOKEN`).

`src/Cli/Arguments` y `Application` aprenden `--live`. `Application` construye
el cliente real solo en modo live.

## 6. Pruebas

Todo determinista salvo la prueba de integración marcada.

### PHP (`tests/php/`)

- `GitHub/GraphQlClientTest`: cabecera Bearer presente, `User-Agent`, 401 →
  excepción `auth`, 403/429 con rate limit → `rateLimit` con `resetAt`, 5xx →
  reintenta y luego falla, 4xx no reintenta, `errors[]` con `data` → falla,
  cuerpo no JSON → falla, y **ningún mensaje de excepción contiene el token**.
- `GitHub/ContributionsQueryTest`: semanas con días fuera de rango se filtran,
  días faltantes → error, `totalContributions` discordante → error, mapeo de
  contadores y de `restricted*`, `user: null` → notFound, login distinto →
  error.
- `GitHub/RepositoriesQueryTest`: paginación de 3 páginas, tope de páginas,
  repos archivados/privados/forks descartados, lenguajes secundarios contados.
- `GitHub/LiveStatisticsTest`: composición completa con `MockHttpClient` y
  respuestas sintéticas → `LiveSnapshot` esperado.
- `Data/PeriodTest`: período móvil para una fecha normal, para 29 de febrero,
  para 1 de enero; siempre UTC; 365 o 366 días.
- `Data/RendererDataLiveTest`: `fromLive` produce el contrato v2 con el orden
  de claves, los tres estados de `privateContributions` a partir de respuestas
  sintéticas (incluido el caso aritmético sin recuento restringido), `source`
  relleno; `fromDemo` emite v2 con `source: null` y `"excluded"`.
- `Config/EnvironmentTest`: `.env` se carga, el entorno del proceso tiene
  prioridad, token vacío o con espacios → error, modo demo no lee `.env`.
- `Cli/ArgumentsTest`: `--live`, exclusividad con `--demo`, ninguno → error.
- `Render/NodeExporterTest`: el entorno pasado al proceso hijo no contiene
  `GITHUB_TOKEN` ni variables con `TOKEN`/`SECRET`/`PASSWORD`.
- `GitHub/RepositoriesQueryTest` (añadido): un lenguaje con `color: null`
  recibe el gris neutro y se cuenta igual.
- `Integration/GitHubLiveTest`: se omite salvo que existan `GITHUB_TOKEN` y
  `GIT_CONSTELLATION_LOGIN` en el entorno. Comprueba calendario completo,
  coherencia de totales, rate limit > 0. No asserta cifras concretas.

Las respuestas simuladas viven en `tests/php/fixtures/github/` y son
sintéticas, no grabadas de la cuenta del usuario.

### TypeScript (`tests/ts/`)

- `validation.test.ts`: acepta `mode: "live"` con los tres estados de
  `privateContributions`, rechaza `included`/`unknown` en demo, exige
  `schemaVersion: 2`, valida `generatedAt` y `source`.
- `render.test.ts`: sin insignia DEMO en live, con insignia en demo, los tres
  subtítulos según estado, `title` de las tarjetas PR/Reviews, tarjetas sin
  descripciones secundarias, cabecera sin tagline.

### Visual (`tests/visual/`)

- Se actualizan los snapshots existentes una sola vez por los cambios de la
  sección 7 (acción deliberada, se informa).
- Nueva prueba: fixture en modo live, sin insignia, con subtítulo de
  contribuciones privadas, a 1920 px y a 830 px.

### Spike previo (antes de implementar)

Un script desechable de solo lectura, fuera de `src/`, que lee `GITHUB_TOKEN`
de `.env`, lanza la consulta A ampliada con los cinco totales visibles contra
la cuenta del usuario y muestra únicamente los contadores agregados (nunca el
token ni nombres de repositorio). Su resultado fija la regla de
`privateContributionsIncluded` de la sección 5.6 y se anota en `docs/phase-2.md`.
El script se borra al terminar; nada de lo que produzca se conserva en el
repositorio.

### Verificación del criterio de aceptación 3

"Verificado" significa, en este orden:

1. Las cifras del resumen de la CLI coinciden exactamente con una consulta
   GraphQL directa (`curl`) con el mismo token, mismo login y mismo `from`/`to`.
   Esto es reproducible y es la comprobación principal.
2. Comprobación puntual de al menos cinco fechas concretas: el `count` de
   `days[]` coincide con el tooltip diario del gráfico de contribuciones del
   perfil público. Los recuentos diarios del perfil son exactos por fecha.
3. El total anual del perfil ("contributions in the last year") se compara solo
   como orden de magnitud: GitHub usa su propia ventana de semanas completas,
   distinta de los 365 días móviles de esta herramienta, así que no tiene que
   coincidir y la documentación lo dice.
4. PR y reviews se comparan con los totales GraphQL (punto 1), no con la
   página de perfil, y la documentación deja claro que excluyen la actividad
   privada no visible para el token.

El token estará en `.env` en la máquina del usuario, que es donde se ejecuta
esta sesión; el agente principal realiza la ejecución real y la comparación, y
el usuario confirma la imagen. Se documenta en `docs/phase-2.md`.

## 7. Ajustes visuales aprobados

Únicos cambios de plantilla y CSS permitidos. Ningún cambio de paleta,
composición, constelación ni tarjetas más allá de lo listado.

1. **Tarjetas inferiores:** se eliminan las líneas secundarias ("Created ·
   simulated", "Submitted · simulated", "In the example period", "Consecutive
   active days") en ambos modos. Queda número y nombre. Para valor `null` se
   mantiene "—" con "Not available". *(usuario; aplicar en ambos modos es
   decisión del diseño)*
2. **Cabecera:** se elimina la línea "ACTIVITY • CODE • CONTRIBUTIONS". A 830 px
   es ilegible. Se conserva "DEVELOPER CONSTELLATION". *(usuario pidió
   valorarlo; diseño decide eliminarlo)*
3. **Top Languages:** sin porcentajes, como está. Se conserva la nota "By public
   repository count" y el README explica el criterio: número de repositorios
   públicos propios, no archivados y no forks en los que aparece el lenguaje,
   incluidos lenguajes secundarios; empate por nombre; top 5. *(usuario)*
4. **Insignia DEMO / SIMULATED DATA:** solo en `mode: "demo"`. En live no se
   renderiza. Los textos de accesibilidad (`<title>`, `aria-label`, párrafo
   `sr-only`) y el texto de estado vacío de lenguajes dejan de decir
   "simulated"/"example" en live. *(usuario)*

El espacio que dejan la tagline y las descripciones no se rellena con nada
nuevo; el builder ajusta el CSS lo mínimo para que las tarjetas no queden con
hueco visible.

## 8. Documentación

- `README.md`: nueva sección "Modo live" (crear token clásico con solo
  `read:user`, colocar en `.env`, copiar `config/live.example.json` a
  `config/local.json`, ejecutar, leer el resumen); tabla de qué métricas
  incluyen actividad privada; criterio de lenguajes; período móvil; nota de
  tokens de grano fino no verificados; comportamiento ante fallos (nunca
  fallback).
- `docs/phase-2.md`: contrato v2, consultas GraphQL, decisiones de privacidad,
  procedimiento de verificación manual, limitaciones conocidas.
- `.env.example` y `config/live.example.json` actualizados.
- `renderer/README.md`: contrato v2 y modos.
- `CONTINUITY.md`: actualizar al cerrar la fase.

## 9. Criterios de aceptación (del usuario)

1. Imagen generada con estadísticas reales de la cuenta del usuario.
2. Constelación basada en el calendario real, sin aleatoriedad.
3. Métricas verificadas frente a GitHub (procedimiento de la sección 6).
4. Pruebas de integración y de procesamiento de estadísticas.
5. Captura real de Playwright del resultado final.
6. Documentación de limitaciones de acceso a información privada.

No se inicia la Fase 3.

## 10. Lo que queda al constructor

- Nombres exactos de métodos, forma de las excepciones internas, formato del
  texto de ayuda.
- Cómo compartir el ensamblado común entre `fromDemo` y `fromLive`.
- Detalle del backoff de reintentos (dentro de "2 reintentos, espera
  creciente, solo transporte/5xx").
- Ajuste CSS mínimo tras quitar tagline y descripciones.
- Organización de los fixtures sintéticos de prueba.
