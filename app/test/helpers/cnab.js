// Monta um arquivo CNAB 240 do DDA no leiaute FEBRABAN (segmentos G, H e Y-03) para os testes.
// As posições seguem o manual "DDA - Sacado Eletrônico - Leiaute padrão FEBRABAN/CNAB 240" (Banrisul e Itaú publicam o mesmo).
const num = (v, n) => String(v).replace(/\D/g, '').padStart(n, '0').slice(-n);
const alfa = (v, n) => String(v ?? '').padEnd(n, ' ').slice(0, n);

function registro(campos) {
  const l = Array(240).fill(' ');
  for (const [ini, fim, valor] of campos) {
    const t = String(valor).padEnd(fim - ini + 1, ' ').slice(0, fim - ini + 1);
    for (let i = 0; i < t.length; i++) l[ini - 1 + i] = t[i];
  }
  return l.join('');
}

export function cnabDda({ banco = '341', cnpjEmpresa, nomeEmpresa = 'EMPRESA TESTE LTDA', geracao = '08102026', titulos, lotes = null }) {
  const linhas = [];
  linhas.push(registro([[1, 3, banco], [4, 7, '0000'], [8, 8, '0'], [18, 18, '2'], [19, 32, num(cnpjEmpresa, 14)], [73, 102, alfa(nomeEmpresa, 30)], [103, 132, alfa('BANCO TESTE', 30)],
    [143, 143, '2'], [144, 151, geracao], [152, 157, '101500'], [158, 163, '000001'], [164, 166, '084']]));
  // um lote por CNPJ (o Itaú manda matriz e filiais em lotes separados); sem `lotes`, um lote só com o CNPJ do arquivo
  const lista = lotes ?? [{ cnpj: cnpjEmpresa, titulos }];
  let seq = 0;
  lista.forEach((lote, k) => {
  const loteNum = String(k + 1).padStart(4, '0');
  linhas.push(registro([[1, 3, banco], [4, 7, loteNum], [8, 8, '1'], [9, 9, 'I'], [10, 11, '03'], [14, 16, '022'], [18, 18, '2'], [19, 33, num(lote.cnpj, 15)], [74, 103, alfa(nomeEmpresa, 30)]]));
  for (const t of lote.titulos) {
    const mov = t.movimento ?? '01';
    seq += 1;
    linhas.push(registro([[1, 3, banco], [4, 7, loteNum], [8, 8, '3'], [9, 13, num(seq, 5)], [14, 14, 'G'], [16, 17, mov], [18, 61, t.barras], [62, 62, '2'], [63, 77, num(t.cnpjCedente, 15)],
      [78, 107, alfa(t.nomeCedente, 30)], [108, 115, t.vencimento], [116, 130, num(Math.round(t.valor * 100), 15)], [146, 147, '09'], [148, 162, alfa(t.documento, 15)], [182, 189, t.emissao ?? '01102026']]));
    seq += 1;
    linhas.push(registro([[1, 3, banco], [4, 7, loteNum], [8, 8, '3'], [9, 13, num(seq, 5)], [14, 14, 'H'], [16, 17, mov], [18, 18, t.cnpjSacador ? '2' : '0'], [19, 33, num(t.cnpjSacador ?? 0, 15)], [34, 73, alfa(t.nomeSacador ?? '', 40)]]));
    seq += 1;
    linhas.push(registro([[1, 3, banco], [4, 7, loteNum], [8, 8, '3'], [9, 13, num(seq, 5)], [14, 14, 'Y'], [16, 17, mov], [18, 19, '03'], [20, 20, '2'], [21, 35, num(t.cnpjSacado ?? lote.cnpj, 15)], [36, 75, alfa(t.nomeSacado ?? nomeEmpresa, 40)]]));
  }
  linhas.push(registro([[1, 3, banco], [4, 7, loteNum], [8, 8, '5'], [18, 23, num(lote.titulos.length * 3 + 2, 6)]]));
  });
  linhas.push(registro([[1, 3, banco], [4, 7, '9999'], [8, 8, '9'], [18, 23, num(lista.length, 6)], [24, 29, num(seq + 2 * lista.length + 2, 6)]]));
  return `${linhas.join('\r\n')}\r\n\x1a`;
}
