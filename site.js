/* Insolar Brasil — comportamentos comuns do site (16/11/2026)
   1) Toda mensagem de WhatsApp que sai do site diz de qual página o cliente veio
      (para o comercial lançar no Pipedrive com a origem "Site").
   2) Formulário em 2 passos (nome, WhatsApp e cidade; depois a conta de luz). */
(function () {
  var PAGINAS = {
    '': 'Início', 'index.html': 'Início', 'energia-solar.html': 'Energia Solar', 'baterias.html': 'Baterias',
    'zero-grid.html': 'Zero Grid', 'mobilidade.html': 'Mobilidade', 'servicos.html': 'Serviços',
    'casa-inteligente.html': 'Casa Inteligente', 'obras.html': 'Obras', 'contato.html': 'Contato', 'obrigado.html': 'Obrigado'
  };
  var arquivo = (location.pathname.split('/').pop() || '').toLowerCase();
  var pagina = PAGINAS[arquivo] || arquivo || 'Início';
  var MARCA = '(Vim pelo site: página ';

  // Medição de visitas (Google Analytics G-ZY5M0HH46K): 4 eventos
  function evento(nome) {
    try { if (window.gtag) window.gtag('event', nome, { pagina: pagina }); } catch (e) {}
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest && ev.target.closest('#simulador button');
    if (b && /calcular/i.test(b.textContent || '')) evento('simulacao_feita');
  }, true);

  // 1) WhatsApp com a página de origem (só muda o texto na hora do clique)
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('a[href*="wa.me/557332235102"]');
    if (!a) return;
    evento('clique_whatsapp');
    try {
      var u = new URL(a.href);
      var t = u.searchParams.get('text') || 'Olá! Quero falar com a Insolar.';
      if (t.indexOf(MARCA) === -1) t += '\n\n' + MARCA + pagina + ')';
      // monta o endereço com %20 (o WhatsApp nem sempre entende "+" como espaço)
      a.href = u.origin + u.pathname + '?text=' + encodeURIComponent(t);
    } catch (e) { /* navegador antigo: segue o link como está */ }
  }, true);

  // 2) Formulário em 2 passos
  document.querySelectorAll('form.form-passos').forEach(function (f) {
    var p1 = f.querySelector('.passo[data-p="1"]'), p2 = f.querySelector('.passo[data-p="2"]');
    var topo = f.querySelectorAll('.passos-topo span');
    var origem = f.querySelector('input[name="origem"]');
    if (origem) origem.value = pagina;
    function mostrar(n) {
      p1.hidden = n !== 1; p2.hidden = n !== 2;
      topo.forEach(function (s, i) { s.classList.toggle('ativo', i === n - 1); });
      var foco = (n === 1 ? p1 : p2).querySelector('input,select');
      if (foco) foco.focus({ preventScroll: true });
      f.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    var av = f.querySelector('.js-avancar');
    if (av) av.addEventListener('click', function () {
      var campos = p1.querySelectorAll('input,select');
      for (var i = 0; i < campos.length; i++) { if (!campos[i].reportValidity()) return; }
      var nome = (f.querySelector('input[name="nome"]').value || '').trim().split(' ')[0];
      var ola = f.querySelector('.ola');
      if (ola) ola.textContent = 'Obrigado, ' + nome + '! Falta pouco: conte para que é o sistema e, se puder, mande a sua conta de luz.';
      evento('formulario_passo1');
      mostrar(2);
    });
    var vt = f.querySelector('.js-voltar');
    if (vt) vt.addEventListener('click', function () { mostrar(1); });
    var arq = f.querySelector('input[type=file]'), nomeArq = f.querySelector('.arq-nome');
    if (arq && nomeArq) arq.addEventListener('change', function () {
      var n = arq.files[0];
      nomeArq.textContent = n ? ('✅ ' + n.name + (n.size > 8388608 ? ' — arquivo grande demais (máx. 8 MB)' : '')) : 'Nenhum arquivo escolhido · até 8 MB';
    });
    f.addEventListener('submit', function () {
      evento('formulario_enviado');
      var b = f.querySelector('button[type=submit]');
      if (b) { b.disabled = true; b.textContent = 'Enviando…'; }
    });
  });
})();
