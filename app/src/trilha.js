// Trilha de auditoria só de inclusão: o que foi liberado, aceito, ligado ou apagado, e quando. Nada aqui é alterado nem apagado.
export function registrar(db, acao, entidade, entidadeId, detalhe = null) {
  db.prepare('INSERT INTO auditoria_log (acao, entidade, entidade_id, detalhe) VALUES (?, ?, ?, ?)')
    .run(acao, entidade, entidadeId ?? null, detalhe === null ? null : String(typeof detalhe === 'string' ? detalhe : JSON.stringify(detalhe)).slice(0, 600));
}

export function historico(db, entidade, entidadeId) {
  return db.prepare('SELECT em, acao, detalhe FROM auditoria_log WHERE entidade = ? AND entidade_id = ? ORDER BY id DESC LIMIT 50').all(entidade, entidadeId);
}
