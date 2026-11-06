# Continuidad — Git Constellation

Última actualización: 2026-11-06

## Estado actual

- Repositorio local: `/Users/eduardcherkashyn/develop/git-constellation`.
- Fase 1 implementada y pulida; **no aprobada todavía por el usuario**.
- No iniciar la Fase 2 ni conectar GitHub hasta que el usuario apruebe expresamente el resultado visual.
- El usuario revisó el primer render, pidió pulirlo y aprobó la dirección general. La aprobación final de Fase 1 sigue pendiente tras el último pulido.
- Instrucción explícita del usuario: delegar la implementación de código y documentación a agentes GPT Sol u otros modelos más básicos; el agente principal revisa el trabajo y su calidad. Si no hay agentes disponibles, explicar esa limitación al usuario antes de cambiar el método de trabajo; no asumir silenciosamente la implementación directa.
- No hay cambios publicados ni release. Git está en `main`, sin commits y sin remoto configurado; el proyecto aparece sin seguimiento (`??`) en el último `git status` consultado.

## Producto y límites acordados

Git Constellation es una CLI open source independiente del repositorio especial `username/username` de GitHub. Genera una imagen panorámica de actividad GitHub para que el README del perfil pueda enlazar el PNG una vez publicado en una URL pública. No es SaaS y no requiere servidor permanente, VPS, Docker de producción, autenticación de usuarios ni base de datos. El PNG aún no está publicado.

Stack previsto: PHP 8.3+, Composer, Symfony HttpClient 7.4, GitHub GraphQL/REST en una fase futura, PHPUnit 12.5, TypeScript 5.9.3, HTML/CSS/SVG, Playwright 1.65 y Vitest 4.1.12. `@types/node` es 22.19.0. Node 24 recomendado; el paquete permite `^22.12.0 || >=24`. No usar Laravel, React, Vue ni Tailwind.

La imagen debe conservar la identidad de la referencia proporcionada: composición panorámica, espacio oscuro, cyan/teal/verde, constelación luminosa central, perfil a la izquierda, lenguajes a la derecha, métricas secundarias abajo y horizonte con desarrollador. No rediseñar, añadir secciones, métricas o animaciones. No cambiar tarjetas ni ranking salvo para resolver legibilidad. La referencia completa nunca se usa como fondo.

## Arquitectura ya implementada

PHP CLI carga configuración y fixture, valida datos y calcula métricas de demo; produce `build/data.json`. El exportador TypeScript valida y transforma ese contrato y Playwright genera PNG/HTML. Resumen de responsabilidades:

- `src/Cli/`, `src/Config/`: CLI y configuración.
- `src/Data/`: fixture, calendario y DTO de render.
- `src/Statistics/`: métricas y ranking.
- `src/Render/`: llamada segura al proceso Node.
- `renderer/`: validación, transformaciones, adornos, plantilla y exportador.
- `assets/constellation.css`, `assets/space.svg`, `assets/fonts/`: diseño, arte provisional y tipografía local Manrope con licencia OFL.
- `fixtures/demo.json`, `config/demo.json`: datos y configuración ficticios.
- `tests/php/`, `tests/ts/`, `tests/visual/`: pruebas PHP, Vitest y Playwright.
- `dist/constellation.png`: artefacto PNG final de demo (1920 × 840); también se genera `dist/constellation.html`.

## Datos demo y visualización

El fixture cubre cada día desde 2025-11-01 hasta 2026-10-31. Muestra el perfil ficticio Alex Morgan, Backend Developer, con stack PHP / TypeScript. Valores simulados: 1.351 contribuciones, 136 PR, 84 reviews, 327 días activos y racha de 20 días. El ranking cuenta presencia de lenguajes en repositorios del fixture, incluidos lenguajes secundarios; excluye forks y repositorios archivados. Son datos ilustrativos, no estadísticas de una persona real. La demo mantiene un período fijo y reproducible; el modo live futuro deberá calcular dinámicamente los últimos 12 meses y documentar el período consultado.

La constelación agrupa días en semanas UTC de lunes, conserva semanas parciales y ubica cada estrella en el punto medio temporal de los días incluidos. La altura usa `sqrt(total semanal / máximo semanal)` (dominio dinámico del período). Tamaño y brillo dependen de actividad. Hasta cinco máximos locales pueden tener destellos; las conexiones secundarias discontinuas son ornamentales y no codifican datos. No hay aleatoriedad en las posiciones medidas.

El último pulido trabajó el espacio vertical, las estrellas y destellos, las conexiones discontinuas, la superficie/atmósfera del planeta, la silueta del personaje y la legibilidad a anchuras de README de 830 y 960 px. No cambió paleta, tarjetas, ranking ni fixtures. Se impone al menos 6 px entre recuadros de destello y el texto superior.

## Privacidad para las fases futuras

La Fase 1 funciona sin token ni red. Para el futuro modo live, consultar inicialmente solo estadísticas agregadas de contribuciones privadas anonimizadas que GitHub permita. No pedir permisos privados innecesarios, no leer código privado ni publicar nombres de repositorios privados, ramas, mensajes, títulos de PR u otros metadatos internos. No asumir que conteos de PR/reviews incluyen actividad privada; si una cifra no está disponible con permisos autorizados, ocultarla o marcarla indisponible. Lenguajes se calculan con repositorios públicos no archivados ni forks contando repositorios donde aparece cada lenguaje; esto mide presencia, no dominio, y puede no representar tamaño/uso real del lenguaje.

## Comandos

Instalación limpia y demo:

```sh
composer install
npm ci
npx playwright install chromium
npm run build
php bin/constellation --demo
open dist/constellation.png
```

Atajo disponible: `composer run demo`. Configuración en `config/demo.json`; puede cambiarse con opciones CLI documentadas en README. Pruebas PHP: `composer test`. `npm test` ejecuta `pretest` (compilación) y Vitest. Pruebas visuales: `npm run test:visual`.

La galería de comparación se regenera, tras producir la demo, con `node build/renderer/review.js` y se abre con `open build/review/comparison.html`. Requiere la imagen de referencia en `build/review/reference.png`; el script genera `new.png`, las reducciones y la galería. El directorio `build/` está ignorado y es efímero: preserva la referencia antes de limpiar una copia de trabajo o cambiar de máquina. La imagen de referencia del usuario puede no estar disponible en una sesión nueva.

## Verificación ya comunicada

- Backend: `composer validate --strict` y 74 pruebas PHPUnit (145 aserciones) pasaron.
- Renderer: 50 pruebas Vitest y 6 pruebas Playwright pasaron.
- Tras el pulido se actualizaron intencionalmente siete snapshots de macOS; una ejecución posterior de `npm run test:visual` sin actualización pasó las seis pruebas.
- Se inspeccionaron el render final y las vistas 830/960 px. No hace falta repetir todas las pruebas por esta nota de continuidad; ejecutar las pertinentes si el estado de archivos ha cambiado.

## Artefactos de revisión

- Imagen final: `dist/constellation.png`.
- HTML autónomo: `dist/constellation.html`.
- Comparativa: `build/review/comparison.html`.
- Capturas: `build/review/reference.png`, `before.png`, `new.png`, `readme-830.png`, `readme-960.png`.
- Copias reducidas alternativas: `build/visual-preview/readme-830.png` y `readme-960.png`.

Los resultados de `build/` no están versionados. `dist/constellation.png` es el artefacto preservado por `.gitignore`; el HTML generado queda ignorado. No se ha creado workflow de GitHub Actions ni integración API. `.env.example` existe, pero la demo no carga secretos.

## Cómo retomar en una sesión nueva

1. Leer este archivo, `README.md` y `renderer/README.md`; luego comprobar `git status --short --branch` y los artefactos existentes.
2. Mostrar/revisar `dist/constellation.png` y, si persiste, abrir `build/review/comparison.html` y las capturas 830/960 px. No reconstruir los snapshots si no hay un cambio visual deliberado.
3. Mantener la Fase 1 pendiente hasta que el usuario apruebe explícitamente la imagen. Si solicita más pulido, delegar el cambio a agentes/modelos subordinados cuando estén disponibles y revisar personalmente el resultado, sin ampliar el alcance.
4. Solo tras aprobación explícita, comenzar Fase 2: cliente oficial GitHub GraphQL/REST, manejo de errores/límites/autenticación, datos reales y pruebas, respetando estrictamente la política de privacidad anterior.
5. Fases posteriores: automatización diaria/manual de GitHub Actions que publique solo `dist/constellation.png`, documentación final, revisión de seguridad y preparación de release. No afirmar publicación hasta que ocurra.

Prompt breve para iniciar otra sesión: «Lee `CONTINUITY.md` en la raíz y continúa Git Constellation desde ese estado. La Fase 1 sigue pendiente de mi aprobación visual. Respeta mis límites: delega la implementación a agentes GPT Sol/modelos más básicos y revisa tú el resultado. Si no hay agentes disponibles, explícame la limitación antes de cambiar el método. No avances a Fase 2 sin mi aprobación explícita».
