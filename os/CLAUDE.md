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
- Vídeo entra só por `MediaStore::storeVideo()` (até 20 MB, hash conferido, guardado como chegou; a tela põe OS, placa e hora do servidor por cima).
- Classes de campo e botão compartilhadas: `$inputClass`, `$labelClass`, `$btnPrimary`, `$btnSecondary`, `$cardClass`. Não redefinir na tela.
- Janela: `<x-modal>`. CPF/CNPJ: `<x-document-input>`. Aviso: evento `toast`.
- Textos da interface em português do Brasil, com a linguagem da oficina (OS, orçamento, conferência, aceite). Cores: azul `brand` como destaque, grafite `plate` no cabeçalho, vermelho `stripe` só na faixa e nos alertas.
- Celular primeiro: botões com no mínimo 44 px de altura, nada de rolagem lateral em 320 px.

## Antes de entregar
- Rodar `vendor/bin/pint`, `php artisan test` e `npm run build:css`, este último se mudou classes nas views ou em `app/`. Commitar `public/css/app.css`.
- Testes com `RefreshDatabase`; telas testadas com `Livewire::test('pages::…')`.
- Commits em português.
- Deploy e backup: ver `README.md`. O último passo é sempre `php artisan sistema:conferir-deploy`, que tem que terminar em "Deploy conferido.", mais a tela Ajustes > Saúde do sistema toda verde.
