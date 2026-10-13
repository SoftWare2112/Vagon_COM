const REGLAS = [
  { texto: 'Mínimo 10 caracteres', ok: (v: string) => v.length >= 10 },
  { texto: 'Mayúsculas y minúsculas', ok: (v: string) => /[a-z]/.test(v) && /[A-Z]/.test(v) },
  { texto: 'Al menos un número', ok: (v: string) => /\d/.test(v) },
];

export const passwordValida = (v: string) => REGLAS.every((r) => r.ok(v));

export function ReglasPassword({ valor }: { valor: string }) {
  return (
    <ul className="reglas-password">
      {REGLAS.map((r) => <li key={r.texto} className={r.ok(valor) ? 'ok' : ''}>{r.ok(valor) ? '✓' : '○'} {r.texto}</li>)}
    </ul>
  );
}
