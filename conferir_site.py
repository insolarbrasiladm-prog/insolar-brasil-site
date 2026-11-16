"""Conferência do site da Insolar Brasil.

Rodar ANTES de toda subida para a hospedagem:  python3 conferir_site.py
Se aparecer qualquer ERRO, nada sobe até corrigir.
"""
import re, sys, html
from pathlib import Path
from urllib.parse import urlparse, unquote

RAIZ = Path(__file__).parent
PAGINAS = ['index.html', 'energia-solar.html', 'baterias.html', 'mobilidade.html', 'zero-grid.html',
           'servicos.html', 'casa-inteligente.html', 'obras.html', 'contato.html', 'obrigado.html']
MENU_PADRAO = ['Início', 'Energia Solar', 'Baterias', 'Mobilidade', 'Zero Grid', 'Serviços', 'Obras',
               '🎁 Promoções', 'Contato']

# Palavras e promessas proibidas pelas regras da Insolar (documento Central)
PROIBIDAS = {
    r'sem juros': 'cartão só como "12x no cartão" (sem "sem juros")',
    r'\b18x\b|\b30x\b|\b24x\b': 'parcelamento não aprovado',
    r'cons[óo]rcio': 'consórcio não confirmado',
    r'\b10 anos\b|desde 2016|2\.600|3\.000 instala': 'credencial errada (usar "mais de 7 anos" e "centenas de instalações")',
    r'95\s*%|conta zerada|zera(r)? a conta': 'promessa de economia',
    r'or[çc]amento gr[áa]tis|visita gr[áa]tis': 'promessa não confirmada',
    r'suporte 24h|atendimento 24h': 'promessa não confirmada',
    r'100% de gera[çc][ãa]o': 'usar "Funcionando 100%"',
    r'\bproblema': 'palavra proibida nas artes e textos',
    r'99183-?3607': 'telefone que nunca pode aparecer',
    r'voltar ao mercado|de volta ao mercado': 'frase proibida',
    r'\bminutos\b|em 1 dia|pronta entrega': 'prazo proibido (prazo só no atendimento)',
    r'\breduz\w*|economiz\w* at[ée]': 'promessa de economia (sem reduzir custo/conta)',
    r'R\$\s?\d': 'preço de serviço não aparece no site (só a seção Black, que sai sozinha)',
}
# Trechos onde preço é permitido (cards da Black aprovados pelo jurídico, somem sozinhos)
PRECO_PERMITIDO = re.compile(r'data-fim=')

erros, avisos = [], []


def texto_visivel(t):
    t = re.sub(r'<script.*?</script>|<style.*?</style>', ' ', t, flags=re.S)
    return html.unescape(re.sub(r'<[^>]+>', ' ', t))


for nome in PAGINAS:
    p = RAIZ / nome
    if not p.exists():
        erros.append(f'{nome}: página não existe')
        continue
    t = p.read_text(encoding='utf-8')

    # 1) Menu: mesmos itens, mesma ordem, nenhum repetido
    m = re.search(r'<ul class="menu">(.*?)</ul>', t, flags=re.S)
    if not m:
        erros.append(f'{nome}: menu não encontrado')
    else:
        itens = [html.unescape(re.sub(r'<[^>]+>', '', x)).strip() for x in re.findall(r'<a [^>]*>(.*?)</a>', m.group(1))]
        if itens != MENU_PADRAO:
            erros.append(f'{nome}: menu diferente do padrão -> {itens}')
        ativos = re.findall(r'class="ativo"', m.group(1))
        if len(ativos) > 1:
            erros.append(f'{nome}: mais de um item do menu marcado')

    # 2) Uma página = um título, uma descrição, um endereço oficial
    checar = [(r'<title>', 'título'), (r'<meta name="description"', 'descrição')]
    if nome != 'obrigado.html':  # página de agradecimento não aparece no Google
        checar.append((r'rel="canonical"', 'endereço oficial'))
    for marca, rot in checar:
        n = len(re.findall(marca, t))
        if n != 1:
            erros.append(f'{nome}: {rot} aparece {n} vezes')
    can = re.search(r'rel="canonical" href="([^"]+)"', t)
    if can and nome not in ('index.html',) and not can.group(1).endswith('/' + nome):
        erros.append(f'{nome}: endereço oficial aponta para outra página ({can.group(1)})')

    # 3) Links internos e imagens existem
    for alvo in re.findall(r'(?:href|src)="([^"#?]+)', t):
        if alvo.startswith(('http', 'mailto:', 'tel:', 'data:', '//')):
            if alvo.startswith('https://wa.me/') and '557332235102' not in alvo:
                erros.append(f'{nome}: WhatsApp com número errado ({alvo})')
            continue
        caminho = RAIZ / unquote(alvo.lstrip('/')) if alvo != '/' else RAIZ / 'index.html'
        if not caminho.exists():
            erros.append(f'{nome}: link ou imagem quebrada -> {alvo}')

    # 3b) Imagem sem foto definida (ou que depende de outra que sumiu)
    for im in re.findall(r'<img\b[^>]*>', t):
        if 'src=' not in im:
            origem = re.search(r'data-from="([^"]+)"', im)
            if not origem or f'id="{origem.group(1)}"' not in t:
                erros.append(f'{nome}: imagem sem foto -> {im[:80]}')

    # 4) Âncoras (#) que levam a lugar nenhum dentro da própria página
    ids = set(re.findall(r'id="([^"]+)"', t))
    for anc in re.findall(r'href="#([^"]+)"', t):
        if anc not in ids:
            erros.append(f'{nome}: atalho #{anc} não leva a lugar nenhum')

    # 5) Palavras proibidas no texto visível e nos dados para buscadores
    visivel = texto_visivel(t) + ' ' + ' '.join(re.findall(r'content="([^"]*)"', t)) + ' ' + ' '.join(re.findall(r'<script type="application/ld\+json">(.*?)</script>', t, flags=re.S))
    for padrao, motivo in PROIBIDAS.items():
        for achado in re.finditer(padrao, visivel, flags=re.I):
            if padrao.startswith('R\\$'):
                # preço só é aceito dentro de bloco que some sozinho (Black)
                pos = t.find(achado.group(0))
                bloco = t[max(0, t.rfind('<section', 0, pos)):pos]
                if PRECO_PERMITIDO.search(bloco):
                    continue
            erros.append(f'{nome}: "{achado.group(0)}" -> {motivo}')
            break

    # 5b) Um título principal (h1) por página
    if len(re.findall(r'<h1\b', t)) != 1:
        erros.append(f'{nome}: precisa ter exatamente 1 título principal (h1)')
    tit = re.search(r'<title>(.*?)</title>', t)
    if tit and len(html.unescape(tit.group(1))) > 62 and nome != 'obrigado.html':
        avisos.append(f'{nome}: título com mais de 60 letras (o Google corta)')

    # 6) Contato obrigatório
    if '(73) 3223-5102' not in t:
        erros.append(f'{nome}: falta o WhatsApp (73) 3223-5102')
    if 'comercial@insolarbrasil.com.br' not in t:
        avisos.append(f'{nome}: sem o e-mail comercial@')

    # 7) Rodapé com as páginas novas
    if 'href="servicos.html"' not in t.split('<footer>')[-1] or 'href="casa-inteligente.html"' not in t.split('<footer>')[-1]:
        erros.append(f'{nome}: rodapé sem "Todos os serviços" ou "Casa Inteligente"')

# 8) Mapa do site e resumo para IAs
sm = (RAIZ / 'sitemap.xml').read_text(encoding='utf-8')
for nome in PAGINAS:
    if nome in ('obrigado.html',):
        continue
    url = 'https://insolarbrasil.com.br/' + ('' if nome == 'index.html' else nome)
    if f'<loc>{url}</loc>' not in sm:
        erros.append(f'sitemap.xml: falta {url}')
llms = (RAIZ / 'llms.txt').read_text(encoding='utf-8')
for padrao, motivo in PROIBIDAS.items():
    if not padrao.startswith('R\\$') and re.search(padrao, llms, flags=re.I):
        erros.append(f'llms.txt: {motivo}')

print('=' * 60)
if erros:
    print(f'❌ {len(erros)} ERRO(S) — NÃO SUBIR:')
    for e in erros:
        print('  -', e)
else:
    print('✅ Nenhum erro. Pode subir.')
for a in avisos:
    print('  aviso:', a)
sys.exit(1 if erros else 0)
