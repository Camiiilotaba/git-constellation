# Git Constellation

Git Constellation convierte un calendario de contribuciones de GitHub en una imagen panorámica inspirada en una constelación. Está pensado para generar un PNG que se pueda incluir en un README de perfil y actualizar más adelante con GitHub Actions.

> **Fase 1 lista para revisión visual:** genera una imagen de demostración con estadísticas simuladas. La fase todavía no está aprobada para iniciar el trabajo posterior. Aún no hay integración con GitHub, estadísticas en vivo ni workflow de actualización automática.

![Vista previa de Git Constellation en modo demo](dist/constellation.png)

La vista previa es el PNG generado por el modo demo: 1920 × 840 píxeles.

## Requisitos

- PHP 8.3 o superior y Composer.
- Node.js 24 y npm. La versión de Node se indica en `.nvmrc`.
- Chromium instalado para Playwright.

## Estructura del proyecto

```text
.
├── assets/
│   ├── constellation.css  # Estilos de la composición
│   ├── fonts/             # Manrope y su licencia OFL
│   └── space.svg          # Fondo artístico provisional
├── bin/                   # CLI PHP
├── build/                 # TypeScript compilado y datos de render (generado)
├── config/                # Configuración de demo
├── dist/                  # PNG generado para publicar
├── docs/                  # Documentación de fases
├── fixtures/              # Calendario y metadatos ficticios para demo
├── renderer/              # Exportador, iconos y render SVG/HTML en TypeScript
├── src/
│   ├── Cli/                # Argumentos y coordinación del comando
│   ├── Config/             # Validación de configuración
│   ├── Data/               # Fixture, calendario y DTO de render
│   ├── Render/             # Invocación segura del proceso Node
│   ├── Statistics/         # Métricas y ranking de demo
│   └── Support/            # Lectura y escritura JSON
└── tests/
    ├── php/                # Pruebas PHPUnit
    ├── ts/                 # Pruebas Vitest
    └── visual/             # Pruebas visuales Playwright
```

`build/`, `dist/`, `vendor/` y `node_modules/` se generan localmente; no son código fuente de la herramienta.

## Dependencias

Composer instala Symfony HttpClient y PHPUnit. Symfony HttpClient está incluido para la integración con GitHub prevista para la Fase 2; el modo demo de Fase 1 no hace llamadas HTTP. PHPUnit es una dependencia de desarrollo para las pruebas PHP.

Las dependencias npm de desarrollo son TypeScript para compilar módulos ESM, `@playwright/test` para el navegador de render y las pruebas visuales, y Vitest para probar transformaciones. `@types/node` aporta tipos al compilador TypeScript y no se incluye en tiempo de ejecución. Playwright utiliza Chromium para exportar el PNG.

## Instalación y generación de la demo

Desde la raíz del proyecto:

```sh
composer install
npm ci
npx playwright install chromium
npm run build
php bin/constellation --demo
```

El CLI acepta `--demo` y usa `config/demo.json` y `fixtures/demo.json` por defecto. Prepara `build/data.json` y llama a `node build/renderer/export.js --input build/data.json --output <png>` para generar `dist/constellation.png`. Puedes cambiar las rutas con `--config=...`, `--fixture=...` y `--output=...`; las rutas relativas se resuelven desde la raíz. `php bin/constellation --help` muestra el uso. `composer run demo` ejecuta el comando predeterminado.

Los datos demo tienen un período fijo para que la imagen sea reproducible. No necesitan token ni acceso a GitHub. Cada ejecución guarda `dist/constellation.png` y, por defecto, `dist/constellation.html`. Al llamar directamente al exportador Node se puede añadir `--no-html` para omitir el archivo HTML; el comando PHP siempre usa la salida predeterminada.

## Configuración de la demo

La configuración de `config/demo.json` contiene:

```json
{
  "username": "demo-developer",
  "name": "Alex Morgan",
  "role": "Backend Developer",
  "stack": "PHP / TypeScript",
  "output": "dist/constellation.png",
  "colors": {
    "cyan": "#30e8ef",
    "green": "#52f5a0"
  }
}
```

Edita `config/demo.json` para usar tu nombre, rol, stack y colores. Las claves son `username`, `name`, `role`, `stack`, `output` y `colors.cyan`/`colors.green`. La configuración y el fixture contienen datos ficticios.

## Integrar la imagen en un perfil

El proyecto generador `git-constellation` es independiente del repositorio especial `usuario/usuario` que GitHub muestra como perfil. Una vez que el PNG esté publicado en una rama pública, el README del perfil puede enlazarlo, por ejemplo:

```md
![Git Constellation](https://raw.githubusercontent.com/OWNER/git-constellation/BRANCH/dist/constellation.png)
```

Sustituye `OWNER` y `BRANCH` por los valores reales. La URL solo funcionará cuando ese archivo se haya subido a un repositorio público; esta fase no publica artefactos.

## Cómo leer la visualización

La transformación TypeScript agrega los días en semanas UTC que comienzan el lunes, incluidos los grupos parciales de los extremos, y coloca cada punto en el punto medio temporal del grupo. Calcula la intensidad como `sqrt(total semanal / máximo semanal del período)`; si todo el período está a cero, la intensidad es cero. La coordenada vertical usa esa intensidad normalizada. El tamaño y la opacidad de cada estrella responden al conteo semanal bruto relativo al máximo; se resaltan hasta cinco máximos locales separados. La escala vertical es relativa al período, no una medida de habilidad.

La curva continua pasa por las estrellas semanales medidas. Las conexiones secundarias tenues y discontinuas son decorativas: no representan contribuciones adicionales, no contienen conteos y no alteran la serie principal. Los fixtures permanecen fijos durante el refinamiento visual.

El ranking de la demo cuenta lenguajes presentes en los repositorios públicos ficticios del fixture, incluidos los lenguajes secundarios; excluye forks y repositorios archivados. Para la futura integración live, el criterio será contar repositorios públicos no archivados ni forks donde aparezca cada lenguaje. En ambos casos mide presencia en repositorios, no experiencia o dominio. La integración live aún no está implementada.

La dirección visual sigue la referencia: fondo oscuro espacial, cyan y teal con acentos verdes, constelación luminosa, jerarquía tipográfica clara y métricas secundarias discretas. El período aparece junto al total de contribuciones y la composición no añade un footer. El fondo es arte provisional en SVG/CSS; no es el recurso definitivo ni una reproducción exacta de la captura.

## Privacidad y estado de GitHub

El modo demo no realiza llamadas a GitHub ni requiere credenciales. La futura integración deberá respetar la privacidad: no leer código privado ni exponer nombres de repositorios, ramas, mensajes de commits, títulos de PR u otros datos internos. Cuando se implemente, las contribuciones privadas se consultarán solo como totales anonimizados que GitHub permita mostrar. Los desgloses de PR y revisiones pueden no incluir actividad privada; si una cifra no está disponible con los permisos autorizados, se ocultará o marcará como no disponible.

No añadas un token real al repositorio. `.env.example` explica que la Fase 1 no necesita credenciales y que el programa no carga `.env` automáticamente.

## Desarrollo y pruebas

Las pruebas PHP se ejecutan con `composer test`; pasaron 74 tests con 145 assertions, y `composer validate --strict` fue correcto. `npm test` ejecuta automáticamente `npm run build` mediante el hook `pretest`, y luego corre Vitest; pasaron 50 tests. Playwright cubre seis pruebas con `npm run test:visual`, incluyendo las capturas reducidas de 830 y 960 píxeles.

Las siete capturas de referencia incluidas corresponden a macOS y Chromium y están en `tests/visual/constellation.spec.ts-snapshots/`. Cubren el PNG a los anchos de README de 830 y 960 píxeles, actividad nula, texto largo, actividad uniforme y un pico inicial. Las capturas reducidas se inspeccionan visualmente, además de comprobar sus límites. `npm run test:visual` compara contra las referencias sin actualizarlas. En otro sistema operativo, genera capturas específicas de la plataforma con `npm run test:visual:update` y revísalas expresamente; no regeneres las referencias como parte de una prueba rutinaria.

La comparación local de la referencia, el render anterior, el render nuevo y las vistas reducidas está en `build/review/comparison.html`. Con la referencia en `build/review/reference.png`, se regenera tras generar la demo mediante `node build/renderer/review.js`. Este material de revisión no forma parte de los recursos de la imagen ni se publica automáticamente. La aprobación visual de la Fase 1 sigue pendiente.

## Alcance por fases

La Fase 1 comprende estructura inicial, configuración y fixture demo, render HTML/SVG/CSS, exportación de un PNG mediante Playwright, pruebas de unidad y pruebas visuales frente a la referencia. La integración GraphQL/REST, métricas live, GitHub Actions y publicación automatizada pertenecen a fases posteriores y quedan pendientes de revisión y aprobación visual de la Fase 1. El detalle está en [docs/phase-1.md](docs/phase-1.md).

## Tipografía

El diseño usa Manrope, del proyecto Manrope Authors (Copyright 2018). La fuente se distribuye bajo SIL Open Font License 1.1; su texto de licencia y atribución están en [assets/fonts/OFL.txt](assets/fonts/OFL.txt).

## Licencia

Este proyecto se distribuye bajo la licencia MIT. Consulta [LICENSE](LICENSE).
