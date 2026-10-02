<?php

declare(strict_types=1);

namespace Sgso\Tests\CajaBlanca;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
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
    public function testCamposObligatoriosRecorrenLaRama422SinGuardar(): void
    {
        $repositorio = new RepositorioProyectoFalso();

        $respuesta = $this->capturar(
            fn () => (new ProyectoController($repositorio))->registrar([])
        );

        self::assertSame(422, $respuesta['codigo']);
        self::assertSame(
            ['nombre', 'tipo', 'ubicacion', 'encargado', 'fechaInicio', 'presupuesto'],
            array_keys($respuesta['cuerpo']['errors'])
        );
        self::assertSame(0, $repositorio->consultasDuplicado);
        self::assertSame(0, $repositorio->creaciones);
    }

    public function testObraDuplicadaRecorreLaRama409SinGuardar(): void
    {
        $repositorio = new RepositorioProyectoFalso(duplicado: true);

        $respuesta = $this->conGeocoderValido(
            fn () => $this->capturar(
                fn () => (new ProyectoController($repositorio))->registrar($this->obraValida())
            )
        );

        self::assertSame(409, $respuesta['codigo']);
        self::assertSame('Obra ya existente', $respuesta['cuerpo']['error']);
        self::assertSame(1, $repositorio->consultasDuplicado);
        self::assertSame(0, $repositorio->creaciones);
    }

    public function testElEstadoDelPedidoSeIgnoraYLaObraArrancaCreada(): void
    {
        $repositorio = new RepositorioProyectoFalso();
        $datos = $this->obraValida() + ['estado' => 'cancelada'];

        $respuesta = $this->conGeocoderValido(
            fn () => $this->capturar(
                fn () => (new ProyectoController($repositorio))->registrar($datos)
            )
        );

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame('creada', $respuesta['cuerpo']['estado']);
        self::assertSame('creada', $repositorio->ultimoCreado['estado']);
    }

    public function testConsumirExactamenteLoAsignadoCubreElLimiteSinExceso(): void
    {
        $controlador = new MaterialObraController($this->baseMaterial(asignado: 10, consumido: 10));

        $respuesta = $this->capturar(fn () => $controlador->listarPorProyecto('1'));
        $material = $respuesta['cuerpo'][0];

        self::assertSame(200, $respuesta['codigo']);
        self::assertEqualsWithDelta(0.0, (float) $material['restante'], 0.001);
        self::assertFalse($material['excedido']);
    }

    public function testConsumirMasDeLoAsignadoDebeSerRechazado(): void
    {
        $db = $this->baseMaterial(asignado: 10, consumido: 0);
        $controlador = new MaterialObraController($db);

        $respuesta = $this->capturar(fn () => $controlador->crearConsumo('1', [
            'cantidad_consumida' => 11,
            'fecha' => '2026-03-02',
        ]));

        self::assertSame(409, $respuesta['codigo']);
        self::assertSame('Stock insuficiente', $respuesta['cuerpo']['error']);
        self::assertSame(
            0,
            (int) $db->query('SELECT COUNT(*) FROM consumo_material WHERE cantidad_consumida > 0')->fetchColumn(),
            'El consumo no debe persistirse cuando supera la disponibilidad'
        );
    }

    public function testIntentosFallidosCubrenAntesEnYDespuesDelLimite(): void
    {
        self::assertSame(0, PoliticaIntentos::segundosDeEspera(4, 0));
        self::assertSame(60, PoliticaIntentos::segundosDeEspera(5, 0));
        self::assertSame(1, PoliticaIntentos::segundosDeEspera(5, 59));
        self::assertSame(0, PoliticaIntentos::segundosDeEspera(5, 60));
        self::assertSame(0, PoliticaIntentos::segundosDeEspera(50, PoliticaIntentos::VENTANA));
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
        self::assertTrue(stream_wrapper_unregister('https'));
        self::assertTrue(stream_wrapper_register('https', FlujoHttpsFalso::class));
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
