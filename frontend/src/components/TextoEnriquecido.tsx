import type { ReactNode } from 'react';

/**
 * v10 — Texto con formato sencillo, SEGURO: no se interpreta HTML (React escapa todo); solo se reconocen
 *   **negrita**, *cursiva*, enlaces https://…, "## Título", listas con "- " o "1. " y citas con "> ".
 * Así nadie puede meter código en una publicación.
 */
/** sinEnlaces: los enlaces se muestran como texto (cuando todo el bloque ya es un enlace, p. ej. en el muro). */
export function TextoEnriquecido({ texto, className, sinEnlaces = false }: { texto: string; className?: string; sinEnlaces?: boolean }) {
  const enLinea = (t: string, k: number | string = 0) => formatoEnLinea(t, k, sinEnlaces);
  const conSaltos = (lineas: string[]): ReactNode[] => lineas.flatMap((t, k) => (k === 0 ? [enLinea(t, k)] : [<br key={`br${k}`} />, enLinea(t, k)]));
  const bloques: ReactNode[] = [];
  const lineas = texto.replace(/\r\n?/g, '\n').split('\n');
  let i = 0;
  while (i < lineas.length) {
    const l = lineas[i];
    if (!l.trim()) { i++; continue; }
    const tipoLista = /^\s*[-*•]\s+/.test(l) ? 'ul' : /^\s*\d+[.)]\s+/.test(l) ? 'ol' : null;
    if (tipoLista) {
      const items: string[] = [];
      const patron = tipoLista === 'ul' ? /^\s*[-*•]\s+/ : /^\s*\d+[.)]\s+/;
      while (i < lineas.length && patron.test(lineas[i])) items.push(lineas[i++].replace(patron, ''));
      const Lista = tipoLista;
      bloques.push(<Lista key={i}>{items.map((t, k) => <li key={k}>{enLinea(t)}</li>)}</Lista>);
      continue;
    }
    if (/^#{1,3}\s+/.test(l)) { bloques.push(<h3 key={i}>{enLinea(l.replace(/^#{1,3}\s+/, ''))}</h3>); i++; continue; }
    if (/^>\s?/.test(l)) {
      const citas: string[] = [];
      while (i < lineas.length && /^>\s?/.test(lineas[i])) citas.push(lineas[i++].replace(/^>\s?/, ''));
      bloques.push(<blockquote key={i}>{conSaltos(citas)}</blockquote>);
      continue;
    }
    const parrafo: string[] = [];
    while (i < lineas.length && lineas[i].trim() && !/^\s*([-*•]|\d+[.)])\s+|^#{1,3}\s+|^>/.test(lineas[i])) parrafo.push(lineas[i++]);
    bloques.push(<p key={i}>{conSaltos(parrafo)}</p>);
  }
  return <div className={`texto-enriquecido ${className ?? ''}`}>{bloques}</div>;
}

/** Negrita, cursiva y enlaces dentro de una línea. */
export function formatoEnLinea(texto: string, clave: number | string = 0, sinEnlaces = false): ReactNode {
  const partes = texto.split(/(\*\*[^*]+\*\*|\*[^*\s][^*]*\*|https?:\/\/[^\s<>"]+)/g);
  return (
    <span key={clave}>
      {partes.map((p, k) => {
        if (!p) return null;
        if (p.startsWith('**') && p.endsWith('**') && p.length > 4) return <strong key={k}>{p.slice(2, -2)}</strong>;
        if (p.startsWith('*') && p.endsWith('*') && p.length > 2) return <em key={k}>{p.slice(1, -1)}</em>;
        if (/^https?:\/\//.test(p) && sinEnlaces) return <span key={k} className="enlace-texto">{p}</span>;
        if (/^https?:\/\//.test(p)) {
          const [, url, resto] = p.match(/^(.*?)([.,;:!?)]*)$/) ?? [p, p, ''];
          return <span key={k}><a href={url} target="_blank" rel="noopener noreferrer">{url}</a>{resto}</span>;
        }
        return p;
      })}
    </span>
  );
}
