import { describe, expect, it } from 'vitest';
import { CONTRATOS, dinero, fecha, numero } from '../formato';

describe('formato (v11)', () => {
  it('muestra dinero en pesos mexicanos', () => {
    expect(dinero(5311.72)).toContain('5,311.72');
    expect(dinero(null)).toBe('—');
  });
  it('convierte fechas ISO a dd/mm/aaaa', () => {
    expect(fecha('2026-11-30')).toBe('30/11/2026');
    expect(fecha(null)).toBe('—');
  });
  it('redondea números con decimales fijos', () => {
    expect(numero(1.0493150, 6)).toBe('1.049315');
    expect(numero(16, 2)).toBe('16.00');
  });
  it('conoce las etapas de contrato de Vagón COM', () => {
    expect(Object.keys(CONTRATOS)).toEqual(['capacitacion', 'prueba', 'temporal', 'indeterminado']);
  });
});
