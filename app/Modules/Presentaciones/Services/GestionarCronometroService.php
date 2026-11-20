<?php

namespace App\Modules\Presentaciones\Services;

use App\Modules\Presentaciones\Events\CronometroActualizado;
use App\Modules\Presentaciones\Events\PresentacionEstadoCambiado;
use App\Modules\Presentaciones\Models\Presentacion;
use App\Support\Enums\PresentacionEstadoEnum;

/**
 * Fuente de verdad del cronómetro. El backend decide los timestamps;
 * el frontend solo representa el estado recibido por WebSocket.
 */
class GestionarCronometroService
{
    /**
     * Inicia el cronómetro de la presentación en curso.
     */
    public function iniciar(Presentacion $presentacion): Presentacion
    {
        $this->comprobarPuedeIniciar($presentacion);

        $presentacion->forceFill([
            'cronometro_iniciado_at' => now(),
            'cronometro_finalizado_at' => null,
        ])->save();

        $this->emitirEvento($presentacion);

        return $presentacion;
    }

    /**
     * Detiene el cronómetro en curso.
     */
    public function detener(Presentacion $presentacion): Presentacion
    {
        if (! $presentacion->cronometroCorriendo()) {
            throw new \UnexpectedValueException('El cronómetro de esta presentación no está en curso.');
        }

        $ahora = now();
        $presentacion->cronometro_finalizado_at = $ahora;

        if ($presentacion->excedioTiempoLimite()) {
            $presentacion->descalificado = true;
            $presentacion->motivo_descalificacion = 'Excedió el tiempo máximo de presentación.';
            $presentacion->nota_final = null;
        }

        $presentacion->save();

        $this->emitirEvento($presentacion);

        return $presentacion;
    }

    /**
     * Marca la presentación como descalificada si excedió el tiempo máximo permitido.
     */
    public function descalificarPorTiempo(Presentacion $presentacion): Presentacion
    {
        if (! $presentacion->estaDescalificado()) {
            throw new \UnexpectedValueException('La presentación aún no excedió el tiempo máximo permitido.');
        }

        $presentacion->forceFill([
            'descalificado' => true,
            'motivo_descalificacion' => $presentacion->motivo_descalificacion ?: 'Excedió el tiempo máximo de presentación.',
            'cronometro_finalizado_at' => $presentacion->cronometro_finalizado_at ?? now(),
            'estado' => PresentacionEstadoEnum::FINALIZADO,
            'hora_fin' => $presentacion->hora_fin ?? now()->format('H:i'),
            'nota_final' => null,
        ])->save();

        $this->emitirEvento($presentacion);

        try {
            event(new PresentacionEstadoCambiado($presentacion));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudo transmitir evento WebSocket de descalificación: '.$e->getMessage());
        }

        return $presentacion;
    }

    /**
     * Reinicia el cronómetro por falla técnica o decisión administrativa y vuelve a EN_ESCENARIO.
     */
    public function reiniciar(Presentacion $presentacion): Presentacion
    {
        $datos = [
            'cronometro_iniciado_at' => null,
            'cronometro_finalizado_at' => null,
            'descalificado' => false,
            'motivo_descalificacion' => null,
        ];

        if (in_array($presentacion->estado, [PresentacionEstadoEnum::PARTICIPANDO, PresentacionEstadoEnum::FINALIZADO], true)) {
            $datos['estado'] = PresentacionEstadoEnum::EN_ESCENARIO;
            $datos['hora_fin'] = null;
        }

        $presentacion->forceFill($datos)->save();

        $this->emitirEvento($presentacion);

        try {
            event(new PresentacionEstadoCambiado($presentacion));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudo transmitir evento WebSocket de reinicio: '.$e->getMessage());
        }

        return $presentacion;
    }

    public function emitirEvento(Presentacion $presentacion): void
    {
        try {
            event(new CronometroActualizado($presentacion));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudo transmitir evento WebSocket de cronómetro: '.$e->getMessage());
        }
    }

    /**
     * @return array{
     *     presentacion_id:int,
     *     corriendo:bool,
     *     tiempo_maximo:int,
     *     cronometro_iniciado_at:string|null,
     *     cronometro_finalizado_at:string|null,
     *     segundos_transcurridos:int,
     *     segundos_restantes:int
     * }
     */
    public function obtenerEstado(Presentacion $presentacion): array
    {
        $iniciado = $presentacion->cronometro_iniciado_at;
        $finalizado = $presentacion->cronometro_finalizado_at;

        $corriendo = $iniciado !== null && $finalizado === null;

        if ($iniciado === null) {
            $segundosTranscurridos = 0;
        } else {
            $fin = $finalizado ?? now();
            $segundosTranscurridos = max(0, (int) $iniciado->diffInSeconds($fin));
        }

        $totalSegundos = $presentacion->tiempo_maximo * 60;

        return [
            'presentacion_id' => $presentacion->id,
            'corriendo' => $corriendo,
            'tiempo_maximo' => $presentacion->tiempo_maximo,
            'cronometro_iniciado_at' => $iniciado?->toIso8601String(),
            'cronometro_finalizado_at' => $finalizado?->toIso8601String(),
            'cronometro_iniciado_at_ms' => $iniciado?->valueOf(),
            'cronometro_finalizado_at_ms' => $finalizado?->valueOf(),
            'server_now_ms' => (int) (microtime(true) * 1000),
            'segundos_transcurridos' => $segundosTranscurridos,
            'segundos_restantes' => max(0, $totalSegundos - $segundosTranscurridos),
            'excedio_tiempo' => $presentacion->excedioTiempoLimite(),
            'descalificado' => $presentacion->estaDescalificado(),
        ];
    }

    private function comprobarPuedeIniciar(Presentacion $presentacion): void
    {
        if ($presentacion->cronometroCorriendo() || $presentacion->cronometro_finalizado_at !== null) {
            throw new \UnexpectedValueException('El cronómetro de esta presentación ya fue utilizado.');
        }

        $permitidos = [
            PresentacionEstadoEnum::EN_ESCENARIO,
            PresentacionEstadoEnum::PARTICIPANDO,
        ];

        if (! in_array($presentacion->estado, $permitidos, true)) {
            throw new \UnexpectedValueException('El cronómetro solo puede iniciarse cuando la presentación está en escenario o participando.');
        }
    }
}