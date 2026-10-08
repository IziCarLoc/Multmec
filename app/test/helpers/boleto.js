// Monta linhas digitáveis válidas para cenários de teste. A validação do algoritmo em si é testada com vetores independentes (vetores_boleto.js).
import { montarLinhaDigitavel } from '../../src/boleto.js';

let contador = 1;
export function linhaDigitavel({ banco = '341', valor, vencimento, livre } = {}) {
  return montarLinhaDigitavel({ banco, valor, vencimento, campoLivre: livre ?? contador++ * 7919 });
}
