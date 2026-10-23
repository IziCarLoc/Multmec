# Sistema de OS da Multmec Liberdade — convenções para o Claude

Pasta `os/` do repositório Multmec. O financeiro em Node (`app/`) é outro sistema: não mexer nele daqui.

## Banco de dados: produção é MySQL/MariaDB (Hostinger); testes são SQLite
- **Nome de índice, unique e foreign key: no máximo 64 caracteres** no MySQL. Índice ou unique composto sempre com nome curto explícito. `MigrationIdentifierLengthTest` trava.
- DDL do MySQL não volta atrás. Migration nova tem que poder rodar de novo (`Schema::hasTable`, `hasColumn`, `hasIndex`).
- Migration que já rodou em produção não muda: correção vira migration nova.
- O CI roda os testes também com MariaDB.

## Código
- Laravel 12 + Livewire 4 (componentes de arquivo único em `resources/views/pages/**`, rota `Route::livewire('/x', 'pages::pasta.nome')`), Alpine inline, Tailwind compilado, Font Awesome 6.0 por CDN.
- Nada de React, Vue, Inertia, API interna ou `fetch` nas telas: o estado é do Livewire. Envio de arquivo usa `$wire.upload(...)`.
- Campo que grava sozinho ao sair usa `wire:model.live.blur`: no Livewire 4 o `.blur` sem `.live` não envia nada ao servidor (`WireModelSyncTest` trava). Hook `updatedX($value, $key)` de array recebe às vezes a lista inteira (`$key` nulo).
- Diretiva Blade nunca colada em letra/número (`@endif @if`, não `@endif@if`). `BladeDirectiveSpacingTest` trava.
- Dinheiro sempre em centavos (inteiro). Datas só-data com o cast `App\Casts\DateOnly`. Hora que vale como prova é sempre a do servidor.
- Regra de negócio em `app/Services`. Erro para o operador é `DomainException` com mensagem em português.
  - Nas telas, a ação passa por `RunsDomainActions::attempt(fn () => …, 'campo')`; nada de `try/catch` solto.
- Perfis fixos (`App\Enums\Role`): gestor, atendimento, mecanico.
  - Gates `gestor`, `atendimento` e `oficina`; o gestor passa em todos.
  - "Responsável" é sempre um usuário escolhido da lista (`User::forPicker()`), nunca texto livre. Toda ação relevante grava o autor.
- Etapa da OS só muda por `App\Services\Orders\StageMachine` (`move`, `back`, `cancel`), passando a etapa que a tela mostrava; o "Falta para avançar" de cada etapa fica em `StageGate::missing()` e cada entrega acrescenta as regras dela ali (a tela e o servidor usam a mesma lista). Nunca `update(['stage' => …])`.
- Placa: `Vehicle::byPlate()` (antiga e Mercosul são o mesmo carro, `plate_key`); telefone/WhatsApp: `App\Support\Phone` (dígitos sem o 55; `whatsappLink()` monta o `wa.me`). Placa na tela: `<x-plate>`; etapa: `<x-stage-badge>`; cartão: `<x-order-card>`.
- Mecânico não vê preço, custo nem lucro em tela nenhuma; o atendimento não vê lucro nem margem.
- Ajustes: `App\Support\Settings::get()`, que lê o banco ou cai em `config/oficina.php`. Textos jurídicos são modelos `[REVISAR]`: nunca inventar linguagem jurídica, valor de torque ou prazo legal.
- Fotos:
  - O celular reduz a foto para 1.600 px e calcula o SHA-256 (`window.sha256File`).
  - O servidor confere o hash e recusa a foto se ele faltar ou não bater.
  - O servidor guarda o arquivo recebido intacto e uma cópia carimbada (`App\Services\Media\PhotoStamper`, desenho do v12).
  - Arquivos ficam no disco privado, servidos só por rota com login (`media.show`). Foto entra só por `App\Services\Media\MediaStore::storePhoto()`; na tela, `x-data="photoCapture"` + `take($event, 'slot')` chamam `savePhoto(slot, hash, horaDaFoto)` do componente. Foto refeita não se apaga (fica `replaced_at`).
  - Marcas de avaria ficam em `damages.marks` (x/y de 0 a 1); o PDF desenha numa cópia (`DamageMarks`).
- Vistoria de entrada (`App\Services\Inspections`): parte A em `EntryInspection`, parte B em `TechnicalChecklist`, envio/assinatura em `InspectionDelivery`. Só muda na etapa "Em vistoria"; se mudar depois de enviada/assinada, precisa enviar de novo (o resumo dos dados tem SHA-256).
- Peças e serviços da OS: `App\Services\Items\OrderItems` (o mecânico lança descrição, quantidade, horas e prioridade; origem, fornecedor/prestador e custo são do atendimento e o serviço recusa campo comercial vindo do mecânico). Item só muda no diagnóstico (e na cotação pelo atendimento); depois do orçamento, item novo é adendo.
- Cotação: `App\Services\Quotes\Quotations` (código COT-AAAA-NNNN, mensagem, `wa.me` por fornecedor, respostas, vencedor que preenche o item). **A mensagem ao fornecedor nunca leva nome, CPF, telefone ou e-mail do cliente**: só o carro e as peças (o teste trava). Peça "do cliente" ou "do estoque" não vai para a cotação (`PartOrigin::skipsQuote()`).
- Orçamento: `App\Services\Budgets\Budgets`. "Enviar orçamento ao cliente" (StageMachine) congela uma cópia (`budgets.snapshot` + SHA-256) e trava os itens; PDF, resumo e link saem da cópia. Aceite (de todos ou de parte dos itens) e recusa só por `approve()`/`refuse()` (ou `answerByLink()`), que gravam o `Consent` e mudam a etapa. Carro da Izi: alçada (`izi_approval_limit_cents`; acima, só o gestor). Peça paralela/usada pede autorização. Depois do aceite, item novo vira adendo (`sendAddendum`). O gestor voltar a etapa cancela o orçamento e os links.
- Link do cliente (`/orcamento/{token}`, sem login): abrir não muda nada; só o botão grava, uma vez, dentro da validade (conta do primeiro envio).
- Execução e conferência (`App\Services\Execution`): `WorkLog` (apontamento; quem apontou tempo na OS não confere), `Execution` (peça recebida/não usada, fotos da peça ligadas por `media.os_item_id`, torque digitado pelo mecânico, nunca um padrão) e `FinalCheck` (modelos em `checklist_items` kind `conferencia`, rodada em `os_checklist_items.round`; CORRIGIR volta para a execução e abre rodada nova; a liberação grava um `Consent` de quem conferiu). Os efeitos de etapa (aguardando peças automático, apontamento ao iniciar, rodada nova, liberação) ficam em `StageMachine::executionEffects()`.
- Entrega: `App\Services\Delivery\Delivery` (fotos de saída, termo de retirada = `Consent` purpose `retirada`, nota e resumo fiscal). Na entrega nascem o "A receber" (`Finance\Receivables::create` com `service_order_id`) e os pagamentos de prestador (`Finance\ProviderPayments`); o custo do prestador entra no lucro por `Finance\Profit::providerCost()` (proporcional às parcelas). PDF completo: `Orders\OrderPdf`; Por mês: `Orders\MonthlyReport`.
- Vídeo entra só por `MediaStore::storeVideo()` (até 20 MB, hash conferido, guardado como chegou; a tela põe OS, placa e hora do servidor por cima).
- Financeiro (`App\Services\Finance`, telas em `pages/auth/finance`, rotas `/financeiro`): só gestor e atendimento (trait `FinanceAccess`).
  - **O atendimento nunca vê lucro nem margem** (Painel, formulário, lista, "Por mês"): a tela pergunta `canSeeProfit()` antes de calcular ou mostrar.
  - Lucro só em `Profit`: bruto = total − peças − frete − prestador (`providerCost()`, hoje 0); da peça = (total − mão de obra) − peças − frete. "Cliente trouxe a peça" zera as peças.
  - Parcela é uma linha própria (`Installments::split`, centavos exatos, o resto nas primeiras); editar uma nunca apaga nem recria as outras.
  - A situação do "A receber" sai dos pagamentos (`Receivables` refaz `paid_cents`/`received_on`); parcial vencido é vencido. Baixa como prejuízo (`Receivables::writeOff`) é uma transação só e o lançamento baixado nunca reabre.
  - Recebimento pela matriz não entra em saldo, lucro, "a receber" nem inadimplência (`Receivable::counted()`). Painel conta pago, recebido e prejuízo na data em que aconteceram (`FinanceSummary`); janelas de vencimento em `DueWindow`.
- Classes de campo e botão compartilhadas: `$inputClass`, `$labelClass`, `$btnPrimary`, `$btnSecondary`, `$cardClass`. Não redefinir na tela.
- Janela: `<x-modal>`. CPF/CNPJ: `<x-document-input>`. Aviso: evento `toast`.
- Textos da interface em português do Brasil, com a linguagem da oficina (OS, orçamento, conferência, aceite). Cores: azul `brand` como destaque, grafite `plate` no cabeçalho, vermelho `stripe` só na faixa e nos alertas.
- Celular primeiro: botões com no mínimo 44 px de altura, nada de rolagem lateral em 320 px.

## Antes de entregar
- Rodar `vendor/bin/pint`, `php artisan test` e `npm run build:css`, este último se mudou classes nas views ou em `app/`. Commitar `public/css/app.css`.
- Testes com `RefreshDatabase`; telas testadas com `Livewire::test('pages::…')`.
- Commits em português.
- Deploy e backup: ver `README.md`. O último passo é sempre `php artisan sistema:conferir-deploy`, que tem que terminar em "Deploy conferido.", mais a tela Ajustes > Saúde do sistema toda verde.
