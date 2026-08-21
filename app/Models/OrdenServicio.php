<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([
    'folio',
    'cotizacion_id',
    'orden_canonica_id',
    'external_id',
    'origen',
    'cliente_id',
    'equipo_id',
    'creado_por_user_id',
    'partner_recepcion_id',
    'partner_tecnico_id',
    'tipo_recepcion',
    'estado',
    'total_cliente',
    'costo_tecnico',
    'comision_logistica',
    'comision_recepcion',
    'nota_recepcion',
    'modelo_financiero',
    'utilidad_estimada',
    'costos_incompletos',
    'utilidad_neta',
    'notas',
    'fecha_recepcion',
    'fecha_entrega',
    'finanzas_generadas',
])]
class OrdenServicio extends Model
{
    public const MODELO_FINANCIERO_LEGACY = 'legacy';

    public const MODELO_FINANCIERO_COSTOS_POR_LINEA = 'costos_por_linea';

    protected $table = 'ordenes_servicio';

    protected $attributes = [
        'modelo_financiero' => self::MODELO_FINANCIERO_LEGACY,
    ];

    /** Valid order statuses, in workflow order. */
    public const ESTADOS = [
        'recibido',
        'en_diagnostico',
        'cotizacion_pendiente',
        'cotizacion_aprobada',
        'en_proceso',
        'en_fixop',
        'listo_para_entregar',
        'entregado',
        'cancelado',
    ];

    /**
     * Human readable labels per status (same wording the panel badge uses).
     *
     * @var array<string, string>
     */
    public const ESTADOS_LABELS = [
        'recibido' => 'Recibido',
        'en_diagnostico' => 'En diagnóstico',
        'cotizacion_pendiente' => 'Cotización pendiente',
        'cotizacion_aprobada' => 'Cotización aprobada',
        'en_proceso' => 'En proceso',
        'en_fixop' => 'En Fixop',
        'listo_para_entregar' => 'Listo para entregar',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelado',
    ];

    /**
     * Get the human readable label for the current status.
     */
    public function estadoLabel(): string
    {
        return self::ESTADOS_LABELS[$this->estado] ?? str_replace('_', ' ', ucfirst((string) $this->estado));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_cliente' => 'decimal:2',
            'costo_tecnico' => 'decimal:2',
            'comision_logistica' => 'decimal:2',
            'comision_recepcion' => 'decimal:2',
            'utilidad_estimada' => 'decimal:2',
            'costos_incompletos' => 'boolean',
            'utilidad_neta' => 'decimal:2',
            'fecha_recepcion' => 'datetime',
            'fecha_entrega' => 'datetime',
            'finanzas_generadas' => 'boolean',
        ];
    }

    public function usaModeloFinancieroLegacy(): bool
    {
        return $this->modelo_financiero === self::MODELO_FINANCIERO_LEGACY;
    }

    public function usaCostosPorLinea(): bool
    {
        return $this->modelo_financiero === self::MODELO_FINANCIERO_COSTOS_POR_LINEA;
    }

    public function setModeloFinancieroAttribute(string $modelo): void
    {
        if (! in_array($modelo, [
            self::MODELO_FINANCIERO_LEGACY,
            self::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        ], true)) {
            throw new InvalidArgumentException("Modelo financiero no válido: {$modelo}.");
        }

        $this->attributes['modelo_financiero'] = $modelo;
    }

    /**
     * Get the client assigned to the service order.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** Get the current quote linked to this service order, when applicable. */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function ordenCanonica(): BelongsTo
    {
        return $this->belongsTo(self::class, 'orden_canonica_id');
    }

    /**
     * Get the equipment assigned to the service order.
     */
    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    /**
     * Get the user that created the service order.
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    /**
     * Get the reception partner assigned to the service order.
     */
    public function partnerRecepcion(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_recepcion_id');
    }

    /**
     * Get the technical partner assigned to the service order.
     */
    public function partnerTecnico(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_tecnico_id');
    }

    /**
     * Get the service details for the order.
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(OrdenServicioDetalle::class);
    }

    /**
     * Get the parts used for the order.
     */
    public function refacciones(): HasMany
    {
        return $this->hasMany(OrdenServicioRefaccion::class);
    }

    /**
     * Get the status history for the order.
     */
    public function historialEstados(): HasMany
    {
        return $this->hasMany(HistorialEstado::class);
    }

    /**
     * Get the financial movements generated by the service order.
     */
    public function movimientosFinancieros(): HasMany
    {
        return $this->hasMany(MovimientoFinanciero::class);
    }

    /** Delivery-generation lotes (FASE H.2): one per GenerarFinanzasOrdenServicio run. */
    public function generacionesFinancieras(): HasMany
    {
        return $this->hasMany(GeneracionFinancieraOrden::class);
    }

    /** Post-delivery adjustment events (FASE H.1): reembolsos, correcciones, anulaciones. */
    public function ajustesFinancieros(): HasMany
    {
        return $this->hasMany(AjusteFinancieroOrden::class);
    }

    /** Gross sale of a delivered order, unaffected by later refunds. */
    public function ventaBruta(): float
    {
        return (float) $this->total_cliente;
    }

    /** Structured sum of all reembolso_cliente adjustments registered for this order. */
    public function totalReembolsado(): float
    {
        if ($this->relationLoaded('ajustesFinancieros')) {
            return (float) $this->ajustesFinancieros
                ->where('tipo', AjusteFinancieroOrden::TIPO_REEMBOLSO_CLIENTE)
                ->sum('delta');
        }

        return (float) $this->ajustesFinancieros()
            ->where('tipo', AjusteFinancieroOrden::TIPO_REEMBOLSO_CLIENTE)
            ->sum('delta');
    }

    /**
     * utilidad_neta es el snapshot de utilidad al momento del cierre/entrega:
     * nunca se reescribe por un reembolso, sólo por correcciones de costo o
     * comisión que corrigen la operación original.
     *
     * utilidadEfectiva() es el resultado económico actual: utilidad_neta menos
     * los reembolsos estructurados de esta orden. No se limita a cero -- los
     * costos ya ocurrieron aunque se haya devuelto toda la venta.
     */
    public function utilidadEfectiva(): float
    {
        return round((float) $this->utilidad_neta - $this->totalReembolsado(), 2);
    }

    /** Net sale after refunds, never negative. */
    public function ventaNeta(): float
    {
        return max(round($this->ventaBruta() - $this->totalReembolsado(), 2), 0.0);
    }

    /** Derived refund status: 'sin_reembolso' | 'reembolso_parcial' | 'reembolso_total'. */
    public function estadoReembolso(): string
    {
        $reembolsado = $this->totalReembolsado();

        if ($reembolsado <= 0.0) {
            return 'sin_reembolso';
        }

        return $reembolsado >= $this->ventaBruta() ? 'reembolso_total' : 'reembolso_parcial';
    }

    /** Determine whether an open quoted order has movements other than its own advances. */
    public function tieneMovimientosFinancierosIncompatibles(?int $cotizacionId = null): bool
    {
        $cotizacionId ??= $this->cotizacion_id;

        return $this->movimientosFinancieros()
            ->where(function ($query) use ($cotizacionId): void {
                self::aplicarMovimientosFinancierosIncompatibles($query, $cotizacionId);
            })
            ->exists();
    }

    public function scopeSinMovimientosFinancierosIncompatibles(Builder $query, ?int $cotizacionId): Builder
    {
        return $query->whereDoesntHave('movimientosFinancieros', function ($query) use ($cotizacionId): void {
            $query->where(function ($query) use ($cotizacionId): void {
                self::aplicarMovimientosFinancierosIncompatibles($query, $cotizacionId);
            });
        });
    }

    private static function aplicarMovimientosFinancierosIncompatibles($query, ?int $cotizacionId): void
    {
        $query->where('tipo', '!=', 'ingreso')
            ->orWhere('categoria', '!=', 'anticipo')
            ->orWhereNull('cotizacion_id')
            ->orWhere('cotizacion_id', '!=', $cotizacionId);
    }
}
