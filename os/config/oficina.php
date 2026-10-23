<?php

/*
 * Padrões dos Ajustes. O que estiver gravado na tabela `settings` (tela Ajustes) vale mais que estes valores.
 * Textos jurídicos são MODELOS para revisão do advogado: nunca são linguagem jurídica definitiva.
 */
return [
    // Carros da frota Izi Car: até este valor (centavos) o Atendimento aprova; acima, só o gestor.
    'izi_approval_limit_cents' => 150000,

    // Nome do cliente "frota Izi Car" (os carros da frota entram nele sozinhos) e o WhatsApp de quem aprova lá.
    'izi_customer_name' => 'Izi Car Locadora',
    'izi_whatsapp' => null,

    // Validade do orçamento em dias, contada do recebimento pelo cliente (CDC art. 40, §1º).
    'budget_validity_days' => 10,

    // Garantia contratual padrão (além da legal). Km em branco = sem limite de km.
    'warranty_days' => 90,
    'warranty_km' => null,

    // Valor padrão do diagnóstico cobrado (centavos). Em branco = combinar caso a caso.
    'diagnosis_fee_cents' => null,

    // Cor da marcação de torque usada na oficina (vai no termo de garantia). Em branco até a oficina definir.
    'torque_mark_color' => null,

    // Financeiro 2b (Meta do mês e crédito a prazo). Em branco = "preencher": o gestor informa na tela Meta do mês.
    // Nada aqui é estimativa do sistema: imposto e maquininha vêm do contador e da proposta da maquininha.
    'finance' => [
        // Meta de faturamento do mês (centavos). O plano (docs/02) fala em degraus: o gestor define a do mês.
        'target_cents' => null,
        // Retirada desejada dos sócios por mês (centavos).
        'withdrawal_cents' => null,
        // Percentuais sobre o faturamento (9.5 = 9,5%).
        'tax_pct' => null,
        'card_fee_pct' => null,
        'reserve_pct' => null,
        // Margem de contribuição fixa (%). Em branco = calcular: 100 − custo medido das peças − imposto − maquininha − reserva.
        'contribution_margin_pct' => null,
        // Sábado nos dias úteis: 0 (não conta), 0.5 (meio dia) ou 1 (dia inteiro). O financeiro em Node usava 0.
        'saturday_weight' => 0,
        // Feriados que dependem da Páscoa em que a oficina fecha (os nacionais de data fixa sempre contam).
        'movable_holidays' => ['sexta_santa'],
        // Outros dias sem expediente (estaduais, municipais, pontes): datas AAAA-MM-DD.
        'extra_holidays' => [],
        // Saldo do banco/caixa num dia: a partir dele o sistema mostra o caixa.
        'cash_opening_cents' => null,
        'cash_opening_date' => null,
        // Crédito a prazo: trava o cliente com atraso acima destes dias (regra do controle financeiro, docs/03).
        'credit_lock_days' => 15,
        // "Atenção" quando o cliente já usou esta parte do limite (%).
        'credit_warning_pct' => 80,
    ],

    // Textos dos termos (Ajustes > Textos). Marcados [REVISAR] até o advogado aprovar.
    'texts' => [
        'warranty' => "[REVISAR COM ADVOGADO]\nGarantia contratual de {dias} dias{km}, além da garantia legal prevista no Código de Defesa do Consumidor, contada da entrega do veículo.\nCobertura: [descrever].\nExclusões: [descrever].\nMarcação de torque: os fixadores trabalhados são marcados com tinta {cor_torque}; marca rompida indica intervenção de terceiros.\nReaperto das rodas: recomendamos voltar à oficina entre 50 e 100 km após a troca ou remoção de rodas (sem custo).\nComo acionar: [descrever].",
        'pickup' => "[REVISAR COM ADVOGADO]\nDeclaro que recebi o veículo descrito nesta OS, conferi o checklist de saída e as fotos de entrada e saída, e recebi as orientações de garantia.",
        'entry' => "[REVISAR COM ADVOGADO]\nDeclaro que o veículo foi recebido no estado registrado nesta vistoria (fotos, avarias, combustível, quilometragem, objetos e acessórios).",
        'approval' => "[REVISAR COM ADVOGADO]\nAutorizo a execução dos serviços e a aplicação das peças descritas neste orçamento, nas condições informadas. Serviços ou peças não previstos só serão executados após nova autorização.",
        'privacy' => "[REVISAR COM ADVOGADO]\nAviso de privacidade: [quem trata os dados, para quê, com quem compartilha, por quanto tempo e como falar com a oficina].",
    ],
];
