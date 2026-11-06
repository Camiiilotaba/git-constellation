# Fase 1: estructura y primera versión visual

## Objetivo

Crear una primera versión ejecutable que genere, con datos ficticios fijos, un PNG panorámico inspirado en la referencia visual. Esta fase sirve para revisar composición, proporciones, jerarquía y legibilidad antes de empezar la integración con GitHub.

El resultado demo debe identificarse claramente como ejemplo. No debe sugerir que sus estadísticas pertenecen a un usuario real.

## Arquitectura

- PHP CLI carga configuración y fixture, valida y normaliza los datos y escribe `build/data.json`.
- El comando PHP inicia el exportador Node con argumentos separados, solicita el PNG de salida y comprueba los errores del proceso.
- TypeScript transforma los datos normalizados en elementos SVG/HTML y Playwright renderiza el resultado a PNG. `npm run build` compila `renderer/*.ts` a `build/renderer/*.js` como módulos ESM.
- Los fixtures, los datos de render y el diseño visual permanecen separados para que sea posible revisar la visualización sin llamadas a servicios externos.

## Contrato de datos entre PHP y el renderer

El JSON preparado por PHP tiene esta forma:

```json
{
  "schemaVersion": 1,
  "mode": "demo",
  "profile": {
    "username": "demo-user",
    "name": "Demo Developer",
    "role": "Backend Developer",
    "stack": "PHP / Laravel"
  },
  "theme": {
    "cyan": "#30e8ef",
    "green": "#52f5a0"
  },
  "period": {
    "start": "2025-11-01",
    "end": "2026-10-31"
  },
  "days": [
    { "date": "2025-11-01", "count": 2 }
  ],
  "metrics": {
    "totalContributions": 2,
    "activeDays": 1,
    "longestStreak": 1,
    "pullRequests": null,
    "codeReviews": null
  },
  "languages": [
    { "name": "PHP", "repositories": 2, "color": "#4f5d95" }
  ],
  "privateContributionsIncluded": false
}
```

El fixture debe incluir todos los días del período, de forma inclusiva, con fechas únicas; el backend ordena la entrada y rechaza huecos y duplicados. Los conteos diarios son enteros no negativos. Las métricas de PR y revisiones admiten `null` cuando no están disponibles. Los colores de tema son valores hexadecimales. Los valores de este ejemplo son ilustrativos.

## CLI acordado

La interfaz de CLI acordada para generar la demo es:

```sh
php bin/constellation --demo \
  [--config=config/demo.json] \
  [--fixture=fixtures/demo.json] \
  [--output=dist/constellation.png]
```

El proceso genera primero `build/data.json` y luego invoca:

```sh
node build/renderer/export.js --input build/data.json --output <png>
```

El exportador guarda también un HTML hermano del PNG por defecto. La opción `--no-html` se admite al invocar directamente Node; el CLI PHP no expone esa opción.

El comando acepta opciones en formato `--opción=valor` o `--opción valor`, resuelve rutas relativas desde la raíz del proyecto y muestra ayuda con `--help`. El proceso valida errores y reporta fallos de Node y Playwright.

## Transformación de la constelación

El gráfico se calcula desde los días reales del fixture, sin aleatoriedad en las posiciones de los puntos. Se agrupan por semanas UTC que comienzan el lunes; también se incluyen el primer y último grupo parcial. La coordenada horizontal usa el punto medio temporal del grupo. La altura y la intensidad representan su total semanal; las conexiones siguen una curva suave. Los adornos de fondo pueden ser decorativos, pero no alteran los datos.

La implementación actual usa `sqrt(total semanal / máximo semanal del período)`, con intensidad cero cuando no hay actividad en todo el período. Así, el máximo queda normalizado a uno y la raíz cuadrada distribuye la actividad por el espacio vertical disponible. La escala es relativa al período y no permite comparar conteos absolutos entre imágenes.

El brillo y tamaño varían según el total semanal relativo. Hasta cinco máximos locales separados reciben destellos; una meseta uniforme se trata como un único máximo. Las ramas discontinuas tenues son decoración explícita, sin conteos ni fechas, y nunca alteran los puntos medidos ni su curva continua. Se conservan los valores originales de los fixtures.

El período de demo es fijo para que el mismo fixture produzca la misma forma temporal. El modo live y cualquier período móvil quedan fuera de esta fase.

## Dirección visual

La composición conserva la identidad de la referencia: perfil y total en la zona izquierda, constelación y meses en el centro, lenguajes a la derecha y métricas secundarias en la parte inferior. El período va junto al total de contribuciones y no hay footer. Se mantienen el fondo oscuro, los tonos cyan/teal/verde, el horizonte planetario, una silueta discreta y paneles translúcidos con bordes finos.

La captura de referencia no se usa como fondo. El fondo inicial puede ser una ilustración provisional hecha con SVG/CSS y debe conservar la dirección artística; no se presenta como arte final ni como réplica exacta. Si la composición panorámica reduce demasiado el tamaño del texto, se debe probar una variante con hero y estadísticas secundarias separados, manteniendo la misma identidad.

## Criterios de aceptación

- La instalación documentada coincide con los requisitos reales de PHP, Composer, Node, npm y Playwright.
- El modo demo funciona sin token, llamadas de red ni credenciales.
- El fixture cubre un período fijo y cada fecha aparece una sola vez, sin huecos; PHP ordena las fechas antes de normalizarlas.
- El CLI prepara el JSON acordado y llama al exportador con argumentos seguros y errores comprobables.
- Playwright produce `dist/constellation.png` y, por defecto, `dist/constellation.html`. El PNG generado mide 1920 × 840 píxeles.
- Los datos demo están identificados en la imagen como una muestra.
- La curva central refleja las agregaciones semanales del fixture, incluidos los grupos parciales de los extremos, y no usa datos aleatorios para ubicarla.
- La composición se inspecciona frente a la referencia en tamaño panorámico y se corrigen los problemas importantes de legibilidad y jerarquía.
- La documentación describe únicamente lo que existe y diferencia claramente lo implementado de lo pendiente.

## Fuera de alcance

Esta fase no implementa cliente GraphQL/REST, acceso a estadísticas live, ranking calculado desde repositorios reales, workflows, publicación automática, servidor, base de datos ni credenciales. La política futura exige limitar la actividad privada a totales anonimizados autorizados, no leer código privado y no divulgar metadatos privados. Los desgloses de PR y revisiones no se asumirán completos si la API o los permisos no los proporcionan.

La revisión y aprobación visual de esta fase preceden al inicio de las fases posteriores.

## Comandos y resultados de verificación

- `composer validate --strict`: correcto.
- `composer test`: correcto, 74 tests y 145 assertions. PHPUnit guarda su caché en `build/phpunit-cache`.
- `npm test`: el hook `pretest` ejecuta `npm run build` antes de Vitest. Resultado: 50 tests pasaron.
- `npm run test:visual`: seis pruebas; siete capturas con los casos especiales y los anchos de README de 830 y 960 píxeles.
- `npm run test:visual:update`: actualizar capturas de referencia de forma explícita.

Las siete capturas de referencia incluidas en `tests/visual/constellation.spec.ts-snapshots/` corresponden a macOS y Chromium. Incluyen el PNG a los anchos de README de 830 y 960 píxeles y el calendario sin actividad. Las versiones reducidas requieren revisión humana de legibilidad; las pruebas automáticas verifican límites y cambios visuales. En otro sistema operativo, genera referencias específicas con `npm run test:visual:update` y revísalas expresamente. Las pruebas visuales normales comparan las referencias existentes y no deben regenerarlas.

La galería local `build/review/comparison.html` muestra la referencia original, el render anterior cuando existe, el nuevo PNG y las vistas de README. Se genera mediante `node build/renderer/review.js` después de la demo; necesita la referencia en `build/review/reference.png`. Las imágenes originales se muestran al mismo ancho conservando su proporción y no se utilizan como fondo de ejecución.

El PNG generado está en `dist/constellation.png` y mide 1920 × 840 píxeles. La Fase 1 queda lista para revisión visual; aún no está aprobada para comenzar las fases posteriores.
