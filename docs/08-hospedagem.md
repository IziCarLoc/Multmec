# 08. Como colocar no ar (hospedagem)

> O sistema é um programa Node.js que guarda os dados num único arquivo (SQLite). Para hospedar, você precisa de **(1) um lugar que rode Node.js 20+ e (2) um disco que não se apaga ao reiniciar**. Sem disco persistente, os dados somem.
> Eu não subi nada: faltam suas respostas sobre a hospedagem (`07`, pergunta 8) e suas credenciais.

## 1. Escolha o caminho

| Se você tem... | Faça | Esforço |
|---|---|---|
| Nada ainda | **Serviço de aplicações com disco persistente** (por exemplo Render ou Railway, com volume/disco). Liga o repositório, define as variáveis, monta o disco em `/data`. | Baixo |
| Um **VPS** (Hostinger, Contabo, DigitalOcean, Locaweb VPS...) | **Docker + Caddy** (seção 3). HTTPS automático. | Médio |
| **Hospedagem compartilhada PHP/cPanel** | Este sistema **não roda** lá. Use um dos caminhos acima e aponte um subdomínio (`financeiro.seusite.com.br`) para ele. Reescrever em PHP/MySQL é possível, mas não está feito. | - |

Planos gratuitos que apagam o disco a cada reinício **não servem** para dados financeiros.

## 2. Variáveis de ambiente (obrigatórias em produção)

| Variável | Valor |
|---|---|
| `NODE_ENV` | `production` |
| `APP_PASSWORD` | senha de acesso, **mínimo 10 caracteres** (o sistema não sobe sem ela) |
| `SESSION_SECRET` | texto longo e aleatório: `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"` |
| `DB_PATH` | caminho do banco no **disco persistente**, por exemplo `/data/multmec.db` |
| `TRUST_PROXY` | `1` quando houver proxy HTTPS na frente (quase sempre) |
| `PORT` | porta (a hospedagem costuma definir sozinha; padrão 3000) |

Há um modelo em `app/.env.example`. **Nunca suba o `.env` para o Git** (o `.gitignore` já bloqueia).

## 3. VPS com Docker e HTTPS automático

```bash
# no servidor (Ubuntu), com Docker instalado
git clone <repositório> && cd Oto/app
cp .env.example .env && nano .env        # preencha senha e segredo
docker compose up -d --build             # sobe o sistema na porta 3000 (só local)
```

HTTPS com **Caddy** (certificado automático). `/etc/caddy/Caddyfile`:

```
financeiro.seusite.com.br {
    reverse_proxy 127.0.0.1:3000
}
```

No painel do seu domínio, crie um registro `A` de `financeiro` para o IP do servidor. Pronto: `https://financeiro.seusite.com.br`.

Testar: `curl https://financeiro.seusite.com.br/saude` deve responder `{"ok":true}`.

## 4. Primeiro uso depois de no ar

1. Entre com a senha.
2. **Importar** > escolha o CSV da planilha > confira a prévia (os totais por mês têm de bater com a sua planilha) > data de corte = hoje > Importar.
3. **Metas**: meta, retirada desejada, % de imposto (peça ao contador), saldo do banco de hoje.
4. **Contas > Modelos fixos**: folha, aluguel, energia, sistema, contador, pró-labore.
5. **A prazo**: ajuste a locadora (prazo, limite) e lance o saldo anterior dela.

Para importar direto no servidor, sem o navegador:
`docker compose exec multmec node scripts/importar.js /data/servicos.csv --corte 2026-10-03`

## 5. Backup (não é opcional)

O banco é um arquivo. Se o servidor morrer sem cópia, o financeiro acaba.

```bash
# todo dia às 3h, cópia consistente em /data/backups (guarda 30 dias)
0 3 * * * docker compose -f /caminho/Oto/app/docker-compose.yml exec -T multmec node scripts/backup.js
```

O banco guarda também o **XML original das notas fiscais** (compactado), então o backup já leva as provas da conferência de compras (`09-conciliacao-compras.md`). Isso protege de erro, não de perder o servidor inteiro. **Leve as cópias para fora**: sincronize a pasta de backups com o Google Drive (por exemplo com `rclone`) ou ative o backup/snapshot do provedor. Teste restaurar uma vez: pare o sistema, copie um arquivo `multmec-AAAA-MM-DD-HH-MM.db` sobre `multmec.db`, suba de novo.

## 6. Segurança (lista de conferência)

- [ ] Só HTTPS (o cookie é `Secure` atrás de proxy HTTPS).
- [ ] Senha longa, só entre os sócios. Trocar quando alguém sair.
- [ ] `SESSION_SECRET` definido (senão cada reinício derruba o login).
- [ ] Nenhum arquivo `.db`, `.env` ou CSV com clientes no Git.
- [ ] Backup diário **fora do servidor** e teste de restauração.
- [ ] Servidor atualizado (`apt upgrade`) e firewall liberando só 80, 443 e SSH por chave.
- [ ] Quem tem acesso ao Drive/servidor é quem precisa.
- [ ] Dados pessoais (nome, placa): ver nota de LGPD em `05-sistema.md`.

## 7. Atualizar o sistema

```bash
cd Oto && git pull && cd app && docker compose up -d --build
```

O banco fica no volume e não é tocado. O esquema se atualiza sozinho ao subir (`CREATE TABLE IF NOT EXISTS`). Antes de qualquer atualização grande, rode o backup.

## 8. Sem Docker (Node direto)

```bash
cd app && npm ci --omit=dev
NODE_ENV=production APP_PASSWORD='...' SESSION_SECRET='...' DB_PATH=/dados/multmec.db TRUST_PROXY=1 node src/server.js
```

Use `systemd` ou `pm2` para manter rodando e reiniciar sozinho.

## 9. Rodar no seu computador para testar

```bash
cd app && npm install
npm run demo        # cria um banco com dados inventados em app/data/multmec.db
npm start           # http://localhost:3000   senha de desenvolvimento: multmec
npm test            # 68 testes
```

Para usar seus dados reais localmente: `npm run importar -- caminho/servicos.csv --corte AAAA-MM-DD` (em outro `DB_PATH` se já rodou o demo).
