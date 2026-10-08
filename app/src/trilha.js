// Trilha de auditoria só de inclusão: o que foi liberado, aceito, ligado ou apagado, quando e por qual perfil. Nada aqui é alterado nem apagado.
import { AsyncLocalStorage } from 'node:async_hooks';

// perfil de quem está fazendo o pedido (dono ou lancamento); a API abre o contexto a cada pedido
export const contexto = new AsyncLocalStorage();
export const perfilAtual = () => contexto.getStore()?.perfil ?? null;

export function registrar(db, acao, entidade, entidadeId, detalhe = null) {
  db.prepare('INSERT INTO auditoria_log (acao, entidade, entidade_id, detalhe, perfil) VALUES (?, ?, ?, ?, ?)')
    .run(acao, entidade, entidadeId ?? null, detalhe === null ? null : String(typeof detalhe === 'string' ? detalhe : JSON.stringify(detalhe)).slice(0, 600), perfilAtual());
}

export function historico(db, entidade, entidadeId) {
  return db.prepare('SELECT em, acao, detalhe, perfil FROM auditoria_log WHERE entidade = ? AND entidade_id = ? ORDER BY id DESC LIMIT 50').all(entidade, entidadeId);
}
