import { useEffect, useState, type FormEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { empleadosApi, nominaApi } from '../../api/endpoints';
import { erroresDeCampo, mensajeDeError } from '../../api/http';
import { Aviso } from '../../components/Aviso';
import type { CampoFiscal, DatosEmpleado, TipoContrato } from '../../types';
import { CONTRATOS, DIAS_SEMANA, dinero, hoyISO, PERIODICIDADES } from '../../utils/formato';

const sumarMeses = (iso: string, meses: number) => {
  const d = new Date(`${iso}T12:00:00`);
  d.setMonth(d.getMonth() + meses);
  d.setDate(d.getDate() - 1);
  return d.toISOString().slice(0, 10);
};

/**
 * v11 — Alta y edición del expediente. Se capturan a mano (ya no se importan de Excel).
 * El servidor valida RFC, CURP, NSS y CLABE con su dígito verificador; aquí se avisa el formato mientras se escribe.
 */
export function EmpleadoForm() {
  const id = useParams().id ? Number(useParams().id) : null;
  const nav = useNavigate();
  const qc = useQueryClient();
  const existente = useQuery({ queryKey: ['empleado', id], queryFn: () => empleadosApi.obtener(id!), enabled: id !== null });
  const { data: puestos = [] } = useQuery({ queryKey: ['puestos'], queryFn: nominaApi.puestos, staleTime: 300_000 });
  const { data: empresa } = useQuery({ queryKey: ['empresa'], queryFn: nominaApi.empresa, staleTime: 300_000 });
  const [d, setD] = useState<DatosEmpleado>({
    nombres: '', apellido_paterno: '', apellido_materno: '', rfc: '', curp: '', nss: '', codigo_postal: '', email: '', telefono: '',
    fecha_ingreso: hoyISO(), puesto_id: null, departamento: '', salario_mensual: null, tipo_contrato: 'capacitacion', contrato_fin: null,
    jornada_horas: 10, dias_descanso: [], banco: '', clabe: '', notas: '',
  });
  const [errores, setErrores] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [guardando, setGuardando] = useState(false);
  const [nuevoPuesto, setNuevoPuesto] = useState('');
  // v12: un dato real que no pasa el dígito verificador se puede aceptar con motivo (el formato nunca)
  const [omitir, setOmitir] = useState<CampoFiscal[]>([]);
  const [motivoOmision, setMotivoOmision] = useState('');

  useEffect(() => {
    const e = existente.data;
    if (!e) return;
    setD({ nombres: e.nombres ?? '', apellido_paterno: e.apellido_paterno ?? '', apellido_materno: e.apellido_materno ?? '', rfc: e.rfc ?? '',
      curp: e.curp ?? '', nss: e.nss ?? '', codigo_postal: e.codigo_postal ?? '', email: e.email ?? '', telefono: e.telefono ?? '',
      fecha_ingreso: e.fecha_ingreso, puesto_id: e.puesto_id, departamento: e.departamento ?? '', periodicidad: e.periodicidad,
      jornada_horas: e.jornada_horas, dias_descanso: e.dias_descanso ?? [], banco: e.banco ?? '', clabe: e.clabe ?? '', notas: e.notas ?? '' });
  }, [existente.data]);

  // Al elegir el tipo de contrato se propone la fecha de fin (capacitación: 2 semanas; prueba y temporal: 3 meses)
  const proponerFin = (tipo: TipoContrato, ingreso = d.fecha_ingreso) => {
    if (!ingreso || tipo === 'indeterminado') return null;
    if (tipo === 'capacitacion') { const f = new Date(`${ingreso}T12:00:00`); f.setDate(f.getDate() + 13); return f.toISOString().slice(0, 10); }
    return sumarMeses(ingreso, 3);
  };
  useEffect(() => { if (!id) set({ contrato_fin: proponerFin(d.tipo_contrato ?? 'capacitacion') }); }, [d.fecha_ingreso, d.tipo_contrato]); // eslint-disable-line react-hooks/exhaustive-deps

  if (id && existente.isLoading) return <p>Cargando…</p>;

  const set = (c: Partial<DatosEmpleado>) => setD((x) => ({ ...x, ...c }));
  const mayus = (v: string) => v.toUpperCase().replace(/\s+/g, '');
  const formato = {
    rfc: d.rfc && !/^[A-ZÑ&]{4}\d{6}[A-Z\d]{3}$/.test(d.rfc) ? '13 caracteres: 4 letras, 6 números (AAMMDD) y 3 de homoclave' : null,
    curp: d.curp && !/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z\d]\d$/.test(d.curp) ? '18 caracteres' : null,
    nss: d.nss && !/^\d{11}$/.test(d.nss) ? '11 dígitos' : null,
    clabe: d.clabe && !/^\d{18}$/.test(d.clabe) ? '18 dígitos' : null,
    coherencia: d.rfc?.length === 13 && d.curp?.length === 18 && d.rfc.slice(0, 10) !== d.curp.slice(0, 10)
      ? 'El RFC y la CURP no coinciden en sus primeros 10 caracteres.' : null,
  };
  const sd = d.salario_mensual && empresa ? d.salario_mensual / empresa.divisor_salario_diario : null;
  const err = (c: string) => errores[c] && <small className="texto-peligro">{errores[c]}</small>;
  const esDigito = (c: CampoFiscal) => /verificador|dígito de control/.test(errores[c] ?? '');
  const confirmar = (c: CampoFiscal) => (esDigito(c) || omitir.includes(c)) && (
    <span className="confirmar-dato">
      <input type="checkbox" id={`omitir-${c}`} checked={omitir.includes(c)} onChange={(e) => setOmitir(e.target.checked ? [...omitir, c] : omitir.filter((x) => x !== c))} />
      <label htmlFor={`omitir-${c}`}>El dato es correcto: lo verifiqué en el documento oficial</label>
    </span>
  );
  const ayuda = (c: keyof typeof formato) => formato[c] && !errores[c] && <small className="tenue">{formato[c]}</small>;

  const agregarPuesto = async () => {
    if (!nuevoPuesto.trim()) return;
    const p = await nominaApi.crearPuesto(nuevoPuesto.trim());
    void qc.invalidateQueries({ queryKey: ['puestos'] });
    set({ puesto_id: p.id });
    setNuevoPuesto('');
  };

  const guardar = async (ev: FormEvent) => {
    ev.preventDefault();
    setErrores({});
    setError(null);
    setGuardando(true);
    try {
      const datos: DatosEmpleado = { ...d, apellido_materno: d.apellido_materno || undefined, curp: d.curp || undefined,
        ...(omitir.length ? { omitir_validacion: omitir, motivo_omision: motivoOmision } : {}) };
      if (id) { delete datos.salario_mensual; delete datos.tipo_contrato; delete datos.contrato_fin; }
      const e = id ? await empleadosApi.actualizar(id, datos) : await empleadosApi.crear(datos);
      void qc.invalidateQueries({ queryKey: ['empleados'] });
      qc.setQueryData(['empleado', e.id], e);
      nav(`/empleados/${e.id}`);
    } catch (e) {
      const campos = erroresDeCampo(e);
      setErrores(campos);
      setError(Object.keys(campos).length ? 'Revisa los campos marcados.' : mensajeDeError(e));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <form className="form-expediente" onSubmit={(e) => void guardar(e)} noValidate>
      <Link to={id ? `/empleados/${id}` : '/empleados'} className="btn-link">← {id ? 'Volver al expediente' : 'Empleados'}</Link>
      <h1>{id ? 'Editar expediente' : 'Alta de empleado'}</h1>
      {error && <Aviso tipo="error">{error}</Aviso>}

      <fieldset className="tarjeta seccion-form">
        <legend>Datos personales y fiscales</legend>
        <p className="tenue">Escríbelos tal como aparecen en la Constancia de Situación Fiscal del trabajador.</p>
        <div className="rejilla-form">
          <label>Nombre(s) *<input value={d.nombres} onChange={(e) => set({ nombres: e.target.value })} required autoComplete="off" />{err('nombres')}</label>
          <label>Apellido paterno *<input value={d.apellido_paterno} onChange={(e) => set({ apellido_paterno: e.target.value })} required />{err('apellido_paterno')}</label>
          <label>Apellido materno<input value={d.apellido_materno} onChange={(e) => set({ apellido_materno: e.target.value })} /></label>
          <label>RFC *<input className="mono" value={d.rfc} maxLength={13} onChange={(e) => set({ rfc: mayus(e.target.value) })} placeholder="GODE561231GR8" />{err('rfc')}{ayuda('rfc')}{confirmar('rfc')}</label>
          <label>CURP<input className="mono" value={d.curp} maxLength={18} onChange={(e) => set({ curp: mayus(e.target.value) })} placeholder="HEGG560427MVZRRL04" />{err('curp')}{ayuda('curp')}{confirmar('curp')}</label>
          <label>Número de Seguridad Social *<input className="mono" inputMode="numeric" value={d.nss} maxLength={11} onChange={(e) => set({ nss: e.target.value.replace(/\D/g, '') })} placeholder="11 dígitos" />{err('nss')}{ayuda('nss')}{confirmar('nss')}</label>
          <label>Código postal fiscal<input inputMode="numeric" maxLength={5} value={d.codigo_postal} onChange={(e) => set({ codigo_postal: e.target.value.replace(/\D/g, '') })} />{err('codigo_postal')}</label>
          <label>Correo<input type="email" value={d.email} onChange={(e) => set({ email: e.target.value })} />{err('email')}</label>
          <label>Teléfono<input type="tel" value={d.telefono} onChange={(e) => set({ telefono: e.target.value })} /></label>
        </div>
        {formato.coherencia && <Aviso tipo="advertencia">{formato.coherencia}</Aviso>}
        {omitir.length > 0 && (
          <div className="caja-respaldo">
            <Aviso tipo="advertencia">Vas a guardar {omitir.map((c) => c.toUpperCase()).join(', ')} aunque no pasa el dígito verificador. Hazlo solo si coincide exactamente con el documento oficial (Constancia de Situación Fiscal, CURP de RENAPO, alta del IMSS o estado de cuenta).</Aviso>
            <label>Motivo (queda en el expediente)<input value={motivoOmision} placeholder="Ej. Coincide con la Constancia de Situación Fiscal del 10/11/2026" onChange={(e) => setMotivoOmision(e.target.value)} />{err('motivo_omision')}</label>
          </div>
        )}
      </fieldset>

      <fieldset className="tarjeta seccion-form">
        <legend>Puesto y salario</legend>
        <div className="rejilla-form">
          <label>Fecha de ingreso *<input type="date" value={d.fecha_ingreso} onChange={(e) => set({ fecha_ingreso: e.target.value })} />
            <small className="tenue">Incluye la capacitación: desde aquí cuenta la antigüedad.</small>{err('fecha_ingreso')}</label>
          <label>Puesto *
            <select value={d.puesto_id ?? ''} onChange={(e) => set({ puesto_id: e.target.value ? Number(e.target.value) : null })}>
              <option value="">Elige…</option>
              {puestos.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
            </select>{err('puesto_id')}
          </label>
          <label>Nuevo puesto
            <span className="campo-con-boton"><input value={nuevoPuesto} placeholder="Si no está en la lista" onChange={(e) => setNuevoPuesto(e.target.value)} />
              <button type="button" className="btn btn-sec btn-sm" disabled={!nuevoPuesto.trim()} onClick={() => void agregarPuesto()}>Agregar</button></span>
          </label>
          <label>Departamento<input value={d.departamento} onChange={(e) => set({ departamento: e.target.value })} /></label>
          {!id && <>
            <label>Sueldo mensual *<input type="number" min={1} step="0.01" inputMode="decimal" value={d.salario_mensual ?? ''}
              onChange={(e) => set({ salario_mensual: e.target.value ? Number(e.target.value) : null })} />
              {sd !== null && <small className="tenue">Salario diario: {dinero(sd)} (sueldo ÷ {empresa?.divisor_salario_diario})</small>}{err('salario_mensual')}</label>
          </>}
          <label>Pago
            <select value={d.periodicidad ?? empresa?.periodicidad ?? 'quincenal'} onChange={(e) => set({ periodicidad: e.target.value as DatosEmpleado['periodicidad'] })}>
              {Object.entries(PERIODICIDADES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </label>
        </div>
        {id && <p className="tenue">El sueldo y el contrato se cambian desde el expediente («Cambiar salario» y «Nuevo contrato») para conservar su historial.</p>}
      </fieldset>

      {!id && (
        <fieldset className="tarjeta seccion-form">
          <legend>Contrato</legend>
          <div className="rejilla-form">
            <label>Tipo de contrato *
              <select value={d.tipo_contrato} onChange={(e) => set({ tipo_contrato: e.target.value as TipoContrato })}>
                {Object.entries(CONTRATOS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </label>
            {d.tipo_contrato !== 'indeterminado' && (
              <label>Termina el<input type="date" value={d.contrato_fin ?? ''} onChange={(e) => set({ contrato_fin: e.target.value || null })} />{err('contrato_fin')}</label>
            )}
          </div>
          <p className="tenue">En Vagón COM: 2 semanas de capacitación pagada → contrato de prueba de 3 meses → contrato de 3 meses → indeterminado. El sistema avisa antes de que venza cada uno.</p>
        </fieldset>
      )}

      <fieldset className="tarjeta seccion-form">
        <legend>Jornada y pago</legend>
        <div className="rejilla-form">
          <label>Horas por día<input type="number" min={1} max={24} step="0.5" value={d.jornada_horas ?? ''} onChange={(e) => set({ jornada_horas: e.target.value ? Number(e.target.value) : null })} /></label>
          <div className="campo">
            <span>Días de descanso</span>
            <div className="dias-semana">
              {DIAS_SEMANA.map((nombre, i) => (
                <label key={i} className={`dia ${d.dias_descanso?.includes(i) ? 'activo' : ''}`}>
                  <input type="checkbox" checked={!!d.dias_descanso?.includes(i)}
                    onChange={() => set({ dias_descanso: d.dias_descanso?.includes(i) ? d.dias_descanso.filter((x) => x !== i) : [...(d.dias_descanso ?? []), i].sort() })} />
                  {nombre.slice(0, 3)}
                </label>
              ))}
            </div>
            <small className="tenue">{7 - (d.dias_descanso?.length ?? 0)} días × {d.jornada_horas ?? 0} h = {(7 - (d.dias_descanso?.length ?? 0)) * (d.jornada_horas ?? 0)} horas por semana</small>
          </div>
          <label>Banco<input value={d.banco} onChange={(e) => set({ banco: e.target.value })} /></label>
          <label>CLABE<input className="mono" inputMode="numeric" maxLength={18} value={d.clabe} onChange={(e) => set({ clabe: e.target.value.replace(/\D/g, '') })} />{err('clabe')}{ayuda('clabe')}{confirmar('clabe')}</label>
        </div>
        <label>Notas<textarea rows={2} value={d.notas} onChange={(e) => set({ notas: e.target.value })} /></label>
      </fieldset>

      <div className="editor-pub-pie">
        <Link className="btn btn-sec" to={id ? `/empleados/${id}` : '/empleados'}>Cancelar</Link>
        <button className="btn" type="submit" disabled={guardando}>{guardando ? 'Guardando…' : id ? 'Guardar cambios' : 'Dar de alta'}</button>
      </div>
    </form>
  );
}
