<?php

namespace Tests\Feature\Presentaciones;

use App\Modules\Jurados\Models\JuradoPresentacion;
use App\Modules\Presentaciones\Events\CronometroActualizado;
use App\Modules\Presentaciones\Models\Presentacion;
use App\Modules\Usuarios\Models\Usuario;
use App\Support\Enums\PresentacionEstadoEnum;
use App\Support\Enums\RolEnum;
use Database\Seeders\CategoriaParametrosSeeder;
use Database\Seeders\DepartamentoSeeder;
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreaPresentacion;
use Tests\TestCase;

class CronometroTest extends TestCase
{
    use CreaPresentacion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            DepartamentoSeeder::class,
            FaseSeeder::class,
            CategoriaParametrosSeeder::class,
        ]);
    }

    #[Test]
    public function un_admin_con_control_de_tiempo_puede_iniciar_y_detener_el_cronometro(): void
    {
        $admin = Usuario::factory()->administrador()->create(['puede_controlar_tiempo' => true]);
        $presentacion = $this->presentacionPreseleccion();
        $presentacion->forceFill(['estado' => PresentacionEstadoEnum::EN_ESCENARIO])->save();

        $this->actingAs($admin)
            ->post(route('presentaciones.cronometro.iniciar', $presentacion))
            ->assertRedirect();

        $presentacion->refresh();
        $this->assertNotNull($presentacion->cronometro_iniciado_at);

        $this->actingAs($admin)
            ->post(route('presentaciones.cronometro.detener', $presentacion))
            ->assertRedirect();

        $presentacion->refresh();
        $this->assertNotNull($presentacion->cronometro_finalizado_at);
    }

    #[Test]
    public function un_admin_sin_control_de_tiempo_no_puede_manejar_el_cronometro(): void
    {
        $admin = Usuario::factory()->administrador()->create(['puede_controlar_tiempo' => false]);
        $presentacion = $this->presentacionPreseleccion();
        $presentacion->forceFill(['estado' => PresentacionEstadoEnum::EN_ESCENARIO])->save();

        $this->actingAs($admin)
            ->post(route('presentaciones.cronometro.iniciar', $presentacion))
            ->assertForbidden();
    }

    #[Test]
    public function un_jurado_controlador_puede_manejar_el_cronometro_de_su_presentacion(): void
    {
        $jurado = Usuario::factory()->create(['rol' => RolEnum::JURADO, 'puede_controlar_tiempo' => true]);
        $presentacion = $this->presentacionPreseleccion();

        JuradoPresentacion::create([
            'id_presentacion' => $presentacion->id,
            'id_usuario' => $jurado->id,
            'es_controlador' => true,
        ]);

        $presentacion->forceFill(['estado' => PresentacionEstadoEnum::EN_ESCENARIO])->save();

        $this->actingAs($jurado)
            ->post(route('presentaciones.cronometro.iniciar', $presentacion))
            ->assertRedirect();

        $this->assertNotNull($presentacion->fresh()->cronometro_iniciado_at);
    }

    #[Test]
    public function un_jurado_no_controlador_no_puede_manejar_el_cronometro(): void
    {
        $jurado = Usuario::factory()->create(['rol' => RolEnum::JURADO, 'puede_controlar_tiempo' => false]);
        $presentacion = $this->presentacionPreseleccion();

        JuradoPresentacion::create([
            'id_presentacion' => $presentacion->id,
            'id_usuario' => $jurado->id,
            'es_controlador' => false,
        ]);

        $presentacion->forceFill(['estado' => PresentacionEstadoEnum::EN_ESCENARIO])->save();

        $this->actingAs($jurado)
            ->post(route('presentaciones.cronometro.iniciar', $presentacion))
            ->assertForbidden();
    }

    #[Test]
    public function no_se_puede_iniciar_si_el_cronometro_ya_fue_utilizado(): void
    {
        $admin = Usuario::factory()->administrador()->create(['puede_controlar_tiempo' => true]);
        $presentacion = $this->presentacionPreseleccion();
        $presentacion->forceFill([
            'estado' => PresentacionEstadoEnum::PARTICIPANDO,
            'cronometro_iniciado_at' => now()->subMinutes(2),
            'cronometro_finalizado_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->from(route('admin.presentaciones.index'))
            ->post(route('presentaciones.cronometro.iniciar', $presentacion))
            ->assertRedirect(route('admin.presentaciones.index'))
            ->assertSessionHas('error');
    }

    #[Test]
    public function iniciar_el_cronometro_emite_el_evento_websocket(): void
    {
        Event::fake([CronometroActualizado::class]);

        $admin = Usuario::factory()->administrador()->create(['puede_controlar_tiempo' => true]);
        $presentacion = $this->presentacionPreseleccion();
        $presentacion->forceFill(['estado' => PresentacionEstadoEnum::EN_ESCENARIO])->save();

        $this->actingAs($admin)
            ->post(route('presentaciones.cronometro.iniciar', $presentacion))
            ->assertRedirect();

        Event::assertDispatched(CronometroActualizado::class);
    }

    #[Test]
    public function peticion_ajax_iniciar_y_detener_retorna_json_con_timestamps_del_servidor(): void
    {
        $admin = Usuario::factory()->administrador()->create(['puede_controlar_tiempo' => true]);
        $presentacion = $this->presentacionPreseleccion();
        $presentacion->forceFill(['estado' => PresentacionEstadoEnum::EN_ESCENARIO])->save();

        // Iniciar vía JSON
        $respuestaIniciar = $this->actingAs($admin)
            ->postJson(route('presentaciones.cronometro.iniciar', $presentacion))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'cronometro' => [
                    'presentacion_id',
                    'corriendo',
                    'tiempo_maximo',
                    'cronometro_iniciado_at',
                    'cronometro_iniciado_at_ms',
                    'server_now_ms',
                    'segundos_restantes',
                ],
                'estado',
                'estado_label',
            ]);

        $this->assertTrue($respuestaIniciar->json('cronometro.corriendo'));
        $this->assertNotNull($respuestaIniciar->json('cronometro.server_now_ms'));
        $this->assertNotNull($respuestaIniciar->json('cronometro.cronometro_iniciado_at_ms'));

        $presentacion->refresh();

        // Detener vía JSON
        $respuestaDetener = $this->actingAs($admin)
            ->postJson(route('presentaciones.cronometro.detener', $presentacion))
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'cronometro' => [
                    'presentacion_id',
                    'corriendo',
                    'cronometro_finalizado_at',
                    'cronometro_finalizado_at_ms',
                    'server_now_ms',
                ],
            ]);

        $this->assertFalse($respuestaDetener->json('cronometro.corriendo'));
        $this->assertNotNull($respuestaDetener->json('cronometro.cronometro_finalizado_at_ms'));
    }

    #[Test]
    public function participante_que_excede_el_tiempo_queda_descalificado_y_no_puede_calificarse(): void
    {
        $admin = Usuario::factory()->administrador()->create(['puede_controlar_tiempo' => true]);
        $jurado = Usuario::factory()->create(['rol' => RolEnum::JURADO]);
        $presentacion = $this->presentacionPreseleccion();

        $jp = JuradoPresentacion::create([
            'id_presentacion' => $presentacion->id,
            'id_usuario' => $jurado->id,
        ]);

        // Simular presentación iniciada hace más minutos que su tiempo_maximo
        $presentacion->forceFill([
            'estado' => PresentacionEstadoEnum::PARTICIPANDO,
            'tiempo_maximo' => 5,
            'cronometro_iniciado_at' => now()->subMinutes(6),
            'cronometro_finalizado_at' => null,
        ])->save();

        // Al reportar exceso de tiempo, queda descalificado y finalizado
        $this->actingAs($admin)
            ->postJson(route('presentaciones.cronometro.excedido', $presentacion))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'descalificado' => true,
            ]);

        $presentacion->refresh();
        $this->assertTrue($presentacion->descalificado);
        $this->assertNull($presentacion->nota_final);
        $this->assertSame(PresentacionEstadoEnum::FINALIZADO, $presentacion->estado);

        // Intentar calificar debe ser bloqueado por DomainException
        $valores = [];
        foreach ($presentacion->inscripcion->categoria->categoriaParametros as $cp) {
            $valores[$cp->id_parametro] = $cp->valor_maximo;
        }

        $this->expectException(\DomainException::class);
        app(\App\Modules\Calificaciones\Services\RegistrarCalificacionService::class)
            ->registrar($jp, $valores, $jurado);
    }

    #[Test]
    public function no_descalifica_si_aun_no_excede_el_tiempo_maximo(): void
    {
        $admin = Usuario::factory()->administrador()->create(['puede_controlar_tiempo' => true]);
        $presentacion = $this->presentacionPreseleccion();

        $presentacion->forceFill([
            'estado' => PresentacionEstadoEnum::PARTICIPANDO,
            'tiempo_maximo' => 5,
            'cronometro_iniciado_at' => now()->subMinutes(2),
            'cronometro_finalizado_at' => null,
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('presentaciones.cronometro.excedido', $presentacion))
            ->assertStatus(422);

        $this->assertFalse($presentacion->fresh()->descalificado);
    }

    #[Test]
    public function admin_puede_reiniciar_cronometro_por_falla_tecnica_y_jurado_no(): void
    {
        $admin = Usuario::factory()->administrador()->create();
        $jurado = Usuario::factory()->create(['rol' => RolEnum::JURADO]);
        $presentacion = $this->presentacionPreseleccion();

        $presentacion->forceFill([
            'estado' => PresentacionEstadoEnum::FINALIZADO,
            'cronometro_iniciado_at' => now()->subMinutes(6),
            'cronometro_finalizado_at' => now(),
            'descalificado' => true,
            'motivo_descalificacion' => 'Excedió el tiempo máximo de presentación.',
        ])->save();

        // Un jurado no tiene acceso a la ruta administrativa de reinicio
        $this->actingAs($jurado)
            ->post(route('admin.presentaciones.cronometro.reiniciar', $presentacion))
            ->assertForbidden();

        // El administrador reinicia el cronómetro y vuelve a EN_ESCENARIO
        $this->actingAs($admin)
            ->post(route('admin.presentaciones.cronometro.reiniciar', $presentacion))
            ->assertRedirect();

        $presentacion->refresh();
        $this->assertSame(PresentacionEstadoEnum::EN_ESCENARIO, $presentacion->estado);
        $this->assertNull($presentacion->cronometro_iniciado_at);
        $this->assertNull($presentacion->cronometro_finalizado_at);
        $this->assertFalse($presentacion->descalificado);
    }
}