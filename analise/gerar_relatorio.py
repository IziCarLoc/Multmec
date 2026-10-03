"""Gera docs/dados/metricas.json (só agregados, sem nomes/placas) e os gráficos de docs/img.

    python analise/gerar_relatorio.py analise/dados/servicos.csv

O CSV é a exportação da aba SERVIÇOS da planilha CONTROLE SERVIÇOS (Arquivo > Baixar > CSV).
"""
import json
import sys
from pathlib import Path

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd

from servicos import carregar, executadas

RAIZ = Path(__file__).resolve().parent.parent
IMG = RAIZ / "docs" / "img"
DADOS = RAIZ / "docs" / "dados"

# Paleta de referência (skill dataviz): azul = série principal, laranja = destaque, cinza = contexto
AZUL, LARANJA, VERDE, CINZA = "#2a78d6", "#eb6834", "#1baf7a", "#b9b8b2"
TINTA, TINTA2, GRADE, FUNDO = "#0b0b0b", "#52514e", "#e6e5e1", "#fcfcfb"

MESES_CHEIOS = ("2025-09", "2026-09")          # set/25 .. set/26 (ago/25 e out/26 são parciais)
MESES_CONFIAVEIS = ("2025-09", "2026-02")      # custos preenchidos de forma consistente
ULT6 = ("2026-04", "2026-09")
META = 100_000
ROT = {"01": "jan", "02": "fev", "03": "mar", "04": "abr", "05": "mai", "06": "jun",
       "07": "jul", "08": "ago", "09": "set", "10": "out", "11": "nov", "12": "dez"}


def rot_mes(p):
    s = str(p)
    return f"{ROT[s[5:7]]}/{s[2:4]}"


def estilo():
    plt.rcParams.update({
        "figure.facecolor": FUNDO, "axes.facecolor": FUNDO, "savefig.facecolor": FUNDO,
        "axes.edgecolor": GRADE, "axes.labelcolor": TINTA2, "xtick.color": TINTA2,
        "ytick.color": TINTA2, "text.color": TINTA, "font.family": "DejaVu Sans",
        "font.size": 10, "axes.spines.top": False, "axes.spines.right": False,
        "axes.grid": True, "grid.color": GRADE, "grid.linewidth": 0.8,
        "axes.axisbelow": True,
    })


def brl_k(v, _=None):
    return f"R$ {v/1000:,.0f} mil".replace(",", ".")


def mensal(ex):
    g = ex.groupby("mes").agg(
        os=("os_n", "count"), faturamento=("vl_servico0", "sum"),
        custo_pecas=("custo_pecas0", "sum"), mao_obra=("mao_obra0", "sum"),
        insumos=("insumos0", "sum"), frete=("frete0", "sum"),
        lucro_bruto=("lucro_bruto_calc", "sum"), margem_pecas=("margem_pecas", "sum"))
    g["ticket"] = g.faturamento / g.os
    g["lucro_bruto_pct"] = g.lucro_bruto / g.faturamento
    sem = ex.assign(sem_custo=(ex.custo_pecas0 == 0) & (ex.mao_obra0 == 0)).groupby("mes").sem_custo.mean()
    g["os_sem_custo_nem_mo_pct"] = sem
    g["os_sem_mecanico_pct"] = ex.assign(x=ex.mecanico.eq("(SEM)")).groupby("mes").x.mean()
    return g


def grafico_faturamento(m):
    cheios = m.loc[MESES_CHEIOS[0]:MESES_CHEIOS[1]]
    fig, ax = plt.subplots(figsize=(10, 4.6))
    x = np.arange(len(cheios))
    cores = [AZUL] * len(cheios)
    ax.bar(x, cheios.faturamento, color=cores, width=0.62)
    media = cheios.faturamento.mean()
    ax.axhline(META, color=LARANJA, lw=1.8)
    ax.text(len(cheios) - 0.5, META + 1500, "meta R$ 100 mil", color=TINTA2, ha="right", fontsize=10)
    ax.axhline(media, color=TINTA2, lw=1.2, ls=(0, (4, 3)))
    ax.text(-0.45, media + 1500, f"média 13 meses: {brl_k(media)}", color=TINTA2, fontsize=9.5)
    for xi, v in zip(x, cheios.faturamento):
        ax.text(xi, v + 1200, f"{v/1000:.0f}", ha="center", fontsize=9, color=TINTA)
    ax.set_xticks(x)
    ax.set_xticklabels([rot_mes(p) for p in cheios.index], fontsize=9)
    ax.yaxis.set_major_formatter(brl_k)
    ax.set_ylim(0, 112_000)
    ax.set_title("Faturamento mensal (valor das OS, em R$ mil) x meta", loc="left", fontsize=12, color=TINTA)
    ax.grid(axis="x", visible=False)
    fig.tight_layout()
    fig.savefig(IMG / "01_faturamento_mensal.png", dpi=150)
    plt.close(fig)


def grafico_composicao(m):
    cheios = m.loc[MESES_CHEIOS[0]:MESES_CHEIOS[1]]
    fig, ax = plt.subplots(figsize=(10, 4.6))
    x = np.arange(len(cheios))
    custo = cheios.custo_pecas + cheios.frete + cheios.insumos
    mo = cheios.mao_obra
    mp = cheios.margem_pecas
    ax.bar(x, custo, color=CINZA, width=0.62, label="Custo de peças, frete e insumos")
    ax.bar(x, mo, bottom=custo + 0, color=AZUL, width=0.62, label="Mão de obra cobrada")
    ax.bar(x, mp, bottom=custo + mo, color=VERDE, width=0.62, label="Margem sobre as peças")
    # meses com custo não preenchido: hachura
    for i, p in enumerate(cheios.index):
        if cheios.os_sem_custo_nem_mo_pct.iloc[i] > 0.15:
            ax.bar(i, cheios.faturamento.iloc[i], width=0.62, fill=False, hatch="///", edgecolor=FUNDO, lw=0)
    ax.set_xticks(x)
    ax.set_xticklabels([rot_mes(p) for p in cheios.index], fontsize=9)
    ax.yaxis.set_major_formatter(brl_k)
    ax.set_title("De onde vem o faturamento (hachurado = custo e mão de obra em branco em mais de 15% das OS)",
                 loc="left", fontsize=11.5)
    ax.legend(frameon=False, loc="upper left", fontsize=9, ncol=1)
    ax.grid(axis="x", visible=False)
    fig.tight_layout()
    fig.savefig(IMG / "02_composicao.png", dpi=150)
    plt.close(fig)


def grafico_faixas(f):
    fig, ax = plt.subplots(figsize=(10, 4.4))
    x = np.arange(len(f))
    w = 0.38
    ax.bar(x - w / 2, f.pct_os, width=w, color=CINZA, label="% das OS")
    ax.bar(x + w / 2, f.pct_fat, width=w, color=AZUL, label="% do faturamento")
    for xi, a, b in zip(x, f.pct_os, f.pct_fat):
        ax.text(xi - w / 2, a + 0.6, f"{a:.0f}%", ha="center", fontsize=9)
        ax.text(xi + w / 2, b + 0.6, f"{b:.0f}%", ha="center", fontsize=9)
    ax.set_xticks(x)
    ax.set_xticklabels(f.index, fontsize=9.5)
    ax.set_xlabel("valor da OS (R$)")
    ax.set_ylabel("")
    ax.set_title("Muitas OS pequenas, pouco dinheiro: 38% das OS valem menos de R$ 300 e dão 8% do faturamento",
                 loc="left", fontsize=11)
    ax.legend(frameon=False, fontsize=9)
    ax.grid(axis="x", visible=False)
    fig.tight_layout()
    fig.savefig(IMG / "03_faixas_ticket.png", dpi=150)
    plt.close(fig)


def grafico_markup(mk):
    fig, ax = plt.subplots(figsize=(10, 4.2))
    x = np.arange(len(mk))
    ax.bar(x, mk.markup * 100, color=AZUL, width=0.55)
    for xi, v, n in zip(x, mk.markup * 100, mk.n):
        ax.text(xi, v + 4, f"{v:.0f}%", ha="center", fontsize=10)
        ax.text(xi, 6, f"{int(n)} OS", ha="center", fontsize=8.5, color=FUNDO)
    ax.set_xticks(x)
    ax.set_xticklabels(mk.index, fontsize=9.5)
    ax.set_xlabel("custo das peças na OS (R$)")
    ax.set_title("Acréscimo médio sobre o custo das peças cai conforme a peça fica cara",
                 loc="left", fontsize=11.5)
    ax.set_ylabel("")
    ax.yaxis.set_major_formatter(lambda v, _: f"{v:.0f}%")
    ax.grid(axis="x", visible=False)
    fig.tight_layout()
    fig.savefig(IMG / "04_markup_por_faixa.png", dpi=150)
    plt.close(fig)


def grafico_locadora(lm):
    fig, ax = plt.subplots(figsize=(10, 4.2))
    x = np.arange(len(lm))
    ax.bar(x, lm.fat_loc, color=LARANJA, width=0.62, label="Locadora (Izi/IziCar)")
    ax.bar(x, lm.fat_outros, bottom=lm.fat_loc, color=AZUL, width=0.62, label="Demais clientes")
    for xi, p, t in zip(x, lm.pct, lm.fat_loc + lm.fat_outros):
        ax.text(xi, t + 1200, f"{p:.0f}%", ha="center", fontsize=9, color=TINTA2)
    ax.set_xticks(x)
    ax.set_xticklabels([rot_mes(p) for p in lm.index], fontsize=9)
    ax.yaxis.set_major_formatter(brl_k)
    ax.set_title("Quanto do faturamento é da locadora (% acima de cada barra)", loc="left", fontsize=11.5)
    ax.legend(frameon=False, fontsize=9, loc="upper left")
    ax.grid(axis="x", visible=False)
    fig.tight_layout()
    fig.savefig(IMG / "05_locadora_no_faturamento.png", dpi=150)
    plt.close(fig)


def grafico_semana(sem):
    fig, ax = plt.subplots(figsize=(8, 4))
    nomes = ["seg", "ter", "qua", "qui", "sex", "sáb"]
    x = np.arange(len(sem))
    cores = [LARANJA if n == "sex" else AZUL for n in nomes[:len(sem)]]
    ax.bar(x, sem.media, color=cores, width=0.6)
    for xi, v in zip(x, sem.media):
        ax.text(xi, v + 60, f"R$ {v:,.0f}".replace(",", "."), ha="center", fontsize=9)
    ax.set_xticks(x)
    ax.set_xticklabels(nomes[:len(sem)])
    ax.set_title("Faturamento médio por dia da semana (dias com OS)", loc="left", fontsize=11.5)
    ax.yaxis.set_major_formatter(lambda v, _: f"{v/1000:.1f} mil")
    ax.grid(axis="x", visible=False)
    fig.tight_layout()
    fig.savefig(IMG / "06_dia_da_semana.png", dpi=150)
    plt.close(fig)


def main(csv):
    estilo()
    IMG.mkdir(parents=True, exist_ok=True)
    DADOS.mkdir(parents=True, exist_ok=True)
    bruto, df = carregar(csv)
    ex = executadas(df)
    m = mensal(ex)
    cheios = m.loc[MESES_CHEIOS[0]:MESES_CHEIOS[1]]
    ult6 = m.loc[ULT6[0]:ULT6[1]]
    conf = m.loc[MESES_CONFIAVEIS[0]:MESES_CONFIAVEIS[1]]

    # faixas de ticket
    bins = [0, 150, 300, 600, 1000, 2000, 5000, 1e9]
    lab = ["<150", "150-300", "300-600", "600-1k", "1k-2k", "2k-5k", ">5k"]
    ex["faixa"] = pd.cut(ex.vl_servico0, bins=bins, labels=lab, right=False)
    f = ex.groupby("faixa", observed=True).agg(os=("os_n", "count"), fat=("vl_servico0", "sum"))
    f["pct_os"] = f.os / f.os.sum() * 100
    f["pct_fat"] = f.fat / f.fat.sum() * 100

    # acréscimo sobre peças por faixa de custo (período confiável + mar-jun)
    r = ex[(ex.mes >= pd.Period("2025-09")) & (ex.mes <= pd.Period("2026-06")) & (ex.custo_pecas0 > 0)].copy()
    r["receita_pecas"] = r.vl_servico0 - r.mao_obra0 - r.frete0 - r.insumos0
    r["markup"] = r.receita_pecas / r.custo_pecas0 - 1
    r["faixa_custo"] = pd.cut(r.custo_pecas0, [0, 100, 300, 700, 1500, 1e9],
                              labels=["<100", "100-300", "300-700", "700-1.500", ">1.500"])
    mk = r.groupby("faixa_custo", observed=True).apply(
        lambda g: pd.Series({"n": len(g), "markup": g.receita_pecas.sum() / g.custo_pecas0.sum() - 1,
                             "mediana": g.markup.median()}), include_groups=False)
    piso = {}
    for pct in (0.6, 0.8, 1.0):
        up = ((pct - r.markup).clip(lower=0) * r.custo_pecas0)
        piso[f"{int(pct*100)}%"] = {"os_afetadas_pct": float((r.markup < pct).mean()),
                                    "ganho_mensal": float(up.sum() / 10)}

    # locadora
    ex["fat_loc"] = np.where(ex.eh_locadora, ex.vl_servico0, 0.0)
    lm = ex.groupby("mes").agg(fat=("vl_servico0", "sum"), fat_loc=("fat_loc", "sum"))
    lm["fat_outros"] = lm.fat - lm.fat_loc
    lm["pct"] = lm.fat_loc / lm.fat * 100
    lm = lm.loc[MESES_CHEIOS[0]:MESES_CHEIOS[1]]
    loc = ex[ex.eh_locadora]
    out = ex[~ex.eh_locadora]
    rel_loc = ex[(ex.mes >= pd.Period(MESES_CONFIAVEIS[0])) & (ex.mes <= pd.Period(MESES_CONFIAVEIS[1]))]

    # dia da semana
    ex["dow"] = ex.dt.dt.dayofweek
    diario = ex.groupby([ex.dt.dt.normalize(), "dow"]).vl_servico0.sum().reset_index()
    diario = diario[(diario.dt >= "2025-09-01") & (diario.dt <= "2026-09-30")]
    sem = diario.groupby("dow").vl_servico0.agg(media="mean", dias="count").loc[0:5]

    # clientes (sem nomes)
    cli = ex.assign(c=np.where(ex.eh_locadora, "LOCADORA", ex.cliente)).groupby("c").vl_servico0.sum().sort_values(ascending=False)
    placas = ex.placa.str.upper().str.replace(" ", "").replace({"": np.nan})
    orc = df[df.tipo == "orcamento"]

    metricas = {
        "periodo": {"inicio": str(ex.dt.min().date()), "fim": str(ex.dt.max().date()), "os_total": int(len(ex))},
        "mensal": {str(k): {c: (None if pd.isna(v) else round(float(v), 4)) for c, v in row.items()}
                   for k, row in m.iterrows()},
        "media_13_meses": {k: round(float(v), 2) for k, v in cheios.mean().items()},
        "media_ultimos_6": {k: round(float(v), 2) for k, v in ult6.mean().items()},
        "media_meses_confiaveis": {k: round(float(v), 4) for k, v in conf.mean().items()},
        "faturamento_total": round(float(ex.vl_servico0.sum()), 2),
        "ticket_mediano": round(float(ex.vl_servico0.median()), 2),
        "faixas_ticket": {k: {c: round(float(v), 2) for c, v in row.items()} for k, row in f.iterrows()},
        "pareto_os": {f"top{int(p*100)}pct": round(float(ex.vl_servico0.sort_values(ascending=False).iloc[:int(len(ex)*p)].sum() / ex.vl_servico0.sum()), 4)
                      for p in (0.05, 0.1, 0.2, 0.5)},
        "markup_por_faixa_custo": {k: {c: round(float(v), 4) for c, v in row.items()} for k, row in mk.iterrows()},
        "piso_de_markup": piso,
        "locadora": {
            "os": int(len(loc)), "faturamento": round(float(loc.vl_servico0.sum()), 2),
            "participacao": round(float(loc.vl_servico0.sum() / ex.vl_servico0.sum()), 4),
            "ticket_locadora": round(float(loc.vl_servico0.mean()), 2),
            "ticket_demais": round(float(out.vl_servico0.mean()), 2),
            "lucro_bruto_pct_locadora": round(float(rel_loc[rel_loc.eh_locadora].lucro_bruto_calc.sum() / rel_loc[rel_loc.eh_locadora].vl_servico0.sum()), 4),
            "lucro_bruto_pct_demais": round(float(rel_loc[~rel_loc.eh_locadora].lucro_bruto_calc.sum() / rel_loc[~rel_loc.eh_locadora].vl_servico0.sum()), 4),
            "media_mensal": round(float(loc.groupby("mes").vl_servico0.sum().loc[MESES_CHEIOS[0]:MESES_CHEIOS[1]].mean()), 2),
            "marcadas_locadora_ok": int((ex.locadora.str.strip().str.upper() == "OK").sum()),
        },
        "clientes": {
            "distintos": int(ex.cliente.nunique()),
            "maior_cliente_sem_locadora_pct": round(float(cli.drop("LOCADORA").iloc[0] / cli.sum()), 4),
            "top10_sem_locadora_pct": round(float(cli.drop("LOCADORA").iloc[:10].sum() / cli.sum()), 4),
            "placas_distintas": int(placas.nunique()),
            "os_por_placa": round(float(placas.notna().sum() / placas.nunique()), 2),
        },
        "mecanicos": {str(k): {kk: int(vv) for kk, vv in row.items()}
                      for k, row in pd.crosstab(ex.mes, ex.mecanico).iterrows()},
        "dia_da_semana": {int(k): {"media": round(float(v.media), 2), "dias": int(v.dias)} for k, v in sem.iterrows()},
        "orcamentos": {"qtd": int(len(orc)), "valor": round(float(orc.vl_servico0.sum()), 2)},
        "desconto_tabela_locadora": 0.25,
    }
    (DADOS / "metricas.json").write_text(json.dumps(metricas, ensure_ascii=False, indent=1), encoding="utf-8")

    grafico_faturamento(m)
    grafico_composicao(m)
    grafico_faixas(f)
    grafico_markup(mk)
    grafico_locadora(lm)
    grafico_semana(sem)
    print("ok: métricas em", DADOS / "metricas.json")
    print(m.loc[:, ["os", "faturamento", "lucro_bruto", "lucro_bruto_pct", "os_sem_custo_nem_mo_pct", "os_sem_mecanico_pct"]].round(3).to_string())


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "analise/dados/servicos.csv")
