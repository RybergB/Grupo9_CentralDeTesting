# Pruebas de caja blanca de los requerimientos 1 y 2

Esta carpeta reúne las pruebas PHP utilizadas en el análisis de caja blanca.

## Requerimiento 1 - Registrar proyecto

- `R1-CB-01.1` a `R1-CB-01.6`: validan individualmente los seis campos obligatorios. Cada ejecución agrupa el código 422, el mensaje `Obligatorio` y la ausencia de persistencia.
- `R1-CB-01.7`: carga los seis campos válidos y comprueba la creación correcta de la obra.
- `R1-CB-02`: rechaza una obra duplicada y comprueba que no se guarde.
- `R1-CB-03`: comprueba que una obra nueva se registre con estado inicial `creada`.

## Requerimiento 2 - Carga de materiales y registro de consumo

- `R2-CB-01`: verifica el límite exacto entre cantidad asignada y consumida.
- `R2-CB-02`: exige rechazar un consumo superior al stock, informar `Stock insuficiente` y no persistirlo.
- `R2-CB-03`: prueba cinco fronteras de la política de intentos fallidos de inicio de sesión.
- `R2-CB-04`: exige que cancelar un consumo descarte el borrador sin llamar a `crearConsumo`.

`CicloDeVidaTest.php` y `PoliticaIntentosTest.php` se conservan como pruebas complementarias del backend, pero no forman parte de la ejecución focalizada de estos dos requerimientos.

## Ejecución desde el backend SCGO

```powershell
cd "C:\Users\brian\Documents\Codex\2026-09-23\da\scgo_source\SCGO-main\back"
php .\vendor\bin\phpunit .\tests\CajaBlanca\FlujosTp1Test.php --testdox --do-not-cache-result
```

## Ejecución desde claseTesting

```powershell
cd "C:\Users\brian\Facultad\Software2\claseTesting"
php "C:\Users\brian\Documents\Codex\2026-09-23\da\scgo_source\SCGO-main\back\vendor\bin\phpunit" `
  ".\tests\caja_blanca\FlujosTp1Test.php" `
  --testdox `
  --do-not-cache-result
```

Resultado documentado: 17 ejecuciones y 17 aserciones, con 15 aprobadas y 2 fallidas.

Las fallas corresponden al Requerimiento 2:

1. `R2-CB-02`: el código acepta y persiste un consumo superior al stock.
2. `R2-CB-04`: el componente de materiales no ofrece una acción para cancelar y descartar el consumo antes de guardarlo.

Estas pruebas se ejecutan con PHPUnit. Las pruebas Selenium de caja negra se ejecutan por separado mediante `pytest`.

