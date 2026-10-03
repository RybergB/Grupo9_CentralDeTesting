# Análisis de cobertura — CoberturaEstructuralCompletaTest

Fecha de medición: 3 de octubre de 2026  
Motor: PHPUnit 11.5.56, PHP 8.2.12 y Xdebug 3.5.3  
Suite: `CoberturaEstructuralCompletaTest.php`

## Resultado de ejecución

| Indicador | Resultado |
|---|---:|
| Casos ejecutados | 231 |
| Casos aprobados | 231 |
| Casos fallidos | 0 |
| Aserciones | 235 |
| Tasa de aprobación | 100% |
| Tiempo con cobertura | 10,565 s |

## Cobertura focalizada

La medición focalizada usa como denominador las 15 clases que esta suite prueba de manera intencional.

| Dimensión | Cubierto | Total | Cobertura |
|---|---:|---:|---:|
| Líneas ejecutables | 219 | 220 | **99,55%** |
| Ramas de decisión | 192 | 194 | **98,97%** |
| Métodos completos | 47 | 48 | **97,92%** |
| Clases completas | 13 | 15 | **86,67%** |
| Caminos completos | 99 | 719 | **13,77%** |

La cobertura de caminos es baja porque `Paginacion::desdeConsulta()` genera 590 combinaciones de caminos al combinar condiciones cortocircuitadas. Esa función tiene 100% de líneas, métodos y ramas. Por eso, para este conjunto, líneas y ramas describen mejor el riesgo cubierto que el porcentaje de caminos.

Sin contar esa explosión combinatoria de paginación, se recorrieron 83 de 129 caminos: **64,34%**.

## Cobertura por clase

| Clase | Líneas | Ramas | Métodos | Caminos |
|---|---:|---:|---:|---:|
| ManejadorErrores | 100% | 100% | 100% | 66,67% |
| Paginacion | 100% | 100% | 100% | 2,71% |
| Sentry | 100% | 96,77% | 100% | 40,54% |
| Certificacion | 100% | 100% | 100% | 100% |
| CicloDeVida | 100% | 100% | 100% | 100% |
| ConsumoMaquinaria | 100% | 100% | 100% | 100% |
| Enlaces | 90% | 90,91% | 0% completo | 50% |
| Permisos | 100% | 100% | 100% | 100% |
| ProtocoloIncidencias | 100% | 100% | 100% | 100% |
| Despachador | 100% | 100% | 100% | 41,67% |
| Resolucion | 100% | 100% | 100% | 100% |
| Ruta | 100% | 100% | 100% | 100% |
| PoliticaContrasena | 100% | 100% | 100% | 100% |
| PoliticaIntentos | 100% | 100% | 100% | 85,71% |
| SecretoJwt | 100% | 100% | 100% | 62,50% |

## KPIs derivados

| KPI | Fórmula | Resultado | Lectura |
|---|---|---:|---|
| Índice estructural ponderado | 50% líneas + 30% ramas + 20% métodos | **99,05%** | La suite cubre casi toda la estructura relevante. |
| Brecha de líneas | Líneas no ejecutadas / líneas totales | **1/220 (0,45%)** | Sólo queda una línea ejecutable sin recorrer. |
| Brecha de ramas | Ramas no recorridas / ramas totales | **2/194 (1,03%)** | Sólo quedan dos resultados internos de decisión. |
| Clases con 100% de líneas | Clases con todas sus líneas / clases objetivo | **14/15 (93,33%)** | Únicamente `Enlaces` no alcanza 100% lineal. |
| Densidad de pruebas | Casos / líneas ejecutables objetivo | **1,05 casos por línea** | Hay más de un caso por cada línea de producción analizada. |
| Densidad de aserciones | Aserciones / casos | **1,02** | Cada combinación tiene, en promedio, una verificación principal. |
| Rendimiento con instrumentación | Casos / segundo | **21,86 casos/s** | Incluye el costo de registrar líneas, ramas y caminos. |

## Línea pendiente

La única línea no ejecutada es `Enlaces.php:42`, el retorno defensivo posterior a `parse_url()`. Para alcanzarla, la URL tendría que superar primero `FILTER_VALIDATE_URL` y luego hacer que `parse_url()` devolviera un valor no válido. Con las implementaciones actuales de PHP, las entradas que rompen `parse_url()` ya son rechazadas por la validación anterior. No conviene alterar el código productivo ni fabricar un reemplazo de funciones sólo para inflar el porcentaje.

## Cobertura global configurada por el backend

Si se conserva el filtro amplio original de `phpunit.xml`, que también incorpora clases fuera del objetivo de esta suite, la cobertura lineal es **58,71% (219/373)**. Esta cifra responde a otra pregunta: cuánto del núcleo completo configurado cubre este único archivo. La cifra principal para evaluar el diseño de esta suite es la focalizada, **99,55%**, porque usa las 15 clases seleccionadas como alcance declarado.

## Conclusión

La suite logra 100% de aprobación, 99,55% de líneas y 98,97% de ramas sobre su alcance. El resultado es fuerte para caja blanca: se ejecutan ambos valores de casi todas las decisiones y se cubren por completo 47 de 48 métodos. El porcentaje de caminos debe acompañarse con su contexto, ya que la cantidad de combinaciones de paginación domina artificialmente ese indicador.
