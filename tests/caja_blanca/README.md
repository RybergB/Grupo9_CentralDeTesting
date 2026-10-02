# Pruebas de caja blanca del TP1

Esta carpeta reúne las pruebas PHP usadas para el análisis de caja blanca:

- `FlujosTp1Test.php`: CB-01 a CB-06.
- `CicloDeVidaTest.php`: contiene CB-07 mediante siete estados evaluados.
- `PoliticaIntentosTest.php`: pruebas complementarias de la política de intentos de inicio de sesión.

Estas pruebas analizan el backend PHP de SCGO. No se ejecutan con `pytest`, porque `pytest` corresponde a las pruebas Selenium de caja negra del proyecto `claseTesting`.

Para ejecutarlas, copie estos archivos dentro de las carpetas equivalentes de `back/tests` del repositorio SCGO y, desde `back`, ejecute PHPUnit. La ejecución focalizada utilizada en el informe fue:

```powershell
php vendor\bin\phpunit tests\CajaBlanca\FlujosTp1Test.php tests\Reglas\CicloDeVidaTest.php --filter '/(FlujosTp1|testSoloSeCancelaUnaObraEnEjecucionOPausada)/' --testdox
```

Resultado documentado: 13 ejecuciones, 31 aserciones, 12 aprobadas y 1 fallida. La falla corresponde a MAT-05: el código permite registrar un consumo mayor al stock disponible cuando el requerimiento exige rechazarlo.
