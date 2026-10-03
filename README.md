# Multmec: controle financeiro e plano de R$ 100 mil

Projeto de gestão da oficina **Multmec** (Santa Maria/RS): análise dos dados, plano de negócio e um sistema de controle financeiro simples, pronto para hospedar.

> **Comece por [`docs/00-resumo-executivo.md`](docs/00-resumo-executivo.md)** (uma página).

## O que tem aqui

| Pasta / arquivo | Conteúdo |
|---|---|
| [`docs/00-resumo-executivo.md`](docs/00-resumo-executivo.md) | O que descobri, o que recomendo, o que preciso de você |
| [`docs/01-diagnostico.md`](docs/01-diagnostico.md) | Análise da planilha CONTROLE SERVIÇOS (1.064 OS, ago/25 a out/26), com gráficos |
| [`docs/02-plano-100k.md`](docs/02-plano-100k.md) | Como chegar a R$ 100 mil/mês sem aumentar a equipe: alavancas, cenários, calendário |
| [`docs/03-controle-financeiro.md`](docs/03-controle-financeiro.md) | A estrutura de controle: cascata do mês, regra do dinheiro, rotinas, indicadores |
| [`docs/04-locadora.md`](docs/04-locadora.md) | Política para a locadora deixar de financiar-se com a oficina |
| [`docs/05-sistema.md`](docs/05-sistema.md) | Especificação do sistema, modelo de dados, segurança, roadmap |
| [`docs/06-pesquisa.md`](docs/06-pesquisa.md) | Pesquisa com fontes e grau de confiança (benchmarks, tributário, cliente-chave) |
| [`docs/07-perguntas-e-pendencias.md`](docs/07-perguntas-e-pendencias.md) | Perguntas para o dono e o que ficou de fora |
| [`docs/08-hospedagem.md`](docs/08-hospedagem.md) | Como colocar no ar, backup e segurança |
| [`app/`](app) | O sistema (Node.js + SQLite), com testes |
| [`analise/`](analise) | Scripts em Python que geram os números e gráficos do diagnóstico |

## Rodar o sistema

```bash
cd app
npm install
npm run demo     # banco de demonstração com dados inventados
npm start        # http://localhost:3000  (senha de desenvolvimento: multmec)
npm test         # 20 testes
```

Com os dados reais: exporte a aba SERVIÇOS da planilha como CSV e use a tela **Importar** (ou `npm run importar -- servicos.csv --corte AAAA-MM-DD`).

Em produção, o sistema **recusa subir sem `APP_PASSWORD`** (mínimo 10 caracteres). Veja [`docs/08-hospedagem.md`](docs/08-hospedagem.md).

## Regenerar a análise

```bash
pip install pandas numpy matplotlib
python analise/gerar_relatorio.py analise/dados/servicos.csv   # grava docs/dados/metricas.json e docs/img/*.png
```

## Regra de ouro com dados

**Nenhum dado real vai para o Git**: nome de cliente, placa, valor por cliente, banco de dados, `.env`, o CSV da planilha. O `.gitignore` já bloqueia `app/data/`, `*.db`, `.env` e `analise/dados/`. Os documentos trazem só números agregados.

## O que foi e o que não foi verificado

- Testado: 20 testes automatizados (`app`), importação conferida contra a análise independente em Python, telas conferidas em celular e desktop com navegador de teste, subida em modo produção (recusa sem senha, cookie `Secure` atrás de proxy, 401 sem login, 415 para formulário).
- **Não testado aqui:** build da imagem Docker (o ambiente não tem daemon do Docker). O `Dockerfile` e o `docker-compose.yml` seguem o padrão, mas rode um build antes de contar com eles.
- Dois links do Drive que você mandou não abriram para a conta conectada; veja [`docs/07-perguntas-e-pendencias.md`](docs/07-perguntas-e-pendencias.md).
