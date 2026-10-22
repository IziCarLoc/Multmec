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

    // Textos dos termos (Ajustes > Textos). Marcados [REVISAR] até o advogado aprovar.
    'texts' => [
        'warranty' => "[REVISAR COM ADVOGADO]\nGarantia contratual de {dias} dias{km}, além da garantia legal prevista no Código de Defesa do Consumidor, contada da entrega do veículo.\nCobertura: [descrever].\nExclusões: [descrever].\nMarcação de torque: os fixadores trabalhados são marcados com tinta {cor_torque}; marca rompida indica intervenção de terceiros.\nReaperto das rodas: recomendamos voltar à oficina entre 50 e 100 km após a troca ou remoção de rodas (sem custo).\nComo acionar: [descrever].",
        'pickup' => "[REVISAR COM ADVOGADO]\nDeclaro que recebi o veículo descrito nesta OS, conferi o checklist de saída e as fotos de entrada e saída, e recebi as orientações de garantia.",
        'entry' => "[REVISAR COM ADVOGADO]\nDeclaro que o veículo foi recebido no estado registrado nesta vistoria (fotos, avarias, combustível, quilometragem, objetos e acessórios).",
        'approval' => "[REVISAR COM ADVOGADO]\nAutorizo a execução dos serviços e a aplicação das peças descritas neste orçamento, nas condições informadas. Serviços ou peças não previstos só serão executados após nova autorização.",
        'privacy' => "[REVISAR COM ADVOGADO]\nAviso de privacidade: [quem trata os dados, para quê, com quem compartilha, por quanto tempo e como falar com a oficina].",
    ],
];
