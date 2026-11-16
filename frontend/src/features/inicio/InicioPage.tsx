import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { empleadosApi, nominaApi, publicacionesApi } from '../../api/endpoints';
import { useAuth } from '../../auth/AuthContext';
import type { Alerta } from '../../types';
import { dinero, fecha, PERIODICIDADES } from '../../utils/formato';

const ICONO: Record<Alerta['tipo'], string> = { contrato: '📄', tres_meses: '🎉', aniversario: '🏖', incompleto: '⚠', tablas: '🧾' };

/** v11 — Inicio: resumen de la plantilla, próximo pago, avisos de contratos y publicaciones recientes. */
export function InicioPage() {
  const { puede, usuario } = useAuth();
  const verEmpleados = puede('empleados.ver');
  const { data: empleados = [] } = useQuery({ queryKey: ['empleados', { estado: 'activo' }], queryFn: () => empleadosApi.listar({ estado: 'activo' }), enabled: verEmpleados });
  const { data: alertas = [] } = useQuery({ queryKey: ['alertas'], queryFn: empleadosApi.alertas, enabled: verEmpleados });
  const { data: empresa } = useQuery({ queryKey: ['empresa'], queryFn: nominaApi.empresa, enabled: verEmpleados, staleTime: 300_000 });
  const anio = new Date().getFullYear();
  const { data: periodos = [] } = useQuery({
    queryKey: ['periodos', empresa?.periodicidad, anio], queryFn: () => nominaApi.periodos(empresa!.periodicidad, anio), enabled: !!empresa,
  });
  const { data: pubs } = useQuery({ queryKey: ['publicaciones', { inicio: true }], queryFn: () => publicacionesApi.listar({}), enabled: puede('publicaciones.ver') });

  const hoy = new Date().toISOString().slice(0, 10);
  const proximo = periodos.find((p) => p.fecha_pago >= hoy);
  const masa = empleados.reduce((s, e) => s + (e.salario_mensual ?? 0), 0);
  const incompletos = empleados.filter((e) => e.faltantes.length > 0).length;

  return (
    <section className="inicio">
      <div className="inicio-cab">
        <div>
          <h1>Hola, {usuario?.name?.split(' ')[0]}</h1>
          <p className="tenue">{empresa?.nombre ?? 'Vagón COM'} · nómina {empresa ? PERIODICIDADES[empresa.periodicidad].toLowerCase() : ''}</p>
        </div>
        <div className="barra-acciones">
          {puede('nomina.ver') && <Link className="btn btn-sec" to="/nomina">Nómina del periodo</Link>}
          {puede('empleados.gestionar') && <Link className="btn" to="/empleados/nuevo">+ Alta de empleado</Link>}
        </div>
      </div>

      {verEmpleados && (
        <div className="kpis">
          <Link className="kpi kpi-enlace" to="/empleados"><span className="tenue">Empleados activos</span><strong>{empleados.length}</strong>
            <small className="tenue">{incompletos ? `${incompletos} con expediente incompleto` : 'Expedientes completos'}</small></Link>
          <div className="kpi"><span className="tenue">Sueldos base al mes</span><strong>{dinero(masa)}</strong><small className="tenue">Antes de bonos e impuestos</small></div>
          <div className="kpi"><span className="tenue">Próximo pago</span><strong>{proximo ? fecha(proximo.fecha_pago) : '—'}</strong>
            <small className="tenue">{proximo ? proximo.etiqueta : periodos.length === 0 ? <Link to="/nomina/configuracion?pestana=periodos">Genera el calendario {anio}</Link> : `Sin periodos pendientes en ${anio}`}</small></div>
          <div className="kpi"><span className="tenue">Avisos</span><strong>{alertas.length}</strong><small className="tenue">Contratos, bonos y aniversarios</small></div>
        </div>
      )}

      <div className="inicio-rejilla">
        {verEmpleados && (
          <div className="tarjeta">
            <h2 className="seccion-titulo">Avisos de la plantilla</h2>
            {alertas.length === 0
              ? <p className="tenue">{empleados.length === 0 ? 'Cuando captures empleados, aquí verás contratos por vencer, quién cumple 3 meses y aniversarios.' : 'Nada pendiente en los próximos 15 días.'}</p>
              : <ul className="lista-alertas">
                  {alertas.map((a, i) => (
                    <li key={i} className={`alerta-${a.nivel}`}>
                      <span aria-hidden>{ICONO[a.tipo]}</span>
                      <span><Link to={a.empleado_id ? `/empleados/${a.empleado_id}` : '/nomina/configuracion?pestana=tablas'}><strong>{a.nombre}</strong></Link><small>{a.texto}</small></span>
                    </li>
                  ))}
                </ul>}
          </div>
        )}
        {puede('publicaciones.ver') && (
          <div className="tarjeta">
            <div className="seccion-cab"><h2 className="seccion-titulo">Publicaciones recientes</h2><Link className="btn-link" to="/publicaciones">Ver todas</Link></div>
            {(pubs?.data ?? []).length === 0 ? <p className="tenue">No hay publicaciones todavía.</p> : (
              <ul className="lista-simple">
                {pubs!.data.slice(0, 5).map((p) => (
                  <li key={p.id}><Link to={`/publicaciones/${p.id}`}>{!p.leida && <span className="punto-nuevo" aria-label="Nueva" />}<strong>{p.titulo}</strong></Link>
                    <small className="tenue">{p.autor.nombre} · {p.publicada ?? 'borrador'}</small></li>
                ))}
              </ul>
            )}
          </div>
        )}
      </div>
      {!verEmpleados && !puede('publicaciones.ver') && <div className="vacio">Tu usuario aún no tiene acceso a ningún apartado. Pide a un administrador que te asigne un rol.</div>}
    </section>
  );
}
