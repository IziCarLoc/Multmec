"""Leitura e limpeza da planilha CONTROLE SERVIÇOS (aba SERVIÇOS) da Multmec.

A planilha é preenchida à mão e tem vários problemas conhecidos (datas sem ano,
datas digitadas errado, linhas de orçamento misturadas com OS, colunas de custo e
de lucro em branco nos meses recentes). Este módulo normaliza tudo o que dá para
normalizar e marca o que não dá, sem inventar valores.

Uso:  from servicos import carregar;  bruto, os_df = carregar("dados/servicos.csv")
"""
import re

import numpy as np
import pandas as pd

COLUNAS = ["os", "data", "veiculo", "placa", "operador", "custo_pecas", "mao_obra",
           "insumos", "frete", "vl_servico", "lucro_pc", "proprietario", "desc",
           "lucro_bruto", "obs", "status", "locadora"]
VALORES = ["custo_pecas", "mao_obra", "insumos", "frete", "vl_servico", "lucro_pc",
           "desc", "lucro_bruto"]
LOCADORA = {"IZI", "IZICAR", "IZICR"}


def brl(texto):
    """'R$ 1.234,56' -> 1234.56 ; '-R$ 28,00' -> -28.0 ; vazio/'-' -> NaN."""
    if texto is None or pd.isna(texto):
        return np.nan
    s = str(texto).strip()
    if s in ("", "-", "\\-"):
        return np.nan
    negativo = "-" in s
    s = re.sub(r"[^0-9,]", "", s).replace(",", ".")
    if not s:
        return np.nan
    v = float(s)
    return -v if negativo else v


def _parse_data(texto):
    """Devolve Timestamp (data completa), ('sem_ano', dia, mes) ou NaT."""
    s = texto.strip()
    s = re.sub(r"^0(\d{2}/)", r"\1", s)                    # 019/09/2026
    s = s.replace("/001/", "/01/")                         # 21/001/2026
    s = re.sub(r"^(\d{1,2})/(\d{2})(\d{4})$", r"\1/\2/\3", s)  # 19/022026
    m = re.match(r"^(\d{1,2})/(\d{1,2})/(\d{4})$", s)
    if m:
        d, mo, y = map(int, m.groups())
        if y == 2028:                                      # 27/08/2028 (digitação)
            y = 2025
        try:
            return pd.Timestamp(y, mo, d)
        except ValueError:
            return pd.NaT
    m = re.match(r"^(\d{1,2})/(\d{1,2})$", s)
    if m:
        return ("sem_ano", int(m.group(1)), int(m.group(2)))
    return pd.NaT


def _datas(df):
    """Reconstrói a data de cada OS.

    A planilha é cronológica por blocos. Usamos as datas confiáveis como âncoras
    (mediana móvel descarta digitações absurdas) e interpolamos pela posição na
    planilha as linhas sem data (orçamentos, linhas sem preenchimento).
    """
    parsed = df["data"].map(_parse_data)
    completa = parsed.map(lambda p: p if isinstance(p, pd.Timestamp) else pd.NaT)
    ult = None
    resolvida = []
    for p in parsed:                                       # dd/mm sem ano
        if isinstance(p, tuple):
            _, d, mo = p
            ref = ult if ult is not None else pd.Timestamp(2025, 8, 25)
            cand = [pd.Timestamp(y, mo, min(d, 28)) for y in (2025, 2026)]
            best = min(cand, key=lambda c: abs((c - ref).days))
            try:
                resolvida.append(pd.Timestamp(best.year, mo, d))
            except ValueError:
                resolvida.append(best)
        else:
            resolvida.append(p)
            if isinstance(p, pd.Timestamp):
                ult = p
    ordv = pd.Series([x.toordinal() if isinstance(x, pd.Timestamp) and pd.notna(x) else np.nan
                      for x in resolvida], index=df.index)
    ok = ordv.notna()
    x = df.loc[ok, "linha"].values.astype(float)
    y = ordv[ok].values.astype(float)
    med = pd.Series(y).rolling(15, center=True, min_periods=5).median().values
    bom = np.abs(y - med) <= 25
    xg = x[bom]
    yg = np.maximum.accumulate(pd.Series(y[bom]).rolling(5, center=True, min_periods=1).median().values)
    interp = np.interp(df["linha"].values.astype(float), xg, yg)
    proprio_ok = pd.Series(False, index=df.index)
    proprio_ok[ok] = bom
    proprio = ordv.values
    usa_proprio = proprio_ok.values & (np.abs(proprio - interp) <= 25)
    final = np.where(usa_proprio, proprio, interp)
    df["data_ok"] = usa_proprio
    df["dt"] = [pd.Timestamp.fromordinal(int(round(v))) for v in final]
    df["mes"] = df["dt"].dt.to_period("M")
    return df


def carregar(caminho):
    bruto = pd.read_csv(caminho, dtype=str, keep_default_na=False)
    bruto.columns = COLUNAS
    bruto["linha"] = np.arange(len(bruto)) + 2
    for c in VALORES:
        bruto[c] = bruto[c].map(brl)
    df = bruto[bruto["os"].str.strip().str.fullmatch(r"\d{1,4}")].copy()
    df["os_n"] = df["os"].str.strip().astype(int)
    df = df.sort_values("linha").reset_index(drop=True)
    df = _datas(df)

    d = df["data"].str.upper().str.strip()
    df["tipo"] = np.select(
        [d.str.contains("OR[CÇ]AM"), d.str.contains("MANUTEN"), d.str.contains("IZICAR"),
         d.str.contains("PE[CÇ]AS"), d.str.contains("JUNTO")],
        ["orcamento", "manutencao", "izicar", "pecas", "junto"], default="normal")
    df["cliente"] = df["proprietario"].str.upper().str.strip().replace({"": "(SEM NOME)"})
    df["eh_locadora"] = df["cliente"].isin(LOCADORA)
    df["mecanico"] = df["operador"].str.upper().str.strip().replace({"": "(SEM)"})
    for c in VALORES:
        df[c + "0"] = df[c].fillna(0.0)
    df["lucro_bruto_calc"] = df["vl_servico0"] - df["custo_pecas0"] - df["frete0"] - df["insumos0"]
    df["margem_pecas"] = df["lucro_bruto_calc"] - df["mao_obra0"]
    return bruto, df


def executadas(df):
    """OS que viraram serviço de fato (exclui orçamento, compra de peça, valor zero)."""
    return df[(~df["tipo"].isin(["orcamento", "pecas"])) & (df["vl_servico0"] > 0)].copy()
