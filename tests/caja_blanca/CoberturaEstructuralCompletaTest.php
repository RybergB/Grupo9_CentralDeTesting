<?php

declare(strict_types=1);

namespace Sgso\Tests\CajaBlanca;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sgso\Http\ManejadorErrores;
use Sgso\Http\Paginacion;
use Sgso\Monitoreo\Sentry;
use Sgso\Reglas\Certificacion;
use Sgso\Reglas\CicloDeVida;
use Sgso\Reglas\ConsumoMaquinaria;
use Sgso\Reglas\Enlaces;
use Sgso\Reglas\Permisos;
use Sgso\Reglas\ProtocoloIncidencias;
use Sgso\Ruteo\Despachador;
use Sgso\Ruteo\Resolucion;
use Sgso\Ruteo\Ruta;
use Sgso\Seguridad\PoliticaContrasena;
use Sgso\Seguridad\PoliticaIntentos;
use Sgso\Seguridad\SecretoJwt;
use Throwable;

/**
 * Suite adicional de caja blanca orientada a cobertura estructural.
 *
 * Cada fila de un proveedor es un caso independiente. PHPUnit la muestra por
 * separado con --testdox, para que cada combinación verdadera/falsa tenga su
 * propio resultado. La suite no reemplaza ni modifica FlujosTp1Test.php.
 */
final class CoberturaEstructuralCompletaTest extends TestCase
{
    /** @return iterable<string, array{string, string, int|float|bool|string|null, mixed}> */
    public static function decisionesCicloDeVida(): iterable
    {
        foreach ([...CicloDeVida::ESTADOS, 'desconocida'] as $estado) {
            yield "esEstado {$estado}" => ['esEstado', $estado, null, in_array($estado, CicloDeVida::ESTADOS, true)];
            yield "esTerminal {$estado}" => ['esTerminal', $estado, null, in_array($estado, CicloDeVida::TERMINALES, true)];
            yield "cargar planificacion desde {$estado}" => [
                'alCargarPlanificacion', $estado, null,
                $estado === CicloDeVida::CREADA ? CicloDeVida::PLANIFICACION : null,
            ];
            yield "borrar planificacion desde {$estado}" => [
                'alBorrarPlanificacion', $estado, null,
                $estado === CicloDeVida::PLANIFICACION ? CicloDeVida::CREADA : null,
            ];
            yield "acepta avance {$estado}" => [
                'aceptaAvance', $estado, null, $estado !== CicloDeVida::CANCELADA,
            ];
            yield "puede cancelar {$estado}" => [
                'puedeCancelar', $estado, null, in_array($estado, CicloDeVida::CANCELABLES, true),
            ];
            yield "puede enviar reporte final {$estado}" => [
                'puedeEnviarReporteFinal', $estado, null, $estado === CicloDeVida::EN_EJECUCION,
            ];
        }

        foreach ([-0.01, 0.0, 0.01, 100.0] as $avance) {
            yield "arranque en planificacion con avance {$avance}" => [
                'arrancaPorAvance', CicloDeVida::PLANIFICACION, $avance, $avance > 0.0,
            ];
        }
        yield 'avance positivo fuera de planificacion' => [
            'arrancaPorAvance', CicloDeVida::EN_EJECUCION, 1.0, false,
        ];

        foreach ([-1, 0, 1, 2] as $periodos) {
            yield "pausar ejecucion con {$periodos} periodos" => [
                'debePausar', CicloDeVida::EN_EJECUCION, $periodos, $periodos > 0,
            ];
            yield "reactivar pausada con {$periodos} periodos" => [
                'debeReactivar', CicloDeVida::PAUSADA, $periodos, $periodos === 0,
            ];
        }
        yield 'no pausar estado fuera de marcha' => ['debePausar', CicloDeVida::CREADA, 1, false];
        yield 'no reactivar estado que no esta pausado' => ['debeReactivar', CicloDeVida::EN_EJECUCION, 0, false];
        yield 'reactivar con reporte pendiente' => ['destinoAlReactivar', '', true, CicloDeVida::EN_REVISION];
        yield 'reactivar sin reporte pendiente' => ['destinoAlReactivar', '', false, CicloDeVida::EN_EJECUCION];
        yield 'aprobar final en revision' => [
            'destinoTrasResolverFinal', CicloDeVida::EN_REVISION, 'aprobado', CicloDeVida::FINALIZADA,
        ];
        yield 'rechazar final en revision' => [
            'destinoTrasResolverFinal', CicloDeVida::EN_REVISION, 'rechazado', CicloDeVida::EN_EJECUCION,
        ];
        yield 'resolucion desconocida usa rama no aprobada' => [
            'destinoTrasResolverFinal', CicloDeVida::EN_REVISION, 'otra', CicloDeVida::EN_EJECUCION,
        ];
        yield 'resolver final fuera de revision no cambia estado' => [
            'destinoTrasResolverFinal', CicloDeVida::PAUSADA, 'aprobado', null,
        ];
    }

    #[DataProvider('decisionesCicloDeVida')]
    public function testCadaDecisionDelCicloDeVida(
        string $operacion,
        string $estado,
        int|float|bool|string|null $dato,
        mixed $esperado
    ): void {
        $obtenido = match ($operacion) {
            'esEstado' => CicloDeVida::esEstado($estado),
            'esTerminal' => CicloDeVida::esTerminal($estado),
            'alCargarPlanificacion' => CicloDeVida::alCargarPlanificacion($estado),
            'alBorrarPlanificacion' => CicloDeVida::alBorrarPlanificacion($estado),
            'aceptaAvance' => CicloDeVida::aceptaAvance($estado),
            'puedeCancelar' => CicloDeVida::puedeCancelar($estado),
            'puedeEnviarReporteFinal' => CicloDeVida::puedeEnviarReporteFinal($estado),
            'arrancaPorAvance' => CicloDeVida::arrancaPorAvance($estado, (float) $dato),
            'debePausar' => CicloDeVida::debePausar($estado, (int) $dato),
            'debeReactivar' => CicloDeVida::debeReactivar($estado, (int) $dato),
            'destinoAlReactivar' => CicloDeVida::destinoAlReactivar((bool) $dato),
            'destinoTrasResolverFinal' => CicloDeVida::destinoTrasResolverFinal($estado, (string) $dato),
            default => throw new \LogicException("Operación de prueba desconocida: {$operacion}"),
        };

        self::assertSame($esperado, $obtenido);
    }

    /** @return iterable<string, array{?string, list<string>, bool}> */
    public static function matrizPermisos(): iterable
    {
        $roles = [...Permisos::ROLES, null, 'RolInventado'];
        foreach (Permisos::grupos() as $nombre => $grupo) {
            foreach ($roles as $rol) {
                $etiqueta = $rol ?? 'sin rol';
                yield "{$nombre}: {$etiqueta}" => [$rol, $grupo, $rol !== null && in_array($rol, $grupo, true)];
            }
        }
    }

    #[DataProvider('matrizPermisos')]
    public function testCadaCombinacionDeRolYGrupo(?string $rol, array $grupo, bool $esperado): void
    {
        self::assertSame($esperado, Permisos::puede($rol, $grupo));
    }

    public function testLaTablaDeGruposExponeTodosLosPermisosDefinidos(): void
    {
        self::assertSame([
            'GESTION_OBRA' => Permisos::GESTION_OBRA,
            'AVANCE' => Permisos::AVANCE,
            'DOC' => Permisos::DOC,
            'REPORTE_APROBAR' => Permisos::REPORTE_APROBAR,
            'ADMIN' => Permisos::ADMIN,
        ], Permisos::grupos());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function enlacesSegurosEInseguros(): iterable
    {
        yield 'https valido' => ['https://ejemplo.com/documento.pdf', true];
        yield 'http valido' => ['http://ejemplo.com', true];
        yield 'https mayuscula' => ['HTTPS://ejemplo.com/ruta', true];
        yield 'puerto y consulta' => ['https://ejemplo.com:8443/a?b=1', true];
        yield 'vacio' => ['', false];
        yield 'espacio inicial' => [' https://ejemplo.com', false];
        yield 'espacio intermedio' => ['https://ejemplo .com', false];
        yield 'salto de linea' => ["https://ejemplo.com\nmal", false];
        yield 'texto sin URL' => ['documento.pdf', false];
        yield 'host ausente' => ['https:///ruta', false];
        yield 'javascript' => ['javascript://host/alert(1)', false];
        yield 'data' => ['data://host/texto', false];
        yield 'ftp' => ['ftp://ejemplo.com/archivo', false];
        yield 'barra relativa' => ['/documentos/uno.pdf', false];
    }

    #[DataProvider('enlacesSegurosEInseguros')]
    public function testCadaVarianteDeEnlace(string $url, bool $esperado): void
    {
        self::assertSame($esperado, Enlaces::esSeguro($url));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function contrasenasPorRama(): iterable
    {
        $corta = 'Debe tener al menos ' . PoliticaContrasena::LARGO_MINIMO . ' caracteres';
        yield 'vacia' => ['', $corta];
        yield 'nueve ASCII' => ['123456789', $corta];
        yield 'nueve caracteres multibyte' => ['añoañoañ', $corta];
        yield 'comun exacta' => ['password123', 'Es una de las contraseñas más usadas: elegí otra'];
        yield 'comun con mayusculas y espacios' => ['  PASSWORD123  ', 'Es una de las contraseñas más usadas: elegí otra'];
        yield 'caracter repetido diez veces' => ['aaaaaaaaaa', 'No puede ser un mismo carácter repetido'];
        yield 'caracter Unicode repetido' => ['ññññññññññ', 'No puede ser un mismo carácter repetido'];
        yield 'largo minimo valido' => ['Abcdef1234', null];
        yield 'frase valida' => ['obra-segura-2026', null];
    }

    #[DataProvider('contrasenasPorRama')]
    public function testCadaRamaDeLaPoliticaDeContrasena(string $contrasena, ?string $esperado): void
    {
        self::assertSame($esperado, PoliticaContrasena::validar($contrasena));
    }

    /** @return iterable<string, array{int, int}> */
    public static function esperaTotalPorFallos(): iterable
    {
        yield 'menos uno' => [-1, 0];
        yield 'cero' => [0, 0];
        yield 'cuatro' => [4, 0];
        yield 'cinco limite inferior' => [5, 60];
        yield 'seis' => [6, 120];
        yield 'siete' => [7, 240];
        yield 'ocho' => [8, 480];
        yield 'nueve alcanza tope' => [9, 900];
        yield 'quince limita exponente' => [15, 900];
        yield 'cincuenta sigue en tope' => [50, 900];
    }

    #[DataProvider('esperaTotalPorFallos')]
    public function testEsperaExponencialYTopes(int $fallos, int $esperado): void
    {
        self::assertSame($esperado, PoliticaIntentos::espera($fallos));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function esperaRestante(): iterable
    {
        yield 'menos del umbral' => [4, 0, 0];
        yield 'quinto fallo instante cero' => [5, 0, 60];
        yield 'quinto fallo antes de vencer' => [5, 59, 1];
        yield 'quinto fallo al vencer' => [5, 60, 0];
        yield 'quinto fallo despues de vencer' => [5, 61, 0];
        yield 'sexto fallo parcial' => [6, 20, 100];
        yield 'tope antes de ventana' => [50, 899, 1];
        yield 'ventana exacta olvida' => [50, 900, 0];
        yield 'despues de ventana olvida' => [50, 901, 0];
    }

    #[DataProvider('esperaRestante')]
    public function testEsperaRestanteSegunTiempo(int $fallos, int $transcurrido, int $esperado): void
    {
        self::assertSame($esperado, PoliticaIntentos::segundosDeEspera($fallos, $transcurrido));
    }

    /** @return iterable<string, array{float, float, float}> */
    public static function consumosPorHora(): iterable
    {
        yield 'horas positivas' => [100.0, 4.0, 25.0];
        yield 'hora fraccionaria' => [10.0, 0.5, 20.0];
        yield 'cero horas' => [100.0, 0.0, 0.0];
        yield 'horas negativas' => [100.0, -1.0, 0.0];
        yield 'combustible cero' => [0.0, 8.0, 0.0];
        yield 'combustible negativo conservado por formula' => [-10.0, 2.0, -5.0];
    }

    #[DataProvider('consumosPorHora')]
    public function testTodasLasRamasDeConsumoPorHora(float $combustible, float $horas, float $esperado): void
    {
        self::assertSame($esperado, ConsumoMaquinaria::porHora($combustible, $horas));
    }

    /** @return iterable<string, array{float, float, bool}> */
    public static function consumosAnomalos(): iterable
    {
        yield 'sin promedio' => [100.0, 0.0, false];
        yield 'promedio negativo' => [100.0, -1.0, false];
        yield 'debajo del umbral' => [14.99, 10.0, false];
        yield 'igual al umbral' => [15.0, 10.0, false];
        yield 'apenas sobre umbral' => [15.01, 10.0, true];
        yield 'muy por encima' => [100.0, 10.0, true];
    }

    #[DataProvider('consumosAnomalos')]
    public function testLimitesDeConsumoAnomalo(float $porHora, float $promedio, bool $esperado): void
    {
        self::assertSame($esperado, ConsumoMaquinaria::esAnomalo($porHora, $promedio));
    }

    /** @return iterable<string, array{float, float, float}> */
    public static function montosCertificados(): iterable
    {
        yield 'cero avance' => [100000.0, 0.0, 0.0];
        yield 'cien por ciento' => [100000.0, 100.0, 100000.0];
        yield 'avance parcial' => [100000.0, 25.0, 25000.0];
        yield 'redondeo a centavos' => [10.0, 33.333, 3.33];
        yield 'avance mayor a cien se calcula' => [100.0, 150.0, 150.0];
        yield 'avance negativo se calcula' => [100.0, -10.0, -10.0];
    }

    #[DataProvider('montosCertificados')]
    public function testCalculoYLimitesDeCertificacion(float $presupuesto, float $avance, float $esperado): void
    {
        self::assertSame($esperado, Certificacion::monto($presupuesto, $avance));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function avisosPorGravedad(): iterable
    {
        yield 'alta' => ['alta', [Permisos::GERENTE, Permisos::PERSONAL_ADMINISTRATIVO]];
        yield 'media' => ['media', [Permisos::PERSONAL_ADMINISTRATIVO]];
        yield 'baja' => ['baja', []];
        yield 'desconocida' => ['critica', []];
        yield 'vacia' => ['', []];
        yield 'mayusculas caen en default' => ['ALTA', []];
    }

    #[DataProvider('avisosPorGravedad')]
    public function testCadaRamaDelProtocoloDeIncidencias(string $gravedad, array $esperado): void
    {
        self::assertSame($esperado, ProtocoloIncidencias::rolesAAvisar($gravedad));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function consultasDePaginacion(): iterable
    {
        yield 'sin parametros' => [[], 'sin_paginacion'];
        yield 'parametros vacios' => [['limite' => '', 'desde' => ''], 'sin_paginacion'];
        yield 'limite minimo entero' => [['limite' => 1], 'LIMIT 1 OFFSET 0'];
        yield 'limite minimo texto' => [['limite' => '1'], 'LIMIT 1 OFFSET 0'];
        yield 'limite maximo' => [['limite' => '200', 'desde' => '0'], 'LIMIT 200 OFFSET 0'];
        yield 'limite sobre maximo se recorta' => [['limite' => '201', 'desde' => '7'], 'LIMIT 200 OFFSET 7'];
        yield 'limite de nueve digitos se recorta' => [['limite' => '999999999'], 'LIMIT 200 OFFSET 0'];
        yield 'diez digitos invalido' => [['limite' => '1000000000'], 'error:limite'];
        yield 'limite cero' => [['limite' => '0'], 'error:limite'];
        yield 'limite negativo' => [['limite' => '-1'], 'error:limite'];
        yield 'limite decimal' => [['limite' => '1.5'], 'error:limite'];
        yield 'limite booleano' => [['limite' => true], 'error:limite'];
        yield 'desde sin limite' => [['desde' => '1'], 'error:limite'];
        yield 'desde negativo' => [['limite' => '10', 'desde' => '-1'], 'error:desde'];
        yield 'desde decimal' => [['limite' => '10', 'desde' => '1.5'], 'error:desde'];
        yield 'ambos invalidos' => [['limite' => 'x', 'desde' => 'y'], 'error:desde,limite'];
    }

    #[DataProvider('consultasDePaginacion')]
    public function testEntradasValidasEInvalidasDePaginacion(array $consulta, string $esperado): void
    {
        $resultado = Paginacion::desdeConsulta($consulta);
        if ($resultado === null) {
            $normalizado = 'sin_paginacion';
        } elseif (is_array($resultado)) {
            $claves = array_keys($resultado);
            sort($claves);
            $normalizado = 'error:' . implode(',', $claves);
        } else {
            $normalizado = trim($resultado->sql());
        }

        self::assertSame($esperado, $normalizado);
    }

    public function testAplicarPaginacionCuentaElTotalYDevuelveElFragmentoSql(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE elementos (id INTEGER PRIMARY KEY, activo INTEGER)');
        $db->exec('INSERT INTO elementos (activo) VALUES (1), (1), (0)');
        $pagina = Paginacion::desdeConsulta(['limite' => '1', 'desde' => '1']);
        self::assertInstanceOf(Paginacion::class, $pagina);

        $sql = $pagina->aplicar(
            $db,
            'SELECT COUNT(*) FROM elementos WHERE activo = ?',
            [1]
        );

        self::assertSame([' LIMIT 1 OFFSET 1', 2], [$sql, $pagina->total()]);
    }

    /** @return list<Ruta> */
    private static function rutasDePrueba(): array
    {
        return [
            new Ruta('GET', '/', null, true, 'raiz'),
            new Ruta('GET', '/health', null, true, 'salud'),
            new Ruta('GET', '/proyectos/fijos', Permisos::GESTION_OBRA, false, 'literal'),
            new Ruta('GET', '/proyectos/{id}', Permisos::GESTION_OBRA, false, 'ver'),
            new Ruta('PUT', '/proyectos/{id}', Permisos::GESTION_OBRA, false, 'editar'),
            new Ruta('GET', '/proyectos/{id}/avances/{avance}', Permisos::AVANCE, false, 'avance'),
        ];
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function resolucionesDeRuta(): iterable
    {
        yield 'raiz encontrada' => ['GET', '/', 'encontrada:raiz:'];
        yield 'literal encontrada' => ['GET', '/health', 'encontrada:salud:'];
        yield 'literal prevalece sobre parametro' => ['GET', '/proyectos/fijos', 'encontrada:literal:'];
        yield 'un parametro' => ['GET', '/proyectos/42', 'encontrada:ver:id=42'];
        yield 'dos parametros' => ['GET', '/proyectos/7/avances/9', 'encontrada:avance:id=7,avance=9'];
        yield 'otro metodo misma ruta' => ['PUT', '/proyectos/42', 'encontrada:editar:id=42'];
        yield 'metodo no permitido' => ['DELETE', '/proyectos/42', 'metodo_no_permitido::'];
        yield 'ruta inexistente' => ['GET', '/inexistente', 'no_encontrada::'];
        yield 'faltan segmentos' => ['GET', '/proyectos', 'no_encontrada::'];
        yield 'sobran segmentos' => ['GET', '/proyectos/1/extra', 'no_encontrada::'];
        yield 'parametro vacio' => ['GET', '/proyectos/', 'no_encontrada::'];
        yield 'segmento literal diferente' => ['GET', '/salud', 'no_encontrada::'];
    }

    #[DataProvider('resolucionesDeRuta')]
    public function testTodasLasSalidasDelDespachador(string $metodo, string $camino, string $esperado): void
    {
        $resultado = Despachador::resolver($metodo, $camino, self::rutasDePrueba());
        $parametros = [];
        foreach ($resultado->parametros as $clave => $valor) {
            $parametros[] = "{$clave}={$valor}";
        }
        $normalizado = implode(':', [
            $resultado->estado,
            $resultado->ruta === null ? '' : $resultado->ruta->manejador,
            implode(',', $parametros),
        ]);

        self::assertSame($esperado, $normalizado);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function publicidadDeRutas(): iterable
    {
        yield 'raiz publica' => ['/', true];
        yield 'health publica' => ['/health', true];
        yield 'proyecto protegido' => ['/proyectos/1', false];
        yield 'literal protegido' => ['/proyectos/fijos', false];
        yield 'desconocida no publica' => ['/otra', false];
        yield 'parametro vacio no matchea' => ['/proyectos/', false];
    }

    #[DataProvider('publicidadDeRutas')]
    public function testRamasDeCaminoPublico(string $camino, bool $esperado): void
    {
        self::assertSame($esperado, Despachador::caminoEsPublico($camino, self::rutasDePrueba()));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function secretosJwtInvalidos(): iterable
    {
        yield 'null' => [null, 'no está definido'];
        yield 'vacio' => ['', 'no está definido'];
        yield 'solo espacios' => ['   ', 'no está definido'];
        yield 'ejemplo corto publico' => ['cambiar_esta_clave', 'valor de ejemplo'];
        yield 'ejemplo largo publico' => ['cambiar_por_una_clave_larga_y_secreta', 'valor de ejemplo'];
        yield '31 caracteres' => [str_repeat('x', 31), 'demasiado corto'];
    }

    #[DataProvider('secretosJwtInvalidos')]
    public function testCadaRechazoDelSecretoJwt(?string $secreto, string $fragmento): void
    {
        try {
            SecretoJwt::validar($secreto);
            self::fail('Se esperaba RuntimeException');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($fragmento, $error->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function secretosJwtValidos(): iterable
    {
        yield 'exactamente 32 caracteres' => [str_repeat('a', 32)];
        yield '64 caracteres aleatorios simulados' => [str_repeat('ab', 32)];
        yield 'espacios internos cuentan y no se recortan' => [str_repeat('x', 31) . ' '];
    }

    #[DataProvider('secretosJwtValidos')]
    public function testCadaSecretoJwtValidoSeDevuelveSinCambios(string $secreto): void
    {
        self::assertSame($secreto, SecretoJwt::validar($secreto));
    }

    public function testElManejadorOcultaElDetalleAlCliente(): void
    {
        $respuesta = ManejadorErrores::atender(new RuntimeException('clave-super-secreta'), static function (): void {
        });

        self::assertSame(ManejadorErrores::MENSAJE, $respuesta['error']);
    }

    public function testElManejadorRegistraContextoYDetalleInterno(): void
    {
        $registro = '';
        ManejadorErrores::atender(
            new RuntimeException('detalle-interno'),
            static function (string $linea) use (&$registro): void {
                $registro = $linea;
            },
            ['metodo' => 'POST', 'ruta' => '/prueba']
        );

        self::assertMatchesRegularExpression(
            '/\[referencia [a-f0-9]{12}\] metodo=POST ruta=\/prueba RuntimeException: detalle-interno/',
            $registro
        );
    }

    /** @return iterable<string, array{?string}> */
    public static function dsnInvalidos(): iterable
    {
        yield 'null' => [null];
        yield 'vacio' => [''];
        yield 'espacios' => ['   '];
        yield 'URL que parse_url no puede interpretar' => ['http://'];
        yield 'sin usuario' => ['https://sentry.example/1'];
        yield 'sin proyecto' => ['https://clave@sentry.example'];
        yield 'sin host' => ['https://clave@/1'];
        yield 'texto arbitrario' => ['no-es-un-dsn'];
    }

    #[DataProvider('dsnInvalidos')]
    public function testCadaDsnInvalidoDesactivaSentry(?string $dsn): void
    {
        self::assertNull(Sentry::desdeDsn($dsn));
    }

    public function testSentryEnviaConUrlPuertoCabecerasEtiquetasYSecretoOculto(): void
    {
        $captura = [];
        $sentry = Sentry::desdeDsn(
            'https://clave-publica@sentry.example:8443/99',
            'pruebas',
            ['', 'secreto-db'],
            static function (string $url, string $cuerpo, array $cabeceras) use (&$captura): void {
                $captura = [$url, $cuerpo, $cabeceras];
            }
        );
        self::assertNotNull($sentry);

        $enviado = $sentry->reportar(
            new RuntimeException('fallo con secreto-db'),
            'ref-123',
            ['ruta' => '/proyectos']
        );

        self::assertSame(
            [true, 'https://sentry.example:8443/api/99/envelope/', true, false, true],
            [
                $enviado,
                $captura[0] ?? '',
                str_contains($captura[1] ?? '', '[oculto]'),
                str_contains($captura[1] ?? '', 'secreto-db'),
                str_contains(implode(' ', $captura[2] ?? []), 'clave-publica'),
            ]
        );
    }

    public function testSentryDevuelveFalsoSiFallaElTransporte(): void
    {
        $sentry = Sentry::desdeDsn(
            'https://clave@sentry.example/1',
            'pruebas',
            [],
            static function (): void {
                throw new RuntimeException('red caída');
            }
        );
        self::assertNotNull($sentry);

        self::assertFalse($sentry->reportar(new RuntimeException('error original'), 'ref-456'));
    }

    public function testSentryEjecutaSuTransporteHttpPredeterminadoSinPropagarErroresDeRed(): void
    {
        $sentry = Sentry::desdeDsn('http://clave@127.0.0.1:1/1');
        self::assertNotNull($sentry);

        self::assertTrue($sentry->reportar(new RuntimeException('error controlado'), 'ref-local'));
    }
}
