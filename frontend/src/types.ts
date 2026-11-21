export type Permiso =
  | 'usuarios.gestionar'
  | 'publicaciones.ver' | 'publicaciones.crear' | 'publicaciones.moderar'
  | 'empleados.ver' | 'empleados.gestionar' | 'nomina.configurar'
  | 'nomina.ver' | 'nomina.calcular' | 'nomina.autorizar' | 'indicadores.ver'   // v12
  | 'nomina.timbrar';   // v14

export interface Usuario {
  id: number;
  name: string;
  email: string;
  rol: 'admin_rh' | 'supervisor' | 'lector' | null;
  rol_id: number | null;
  permisos: Permiso[];
  is_active: boolean;
  bloqueado: boolean;
  locked_until: string | null;
  must_change_password: boolean;
  last_login_at: string | null;
}

export interface Paginado<T> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number; per_page: number };
}

export interface RolesRespuesta {
  permisos: { clave: Permiso; descripcion: string }[];
  roles: { id: number; nombre: string; descripcion: string; permisos: Permiso[] }[];
}

// ───────────────────────── v11: Nómina ─────────────────────────
export type Periodicidad = 'semanal' | 'quincenal' | 'mensual';
export type TipoPeriodo = Periodicidad | 'especial';   // v13
export type TipoContrato = 'capacitacion' | 'prueba' | 'temporal' | 'indeterminado';

export interface EmpleadoResumen {
  id: number; numero_empleado: string; nombre: string; rfc: string | null; puesto: string | null; salario_mensual: number | null;
  periodicidad: Periodicidad; tipo_contrato: TipoContrato; contrato_fin: string | null; fecha_ingreso: string;
  estado: 'activo' | 'baja'; faltantes: string[];
}

export interface Empleado extends EmpleadoResumen {
  nombres: string | null; apellido_paterno: string | null; apellido_materno: string | null; curp: string | null; nss: string | null;
  codigo_postal: string | null; email: string | null; telefono: string | null; puesto_id: number | null; departamento: string | null;
  jornada_horas: number | null; dias_descanso: number[] | null; banco: string | null; clabe: string | null;
  fecha_baja: string | null; motivo_baja: string | null; notas: string | null;
  calculos: { salario_diario: number | null; factor_integracion: number; sdi: number | null; antiguedad_anios: number; antiguedad_meses: number;
    dias_vacaciones: number; vacaciones_desde: string; aguinaldo_dias: number };
  contratos: { id: number; tipo: TipoContrato; inicio: string; fin: string | null; notas: string | null }[];
  salarios: { id: number; salario_mensual: number; desde: string; motivo: string | null }[];
  // v12
  movimientos: { id: number; tipo: TipoMovimiento; fecha: string; descripcion: string; por: string | null }[];
  validaciones_omitidas: Partial<Record<CampoFiscal, { motivo: string; por_nombre: string | null; fecha: string }>>;
  recibos: ReciboLista[];
}

export type CampoFiscal = 'rfc' | 'curp' | 'nss' | 'clabe';
export type TipoMovimiento = 'alta' | 'baja' | 'reingreso' | 'salario' | 'contrato' | 'puesto';

export interface DatosEmpleado {
  numero_empleado?: string; nombres?: string; apellido_paterno?: string; apellido_materno?: string; rfc?: string; curp?: string; nss?: string;
  codigo_postal?: string; email?: string; telefono?: string; fecha_ingreso?: string; puesto_id?: number | null; departamento?: string;
  salario_mensual?: number | null; periodicidad?: Periodicidad; tipo_contrato?: TipoContrato; contrato_fin?: string | null;
  jornada_horas?: number | null; dias_descanso?: number[]; banco?: string; clabe?: string; notas?: string;
  omitir_validacion?: CampoFiscal[]; motivo_omision?: string;   // v12
}

export interface Alerta { empleado_id: number | null; nombre: string; numero: string | null; tipo: 'contrato' | 'tres_meses' | 'aniversario' | 'incompleto' | 'tablas'; nivel: 'error' | 'advertencia' | 'info'; fecha: string | null; texto: string }

export interface LineaNomina { clave: string; nombre: string; clave_sat: string | null; importe: number; gravado?: number; exento?: number; detalle?: string }

/** Resultado del motor de cálculo (simulación de un periodo). */
export interface CalculoNomina {
  salario_diario: number; sdi: number; sbc: number; dias_pagados: number; dias_periodo: number; dias_fuera?: number;
  tipo_calculo?: 'aguinaldo'; dias_aguinaldo?: number;   // v13
  antiguedad: { anios: number; meses: number } | null;
  percepciones: LineaNomina[]; deducciones: LineaNomina[]; otros_pagos?: LineaNomina[];
  isr: { base: number; limite_inferior: number; excedente: number; porcentaje: number; impuesto_marginal: number; cuota_fija: number;
    isr_causado: number; subsidio_causado: number; subsidio_aplicado: number; isr_retenido: number;
    // v13: pagos extraordinarios
    metodo?: 'art96' | 'art174'; ingreso_ordinario?: number; exento_tope?: number;
    comparativa?: { art96: { isr: number; tasa: number; pasos: Record<string, number> }; art174: { isr: number; tasa: number; pasos: Record<string, number> } } };
  imss: { clave: string; nombre: string; tasa: number; base_diaria: number; importe: number }[];
  totales: { percepciones: number; gravado: number; exento: number; deducciones: number; otros_pagos?: number; neto: number };
  avisos: string[];
  periodo: { numero: number; inicio: string; fin: string; fecha_pago: string; dias: number; fin_de_mes: boolean; guardado: boolean; tipo: Periodicidad };
}

export interface Empresa {
  id: number; nombre: string; razon_social: string | null; rfc: string | null; regimen_fiscal: string | null; registro_patronal: string | null;
  codigo_postal: string | null; estado: string | null; periodicidad: Periodicidad; divisor_salario_diario: number; dias_aguinaldo: number;
  prima_vacacional: number; zona_salario_minimo: 'general' | 'frontera_norte';
  timbrado_activo: boolean;   // v12: el CFDI es opcional
  metodo_isr_extraordinario: 'art174' | 'art96';   // v13: ISR del aguinaldo
}

export interface ReglaConcepto { solo_fin_de_mes?: boolean; sin_faltas?: boolean; sin_retardos?: boolean; antiguedad_meses_min?: number | null; frecuencia?: 'recurrente' | 'unico'; solo_ajuste?: boolean }
export interface ConceptoNomina {
  id: number; clave: string; nombre: string; tipo: 'percepcion' | 'deduccion' | 'otro_pago'; clave_sat: string | null;
  metodo: 'sueldo' | 'fijo' | 'por_evento' | 'captura' | 'isr' | 'imss' | 'prima_vacacional' | 'aguinaldo'; monto: number; porcentaje: number;
  regla: ReglaConcepto | null; exencion: { tipo: 'ninguna' | 'total' | 'uma_por_evento' | 'uma_tope' | 'uma_anual'; umas?: number } | null;
  descripcion: string | null; sistema: boolean; activo: boolean; orden: number;
}

export interface TablaFiscal { id: number; tipo: string; nombre: string; vigente_desde: string; datos: Record<string, unknown>; fuente: string | null; vigente: boolean }

export type EstadoPeriodo = 'abierto' | 'calculado' | 'autorizado';
export interface PeriodoNomina { id: number; tipo: TipoPeriodo; clase?: 'ordinaria' | 'aguinaldo'; ejercicio: number; numero: number; inicio: string; fin: string; fecha_pago: string; dias: number; fin_de_mes: boolean; estado: EstadoPeriodo; etiqueta: string;
  calculado_at?: string | null; autorizado_at?: string | null; autorizado_por?: string | null }

// ───────────────────────── v12: nómina del periodo ─────────────────────────
export interface ReciboResumen { id: number; estado: 'borrador' | 'autorizado'; dias_pagados: number; percepciones: number; deducciones: number; isr: number; imss: number; neto: number; uuid: string | null; avisos: string[]; cfdi_modo?: ModoCfdi | null }
export interface FilaCaptura {
  empleado_id: number; numero_empleado: string; nombre: string; puesto: string | null; dias_fuera: number;
  faltas: number; retardos: number; domingos: number; capturados: Record<string, number>; notas: string | null; recibo: ReciboResumen | null;
  vacaciones: number;   // v13: días de vacaciones que inician en el periodo
}
export interface Captura {
  periodo: PeriodoNomina; timbrado_activo: boolean; columnas: { clave: string; nombre: string; tipo: 'percepcion' | 'deduccion' | 'otro_pago'; solo_ajuste?: boolean }[];
  filas: FilaCaptura[]; pendiente_recalcular: boolean;
  totales: { empleados: number; percepciones: number; deducciones: number; isr: number; imss: number; neto: number } | null;
}
export interface PeriodoHistorial extends PeriodoNomina { recibos: number; timbrados: number; neto: number; percepciones: number }
export interface ReciboLista { id: number; ejercicio: number; numero: number; tipo: TipoPeriodo; etiqueta: string; fecha_pago: string; neto: number; uuid: string | null }
export interface Recibo extends ReciboResumen {
  empleado_id: number; numero_empleado: string; nombre: string; rfc: string | null; curp: string | null; nss: string | null; puesto: string | null;
  salario_mensual: number; salario_diario: number; sdi: number; sbc: number; faltas: number; retardos: number; domingos: number;
  periodo: PeriodoNomina; calculo: Omit<CalculoNomina, 'periodo'>;
  cfdi: { activo: boolean; uuid: string | null; timbrado_at: string | null; estado: 'timbrado' | 'simulado' | 'pendiente' | 'desactivado' | 'no_aplica';
    modo?: ModoCfdi | null; xml?: boolean; total?: number | null; timbre?: TimbreCfdi | null };   // v14
  integridad: 'ok' | 'alterado' | null;
}
export interface ListaDeRaya {
  periodo: PeriodoNomina; columnas: { clave: string; nombre: string; tipo: 'percepcion' | 'deduccion' | 'otro_pago' }[];
  filas: { recibo_id: number; numero_empleado: string; nombre: string; rfc: string | null; puesto: string | null; dias: number; importes: Record<string, number>; percepciones: number; deducciones: number; neto: number; uuid: string | null }[];
  totales: { importes: Record<string, number>; percepciones: number; deducciones: number; neto: number };
}
export interface Acumulados {
  ejercicio: number; tipo: TipoPeriodo; periodos: { numero: number; inicio: string; fin: string }[];
  filas: { clave: string; nombre: string; tipo: 'percepcion' | 'deduccion' | 'otro_pago'; periodos: Record<string, number>; total: number }[];
}
export interface Respaldo { archivo: string; tamano: number; fecha: string }
export interface PuntoIndicador {
  clave: string; etiqueta: string; inicio: string; fin: string; recibos: number; faltas: number; retardos: number; domingos: number;
  bonos: Record<string, number>; prima_dominical: number | null; neto: number | null; altas: number; bajas: number; plantilla: number; rotacion: number;
}
export interface IndicadoresRespuesta {
  ejercicio: number; agrupar: 'quincena' | 'mes'; bonos: { clave: string; nombre: string }[]; serie: PuntoIndicador[]; con_nomina: boolean;
  totales: { faltas: number; retardos: number; domingos: number; altas: number; bajas: number; bonos: number | null };
}

// ───────────────────────── v10: Publicaciones ─────────────────────────
export type TipoPublicacion = 'texto' | 'documentos' | 'imagenes' | 'video';   // v11: se retiró 'trabajo'
export type EstadoPublicacion = 'borrador' | 'publicada' | 'archivada';

export interface AdjuntoPublicacion {
  id: number; nombre: string; mime: string; tamano: number; ancho: number | null; alto: number | null;
  es_imagen: boolean; url: string; miniatura: string;
}

export interface Publicacion {
  id: number; tipo: TipoPublicacion; titulo: string; cuerpo: string; categoria: string | null; etiquetas: string[];
  estado: EstadoPublicacion; fijada: boolean; oficial: boolean; permite_descarga: boolean; permite_copia: boolean;
  video: { url: string; proveedor: string; id: string | null; incrustar: string | null } | null;
  autor: { id: number; nombre: string; rol: string | null };
  publicada: string | null; publicada_iso: string | null; caduca: string | null;
  adjuntos: AdjuntoPublicacion[];
  trabajo: null;   // v11: ya no existe el tipo «trabajo de la plataforma»
  leida: boolean; favorita: boolean; puede_editar: boolean;
  audiencia: 'todos' | 'roles' | 'usuarios'; audiencia_ids?: (string | number)[]; lecturas?: number;
}

export interface FiltrosPublicaciones {
  q?: string; tipo?: TipoPublicacion | ''; categoria?: string; etiqueta?: string;
  vista?: 'todas' | 'mias' | 'favoritas' | 'borradores' | 'archivadas'; page?: number;
}

export interface DatosPublicacion {
  tipo?: TipoPublicacion; titulo?: string; cuerpo?: string; categoria?: string; etiquetas?: string[];
  audiencia?: Publicacion['audiencia']; audiencia_ids?: (string | number)[]; estado?: 'borrador' | 'publicada';
  fijada?: boolean; oficial?: boolean; permite_descarga?: boolean; permite_copia?: boolean;
  video_url?: string; caduca_at?: string | null;
}

// ───────────────────────── v13: vacaciones, cálculo anual y envíos ─────────────────────────
export interface SaldoVacaciones {
  anios: { anio: number; desde: string; derecho: number; tomados: number; pendientes: number; estado: 'vigente' | 'por_vencer' | 'vencido' | 'agotado' }[];
  disponibles: number; proximo: { anio: number; desde: string; derecho: number };
  registros: { id: number; desde: string; hasta: string; dias: number; anio_servicio: number; notas: string | null; por: string | null; prima_pagada: boolean }[];
}
export interface ResultadoAnual {
  aplica: boolean; motivo: string | null; ingresos_totales: number; gravado: number; isr_anual: number; subsidio_aplicado: number;
  isr_despues_subsidio: number; isr_retenido: number; diferencia: number; resultado: 'a_cargo' | 'a_favor' | 'cero';
  tarifa: { limite_inferior: number; porcentaje: number; cuota_fija: number };
}
export interface AjusteAnualRespuesta {
  ejercicio: number; periodos_sin_autorizar: number;
  filas: { id: number; empleado_id: number; numero_empleado: string; nombre: string; declara_por_su_cuenta: boolean; aplica: boolean; motivo: string | null;
    resultado: ResultadoAnual['resultado']; diferencia: number; datos: ResultadoAnual; estado: 'calculado' | 'aplicado'; periodo: string | null }[];
  totales: { a_cargo: number; a_favor: number; aplican: number };
}
export interface ResultadoEnvio { enviados: number; sin_correo: string[]; errores: string[] }
export type EnviosPeriodo = Record<string, { email: string; estado: 'enviado' | 'error'; error: string | null; fecha: string }>;

// ───────────────────────── v14: CFDI de nómina ─────────────────────────
export type ModoCfdi = 'simulado' | 'pruebas' | 'produccion' | 'importado';
export interface TimbreCfdi {
  uuid: string; fecha_timbrado: string; rfc_prov_certif: string; no_certificado_emisor: string | null; no_certificado_sat: string;
  sello_cfd: string; sello_sat: string; cadena_tfd: string; leyenda: string | null; simulado: boolean; pruebas: boolean;
  url_verificacion: string; serie: string | null; folio: string | null; total: number; modo: ModoCfdi | null;
}
export interface EstadoTimbrado {
  timbrado_activo: boolean;
  empresa: { rfc: string | null; razon_social: string | null; regimen_fiscal: string | null; codigo_postal: string | null; registro_patronal: string | null;
    curp: string | null; clave_ent_fed: string | null; riesgo_puesto: string; tipo_jornada: string; serie_cfdi: string | null };
  pac: { proveedor: 'simulado' | 'sw'; modo: 'pruebas' | 'produccion'; usuario: string | null; tiene_password: boolean; nombre: string; modo_timbre: ModoCfdi };
  csd: { no_certificado: string; rfc: string; vigente_desde: string; vigente_hasta: string; vigente: boolean; dias_restantes: number | null } | null;
  revision: { clave: string; ok: boolean; texto: string }[];
  listo: boolean;
  catalogos: { estados: Record<string, string>; jornadas: Record<string, string>; riesgos: Record<string, string>; motivos_cancelacion: Record<string, string> };
  resumen: { timbrados: Partial<Record<ModoCfdi, number>>; pendientes: number };
}
export interface ResultadoTimbradoPeriodo {
  timbrados: number; errores: number;
  resultados: ({ ok: true; recibo_id: number; nombre: string; uuid: string; modo: ModoCfdi; total: number } | { ok: false; recibo_id: number; nombre: string; error: string; errores: string[] })[];
}
export type EstadoImportacion = 'listo' | 'ligado' | 'diferencia' | 'ya_ligado' | 'conflicto' | 'uuid_en_otro' | 'sin_recibo' | 'emisor_distinto'
  | 'no_es_nomina' | 'sin_timbre' | 'invalido' | 'repetido';
export interface FilaImportacion {
  archivo: string; estado: EstadoImportacion; mensaje: string | null; uuid: string | null; receptor: string | null; rfc: string | null;
  fecha_pago: string | null; tipo_nomina: 'O' | 'E' | null; total: number | null;
  recibo: { id: number; nombre: string; numero_empleado: string; periodo: string | null; periodo_id: number; neto: number; uuid: string | null } | null;
  diferencias: { concepto: string; recibo: number; xml: number; diferencia: number }[];
}
export interface ResultadoImportacion { aplicado: boolean; total: number; conteo: Partial<Record<EstadoImportacion, number>>; estados: Record<EstadoImportacion, string>; filas: FilaImportacion[] }
export interface EventoCfdi {
  id: number; tipo: 'timbrado' | 'error' | 'cancelacion' | 'importacion' | 'csd' | 'prueba'; uuid: string | null; modo: ModoCfdi | null; mensaje: string;
  datos: Record<string, unknown> | null; fecha: string; usuario: string | null; recibo: { id: number; nombre: string; numero_empleado: string } | null;
}
