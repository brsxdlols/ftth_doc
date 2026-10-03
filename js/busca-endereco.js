/* Pesquisa unificada: itens da rede e sugestões Photon; Enter/botão consulta endereço/CEP. */
window.FTTH_BUSCA = { iniciar: function (regiao) {
    'use strict';
    var timer, seq = 0, rede = [], enderecos = [], aviso = '', aguardando = false;
    var $campo = $('#busca'), $lista = $('#busca-lista'), $botao = $('#busca-endereco');
    function desenhar() {
        var html = '', grupo = '';
        rede.forEach(function (r) {
            if (grupo !== r.grupo) { grupo = r.grupo; html += '<div class="ftth-busca-grupo">' + FTTH.esc(grupo) + '</div>'; }
            html += '<div class="ftth-busca-item" tabindex="0" role="option" data-lat="' + Number(r.lat) + '" data-lng="' + Number(r.lng) +
                '" data-id="' + Number(r.id) + '" data-tipo="' + FTTH.esc(r.tipo) + '"><strong>' + FTTH.esc(r.rotulo) + '</strong> ' + FTTH.esc(r.detalhe || '') + '</div>';
        });
        if (enderecos.length) html += '<div class="ftth-busca-grupo">Endereços · OpenStreetMap</div>';
        enderecos.forEach(function (r) {
            html += '<div class="ftth-busca-item" tabindex="0" role="option" data-lat="' + Number(r.lat) + '" data-lng="' + Number(r.lng) + '">' + FTTH.esc(r.rotulo) + '</div>';
        });
        if (aguardando) html += '<div class="ftth-busca-item ftth-sub">Buscando endereços…</div>';
        else if (aviso) html += '<div class="ftth-busca-item ftth-sub">' + FTTH.esc(aviso) + '</div>';
        else if (!html) html = '<div class="ftth-busca-item ftth-sub">Nenhum resultado. Informe cidade/UF ou um CEP e pressione Enter.</div>';
        $lista.html(html).show();
    }
    function pesquisar(explicita) {
        clearTimeout(timer);
        var termo = $.trim($campo.val()), atual = ++seq;
        rede = []; enderecos = []; aviso = ''; aguardando = termo.length >= (explicita ? 3 : 4);
        $botao.prop('disabled', false);
        if (termo.length < 2) { $lista.hide(); return; }
        desenhar();
        FTTH.chamar({ url: 'mapa.php?ajax=buscar&regiao=' + regiao() + '&q=' + encodeURIComponent(termo),
            onOk: function (d) { if (atual !== seq) return; rede = d.resultados || []; desenhar(); }
        });
        if (!aguardando) { aviso = 'Continue digitando ou pressione Enter para pesquisar.'; desenhar(); return; }
        if (explicita) $botao.prop('disabled', true);
        FTTH.chamar({ url: 'mapa.php?ajax=' + (explicita ? 'buscar_endereco' : 'sugerir_endereco') + '&q=' + encodeURIComponent(termo),
            onOk: function (d) {
                if (atual !== seq) return;
                aguardando = false; enderecos = d.resultados || [];
                if (!enderecos.length && d.endereco) aviso = 'CEP encontrado, mas sem coordenadas. Pesquise pela cidade ou bairro.';
                desenhar();
            },
            onErro: function (m) { if (atual !== seq) return; aguardando = false; aviso = explicita ? m : 'Sugestões indisponíveis. Pressione Enter para pesquisar endereço ou CEP.'; desenhar(); }
        }).always(function () { if (atual === seq) $botao.prop('disabled', false); });
    }
    $campo.attr({ 'aria-label': 'Buscar ponto, endereço ou CEP', 'aria-controls': 'busca-lista' });
    $lista.attr('role', 'listbox');
    $campo.on('input', function () {
        ++seq; clearTimeout(timer); $botao.prop('disabled', false);
        $lista.hide();
        timer = setTimeout(function () { pesquisar(false); }, 800);
    }).on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); pesquisar(true); }
        else if (e.key === 'Escape') { ++seq; clearTimeout(timer); $lista.hide(); $botao.prop('disabled', false); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); $lista.find('[data-lat]').first().trigger('focus'); }
    });
    $botao.on('click', function () { pesquisar(true); });
    $lista.on('keydown', '[data-lat]', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); $(this).trigger('click'); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); $(this).nextAll('[data-lat]').first().trigger('focus'); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); var $anterior = $(this).prevAll('[data-lat]').first(); if ($anterior.length) $anterior.trigger('focus'); else $campo.trigger('focus'); }
    });
    $lista.on('click', '[data-lat]', function () { ++seq; clearTimeout(timer); $botao.prop('disabled', false); });
    $(document).on('click.ftthBusca', function (e) { if (!$(e.target).closest('.ftth-busca').length) { ++seq; clearTimeout(timer); $botao.prop('disabled', false); $lista.hide(); } });
} };
