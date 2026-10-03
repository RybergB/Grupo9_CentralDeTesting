<?php

declare(strict_types=1);

namespace Sgso\Tests\CajaBlanca;

use PDO;
use ReflectionClass;
use RuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Sgso\MaterialObraController;
use Sgso\ProyectoController;
use Sgso\ProyectoRepositoryInterface;
use Sgso\Seguridad\PoliticaIntentos;

/**
 * Pruebas de caja blanca vinculadas con los flujos del TP1.
 *
 * Los datos se eligen a partir de las decisiones observadas en el código:
 * validación, duplicados, estado inicial, límite exacto de material, exceso
 * y frontera del bloqueo por intentos fallidos.
 */
#[CoversClass(ProyectoController::class)]
#[CoversClass(MaterialObraController::class)]
#[CoversClass(PoliticaIntentos::class)]
final class FlujosTp1Test extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function camposObligatorios(): iterable
    {
        foreach (['nombre', 'tipo', 'ubicacion', 'encargado', 'fechaInicio', 'presupuesto'] as $campo) {
            yield "R1-CB-01 | {$campo} ausente" => [$campo];
        }
    }

    #[DataProvider('camposObligatorios')]
    public function testR1Cb01CampoObligatorioAgrupaRespuestaYAusenciaDeGuardado(string $campo): void
    {
        $repositorio = new RepositorioProyectoFalso();
        $datos = $this->obraValida();
        unset($datos[$campo]);

        $respuesta = $this->capturar(
            fn () => (new ProyectoController($repositorio))->registrar($datos)
        );

        self::assertSame(
            [
                'codigo' => 422,
                'errores' => [$campo => 'Obligatorio'],
                'consultasDuplicado' => 0,
                'creaciones' => 0,
            ],
            [
                'codigo' => $respuesta['codigo'],
                'errores' => $respuesta['cuerpo']['errors'] ?? [],
                'consultasDuplicado' => $repositorio->consultasDuplicado,
                'creaciones' => $repositorio->creaciones,
            ]
        );
    }

    #[TestDox('R1-CB-01.7 | todos los campos válidos | obra registrada')]
    public function testR1Cb017TodosLosCamposValidosRegistranLaObra(): void
    {
        $repositorio = new RepositorioProyectoFalso();
        $respuesta = $this->conGeocoderValido(
            fn () => $this->capturar(
                fn () => (new ProyectoController($repositorio))->registrar($this->obraValida())
            )
        );

        self::assertSame(
            [
                'codigo' => 201,
                'id' => '1',
                'consultasDuplicado' => 1,
                'creaciones' => 1,
            ],
            [
                'codigo' => $respuesta['codigo'],
                'id' => $respuesta['cuerpo']['id'] ?? null,
                'consultasDuplicado' => $repositorio->consultasDuplicado,
                'creaciones' => $repositorio->creaciones,
            ]
        );
    }

    public function testR1Cb02ObraDuplicadaAgrupaRespuestaYAusenciaDeGuardado(): void
    {
        $repositorio = new RepositorioProyectoFalso(duplicado: true);
        $respuesta = $this->conGeocoderValido(
            fn () => $this->capturar(
                fn () => (new ProyectoController($repositorio))->registrar($this->obraValida())
            )
        );

        self::assertSame(
            [
                'codigo' => 409,
                'error' => 'Obra ya existente',
                'consultasDuplicado' => 1,
                'creaciones' => 0,
            ],
            [
                'codigo' => $respuesta['codigo'],
                'error' => $respuesta['cuerpo']['error'] ?? null,
                'consultasDuplicado' => $repositorio->consultasDuplicado,
                'creaciones' => $repositorio->creaciones,
            ]
        );
    }

    public function testR1Cb03EstadoInicialAgrupaRespuestaYPersistencia(): void
    {
        $repositorio = new RepositorioProyectoFalso();
        $datos = $this->obraValida() + ['estado' => 'cancelada'];
        $respuesta = $this->conGeocoderValido(
            fn () => $this->capturar(
                fn () => (new ProyectoController($repositorio))->registrar($datos)
            )
        );

        self::assertSame(
            ['codigo' => 201, 'estadoRespuesta' => 'creada', 'estadoPersistido' => 'creada'],
            [
                'codigo' => $respuesta['codigo'],
                'estadoRespuesta' => $respuesta['cuerpo']['estado'] ?? null,
                'estadoPersistido' => $repositorio->ultimoCreado['estado'] ?? null,
            ]
        );
    }

    public function testR2Cb01LimiteExactoDeMaterialAgrupaTodasLasSalidas(): void
    {
        $controlador = new MaterialObraController($this->baseMaterial(asignado: 10, consumido: 10));
        $respuesta = $this->capturar(fn () => $controlador->listarPorProyecto('1'));
        $material = $respuesta['cuerpo'][0];

        self::assertSame(
            ['codigo' => 200, 'restante' => 0.0, 'excedido' => false],
            [
                'codigo' => $respuesta['codigo'],
                'restante' => (float) $material['restante'],
                'excedido' => (bool) $material['excedido'],
            ]
        );
    }

    public function testR2Cb02StockInsuficienteAgrupaRespuestaYAusenciaDePersistencia(): void
    {
        $db = $this->baseMaterial(asignado: 10, consumido: 0);
        $controlador = new MaterialObraController($db);
        $respuesta = $this->capturar(fn () => $controlador->crearConsumo('1', [
            'cantidad_consumida' => 11,
            'fecha' => '2026-03-02',
        ]));

        self::assertSame(
            ['codigo' => 409, 'error' => 'Stock insuficiente', 'consumosPositivos' => 0],
            [
                'codigo' => $respuesta['codigo'],
                'error' => $respuesta['cuerpo']['error'] ?? null,
                'consumosPositivos' => (int) $db->query(
                    'SELECT COUNT(*) FROM consumo_material WHERE cantidad_consumida > 0'
                )->fetchColumn(),
            ]
        );
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function fronterasIntentosFallidos(): iterable
    {
        yield 'R2-CB-03.1 | antes del límite | espera 0 s' => [4, 0, 0];
        yield 'R2-CB-03.2 | en el límite | espera 60 s' => [5, 0, 60];
        yield 'R2-CB-03.3 | un segundo restante | espera 1 s' => [5, 59, 1];
        yield 'R2-CB-03.4 | espera cumplida | espera 0 s' => [5, 60, 0];
        yield 'R2-CB-03.5 | ventana vencida | espera 0 s' => [50, PoliticaIntentos::VENTANA, 0];
    }

    #[DataProvider('fronterasIntentosFallidos')]
    public function testR2Cb03IntentosFallidosPorCombinacion(
        int $fallos,
        int $segundosDesdeUltimoFallo,
        int $esperaEsperada
    ): void {
        self::assertSame(
            $esperaEsperada,
            PoliticaIntentos::segundosDeEspera($fallos, $segundosDesdeUltimoFallo)
        );
    }


    public function testR2Cb04CancelarConsumoDescartaElBorradorSinGuardar(): void
    {
        $archivoBackend = (new ReflectionClass(MaterialObraController::class))->getFileName();
        if ($archivoBackend === false) {
            throw new RuntimeException('No se pudo localizar el backend de SCGO');
        }

        $raizRepositorio = dirname(dirname(dirname($archivoBackend)));
        $ruta = $raizRepositorio . '/FRONT/src/app/components/MaterialesPage.tsx';
        if (!is_file($ruta)) {
            throw new RuntimeException("No se encontró el componente de materiales en {$ruta}");
        }

        $codigo = (string) file_get_contents($ruta);
        preg_match(
            '/function\\s+cancelarConsumo\\s*\\(idAsig:\\s*number\\)\\s*\\{(.*?)\\n\\s*\\}/s',
            $codigo,
            $coincidencia
        );
        $cuerpoCancelar = $coincidencia[1] ?? '';

        self::assertSame(
            [
                'botonCancelarVisible' => true,
                'manejadorCancelarExiste' => true,
                'borradorDescartado' => true,
                'sinLlamadaCrearConsumo' => true,
            ],
            [
                'botonCancelarVisible' => str_contains($codigo, '>Cancelar</Button>'),
                'manejadorCancelarExiste' => $cuerpoCancelar !== '',
                'borradorDescartado' => str_contains($cuerpoCancelar, 'setConsumoInput'),
                'sinLlamadaCrearConsumo' => $cuerpoCancelar !== ''
                    && !str_contains($cuerpoCancelar, 'crearConsumo'),
            ]
        );
    }

    /** @return array<string, mixed> */
    private function obraValida(): array
    {
        return [
            'nombre' => 'Obra TP1 Grupo 9',
            'tipo' => 'Mantenimiento',
            'ubicacion' => 'Posadas, Misiones',
            'encargado' => 'Responsable de prueba',
            'fechaInicio' => date('Y-m-d', strtotime('+1 day')),
            'presupuesto' => '100000',
        ];
    }

    /** @return array{codigo:int,cuerpo:mixed} */
    private function capturar(callable $accion): array
    {
        http_response_code(200);
        ob_start();
        try {
            $accion();
        } finally {
            $salida = (string) ob_get_clean();
        }

        return [
            'codigo' => http_response_code() ?: 200,
            'cuerpo' => json_decode($salida, true),
        ];
    }

    private function conGeocoderValido(callable $accion): mixed
    {
        if (!stream_wrapper_unregister('https')) {
            throw new RuntimeException('No se pudo reemplazar el transporte HTTPS para la prueba');
        }
        if (!stream_wrapper_register('https', FlujoHttpsFalso::class)) {
            stream_wrapper_restore('https');
            throw new RuntimeException('No se pudo registrar el transporte HTTPS falso');
        }
        try {
            return $accion();
        } finally {
            stream_wrapper_restore('https');
        }
    }

    private function baseMaterial(float $asignado, float $consumido): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE material (id_material INTEGER PRIMARY KEY, nombre TEXT, unidad TEXT)');
        $db->exec('CREATE TABLE asignacion_material (
            id_asignacion INTEGER PRIMARY KEY,
            id_proyecto INTEGER,
            id_material INTEGER,
            cantidad_asignada REAL
        )');
        $db->exec('CREATE TABLE consumo_material (
            id_consumo INTEGER PRIMARY KEY,
            id_asignacion INTEGER,
            fecha TEXT,
            cantidad_consumida REAL,
            observaciones TEXT
        )');
        $db->exec("INSERT INTO material VALUES (1, 'Cemento', 'bolsa')");
        $stmt = $db->prepare('INSERT INTO asignacion_material VALUES (1, 1, 1, ?)');
        $stmt->execute([$asignado]);
        $stmt = $db->prepare("INSERT INTO consumo_material VALUES (1, 1, '2026-03-02', ?, NULL)");
        $stmt->execute([$consumido]);

        return $db;
    }
}

/** @internal doble controlable del contrato de persistencia */
final class RepositorioProyectoFalso implements ProyectoRepositoryInterface
{
    public int $consultasDuplicado = 0;
    public int $creaciones = 0;
    /** @var array<string, mixed> */
    public array $ultimoCreado = [];

    public function __construct(private bool $duplicado = false)
    {
    }

    public function listar(?string $busqueda = null): array
    {
        return [];
    }

    public function buscarPorId(string $id): ?array
    {
        return null;
    }

    public function crear(array $datos): array
    {
        $this->creaciones++;
        $this->ultimoCreado = $datos;
        return ['id' => '1'] + $datos;
    }

    public function actualizar(string $id, array $datos): ?array
    {
        return null;
    }

    public function eliminar(string $id): bool
    {
        return false;
    }

    public function existeDuplicado(string $nombre, string $ubicacion, ?string $idExcluido = null): bool
    {
        $this->consultasDuplicado++;
        return $this->duplicado;
    }
}

/** @internal evita que la prueba de ProyectoController dependa de Internet */
final class FlujoHttpsFalso
{
    /** @var resource|null */
    public $context;
    private int $posicion = 0;
    private string $contenido = '[{"display_name":"Posadas, Misiones"}]';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $parte = substr($this->contenido, $this->posicion, $count);
        $this->posicion += strlen($parte);
        return $parte;
    }

    public function stream_eof(): bool
    {
        return $this->posicion >= strlen($this->contenido);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}


