// Ocorrências de auditoria de compras. Cada regra produz itens {chave, tipo, severidade, titulo, detalhe, entidade, id, estado, ...}.
// A ordem de importância: alta (pode ser prejuízo ou golpe) > media (conferir) > baixa (higiene).
import { lerConfig } from './db.js';
import { r2, somarDias, diasEntre } from './util.js';
import { formatarCnpj } from './documentos.js';
import { ErroValidacao, motivoValido, MOTIVO_MINIMO } from './validar.js';
import { ALOC_VALIDA, duplicatasComSaldo, destinatarioCasa } from './compras.js';
import { registrar } from './trilha.js';
import { saldoDoAdiantamento } from './grupo.js';
import { cruzarDda } from './dda.js';

const reais = (v) => Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const dataBR = (d) => (d ? d.split('-').reverse().join('/') : '');
const raiz = (c) => String(c ?? '').slice(0, 8);
const ORDEM = { alta: 0, media: 1, baixa: 2 };
const AUTORIZADA = new Set(['100', '150']);
/** A nota prova que existe quando tem protocolo de autorização no XML, ou quando alguém consultou a chave no portal e viu "autorizada" com o mesmo valor. */
const COM_XML_AUTORIZADO = (c) => c.nota_origem !== 'manual' && AUTORIZADA.has(String(c.nota_protocolo));
/** Acima deste valor, XML importado por quem lança (o sistema não confere a assinatura digital) pede a consulta do dono no portal. */
const LIMITE_XML_SEM_DONO = 2000;
const valorConfere = (c) => c.consulta_valor !== null && Math.abs(c.consulta_valor - c.nota_total) <= 0.05;
/** O destinatário que o portal mostrou (se mostrou) bate com a nota digitada, ou com a oficina ou uma empresa do grupo? Não mostrou: não há o que comparar. */
const destinatarioDaConsultaOk = (c, donos) => !c.consulta_destinatario
  || (c.cnpj_destinatario ? destinatarioCasa(c.consulta_destinatario, c.cnpj_destinatario) : donos.some((d) => destinatarioCasa(c.consulta_destinatario, d)));
/** A nota prova que existe quando tem protocolo de autorização no XML, ou quando o DONO consultou a chave no portal e viu "autorizada" com o mesmo valor (e, se o portal mostrou, a mesma emissão e o mesmo destinatário). */
const provaDaNota = (c, donos) => COM_XML_AUTORIZADO(c)
  || (c.consulta_situacao === 'autorizada' && c.consulta_por === 'dono' && valorConfere(c)
    && (!c.consulta_emissao || c.consulta_emissao === c.nota_emissao) && destinatarioDaConsultaOk(c, donos));

/** Por que a nota ainda não prova nada, em palavras que a pessoa sabe resolver. */
function motivoSemProva(c) {
  if (c.consulta_situacao === 'nao_encontrada') return `A chave da nota ${c.nota_numero} NÃO foi encontrada no portal da NF-e: a nota pode ser falsa ou a chave estar errada.`;
  if (c.consulta_situacao === 'autorizada' && c.consulta_valor !== null && !valorConfere(c)) {
    return `O portal mostrou R$ ${Number(c.consulta_valor).toFixed(2)} para a nota ${c.nota_numero}, mas ela foi digitada com R$ ${Number(c.nota_total).toFixed(2)}. Um dos dois está errado.`;
  }
  if (c.consulta_situacao === 'autorizada' && c.consulta_emissao && c.consulta_emissao !== c.nota_emissao) return `O portal mostrou a nota ${c.nota_numero} emitida em ${c.consulta_emissao.split('-').reverse().join('/')}, e ela foi digitada com outra data.`;
  if (c.consulta_situacao === 'autorizada' && c.consulta_destinatario && c.consulta_por === 'dono') return `O portal mostrou a nota ${c.nota_numero} para o destinatário ${c.consulta_destinatario}, que não é a oficina nem empresa do grupo: pode ser nota de outro cliente do fornecedor.`;
  if (c.consulta_situacao === 'autorizada' && c.consulta_por !== 'dono') return `A nota ${c.nota_numero} foi consultada no portal por quem lança. Falta o dono repetir a consulta (leva um minuto) e registrar.`;
  return `A nota ${c.nota_numero} foi digitada à mão ou o XML não traz protocolo de autorização, então não prova nada. Importe o XML autorizado ou consulte a chave no portal da NF-e e registre a consulta na nota.`;
}
const semZeros = (n) => String(n ?? '').replace(/\D/g, '').replace(/^0+/, '');

export function ocorrencias(db, hojeStr, cfg = lerConfig(db)) {
  const tol = cfg.toleranciaValor ?? 0.05;
  const out = [];
  // `estado` é a impressão digital do fato: um aceite só vale enquanto o fato for o mesmo (ex.: a divergência de R$ 30 aceita não cobre uma de R$ 310)
  const add = (o) => out.push({ ...o, chave: `${o.tipo}:${o.entidade}:${o.id}`, estado: String(o.estado ?? ''), boletos: o.boletos ?? [], notas: o.notas ?? [] });
  const aceites = new Map(db.prepare('SELECT * FROM auditoria_aceites').all().map((a) => [a.chave, a]));

  // empresas do mesmo grupo (locadora, oficina do sócio): CNPJ delas na nota ou no boleto não é golpe, mas também não é da oficina
  const grupo = new Map(db.prepare('SELECT * FROM empresas_grupo WHERE ativo = 1').all().map((e) => [e.cnpj, e]));
  const jaPagouAoFornecedor = new Set(db.prepare("SELECT DISTINCT fornecedor_id FROM boletos WHERE situacao = 'pago' AND fornecedor_id IS NOT NULL").all().map((x) => x.fornecedor_id));
  const boletos = db.prepare(`SELECT b.*, f.nome AS fornecedor, f.cnpj AS fornecedor_cnpj, f.beneficiarios_autorizados AS fornecedor_autorizados, f.confirmado_em AS fornecedor_confirmado
      FROM boletos b LEFT JOIN fornecedores f ON f.id = b.fornecedor_id WHERE b.situacao NOT IN ('cancelado','contestado')`).all();
  const concs = db.prepare(`SELECT c.*, n.numero AS nota_numero, n.fornecedor_id AS nota_fornecedor, n.origem AS nota_origem, n.cnpj_emitente, n.cnpj_destinatario, n.situacao AS nota_situacao,
      n.protocolo_status AS nota_protocolo, n.cnpj_receb, n.criado_por AS nota_criado_por, n.pago_no_ato AS nota_pago_no_ato, n.valor_total AS nota_total, n.data_emissao AS nota_emissao, n.consulta_situacao, n.consulta_valor, n.consulta_por, n.consulta_em, n.consulta_emissao, n.consulta_destinatario, d.valor AS dup_valor, d.vencimento AS dup_venc
      FROM conciliacoes c JOIN notas_compra n ON n.id = c.nota_id LEFT JOIN nota_duplicatas d ON d.id = c.duplicata_id`).all();
  const porBoleto = new Map();
  const porNota = new Map();
  for (const c of concs) {
    (porBoleto.get(c.boleto_id) ?? porBoleto.set(c.boleto_id, []).get(c.boleto_id)).push(c);
    (porNota.get(c.nota_id) ?? porNota.set(c.nota_id, []).get(c.nota_id)).push(c);
  }
  const nome = (b) => b.fornecedor ?? 'Fornecedor não informado';
  const comAdiantamento = new Set(db.prepare("SELECT DISTINCT boleto_id FROM adiantamentos WHERE origem = 'boleto' AND boleto_id IS NOT NULL").all().map((x) => x.boleto_id));

  if (!cfg.cnpjOficina) {
    add({ tipo: 'config_cnpj_oficina', severidade: 'media', entidade: 'config', id: 0, titulo: 'CNPJ da oficina não informado',
      detalhe: 'Sem o CNPJ da oficina o sistema não confere se a nota foi emitida para vocês nem se o pagador do boleto é vocês. Informe em Metas.' });
  }

  // ---------------------------------------------------------------- boletos
  const anterioresDoFornecedor = new Map();
  for (const b of boletos) {
    if (b.fornecedor_id && b.banco) (anterioresDoFornecedor.get(b.fornecedor_id) ?? anterioresDoFornecedor.set(b.fornecedor_id, []).get(b.fornecedor_id)).push(b);
  }
  for (const b of boletos) {
    const cs = porBoleto.get(b.id) ?? [];
    const resumoB = `${nome(b)}: ${reais(b.valor)}, vence ${dataBR(b.vencimento)}`;
    if (!cs.length) {
      add({ tipo: 'boleto_sem_nota', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento,
        titulo: b.situacao === 'pago' ? 'Boleto PAGO sem nota fiscal ligada' : 'Boleto sem nota fiscal',
        detalhe: `${resumoB}. Nenhuma nota fiscal explica este boleto. Peça a nota ao fornecedor (ou confirme que o boleto é da oficina) antes de pagar.` });
    } else {
      const soma = cs.reduce((a, c) => a + c.valor, 0);
      const dif = r2(b.valor - soma);
      if (dif > tol) {
        add({ tipo: 'boleto_valor_diverge', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], notas: cs.map((c) => c.nota_id), valor: dif, data: b.vencimento, estado: `a_mais:${dif}`,
          titulo: 'Boleto cobra mais do que as notas explicam',
          detalhe: `${resumoB}. As notas ligadas explicam ${reais(soma)}; sobram ${reais(dif)}. Pode ser juros/multa, nota faltando ou cobrança indevida.` });
      } else if (dif < -tol) {
        add({ tipo: 'boleto_valor_diverge', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], notas: cs.map((c) => c.nota_id), valor: -dif, data: b.vencimento, estado: `a_menos:${-dif}`,
          titulo: 'Valor ligado às notas é maior que o boleto', detalhe: `${resumoB}. Você ligou ${reais(soma)} de notas a um boleto de ${reais(b.valor)}: confira a ligação.` });
      }
      for (const c of cs) {
        if (c.dup_venc && c.dup_venc !== b.vencimento) {
          add({ tipo: 'boleto_venc_diverge', severidade: 'baixa', entidade: 'boleto', id: b.id, boletos: [b.id], notas: [c.nota_id], data: b.vencimento, estado: c.dup_venc,
            titulo: 'Vencimento diferente da parcela da nota', detalhe: `${resumoB}, mas a parcela da nota ${c.nota_numero} vence em ${dataBR(c.dup_venc)}.` });
          break;
        }
      }
      for (const c of cs) {
        if (c.nota_situacao === 'cancelada') {
          const denegada = ['110', '301', '302', '303'].includes(c.nota_protocolo);
          add({ tipo: 'nota_cancelada_com_boleto', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], notas: [c.nota_id], valor: b.valor, data: b.vencimento,
            titulo: denegada ? 'Boleto de nota DENEGADA' : 'Boleto de nota CANCELADA',
            detalhe: `${resumoB}. A nota ${c.nota_numero} foi ${denegada ? 'denegada (não vale como nota fiscal)' : 'cancelada'}: não deveria haver cobrança.` });
          break;
        }
      }
      // a nota que "explica" o boleto precisa ter prova: XML com protocolo de autorização. Nota digitada à mão ou XML sem protocolo não prova nada.
      const donos = [cfg.cnpjOficina, ...grupo.keys()].filter(Boolean);
      const semProva = cs.filter((c) => !provaDaNota(c, donos));
      if (semProva.length) {
        add({ tipo: 'boleto_nota_sem_prova', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], notas: semProva.map((c) => c.nota_id), valor: b.valor, data: b.vencimento, estado: semProva.map((c) => `${c.nota_id}:${c.consulta_situacao ?? ''}:${c.consulta_por ?? ''}:${c.consulta_valor ?? ''}:${c.consulta_emissao ?? ''}:${c.consulta_destinatario ?? ''}`).join(','),
          titulo: 'Boleto ligado a nota sem comprovação',
          detalhe: `${resumoB}. ${semProva.map(motivoSemProva).join(' ')}` });
      }
      // a consulta no portal contradiz uma nota que tinha XML com protocolo (XML forjado ou chave de outra nota): vale o portal
      const contradita = cs.filter((c) => COM_XML_AUTORIZADO(c) && c.consulta_situacao && (c.consulta_situacao === 'nao_encontrada' || (c.consulta_situacao === 'autorizada' && !valorConfere(c))));
      if (contradita.length) {
        add({ tipo: 'nota_contradita_pelo_portal', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], notas: contradita.map((c) => c.nota_id), valor: b.valor, data: b.vencimento, estado: contradita.map((c) => `${c.nota_id}:${c.consulta_situacao}:${c.consulta_valor ?? ''}`).join(','),
          titulo: 'O portal da NF-e contradiz a nota', detalhe: `${resumoB}. ${contradita.map(motivoSemProva).join(' ')} O arquivo XML da nota não prova nada contra o portal.` });
      }
      // o XML que quem lança importa não tem a assinatura digital conferida aqui: para boleto de valor alto, o dono confirma a nota no portal
      if (b.situacao === 'aberto' && b.valor >= LIMITE_XML_SEM_DONO) {
        const soDeQuemLanca = cs.filter((c) => COM_XML_AUTORIZADO(c) && c.nota_criado_por === 'lancamento' && !(c.consulta_situacao === 'autorizada' && c.consulta_por === 'dono' && valorConfere(c)));
        if (soDeQuemLanca.length) {
          add({ tipo: 'nota_xml_sem_conferencia_do_dono', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], notas: soDeQuemLanca.map((c) => c.nota_id), valor: b.valor, data: b.vencimento, estado: soDeQuemLanca.map((c) => c.nota_id).join(','),
            titulo: 'Nota por XML de quem lança, sem consulta do dono',
            detalhe: `${resumoB}. A nota ${soDeQuemLanca.map((c) => c.nota_numero).join(', ')} veio de um XML importado por quem lança, e o sistema não confere a assinatura digital do arquivo. Para um valor desses, o dono consulta a chave no portal da NF-e (leva um minuto) e registra a consulta na nota.` });
        }
      }
      // boleto ligado a nota que o próprio XML diz ter sido paga na hora (Pix, dinheiro, cartão): seria cobrança em dobro
      const jaPagasNoAto = cs.filter((c) => c.nota_pago_no_ato);
      if (jaPagasNoAto.length) {
        add({ tipo: 'boleto_nota_paga_no_ato', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], notas: jaPagasNoAto.map((c) => c.nota_id), valor: b.valor, data: b.vencimento, estado: jaPagasNoAto.map((c) => c.nota_id).join(','),
          titulo: 'Boleto de nota que já foi paga na hora',
          detalhe: `${resumoB}. A nota ${jaPagasNoAto.map((c) => c.nota_numero).join(', ')} informa pagamento imediato (Pix, dinheiro ou cartão) e não traz parcelas: não deveria haver boleto dela. Pode ser cobrança em dobro.` });
      }
      // quem lança viu "cancelada/denegada" no portal: não cancela sozinha (é o dono quem confere), mas o boleto não pode ser pago assim
      const canceladaPendente = cs.filter((c) => c.nota_situacao === 'ativa' && (c.consulta_situacao === 'cancelada' || c.consulta_situacao === 'denegada') && c.consulta_por === 'lancamento');
      if (canceladaPendente.length) {
        add({ tipo: 'nota_consulta_cancelada_pendente', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], notas: canceladaPendente.map((c) => c.nota_id), valor: b.valor, data: b.vencimento, estado: canceladaPendente.map((c) => `${c.nota_id}:${c.consulta_situacao}`).join(','),
          titulo: 'Quem lança viu a nota CANCELADA no portal', detalhe: `${resumoB}. A nota ${canceladaPendente.map((c) => c.nota_numero).join(', ')} aparece como ${canceladaPendente[0].consulta_situacao === 'denegada' ? 'denegada' : 'cancelada'} na consulta registrada por quem lança. O dono repete a consulta no portal e registra (se confirmar, a nota é cancelada aqui). Não pague antes.` });
      }
      // nota sem XML cuja prova é só uma consulta antiga: nota autorizada ainda pode ser cancelada depois (em geral até 24 h, às vezes mais)
      if (b.situacao === 'aberto' && b.vencimento <= somarDias(hojeStr, 3)) {
        const antigas = cs.filter((c) => !COM_XML_AUTORIZADO(c) && c.consulta_situacao === 'autorizada' && c.consulta_em && diasEntre(c.consulta_em, hojeStr) > 2);
        if (antigas.length) {
          add({ tipo: 'nota_consulta_antiga', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], notas: antigas.map((c) => c.nota_id), valor: b.valor, data: b.vencimento, estado: antigas.map((c) => `${c.nota_id}:${c.consulta_em}`).join(','),
            titulo: 'Consulte a nota de novo antes de pagar', detalhe: `${resumoB}. A nota ${antigas.map((c) => c.nota_numero).join(', ')} foi consultada no portal em ${antigas.map((c) => dataBR(c.consulta_em)).join(', ')}. Uma nota autorizada ainda pode ser cancelada depois: consulte de novo no dia do pagamento (leva um minuto).` });
        }
      }
      // nota digitada sem o destinatário: não dá para saber se foi emitida para a oficina ou para outro cliente do fornecedor
      const semDestinatario = cs.filter((c) => c.nota_origem === 'manual' && !c.cnpj_destinatario);
      if (semDestinatario.length) {
        add({ tipo: 'nota_sem_destinatario', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], notas: semDestinatario.map((c) => c.nota_id), valor: b.valor, data: b.vencimento, estado: semDestinatario.map((c) => c.nota_id).join(','),
          titulo: 'Não se sabe para quem a nota foi emitida', detalhe: `${resumoB}. A nota ${semDestinatario.map((c) => c.nota_numero).join(', ')} foi digitada sem o CNPJ do destinatário (quem comprou). Informe o que está na DANFE, e, na consulta do portal, o destinatário que ele mostrar: nota de outro cliente do fornecedor parece igual.` });
      }
      const outroFornecedor = b.fornecedor_id ? cs.find((c) => c.nota_fornecedor !== b.fornecedor_id) : null;
      if (outroFornecedor) {
        add({ tipo: 'boleto_fornecedor_diverge', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], notas: [outroFornecedor.nota_id], valor: b.valor, data: b.vencimento, estado: String(outroFornecedor.nota_id),
          titulo: 'Boleto ligado a nota de outro fornecedor', detalhe: `${resumoB}. A nota ${outroFornecedor.nota_numero} é de outro fornecedor. Boleto e nota precisam ser da mesma empresa.` });
      }
      // número do documento impresso que contradiz a nota (alguns fornecedores numeram por conta própria: por isso é só "conferir")
      const docs = [...String(b.numero_documento ?? '').matchAll(/\d+/g)].map((m) => semZeros(m[0])).filter((x) => x.length >= 3);
      if (docs.length && !cs.some((c) => docs.some((d) => d === semZeros(c.nota_numero) || d.startsWith(semZeros(c.nota_numero))))) {
        add({ tipo: 'boleto_doc_diverge', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], notas: cs.map((c) => c.nota_id), valor: b.valor, data: b.vencimento, estado: b.numero_documento,
          titulo: 'O documento impresso no boleto não é o da nota', detalhe: `${resumoB}. O boleto diz "doc ${b.numero_documento}" e a nota ligada é a ${cs.map((c) => c.nota_numero).join(', ')}. Pode ser numeração do fornecedor, mas confirme.` });
      }
    }
    // notas de empresas diferentes no mesmo boleto: o custo de uma não é da outra e não há como dividir sozinho
    const donosDasNotas = new Set(cs.map((c) => (c.cnpj_destinatario && grupo.has(c.cnpj_destinatario) ? `g${grupo.get(c.cnpj_destinatario).id}` : 'oficina')));
    if (donosDasNotas.size > 1) {
      add({ tipo: 'boleto_notas_de_empresas_misturadas', severidade: 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], notas: cs.map((c) => c.nota_id), valor: b.valor, data: b.vencimento, estado: [...donosDasNotas].sort().join(','),
        titulo: 'Boleto cobre notas de empresas diferentes', detalhe: `${resumoB}. As notas ligadas foram emitidas para empresas diferentes (oficina e empresa do grupo). Separe o boleto: o dono divide o valor entre as empresas (Compras > Grupo > Registrar acerto) antes de pagar.` });
    }
    // ligação feita à mão por quem lança que o sistema não sugeriria (nem pelo valor, nem pela parcela, nem pelo número do documento)
    const docsB = [...String(b.numero_documento ?? '').matchAll(/\d+/g)].map((m) => semZeros(m[0])).filter((x) => x.length >= 3);
    const duvidosa = cs.filter((c) => c.origem === 'manual' && c.criado_por === 'lancamento'
      && !(c.dup_valor != null && Math.abs(c.dup_valor - b.valor) <= tol) && !(Math.abs(c.nota_total - b.valor) <= tol) && !docsB.some((d) => d === semZeros(c.nota_numero)));
    if (duvidosa.length) {
      add({ tipo: 'ligacao_manual_sem_aderencia', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], notas: duvidosa.map((c) => c.nota_id), valor: b.valor, data: b.vencimento, estado: duvidosa.map((c) => `${c.nota_id}:${c.valor}`).join(','),
        titulo: 'Ligação à mão que o sistema não sugeriria',
        detalhe: `${resumoB}. Quem lança ligou a nota ${duvidosa.map((c) => c.nota_numero).join(', ')} a este boleto, mas nem o valor, nem a parcela, nem o número do documento batem. Confira se a nota é mesmo deste boleto antes de pagar.` });
    }
    // boleto já pago pela oficina, mas que é de empresa do grupo (cadastrada depois): o dinheiro que a oficina adiantou ficou fora do acerto
    if (b.situacao === 'pago' && !b.empresa_id && b.pagador_cnpj && grupo.has(b.pagador_cnpj) && !comAdiantamento.has(b.id)) {
      add({ tipo: 'boleto_pago_de_empresa_do_grupo', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento, estado: b.pagador_cnpj,
        titulo: `Boleto pago pela oficina é da ${grupo.get(b.pagador_cnpj).nome}`,
        detalhe: `${resumoB}. O pagador impresso é a ${grupo.get(b.pagador_cnpj).nome}, mas o pagamento não entrou no acerto entre empresas. Registre em Compras > Grupo > Registrar acerto (a oficina pagou por ela) ou marque como conferido se já foi resolvido.` });
    }
    // beneficiário x emitente / fornecedor
    if (b.beneficiario_cnpj) {
      // quem pode receber: o fornecedor, o emitente das notas ligadas, o recebedor que a nota informa e os CNPJs autorizados no cadastro
      const autorizados = String(b.fornecedor_autorizados ?? '').split(',').filter(Boolean);
      const permitidos = new Set([b.fornecedor_cnpj, ...cs.map((c) => c.cnpj_emitente), ...cs.map((c) => c.cnpj_receb), ...autorizados].filter(Boolean));
      const esperados = [...new Set([b.fornecedor_cnpj, ...cs.map((c) => c.cnpj_emitente)].filter(Boolean))];
      const diverge = permitidos.size && !permitidos.has(b.beneficiario_cnpj) ? esperados.find((e) => e !== b.beneficiario_cnpj) ?? [...permitidos][0] : null;
      if (diverge) {
        const mesmaRaiz = raiz(diverge) === raiz(b.beneficiario_cnpj);
        add({ tipo: 'boleto_beneficiario_diverge', severidade: mesmaRaiz ? 'media' : 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento, estado: b.beneficiario_cnpj,
          titulo: mesmaRaiz ? 'Boleto emitido por outra filial do fornecedor' : 'Beneficiário do boleto NÃO é o fornecedor',
          detalhe: `${resumoB}. O boleto é de ${formatarCnpj(b.beneficiario_cnpj)} e o fornecedor/nota é ${formatarCnpj(diverge)}.${mesmaRaiz ? ' Mesma raiz de CNPJ: confirme se é filial.' : ' Pode ser boleto trocado ou golpe: confirme por telefone com o fornecedor antes de pagar.'}` });
      }
    }
    // pagador do boleto: a oficina, uma empresa do grupo (não é alerta: o veredito avisa de quem é) ou um CNPJ desconhecido (alerta)
    if (b.pagador_cnpj && cfg.cnpjOficina && b.pagador_cnpj !== cfg.cnpjOficina && !grupo.has(b.pagador_cnpj)) {
      const mesmaRaiz = raiz(b.pagador_cnpj) === raiz(cfg.cnpjOficina);
      add({ tipo: 'boleto_pagador_diverge', severidade: mesmaRaiz ? 'media' : 'alta', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento, estado: b.pagador_cnpj,
        titulo: 'Boleto emitido contra outro CNPJ', detalhe: `${resumoB}. O pagador do boleto é ${formatarCnpj(b.pagador_cnpj)}, não a oficina (${formatarCnpj(cfg.cnpjOficina)}) nem uma empresa cadastrada do grupo. Este boleto pode não ser de vocês. Se for da locadora ou da oficina do sócio, cadastre a empresa em Compras > Empresas do grupo.` });
    }
    // a nota foi emitida para uma empresa e o boleto cobra outra: o fornecedor fez dois documentos que não conversam
    if (b.pagador_cnpj) {
      const destDiferente = cs.find((c) => c.cnpj_destinatario && c.cnpj_destinatario !== b.pagador_cnpj);
      if (destDiferente) {
        add({ tipo: 'boleto_nota_empresas_diferentes', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], notas: [destDiferente.nota_id], valor: b.valor, data: b.vencimento, estado: `${b.pagador_cnpj}|${destDiferente.cnpj_destinatario}`,
          titulo: 'Nota e boleto em nome de empresas diferentes',
          detalhe: `${resumoB}. O boleto é contra ${formatarCnpj(b.pagador_cnpj)} e a nota ${destDiferente.nota_numero} foi emitida para ${formatarCnpj(destDiferente.cnpj_destinatario)}. Pergunte ao fornecedor qual está certo antes de pagar.` });
      }
    }
    if (b.situacao === 'aberto') {
      const urgente = b.vencimento <= somarDias(hojeStr, 3);
      // o CNPJ de quem recebe não vem no código de barras: sem ele digitado (ou lido do texto do boleto) a conferência principal não acontece
      if (!b.beneficiario_cnpj && b.banco) {
        add({ tipo: 'boleto_sem_beneficiario', severidade: urgente ? 'media' : 'baixa', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento,
          titulo: 'Falta conferir quem recebe este boleto',
          detalhe: `${resumoB}. O CNPJ do beneficiário não foi informado, então o sistema não consegue dizer se o dinheiro vai para o fornecedor. Informe-o no boleto (botão "Informar quem recebe") ou confira no app do banco antes de pagar.` });
      }
      if (!b.pagador_cnpj && cfg.cnpjOficina && b.banco) {
        add({ tipo: 'boleto_sem_pagador', severidade: urgente ? 'media' : 'baixa', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento,
          titulo: 'Falta conferir contra quem o boleto foi emitido', detalhe: `${resumoB}. O CNPJ do pagador não foi informado: o sistema não sabe se o boleto é da oficina ou de outro cliente do fornecedor.` });
      }
      if (!b.codigo_barras) {
        add({ tipo: 'boleto_sem_codigo', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento,
          titulo: 'Boleto cadastrado sem linha digitável', detalhe: `${resumoB}. Sem a linha digitável o sistema não confere valor, vencimento, banco nem duplicidade. Cadastre de novo com a linha.` });
      }
      if (b.fornecedor_id && !b.fornecedor_confirmado) {
        // a primeira vez que se paga a um fornecedor é o momento de maior risco (fornecedor inventado ou trocado): bloqueia até o dono confirmar
        const primeira = !jaPagouAoFornecedor.has(b.fornecedor_id);
        add({ tipo: 'fornecedor_nao_confirmado', severidade: primeira ? 'alta' : 'media', entidade: 'fornecedor', id: b.fornecedor_id, boletos: [b.id], valor: b.valor, data: b.vencimento, estado: primeira ? 'primeira_vez' : 'ja_pago',
          titulo: primeira ? 'Primeiro pagamento a um fornecedor não confirmado' : 'Fornecedor ainda não confirmado',
          detalhe: `${nome(b)} nunca teve o CNPJ e o telefone conferidos por uma pessoa${primeira ? ' e ainda não recebeu nenhum pagamento por aqui' : ''}. O dono confere em Compras > Fornecedores pelo cartão CNPJ e por um telefone que a oficina já tinha, e marca "conferido".` });
      }
    }
    // fornecedor que sempre cobrou por um banco e agora aparece com outro: sinal clássico de boleto adulterado (ou de troca legítima de conta)
    if (b.fornecedor_id && b.banco) {
      const anteriores = (anterioresDoFornecedor.get(b.fornecedor_id) ?? []).filter((x) => x.id < b.id);
      if (anteriores.length >= 2 && anteriores.every((x) => x.banco !== b.banco)) {
        add({ tipo: 'boleto_banco_novo', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento,
          titulo: 'Banco diferente do que o fornecedor costuma usar',
          detalhe: `${resumoB}. É um boleto do banco ${b.banco}; os ${anteriores.length} anteriores deste fornecedor eram de ${[...new Set(anteriores.map((x) => x.banco))].join(', ')}. Pode ser troca de conta, mas é também o sinal mais comum de boleto adulterado: confirme por telefone.` });
      }
    }
    if (b.situacao === 'aberto' && b.vencimento < hojeStr) {
      add({ tipo: 'boleto_vencido', severidade: 'media', entidade: 'boleto', id: b.id, boletos: [b.id], valor: b.valor, data: b.vencimento,
        titulo: 'Boleto vencido e não pago', detalhe: `${resumoB} (${diasEntre(b.vencimento, hojeStr)} dia(s) de atraso). Pergunte o valor atualizado com juros e multa.` });
    }
  }

  // duplicidade provável: mesmo fornecedor, valor e vencimento; ou o mesmo título (mesmo nosso número) com outro vencimento (2ª via)
  const grupos = new Map();
  const titulos = new Map();
  for (const b of boletos) {
    if (!b.fornecedor_id) continue;
    const k = `${b.fornecedor_id}|${b.valor}|${b.vencimento}`;
    (grupos.get(k) ?? grupos.set(k, []).get(k)).push(b);
    if (b.codigo_barras) {
      const t = `${b.banco}|${b.codigo_barras.slice(19)}`;
      (titulos.get(t) ?? titulos.set(t, []).get(t)).push(b);
    }
  }
  for (const lista of grupos.values()) {
    if (lista.length > 1) {
      const [primeiro] = lista;
      add({ tipo: 'boleto_duplicado', severidade: 'alta', entidade: 'boleto', id: primeiro.id, boletos: lista.map((x) => x.id), valor: primeiro.valor, data: primeiro.vencimento, estado: lista.map((x) => x.id).sort((a, c) => a - c).join(','),
        titulo: 'Possível boleto em duplicidade', detalhe: `${lista.length} boletos de ${nome(primeiro)} com o mesmo valor (${reais(primeiro.valor)}) e vencimento (${dataBR(primeiro.vencimento)}). Pague só um, depois de confirmar com o fornecedor.` });
    }
  }
  for (const lista of titulos.values()) {
    const venc = new Set(lista.map((x) => `${x.vencimento}|${x.valor}`));
    if (lista.length > 1 && venc.size > 1) {
      const [primeiro] = lista;
      add({ tipo: 'boleto_mesmo_titulo', severidade: 'alta', entidade: 'boleto', id: primeiro.id, boletos: lista.map((x) => x.id), valor: primeiro.valor, data: primeiro.vencimento, estado: lista.map((x) => x.id).sort((a, c) => a - c).join(','),
        titulo: 'Segunda via do mesmo título', detalhe: `${lista.length} boletos de ${nome(primeiro)} têm o mesmo número de título (nosso número) com vencimento ou valor diferente. Provavelmente é a segunda via do mesmo boleto: pague só um.` });
    }
  }

  // ---------------------------------------------------------------- notas
  const notas = db.prepare(`SELECT n.id, n.fornecedor_id, n.chave, n.numero, n.data_emissao, n.valor_total, n.valor_com_tributos, n.cnpj_destinatario, n.nome_destinatario,
      n.finalidade, n.situacao, n.origem, n.pago_no_ato, f.nome AS fornecedor FROM notas_compra n JOIN fornecedores f ON f.id = n.fornecedor_id`).all();
  const itens = db.prepare(`SELECT i.id, i.nota_id, i.codigo, i.descricao, i.quantidade, i.valor_unitario,
      i.quantidade - COALESCE((SELECT SUM(a.quantidade) FROM alocacoes a WHERE a.item_id = i.id AND ${ALOC_VALIDA}), 0) AS resta_qtd,
      i.custo_total - COALESCE((SELECT SUM(a.valor) FROM alocacoes a WHERE a.item_id = i.id AND ${ALOC_VALIDA}), 0) AS resta_valor
      FROM nota_itens i ORDER BY i.nota_id, i.n_item`).all();
  const itensPorNota = new Map();
  for (const i of itens) (itensPorNota.get(i.nota_id) ?? itensPorNota.set(i.nota_id, []).get(i.nota_id)).push(i);
  const notaPorId = new Map(notas.map((n) => [n.id, n]));
  const historico = new Map();                                          // fornecedor|código -> compras anteriores em ordem
  for (const n of [...notas].sort((a, b) => (a.data_emissao < b.data_emissao ? -1 : a.data_emissao > b.data_emissao ? 1 : a.id - b.id))) {
    if (n.finalidade === 'devolucao' || n.finalidade === 'ajuste') continue;
    for (const i of itensPorNota.get(n.id) ?? []) {
      if (!i.codigo || !(i.valor_unitario > 0)) continue;
      const k = `${n.fornecedor_id}|${i.codigo}`;
      (historico.get(k) ?? historico.set(k, []).get(k)).push({ vu: i.valor_unitario, nota: n.id });
    }
  }

  for (const n of notas) {
    const cs = porNota.get(n.id) ?? [];
    const conciliado = cs.reduce((a, c) => a + c.valor, 0);
    const limite = Math.max(n.valor_total, n.valor_com_tributos ?? 0);
    if (n.cnpj_destinatario && cfg.cnpjOficina && n.cnpj_destinatario !== cfg.cnpjOficina && !grupo.has(n.cnpj_destinatario)) {
      add({ tipo: 'nota_destinatario_diverge', severidade: raiz(n.cnpj_destinatario) === raiz(cfg.cnpjOficina) ? 'media' : 'alta', entidade: 'nota', id: n.id, notas: [n.id], boletos: cs.map((c) => c.boleto_id), valor: n.valor_total, data: n.data_emissao, estado: n.cnpj_destinatario,
        titulo: 'Nota emitida para outro CNPJ', detalhe: `Nota ${n.numero} de ${n.fornecedor} (${reais(n.valor_total)}) está em nome de ${formatarCnpj(n.cnpj_destinatario)}${n.nome_destinatario ? ` (${n.nome_destinatario})` : ''}, e não da oficina nem de uma empresa cadastrada do grupo. Se for da locadora ou da oficina do sócio, cadastre a empresa em Compras > Empresas do grupo.` });
    }
    if (conciliado > limite + tol) {
      add({ tipo: 'nota_cobrada_a_mais', severidade: 'alta', entidade: 'nota', id: n.id, notas: [n.id], boletos: cs.map((c) => c.boleto_id), valor: r2(conciliado - limite), data: n.data_emissao, estado: String(r2(conciliado)),
        titulo: 'Boletos somam mais que a nota', detalhe: `Nota ${n.numero} de ${n.fornecedor}: ${reais(limite)}. Os boletos ligados somam ${reais(conciliado)}. Possível cobrança em duplicidade.` });
    }
    if (n.finalidade === 'devolucao' || n.finalidade === 'ajuste') continue;       // devolução é crédito e ajuste é fiscal: nenhum gera boleto
    if (n.situacao === 'cancelada') continue;
    const saldo = r2(n.valor_total - conciliado);
    if (saldo > tol) {
      const dups = duplicatasComSaldo(db, n.id).filter((d) => d.saldo > tol);
      if (dups.length) {
        const prox = dups[0];
        if (prox.vencimento <= somarDias(hojeStr, cfg.diasNotaSemBoleto ?? 5)) {
          add({ tipo: 'nota_sem_boleto', severidade: 'media', entidade: 'nota', id: n.id, notas: [n.id], valor: r2(dups.reduce((a, d) => a + d.saldo, 0)), data: prox.vencimento, estado: `${prox.id}:${prox.vencimento}`,
            titulo: prox.vencimento < hojeStr ? 'Parcela vencida sem boleto' : 'Parcela perto de vencer sem boleto',
            detalhe: `Nota ${n.numero} de ${n.fornecedor}: parcela de ${reais(prox.saldo)} ${prox.vencimento < hojeStr ? 'venceu' : 'vence'} em ${dataBR(prox.vencimento)} e nenhum boleto foi ligado. Se já foi paga de outro jeito (Pix, cartão, dinheiro), marque como conferida com o motivo.` });
        }
      } else if (diasEntre(n.data_emissao, hojeStr) >= 3 && !n.pago_no_ato) {
        add({ tipo: 'nota_sem_boleto', severidade: 'baixa', entidade: 'nota', id: n.id, notas: [n.id], valor: saldo, data: n.data_emissao,
          titulo: 'Nota sem boleto e sem parcelas', detalhe: `Nota ${n.numero} de ${n.fornecedor} (${reais(saldo)}) não tem boleto nem parcelas informadas. Se foi paga à vista (Pix, cartão, dinheiro), marque como conferida com o motivo.` });
      }
    }
    if (n.origem === 'manual' && !n.chave && n.valor_total >= 100) {
      add({ tipo: 'nota_sem_chave', severidade: 'baixa', entidade: 'nota', id: n.id, notas: [n.id], valor: n.valor_total, data: n.data_emissao,
        titulo: 'Nota digitada sem chave de acesso', detalhe: `Nota ${n.numero} de ${n.fornecedor} foi lançada à mão. Sem a chave de 44 dígitos não dá para provar que ela existe; peça o XML ou consulte o portal da NF-e.` });
    }
    // peças sem destino
    const sem = (itensPorNota.get(n.id) ?? []).filter((i) => i.resta_qtd > 1e-6);
    if (sem.length && diasEntre(n.data_emissao, hojeStr) >= (cfg.diasNotaSemDestino ?? 7)) {
      const valor = r2(sem.reduce((a, i) => a + i.resta_valor, 0));
      add({ tipo: 'nota_sem_destino', severidade: valor >= 50 ? 'media' : 'baixa', entidade: 'nota', id: n.id, notas: [n.id], valor, data: n.data_emissao,
        titulo: 'Peças da nota sem destino', detalhe: `Nota ${n.numero} de ${n.fornecedor}: ${sem.length} item(ns), ${reais(valor)}, sem OS, estoque ou uso interno. Peça comprada e não aplicada é dinheiro parado ou perdido.` });
    }
    // preço acima do histórico
    if (diasEntre(n.data_emissao, hojeStr) <= 90) {
      for (const i of itensPorNota.get(n.id) ?? []) {
        if (!i.codigo || !(i.valor_unitario > 0)) continue;
        const hist = historico.get(`${n.fornecedor_id}|${i.codigo}`) ?? [];
        const pos = hist.findIndex((h) => h.nota === n.id);
        const ant = hist.slice(Math.max(0, pos - 5), pos).map((x) => x.vu).sort((a, b) => a - b);
        if (pos > 0 && ant.length >= 2) {
          const mediana = ant[Math.floor(ant.length / 2)];
          if (i.valor_unitario > mediana * (1 + (cfg.variacaoPrecoPct ?? 0.15)) && i.valor_unitario - mediana >= 3) {
            add({ tipo: 'preco_acima', severidade: 'baixa', entidade: 'item', id: i.id, notas: [n.id], valor: r2(i.valor_unitario - mediana), data: n.data_emissao,
              titulo: 'Preço acima do que costuma ser', detalhe: `${i.descricao} (nota ${n.numero}, ${n.fornecedor}): ${reais(i.valor_unitario)} cada; nas últimas compras a mediana foi ${reais(mediana)} (+${Math.round((i.valor_unitario / mediana - 1) * 100)}%).` });
          }
        }
      }
    }
  }
  void notaPorId;

  // ---------------------------------------------------------------- OS
  const desde = cfg.auditoriaDesde || `${hojeStr.slice(0, 7)}-01`;
  const vendas = db.prepare(`SELECT v.id, v.numero, v.placa, v.veiculo, v.data, v.custo_pecas, v.custo_pecas_auto,
      COALESCE((SELECT SUM(a.valor) FROM alocacoes a JOIN nota_itens i ON i.id = a.item_id JOIN notas_compra n ON n.id = i.nota_id
        WHERE a.venda_id = v.id AND a.destino = 'os' AND n.situacao = 'ativa'), 0) AS nf_valor,
      (SELECT COUNT(*) FROM alocacoes a WHERE a.venda_id = v.id AND a.destino = 'os') AS nf_qtd
      FROM vendas v WHERE v.situacao IN ('concluida','aberta') AND v.custo_pecas IS NOT NULL`).all();
  const semNota = [];
  for (const v of vendas) {
    const rotulo = `OS ${v.numero ?? v.id} ${v.placa ?? ''}`.trim();
    if (v.nf_qtd > 0 && Math.abs(v.custo_pecas - v.nf_valor) > Math.max(5, v.custo_pecas * 0.05)) {
      add({ tipo: 'custo_os_diverge', severidade: 'media', entidade: 'os', id: v.id, valor: r2(v.custo_pecas - v.nf_valor), data: v.data, estado: `${v.custo_pecas}|${v.nf_valor}`,
        titulo: 'Custo da OS diferente das notas', detalhe: `${rotulo}: custo ${v.custo_pecas_auto ? 'calculado' : 'digitado'} ${reais(v.custo_pecas)}, mas as notas ligadas somam ${reais(v.nf_valor)}. Um dos dois está errado.` });
    }
    if (v.nf_qtd === 0 && v.data >= desde && v.custo_pecas >= 30) semNota.push(v);
  }
  if (semNota.length) {
    const total = r2(semNota.reduce((a, v) => a + v.custo_pecas, 0));
    const maiores = [...semNota].sort((a, b) => b.custo_pecas - a.custo_pecas).slice(0, 3).map((v) => `OS ${v.numero ?? v.id} (${reais(v.custo_pecas)})`).join(', ');
    add({ tipo: 'os_sem_nota', severidade: 'baixa', entidade: 'os', id: 0, valor: total, data: desde, estado: `${desde}|${semNota.length}`,
      titulo: `${semNota.length} OS com custo de peça e nenhuma nota ligada`,
      detalhe: `Desde ${dataBR(desde)}: ${reais(total)} de custo de peças que não se prova com nota. Maiores: ${maiores}. Ligue as peças às OS em Compras > Notas para saber o custo real e achar compra sem destino.` });
  }

  // peça de nota em nome de outra empresa do grupo aplicada em OS da oficina: alguém pagou por algo que é da oficina
  if (grupo.size) {
    const emOs = db.prepare(`SELECT n.id, n.numero, n.cnpj_destinatario, n.data_emissao, f.nome AS fornecedor, SUM(a.valor) AS v FROM alocacoes a
        JOIN nota_itens i ON i.id = a.item_id JOIN notas_compra n ON n.id = i.nota_id JOIN fornecedores f ON f.id = n.fornecedor_id
        WHERE a.destino = 'os' AND n.situacao = 'ativa' AND n.cnpj_destinatario IS NOT NULL AND ${ALOC_VALIDA} GROUP BY n.id`).all();
    for (const x of emOs) {
      const emp = grupo.get(x.cnpj_destinatario);
      if (!emp) continue;
      add({ tipo: 'peca_de_empresa_na_os', severidade: 'media', entidade: 'nota', id: x.id, notas: [x.id], valor: r2(x.v), data: x.data_emissao, estado: String(r2(x.v)),
        titulo: `Peças pagas pela ${emp.nome} usadas em OS da oficina`,
        detalhe: `Nota ${x.numero} de ${x.fornecedor} está em nome da ${emp.nome}, mas ${reais(x.v)} dela foram aplicados em OS da oficina. A ${emp.nome} pagou por algo que é custo da oficina: registre o acerto em Compras > Entre empresas (a oficina deve à ${emp.nome}) ou marque como conferida se já foi combinado.` });
    }
  }

  // DDA do banco x boletos cadastrados (só existe quando o dono importou o arquivo do banco)
  const dda = cruzarDda(db, hojeStr, cfg);
  for (const x of dda.sem_cadastro) {
    const t = x.titulo;
    add({ tipo: 'dda_sem_cadastro', severidade: 'alta', entidade: 'dda', id: t.id, valor: t.valor, data: t.vencimento, estado: `${t.valor}|${t.vencimento}`,
      titulo: 'Boleto no DDA do banco que ninguém cadastrou',
      detalhe: `O banco mostra no DDA um boleto de ${reais(t.valor)} vencendo em ${dataBR(t.vencimento)}${t.beneficiario_nome ? `, de ${t.beneficiario_nome}` : ''}${t.beneficiario_cnpj ? ` (CNPJ ${formatarCnpj(t.beneficiario_cnpj)})` : ''}, contra ${x.escopo ? 'a empresa do grupo' : 'o CNPJ da oficina'}, e ele NÃO está cadastrado no sistema. Pode ser só falta de cadastro (peça a nota ao fornecedor e cadastre) ou cobrança que não é de vocês. Não pague antes de conferir.` });
  }
  for (const x of dda.diverge.filter((d) => ['aberto', 'pago'].includes(d.boleto.situacao))) {
    add({ tipo: 'dda_diverge', severidade: 'alta', entidade: 'boleto', id: x.boleto.id, boletos: [x.boleto.id], valor: x.boleto.valor, data: x.boleto.vencimento, estado: x.diferencas.join('|'),
      titulo: 'Boleto cadastrado diferente do que o banco mostra no DDA',
      detalhe: `${nome(x.boleto)}: ${x.diferencas.join('; ')}. O DDA vem do banco; o cadastro foi digitado. Confira o papel do boleto antes de pagar.` });
  }
  for (const x of dda.fora_do_dda) {
    add({ tipo: 'boleto_fora_do_dda', severidade: 'media', entidade: 'boleto', id: x.boleto.id, boletos: [x.boleto.id], valor: x.boleto.valor, data: x.boleto.vencimento,
      titulo: 'Boleto cadastrado que o banco não mostra no DDA',
      detalhe: `${nome(x.boleto)}: ${reais(x.boleto.valor)}, vence ${dataBR(x.boleto.vencimento)}. O DDA de ${dataBR(x.importacao.em.slice(0, 10))} não lista este boleto. Pode ser boleto sem registro (alguns fornecedores usam), já pago, ainda não atualizado no banco, ou falso: confirme com o fornecedor.` });
  }
  for (const i of dda.importacoes) {
    if (i.dias > 7) {
      add({ tipo: 'dda_desatualizado', severidade: i.dias > 15 ? 'media' : 'baixa', entidade: 'dda', id: i.id, data: i.em.slice(0, 10), estado: String(Math.floor(i.dias / 8)),
        titulo: 'DDA do banco desatualizado', detalhe: `O último DDA importado${i.empresa_id ? ' desta empresa' : ''} é de ${dataBR(i.em.slice(0, 10))} (${i.dias} dias). Exporte de novo no banco e importe em Compras > DDA: sem isso, boleto novo não é conferido contra o banco.` });
    }
  }

  // peça entregue a outra empresa, cuja nota foi cancelada depois: o valor a receber já não tem base
  for (const a of db.prepare(`SELECT a.id, a.valor, e.nome AS empresa, n.numero FROM adiantamentos a JOIN empresas_grupo e ON e.id = a.empresa_id JOIN alocacoes al ON al.id = a.alocacao_id
      JOIN nota_itens i ON i.id = al.item_id JOIN notas_compra n ON n.id = i.nota_id WHERE a.origem = 'peca' AND n.situacao = 'cancelada'`).all()) {
    if (saldoDoAdiantamento(db, a.id) <= 0.04) continue;
    add({ tipo: 'adiantamento_de_nota_cancelada', severidade: 'media', entidade: 'adiantamento', id: a.id, valor: a.valor, estado: String(a.valor),
      titulo: `A receber da ${a.empresa} por peças de nota cancelada`, detalhe: `A nota ${a.numero} foi cancelada, mas as peças dela ainda estão como "a receber" da ${a.empresa}. Confira se o valor ainda é devido e, se não for, desfaça o destino das peças.` });
  }

  // dinheiro entre as empresas que está demorando para voltar
  const prazoAdiant = cfg.diasDevolucaoAdiantamento ?? 30;
  for (const a of db.prepare('SELECT a.*, e.nome AS empresa FROM adiantamentos a JOIN empresas_grupo e ON e.id = a.empresa_id').all()) {
    const falta = saldoDoAdiantamento(db, a.id);
    if (falta <= 0.04 || diasEntre(a.data, hojeStr) <= prazoAdiant) continue;
    add({ tipo: 'adiantamento_antigo', severidade: 'media', entidade: 'adiantamento', id: a.id, valor: falta, data: a.data, estado: String(falta),
      titulo: a.sentido === 'a_receber' ? `${a.empresa} deve à oficina há ${diasEntre(a.data, hojeStr)} dias` : `A oficina deve à ${a.empresa} há ${diasEntre(a.data, hojeStr)} dias`,
      detalhe: `${a.descricao}: faltam ${reais(falta)} desde ${dataBR(a.data)} (prazo combinado: ${prazoAdiant} dias). Registre a devolução em Compras > Entre empresas ou combine uma data.` });
  }

  // pagamento de peças lançado direto em Contas, sem passar por Compras
  const diretas = db.prepare(`SELECT s.id, s.descricao, s.valor, s.vencimento, s.pago_em FROM saidas s JOIN categorias c ON c.id = s.categoria_id
      WHERE c.grupo = 'pecas' AND s.recorrente_id IS NULL AND s.vencimento >= ? AND NOT EXISTS (SELECT 1 FROM boletos b WHERE b.saida_id = s.id)`).all(desde);
  if (diretas.length) {
    const total = r2(diretas.reduce((a, s) => a + s.valor, 0));
    add({ tipo: 'saida_pecas_sem_boleto', severidade: 'media', entidade: 'saida', id: 0, valor: total, data: desde, estado: `${desde}|${diretas.length}|${total}`,
      titulo: `${diretas.length} conta(s) de peças lançada(s) em Contas sem boleto em Compras`,
      detalhe: `${reais(total)} em contas da categoria de fornecedores que não passaram pela conferência (sem nota nem boleto ligados). A regra é: todo boleto de fornecedor entra por Compras. Maiores: ${[...diretas].sort((a, b) => b.valor - a.valor).slice(0, 3).map((s) => `${s.descricao} (${reais(s.valor)})`).join(', ')}.` });
  }

  // ---------------------------------------------------------------- fornecedores
  const semCnpj = db.prepare(`SELECT f.id, f.nome FROM fornecedores f WHERE f.cnpj IS NULL AND f.ativo = 1
      AND (EXISTS (SELECT 1 FROM notas_compra n WHERE n.fornecedor_id = f.id) OR EXISTS (SELECT 1 FROM boletos b WHERE b.fornecedor_id = f.id))`).all();
  for (const f of semCnpj) {
    add({ tipo: 'fornecedor_sem_cnpj', severidade: 'media', entidade: 'fornecedor', id: f.id,
      titulo: 'Fornecedor sem CNPJ cadastrado', detalhe: `${f.nome}: sem o CNPJ o sistema não consegue conferir se o beneficiário do boleto é mesmo o fornecedor.` });
  }

  // uma ocorrência de fornecedor pode ser gerada por vários boletos: junta numa só
  const vistas = new Map();
  for (const o of out) {
    const antes = vistas.get(o.chave);
    if (antes) { antes.boletos = [...new Set([...antes.boletos, ...o.boletos])]; antes.valor = (antes.valor ?? 0) + (o.valor ?? 0); } else vistas.set(o.chave, o);
  }
  return [...vistas.values()]
    .map((o) => {
      const a = aceites.get(o.chave);
      const vale = a && a.estado === o.estado;
      return { ...o, aceita: vale ? { motivo: a.motivo, em: a.em } : null, aceite_vencido: a && !vale ? { motivo: a.motivo, em: a.em } : null };
    })
    .sort((a, b) => ORDEM[a.severidade] - ORDEM[b.severidade] || (b.valor ?? 0) - (a.valor ?? 0));
}

export function ocorrenciasDoBoleto(db, boletoId, hojeStr, cfg = lerConfig(db)) {
  return ocorrencias(db, hojeStr, cfg).filter((o) => o.boletos.includes(boletoId));
}

export function resumoAuditoria(lista) {
  const abertas = lista.filter((o) => !o.aceita);
  const conta = (s) => abertas.filter((o) => o.severidade === s).length;
  const emRisco = new Map();                                              // cada boleto conta uma vez só
  for (const o of abertas) {
    if (o.severidade !== 'alta' || !['boleto_sem_nota', 'boleto_valor_diverge', 'boleto_beneficiario_diverge', 'boleto_pagador_diverge', 'boleto_duplicado', 'boleto_mesmo_titulo',
      'nota_cancelada_com_boleto', 'boleto_nota_sem_prova', 'boleto_fornecedor_diverge', 'boleto_nota_paga_no_ato'].includes(o.tipo)) continue;
    for (const id of o.boletos) emRisco.set(id, Math.max(emRisco.get(id) ?? 0, o.valor ?? 0));
  }
  return {
    alta: conta('alta'), media: conta('media'), baixa: conta('baixa'), total: abertas.length,
    aceitas: lista.length - abertas.length,
    valorEmRisco: r2([...emRisco.values()].reduce((a, v) => a + v, 0)),
  };
}

/** "Conferi, está certo": só vale para uma ocorrência que existe agora, com motivo, e enquanto o fato for o mesmo. */
export function aceitarOcorrencia(db, chave, motivo, hojeStr, cfg = lerConfig(db)) {
  if (!motivoValido(motivo)) throw new ErroValidacao(MOTIVO_MINIMO);
  const o = ocorrencias(db, hojeStr, cfg).find((x) => x.chave === chave);
  if (!o) throw new ErroValidacao('Essa ocorrência não existe mais (o fato mudou). Atualize a tela.');
  const m = String(motivo).trim().slice(0, 300);
  db.transaction(() => {
    db.prepare("INSERT INTO auditoria_aceites (chave, motivo, estado) VALUES (?, ?, ?) ON CONFLICT(chave) DO UPDATE SET motivo = excluded.motivo, estado = excluded.estado, em = datetime('now')")
      .run(chave, m, o.estado);
    registrar(db, 'aceitar', o.entidade, o.id, { chave, motivo: m, estado: o.estado });
  })();
}

export function desfazerAceite(db, chave) {
  const ok = db.prepare('DELETE FROM auditoria_aceites WHERE chave = ?').run(chave).changes > 0;
  if (ok) registrar(db, 'desfazer_aceite', 'ocorrencia', null, { chave });
  return ok;
}

/**
 * Veredito de uma linha só para o boleto, para o dono bater o olho: nível 'ruim' (não pague), 'atencao' (falta conferir) ou 'ok'.
 * "ok" quer dizer: nota com prova, recebedor e pagador batendo, nada grave aberto. Ainda assim, antes de pagar se olha o app do banco.
 */
export function vereditoDoBoleto(b, ocs, cfg) {
  if (b.situacao === 'pago') return { nivel: 'neutro', texto: 'Pago', curto: 'Pago' };
  if (b.situacao === 'cancelado' || b.situacao === 'contestado') return { nivel: 'neutro', texto: b.situacao === 'contestado' ? 'Contestado: não pagar' : 'Cancelado', curto: b.situacao === 'contestado' ? 'Contestado' : 'Cancelado' };
  const abertas = ocs.filter((o) => !o.aceita);
  const graves = abertas.filter((o) => o.severidade === 'alta');
  if (graves.length) return { nivel: 'ruim', texto: `NÃO PAGUE: ${graves[0].titulo}${graves.length > 1 ? ` (+${graves.length - 1})` : ''}`, curto: 'NÃO PAGUE' };
  if (b.dda === 'confirmado' && !graves.length) {
    const medias0 = abertas.filter((o) => o.severidade === 'media');
    if (!medias0.length) return { nivel: 'ok', texto: `Conferido com a nota e confirmado no DDA do banco: valor, vencimento e quem recebe (${b.fornecedor ?? 'fornecedor'}) batem${b.empresa_nome ? `. Boleto da ${b.empresa_nome}: ao pagar, diga quem paga` : ''}`, curto: b.empresa_nome ? `Da ${b.empresa_nome}` : 'No DDA' };
  }
  if (!b.beneficiario_cnpj) return { nivel: 'atencao', texto: 'NÃO CONFERIDO: falta o CNPJ de quem recebe', curto: 'Falta quem recebe' };
  if (!b.pagador_cnpj && cfg.cnpjOficina) return { nivel: 'atencao', texto: 'NÃO CONFERIDO: falta o CNPJ do pagador', curto: 'Falta quem paga' };
  const medias = abertas.filter((o) => o.severidade === 'media');
  if (medias.length) return { nivel: 'atencao', texto: `Conferir: ${medias[0].titulo}${medias.length > 1 ? ` (+${medias.length - 1})` : ''}`, curto: 'Conferir' };
  if (b.empresa_nome) return { nivel: 'ok', texto: `Boleto da ${b.empresa_nome}, não da oficina. Ao pagar, diga quem paga. Veja no app do banco se quem recebe é ${b.fornecedor ?? 'o fornecedor'}`, curto: `Da ${b.empresa_nome}` };
  return { nivel: 'ok', texto: `Conferido com a nota. Antes de pagar, veja no app do banco se quem recebe é ${b.fornecedor ?? 'o fornecedor'}`, curto: 'Conferido' };
}
