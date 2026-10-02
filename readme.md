# Pruebas de registro de obras con Microsoft Edge

Estas pruebas Selenium verifican el comportamiento visible del sistema (caja
negra / pruebas funcionales de extremo a extremo). Las pruebas de caja blanca
de `src/Operaciones.py` se mantienen en `tests/test_2.py`.

## Preparación (PowerShell)

Requiere Python 3.10 o superior y Microsoft Edge instalado.

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install --no-cache-dir -r requirements.txt
$env:SGSO_EMAIL = "administrativo@sgso.test"
$env:SGSO_PASSWORD = "admin123"
.\.venv\Scripts\python.exe -m pytest tests/test_selenium_3.py -v
```

Si no se definen variables, se usa la cuenta de demostración
`administrativo@sgso.test` / `admin123`.

Selenium Manager descarga automáticamente el controlador compatible con Edge
en la primera ejecución; requiere acceso a Internet. También podés configurar
`EDGE_DRIVER_PATH` con la ruta completa a `msedgedriver.exe`.
Referencia: [Selenium para Edge](https://www.selenium.dev/documentation/webdriver/browsers/edge/).

La URL probada es https://acostaalex10.github.io/SCGO/#/login.
Para ejecutar sin ventana visible: `$env:HEADLESS = "1"`.

Las pruebas fallidas guardan una captura en `screenshots/` y siempre cierran
el navegador. `.pytest_cache/` es una caché generada por pytest y no necesita
ediciones manuales.

Ejecutá el archivo Selenium indicado: `tests/test_1.py` incluye una falla
intencional preexistente (`1 + 1 == 3`).

## Resultado observado el 27/09/2026

- El registro guarda la obra, pero muestra `Proyecto registrado con éxito` en
  vez de `Obra registrada con éxito`.
- Los campos vacíos usan `Completa este campo` del navegador; no muestran
  `Obligatorio` en rojo junto a cada casilla.
- `Cancelar` cierra directamente el formulario; no muestra
  `Confirmar cancelación de registro de obra`.
- El duplicado sí es rechazado con `Obra ya existente`.

Por eso tres pruebas reflejan actualmente incumplimientos del sistema y una
prueba pasa. Cuando la aplicación adopte los mensajes y la confirmación del
caso de uso, las mismas pruebas deberían quedar en verde sin cambiarse.

## Pruebas de materiales y consumo

```powershell
.\.venv\Scripts\python.exe -m pytest tests/test_materiales.py -v
```

Estas pruebas usan por defecto la cuenta técnica
`tecnico@sgso.test` / `tecnico123`. Se puede reemplazar mediante
`SGSO_TECNICO_EMAIL` y `SGSO_TECNICO_PASSWORD`.

Cubren el registro exitoso de consumo, el guardado y envío de una lista,
credenciales incorrectas, salida hacia otro módulo, falta de stock y
cancelación sin guardar. En la versión revisada, el consumo normal y la
navegación funcionan. El sistema registra cada material por separado, permite
superar el stock y solamente lo marca como `Excedido`; además no ofrece las
acciones `Guardar y enviar` ni `Cancelar` para trabajar con la lista completa.
