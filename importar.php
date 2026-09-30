<?php
/**
 * ftth_doc :: importação de KMZ em quarentena.
 *
 * Nada do arquivo entra na rede sem revisão: o KMZ vira itens 'pendentes' e o usuário
 * importa item a item ou em lote. Itens com alerta ficam de fora do lote por padrão.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Regiao.php';
require_once __DIR__ . '/lib/Kmz.php';
require_once __DIR__ . '/lib/Quarentena.php';
require_once __DIR__ . '/lib/Upload.php';
require_once __DIR__ . '/lib/Caixa.php';
require_once __DIR__ . '/lib/Cabo.php';

const FTTH_MAX_KMZ_BYTES = 33554432; // 32 MB

/* ------------------------------------------------------------------ AJAX */
if (isset($_GET['ajax'])) {
    try {
        $acao = (string) $_GET['ajax'];

        if ($acao === 'estado') {
            $regiaoId = (int) ($_GET['regiao'] ?? 0);
            Resultado::ok([
                'regioes'     => Regiao::listar(),
                'contadores'  => $regiaoId ? Quarentena::contar($regiaoId) : null,
                'importacoes' => $regiaoId ? Db::todos(
                    'SELECT id, arquivo, total_itens, pendentes, importados, descartados, alertas,
                            criado_por, criado_em
                       FROM tab_ftth_importacao WHERE regiao_id = ? ORDER BY id DESC LIMIT 10',
                    [$regiaoId]) : [],
            ])->enviar();
        }

        if ($acao === 'itens') {
            $regiaoId = (int) ($_GET['regiao'] ?? 0);
            $status   = in_array($_GET['status'] ?? 'pendente', ['pendente', 'importado', 'descartado'], true)
                        ? $_GET['status'] : 'pendente';
            $itens = Quarentena::listar($regiaoId, $status, 1000);
            // Geometria completa é pesada: a lista só precisa do primeiro ponto e do total.
            foreach ($itens as &$i) {
                $g = json_decode((string) $i['geometria'], true) ?: [];
                $i['pontos']    = count($g);
                $i['lat']       = $g[0][0] ?? null;
                $i['lng']       = $g[0][1] ?? null;
                $i['alertas']   = json_decode((string) $i['alertas_json'], true) ?: [];
                unset($i['geometria'], $i['alertas_json'], $i['hash_geometria']);
            }
            Resultado::ok(['itens' => $itens, 'contadores' => Quarentena::contar($regiaoId)])->enviar();
        }

        if ($acao === 'enviar') {
            ftth_exigir_csrf();
            $regiaoId = (int) ($_POST['regiao'] ?? 0);
            if (!Regiao::obter($regiaoId)) {
                Log::aviso('kmz.enviar.regiao_invalida', ['regiao' => $regiaoId]);
                Resultado::erro('FTTH-SYS-002', ['campo' => 'regiao'], 'Selecione uma região.')->enviar(400);
            }
            $destino = ftth_dir('uploads');   // fora da pasta do addon: AppArmor nao deixa gravar la
            if ($destino === null) {
                Log::erro('kmz.enviar.sem_pasta', ['destino' => FTTH_DIR_DADOS . '/uploads']);
                Resultado::erro('FTTH-SYS-001',
                    ['detalhe' => 'Pasta de dados indisponível: ' . FTTH_DIR_DADOS . '/uploads'])->enviar(500);
            }
            $rec = Upload::receber($_FILES['arquivo'] ?? [], ['kmz', 'kml'],
                $destino, FTTH_MAX_KMZ_BYTES);
            if (!$rec['ok']) {
                // Sem este log, uma recusa de upload vira adivinhação.
                Log::aviso('kmz.enviar.upload_recusado', [
                    'detalhe' => $rec['detalhe'] ?? '',
                    'nome'    => $_FILES['arquivo']['name'] ?? null,
                    'tamanho' => $_FILES['arquivo']['size'] ?? null,
                    'erro_php'=> $_FILES['arquivo']['error'] ?? null,
                ]);
                Resultado::erro($rec['code'], ['detalhe' => $rec['detalhe']])->enviar(400);
            }
            try {
                // "Tipo dos pontos": vazio é Automático (descrição, nome, pasta, documento).
                $tipoPontos = trim((string) ($_POST['tipo_pontos'] ?? ''));
                $corPontos  = trim((string) ($_POST['cor_pontos'] ?? ''));
                $r = Kmz::importarParaQuarentena($rec['caminho'], $rec['nome_original'], $regiaoId,
                    $usuario_logado, !empty($_POST['forcar']), $tipoPontos !== '' ? $tipoPontos : null,
                    $corPontos !== '' ? $corPontos : null);
                Log::info('kmz.importar', ['arquivo' => $rec['nome_original']] + $r['resumo']);
                Resultado::ok($r)->enviar();
            } catch (RuntimeException $e) {
                @unlink($rec['caminho']);
                [$code, $extra] = array_pad(explode(':', $e->getMessage(), 2), 2, null);
                if (!Erros::existe($code)) {
                    throw $e;
                }
                Resultado::erro($code, ['anterior' => $extra ? json_decode($extra, true) : null])->enviar(409);
            }
        }

        if ($acao === 'importar') {
            ftth_exigir_csrf();
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            $r = count($ids) === 1
                ? Quarentena::importarItem($ids[0], $usuario_logado, (array) ($_POST['ajustes'] ?? []))
                : Quarentena::importarLote($ids, $usuario_logado, !empty($_POST['incluir_alerta']));
            $r->enviar();
        }

        // Trocar o tipo na quarentena: o select da linha manda um id, o "Mudar tipo para" vários.
        if ($acao === 'tipo') {
            ftth_exigir_csrf();
            Quarentena::alterarTipo((array) ($_POST['ids'] ?? []), (string) ($_POST['subtipo'] ?? ''),
                                    $usuario_logado)->enviar();
        }

        // Desfazer a decisão: importado volta para pendente ou descartado; descartado, para pendente.
        if ($acao === 'reverter') {
            ftth_exigir_csrf();
            Quarentena::reverter((array) ($_POST['ids'] ?? []), (string) ($_POST['destino'] ?? ''),
                                 $usuario_logado)->enviar();
        }

        if ($acao === 'cor') {
            ftth_exigir_csrf();
            Quarentena::alterarCor((array) ($_POST['ids'] ?? []), (string) ($_POST['cor'] ?? ''),
                                   $usuario_logado)->enviar();
        }

        if ($acao === 'descartar') {
            ftth_exigir_csrf();
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            Quarentena::descartar($ids, $usuario_logado, (string) ($_POST['motivo'] ?? ''))->enviar();
        }

        Resultado::erro('FTTH-SYS-002')->enviar(400);
    } catch (Throwable $e) {
        Log::excecao('importar.ajax', $e, ['ajax' => $_GET['ajax'] ?? '']);
        Resultado::erro('FTTH-SYS-001')->enviar(500);
    }
}

/* ------------------------------------------------------------------ página */
$regioes = [];
$falha   = null;
try {
    $regioes = Regiao::listar();
} catch (Throwable $e) {
    Log::excecao('importar.carregar', $e);
    $falha = 'Não foi possível carregar as regiões. FTTH-SYS-001 · ' . Resultado::requestId();
}

include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<!-- Tela compacta (25/09/2026): cabeçalho numa linha, envio numa linha, e quarentena + itens
     num card só — contadores, abas e ações em lote na mesma barra, em cima da tabela. -->
<div class="ftth-wrap ftth-imp">
    <div class="ftth-imp-cab">
        <a class="ftth-btn ftth-btn--sec" href="mapa.php"><i class="bi-arrow-left"></i> Mapa</a>
        <div>
            <h1 class="ftth-titulo"><i class="bi-box-seam"></i> Importar KMZ</h1>
            <p class="ftth-sub">O arquivo entra em <strong>quarentena</strong>: nada vai para a rede antes
                de você conferir. Itens com alerta ficam de fora da importação em lote.</p>
        </div>
    </div>

    <?php if ($falha): ?><div class="ftth-aviso ftth-aviso--erro"><?= htmlspecialchars($falha) ?></div><?php endif; ?>

    <div class="ftth-card ftth-imp-envio">
        <div class="ftth-imp-campo">
            <label class="ftth-rotulo-campo" for="sel-regiao">Região</label>
            <select id="sel-regiao" class="ftth-campo">
                <option value="">— selecione —</option>
                <?php foreach ($regioes as $r): ?>
                    <option value="<?= (int) $r['id'] ?>">
                        <?= htmlspecialchars($r['nome']) ?>
                        (<?= (int) $r['caixas'] ?> caixas<?= $r['quarentena'] ? ', ' . (int) $r['quarentena'] . ' na quarentena' : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ftth-imp-campo">
            <label class="ftth-rotulo-campo" for="arq">Arquivo KMZ ou KML (até 32 MB)</label>
            <input type="file" id="arq" class="ftth-imp-arquivo" accept=".kmz,.kml">
        </div>
        <!-- Arquivo que não diz o tipo de cada ponto (o de postes da concessionária, por exemplo):
             o usuário diz aqui, e vale para todos os pontos do arquivo. -->
        <div class="ftth-imp-campo">
            <label class="ftth-rotulo-campo" for="tipo-pontos">Tipo dos pontos</label>
            <select id="tipo-pontos" class="ftth-campo">
                <option value="">Automático</option>
                <?php foreach (Caixa::ROTULOS as $valor => $rotulo): ?>
                    <option value="<?= $valor ?>"><?= htmlspecialchars($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ftth-imp-campo">
            <label class="ftth-rotulo-campo" for="cor-pontos">Cor dos pontos</label>
            <select id="cor-pontos" class="ftth-campo">
                <option value="">Do arquivo</option>
                <?php foreach (Caixa::NOMES_CORES as $hex => $nomeCor): ?>
                    <option value="<?= $hex ?>" style="color:<?= $hex ?>">&#9679; <?= htmlspecialchars($nomeCor) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="ftth-btn ftth-btn--pri" id="btn-enviar"><i class="bi-upload"></i> Enviar</button>
        <label class="ftth-sub ftth-imp-chk">
            <input type="checkbox" id="forcar"> importar mesmo se o arquivo já foi usado
        </label>
        <?php if (!$regioes): ?>
            <p class="ftth-sub ftth-imp-linha">Nenhuma região cadastrada ainda: crie a primeira no mapa.</p>
        <?php endif; ?>
        <div id="saida-envio" class="ftth-imp-linha"></div>
    </div>

    <div class="ftth-card" id="card-itens" style="display:none">
        <div class="ftth-imp-topo">
            <h2>Quarentena</h2>
            <div id="contadores" class="ftth-imp-contadores"></div>
        </div>
        <div class="ftth-imp-barra">
            <div class="ftth-imp-abas">
                <button class="btn-status ativo" data-status="pendente">Pendentes</button>
                <button class="btn-status" data-status="importado">Importados</button>
                <button class="btn-status" data-status="descartado">Descartados</button>
            </div>
            <!-- Em lote só se age sobre pendentes: nas outras abas a barra fica só com as abas. -->
            <div class="ftth-imp-lote" id="imp-lote">
                <span class="ftth-sub" id="contagem-sel"></span>
                <select id="lote-tipo" class="ftth-campo ftth-imp-tipo" title="Muda o tipo dos pontos marcados">
                    <option value="">Mudar tipo para…</option>
                    <?php foreach (Caixa::ROTULOS as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"><?= htmlspecialchars($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="ftth-btn ftth-btn--sec" id="btn-tipo-sel" disabled>Aplicar</button>
                <select id="lote-cor" class="ftth-campo ftth-imp-tipo" title="Muda a cor dos itens marcados">
                    <option value="">Mudar cor para…</option>
                    <?php foreach (Caixa::NOMES_CORES as $hex => $nomeCor): ?>
                        <option value="<?= $hex ?>" style="color:<?= $hex ?>">&#9679; <?= htmlspecialchars($nomeCor) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="ftth-btn ftth-btn--sec" id="btn-cor-sel" disabled>Aplicar</button>
                <span class="ftth-acoes-sep"></span>
                <label class="ftth-sub ftth-imp-chk">
                    <input type="checkbox" id="incluir-alerta"> incluir itens com alerta
                </label>
                <button class="ftth-btn ftth-btn--sec" id="btn-descartar-sel" disabled>Descartar selecionados</button>
                <button class="ftth-btn ftth-btn--pri" id="btn-importar-sel" disabled>Importar selecionados</button>
            </div>
            <!-- Importados e Descartados também têm lote: desfazer a decisão (30/09/2026). -->
            <div class="ftth-imp-lote" id="imp-lote-importado" style="display:none">
                <span class="ftth-sub contagem-sel-outra"></span>
                <button class="ftth-btn ftth-btn--sec btn-reverter" data-destino="descartado" disabled>
                    <i class="bi-x-circle"></i> Descartar</button>
                <button class="ftth-btn ftth-btn--pri btn-reverter" data-destino="pendente" disabled>
                    <i class="bi-arrow-counterclockwise"></i> Voltar para pendentes</button>
            </div>
            <div class="ftth-imp-lote" id="imp-lote-descartado" style="display:none">
                <span class="ftth-sub contagem-sel-outra"></span>
                <button class="ftth-btn ftth-btn--pri btn-reverter" data-destino="pendente" disabled>
                    <i class="bi-arrow-counterclockwise"></i> Voltar para pendentes</button>
            </div>
        </div>
        <div id="saida-lote"></div>
        <div class="ftth-imp-tabela">
            <table class="ftth-tabela" id="tabela-itens">
                <thead><tr>
                    <th style="width:26px"><input type="checkbox" id="sel-todos"></th>
                    <th>Nome</th><th>Tipo</th><th>Cor</th><th>Situação</th><th style="width:1%"></th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="ftth-card" id="card-importacoes" style="display:none">
        <h2>Arquivos já enviados</h2>
        <table class="ftth-tabela" id="tabela-importacoes"><tbody></tbody></table>
    </div>
</div>

<script>
(function () {
    var CSRF = <?= json_encode(ftth_csrf_token()) ?>;
    // As opções da coluna Tipo: os tipos do cadastro de ponto e as capacidades de cabo.
    var TIPOS_PONTO = <?= json_encode(Caixa::ROTULOS, JSON_UNESCAPED_UNICODE) ?>;
    var CAPACIDADES = <?= json_encode(array_column(Cabo::tipos(), 'rotulo'), JSON_UNESCAPED_UNICODE) ?>;
    var CORES_PONTO = <?= json_encode(Caixa::NOMES_CORES, JSON_UNESCAPED_UNICODE) ?>;
    var CORES_CABO  = <?= json_encode(Cabo::CORES_ROTA, JSON_UNESCAPED_UNICODE) ?>;
    var regiao = 0, status = 'pendente', itens = [];

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/[&<>"]/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
            });
    }
    function aviso(sel, texto, tipo) {
        $(sel).html('<div class="ftth-aviso ' + (tipo || '') + '">' + String(texto).replace(/\n/g, '<br>') + '</div>');
    }

    function carregarEstado() {
        FTTH.chamar({
            url: 'importar.php?ajax=estado&regiao=' + regiao,
            onOk: function (d) {
                if (d.contadores) {
                    var c = d.contadores;
                    $('#contadores').html(
                        '<span class="ftth-selo ftth-selo--novo">' + (c.pendentes || 0) + ' pendentes</span> ' +
                        '<span class="ftth-selo ftth-selo--ok">' + (c.importados || 0) + ' importados</span> ' +
                        '<span class="ftth-selo ftth-selo--pend">' + (c.com_alerta || 0) + ' com alerta</span> ' +
                        '<span class="ftth-selo ftth-selo--info">' + (c.descartados || 0) + ' descartados</span>');
                }
                var html = '';
                (d.importacoes || []).forEach(function (i) {
                    html += '<tr><td class="mono">' + esc(i.arquivo) + '</td>'
                         +  '<td>' + i.total_itens + ' itens</td>'
                         +  '<td>' + i.importados + ' importados · ' + i.pendentes + ' pendentes · '
                         +  i.descartados + ' descartados</td>'
                         +  '<td class="ftth-sub">' + esc(i.criado_por) + ' · ' + esc(i.criado_em) + '</td></tr>';
                });
                $('#tabela-importacoes tbody').html(html);
                $('#card-importacoes').toggle(html !== '');
            },
            onErro: function (m) { aviso('#saida-envio', m, 'ftth-aviso--erro'); }
        });
    }

    function carregarItens() {
        if (!regiao) { $('#card-itens').hide(); return; }
        FTTH.chamar({
            url: 'importar.php?ajax=itens&regiao=' + regiao + '&status=' + status,
            onOk: function (d) {
                itens = d.itens || [];
                desenharItens();
                $('#card-itens').show();
            },
            onErro: function (m) { aviso('#saida-lote', m, 'ftth-aviso--erro'); }
        });
    }

    function desenharItens() {
        // Recarregar (depois de trocar um tipo, por exemplo) não pode desmarcar o que o
        // usuário já tinha marcado.
        var manter = {};
        selecionados().forEach(function (id) { manter[id] = true; });
        var html = '';
        itens.forEach(function (i) {
            var alertas = (i.alertas || []).map(function (a) {
                return '<span class="ftth-selo ftth-selo--pend" title="' + esc(a.code) + '">' + esc(a.message) + '</span>';
            }).join(' ');
            var situacao = i.status === 'pendente'
                ? (alertas || '<span class="ftth-selo ftth-selo--novo">não revisado</span>')
                : (i.status === 'importado'
                    ? '<span class="ftth-selo ftth-selo--ok">importado</span>'
                    : '<span class="ftth-selo ftth-selo--info">descartado</span>');
            var tipo = celulaTipo(i);
            html += '<tr data-id="' + i.id + '">'
                 +  '<td><input type="checkbox" class="chk" value="' + i.id + '"' + (manter[i.id] ? ' checked' : '') + '></td>'
                 +  '<td class="mono">' + esc(i.nome || '(sem nome)') + '</td>'
                 +  '<td>' + tipo + '</td>'
                 +  '<td>' + celulaCor(i) + '</td>'
                 +  '<td>' + situacao + '</td>'
                 +  '<td class="ftth-imp-acoes">' + (i.status === 'pendente'
                        ? '<button class="ftth-btn ftth-btn--sec btn-item" data-acao="importar" data-id="' + i.id + '">Importar</button> '
                        + '<button class="ftth-btn ftth-btn--sec btn-item" data-acao="descartar" data-id="' + i.id + '">Descartar</button>'
                        : '<button class="ftth-btn ftth-btn--sec btn-reverter-item" data-id="' + i.id + '">'
                        + '<i class="bi-arrow-counterclockwise"></i> Voltar para pendentes</button>') + '</td></tr>';
        });
        $('#tabela-itens tbody').html(html || '<tr><td colspan="6" class="ftth-sub">Nenhum item.</td></tr>');
        atualizarSelecao();
    }

    /**
     * Uma coluna só (30/09/2026): ponto mostra o tipo do cadastro, cabo mostra a capacidade.
     * Pendente vira select — trocar grava na hora; nas outras abas é só texto.
     */
    function celulaTipo(i) {
        var ehCabo = i.tipo_sugerido === 'VAO';
        var atual = i.subtipo || '';
        if (i.status !== 'pendente') {
            return ehCabo ? 'Cabo · ' + esc(atual) : esc(TIPOS_PONTO[atual] || atual);
        }
        var opcoes = ehCabo ? CAPACIDADES.map(function (c) { return [c, c]; })
                            : Object.keys(TIPOS_PONTO).map(function (t) { return [t, TIPOS_PONTO[t]]; });
        // Tipo que veio do arquivo e não está no menu (legado: CTO AP, cliente...) aparece como está.
        if (atual && !opcoes.some(function (o) { return o[0] === atual; })) opcoes.unshift([atual, atual]);
        return (ehCabo ? '<span class="ftth-sub">Cabo</span> ' : '')
             + '<select class="ftth-campo ftth-imp-tipo sel-tipo" data-id="' + i.id + '" data-atual="' + esc(atual) + '">'
             + opcoes.map(function (o) {
                   return '<option value="' + esc(o[0]) + '"' + (o[0] === atual ? ' selected' : '') + '>'
                        + esc(o[1]) + '</option>';
               }).join('')
             + '</select>';
    }

    /**
     * Cor no mesmo padrão do Tipo: select por linha, com a bolinha na cor de cada opção e a
     * borda do select na cor atual. Cor do arquivo fora da paleta aparece como está.
     */
    function celulaCor(i) {
        var atual = String(i.cor || '').toUpperCase();
        var paleta = i.tipo_sugerido === 'VAO' ? CORES_CABO : CORES_PONTO;
        if (i.status !== 'pendente') {
            return '<span class="ftth-imp-bolinha" style="background:' + esc(atual) + '"></span> '
                 + esc(paleta[atual] || atual);
        }
        var opcoes = Object.keys(paleta).map(function (h) { return [h, paleta[h]]; });
        if (atual && !paleta[atual]) opcoes.unshift([atual, 'Do arquivo (' + atual + ')']);
        return '<select class="ftth-campo ftth-imp-tipo ftth-imp-cor sel-cor" data-id="' + i.id + '"'
             + ' data-atual="' + esc(atual) + '" style="border-left-color:' + esc(atual) + '">'
             + opcoes.map(function (o) {
                   return '<option value="' + esc(o[0]) + '" style="color:' + esc(o[0]) + '"'
                        + (o[0] === atual ? ' selected' : '') + '>&#9679; ' + esc(o[1]) + '</option>';
               }).join('')
             + '</select>';
    }

    function trocarCor(ids, cor, aoTerminar) {
        FTTH.chamar({
            url: 'importar.php?ajax=cor',
            method: 'POST',
            data: { csrf: CSRF, ids: ids, cor: cor },
            onOk: function (d) { aoTerminar(null, d); },
            onErro: function (m) { aoTerminar(m); }
        });
    }

    function trocarTipo(ids, subtipo, aoTerminar) {
        FTTH.chamar({
            url: 'importar.php?ajax=tipo',
            method: 'POST',
            data: { csrf: CSRF, ids: ids, subtipo: subtipo },
            onOk: function (d) { aoTerminar(null, d); },
            onErro: function (m) { aoTerminar(m); }
        });
    }

    function selecionados() {
        return $('.chk:checked').map(function () { return parseInt(this.value, 10); }).get();
    }
    function atualizarSelecao() {
        var n = selecionados().length;
        $('#contagem-sel').text(n ? n + ' selecionado(s)' : '');
        $('#btn-importar-sel, #btn-descartar-sel').prop('disabled', n === 0);
        $('#btn-tipo-sel').prop('disabled', n === 0 || !$('#lote-tipo').val());
        $('#btn-cor-sel').prop('disabled', n === 0 || !$('#lote-cor').val());
        $('.contagem-sel-outra').text(n ? n + ' selecionado(s)' : '');
        $('.btn-reverter').prop('disabled', n === 0);
    }

    function agir(ids, acao, extra) {
        var dados = { csrf: CSRF, ids: ids };
        if (extra) $.extend(dados, extra);
        FTTH.chamar({
            url: 'importar.php?ajax=' + acao,
            method: 'POST',
            data: dados,
            onOk: function (d) {
                var msg;
                if (acao === 'descartar') {
                    msg = d.descartados + ' item(ns) descartado(s).';
                } else if (d.importados) {
                    msg = d.importados.length + ' item(ns) importado(s).';
                    if (d.pulados && d.pulados.length) {
                        msg += ' ' + d.pulados.length + ' pulado(s): '
                             + d.pulados.slice(0, 5).map(function (p) { return '#' + p.id + ' (' + p.motivo + ')'; }).join(', ');
                    }
                } else {
                    msg = 'Item importado: ' + esc(d.tipo) + ' #' + d.id + '.';
                }
                var pulou = d.pulados && d.pulados.length;
                aviso('#saida-lote', msg, pulou ? '' : 'ftth-aviso--ok');
                FTTH.toast(pulou ? 'avis' : 'ok', msg);
                carregarItens();
                carregarEstado();
            },
            onErro: function (m) { aviso('#saida-lote', m, 'ftth-aviso--erro'); }
        });
    }

    $('#sel-regiao').on('change', function () {
        regiao = parseInt(this.value, 10) || 0;
        carregarEstado();
        carregarItens();
    });

    $('#btn-enviar').on('click', function () {
        var arq = document.getElementById('arq').files[0];
        if (!regiao) { aviso('#saida-envio', 'Selecione a região antes.', 'ftth-aviso--erro'); return; }
        if (!arq)    { aviso('#saida-envio', 'Escolha um arquivo.', 'ftth-aviso--erro'); return; }

        var fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('regiao', regiao);
        fd.append('arquivo', arq);
        if ($('#forcar').is(':checked')) fd.append('forcar', '1');
        fd.append('tipo_pontos', $('#tipo-pontos').val() || '');
        fd.append('cor_pontos', $('#cor-pontos').val() || '');

        var $b = $(this).prop('disabled', true).text('Enviando…');
        FTTH.chamar({
            url: 'importar.php?ajax=enviar',
            method: 'POST',
            data: fd,
            onOk: function (d) {
                var s = d.resumo;
                aviso('#saida-envio', 'Arquivo lido: ' + s.pontos + ' pontos e ' + s.linhas + ' linhas ('
                    + (s.metros / 1000).toFixed(1) + ' km). ' + s.total + ' itens em quarentena, '
                    + s.alertas + ' com alerta.', 'ftth-aviso--ok');
                carregarEstado(); carregarItens();
            },
            onErro: function (m) { aviso('#saida-envio', m, 'ftth-aviso--erro'); }
        }).always(function () { $b.prop('disabled', false).html('<i class="bi-upload"></i> Enviar'); });
    });

    $(document).on('change', '.chk', atualizarSelecao);
    $('#lote-tipo, #lote-cor').on('change', atualizarSelecao);

    // Cor da linha: grava na hora, sem recarregar a lista (a cor não mexe em alerta nenhum).
    $(document).on('change', '.sel-cor', function () {
        var $s = $(this), id = parseInt($s.data('id'), 10), nova = $s.val();
        $s.prop('disabled', true);
        trocarCor([id], nova, function (erro) {
            $s.prop('disabled', false);
            if (erro) {
                $s.val($s.data('atual'));
                aviso('#saida-lote', erro, 'ftth-aviso--erro');
                return;
            }
            $s.data('atual', nova).css('border-left-color', nova);
            itens.forEach(function (i) { if (Number(i.id) === id) i.cor = nova; });
        });
    });

    // "Mudar cor para": vale para tudo que está marcado, ponto ou cabo.
    $('#btn-cor-sel').on('click', function () {
        var ids = selecionados(), cor = $('#lote-cor').val();
        if (!ids.length || !cor) return;
        var $b = $(this).prop('disabled', true);
        trocarCor(ids, cor, function (erro, d) {
            if (erro) {
                aviso('#saida-lote', erro, 'ftth-aviso--erro');
                $b.prop('disabled', false);
                return;
            }
            aviso('#saida-lote', d.alterados + ' item(ns) pintado(s) de ' + esc(CORES_PONTO[cor] || cor) + '.',
                  'ftth-aviso--ok');
            $('#lote-cor').val('');
            carregarItens();
        });
    });

    // Select da linha: grava na hora. Se o servidor recusar, volta para o que era.
    $(document).on('change', '.sel-tipo', function () {
        var $s = $(this), id = parseInt($s.data('id'), 10), novo = $s.val();
        $s.prop('disabled', true);
        trocarTipo([id], novo, function (erro) {
            $s.prop('disabled', false);
            if (erro) {
                $s.val($s.data('atual'));
                aviso('#saida-lote', erro, 'ftth-aviso--erro');
                return;
            }
            $s.data('atual', novo);
            itens.forEach(function (i) { if (Number(i.id) === id) i.subtipo = novo; });
            // O alerta de "sem nome" muda com o tipo (poste sem nome é o normal).
            carregarItens();
            carregarEstado();
        });
    });

    // "Mudar tipo para": vale para os PONTOS marcados; cabo marcado junto é pulado.
    $('#btn-tipo-sel').on('click', function () {
        var ids = selecionados(), tipo = $('#lote-tipo').val();
        if (!ids.length || !tipo) return;
        var $b = $(this).prop('disabled', true);
        trocarTipo(ids, tipo, function (erro, d) {
            if (erro) {
                aviso('#saida-lote', erro, 'ftth-aviso--erro');
                $b.prop('disabled', false);
                return;
            }
            aviso('#saida-lote', d.alterados + ' ponto(s) mudado(s) para ' + esc(TIPOS_PONTO[tipo] || tipo) + '.'
                + (d.pulados ? ' ' + d.pulados + ' cabo(s) marcado(s) ficaram como estavam.' : ''),
                'ftth-aviso--ok');
            $('#lote-tipo').val('');
            carregarItens();
            carregarEstado();
        });
    });
    $('#sel-todos').on('change', function () {
        $('.chk').prop('checked', this.checked);
        atualizarSelecao();
    });
    $(document).on('click', '.btn-item', function () {
        var id = parseInt($(this).data('id'), 10);
        var acao = $(this).data('acao');
        if (acao === 'descartar' && !confirm('Descartar este item?')) return;
        agir([id], acao);
    });
    $('#btn-importar-sel').on('click', function () {
        var ids = selecionados();
        if (!confirm('Importar ' + ids.length + ' item(ns) para a rede?')) return;
        agir(ids, 'importar', $('#incluir-alerta').is(':checked') ? { incluir_alerta: 1 } : null);
    });
    $('#btn-descartar-sel').on('click', function () {
        var ids = selecionados();
        if (!confirm('Descartar ' + ids.length + ' item(ns)?')) return;
        agir(ids, 'descartar');
    });

    /**
     * Desfazer a decisão. Reverter um importado apaga o que foi criado no mapa — e o servidor
     * recusa, item por item, o que já foi ligado à rede (cabo, fusão, emenda).
     */
    function reverter(ids, destino) {
        var deImportados = status === 'importado';
        var pergunta = deImportados
            ? (destino === 'pendente' ? 'Voltar ' : 'Descartar ') + ids.length + ' item(ns) importado(s)?\n\n'
              + 'O que foi criado no mapa (ponto ou cabo) é excluído. Ponto que já tem cabo ou fusão, '
              + 'e cabo com fibra ligada, ficam de fora.'
            : 'Voltar ' + ids.length + ' item(ns) para pendentes?';
        if (!confirm(pergunta)) return;
        FTTH.chamar({
            url: 'importar.php?ajax=reverter',
            method: 'POST',
            data: { csrf: CSRF, ids: ids, destino: destino },
            onOk: function (d) {
                var msg = d.revertidos + ' item(ns) ' + (destino === 'pendente' ? 'voltaram para pendentes.' : 'descartado(s).');
                if (d.pulados.length) {
                    msg += ' ' + d.pulados.length + ' ficaram de fora: '
                         + d.pulados.slice(0, 5).map(function (p) {
                               return esc(p.nome || ('#' + p.id)) + ' (' + esc(p.motivo) + ')';
                           }).join(', ') + (d.pulados.length > 5 ? '…' : '');
                }
                aviso('#saida-lote', msg, d.pulados.length ? '' : 'ftth-aviso--ok');
                FTTH.toast(d.pulados.length ? 'avis' : 'ok', d.revertidos + ' item(ns) '
                    + (destino === 'pendente' ? 'voltaram para pendentes.' : 'descartado(s).')
                    + (d.pulados.length ? ' ' + d.pulados.length + ' ficaram de fora.' : ''));
                carregarItens();
                carregarEstado();
            },
            onErro: function (m) { aviso('#saida-lote', m, 'ftth-aviso--erro'); }
        });
    }
    $('.btn-reverter').on('click', function () {
        var ids = selecionados();
        if (ids.length) reverter(ids, $(this).data('destino'));
    });
    $(document).on('click', '.btn-reverter-item', function () {
        reverter([parseInt($(this).data('id'), 10)], 'pendente');
    });

    $('.btn-status').on('click', function () {
        status = $(this).data('status');
        $('.btn-status').removeClass('ativo');
        $(this).addClass('ativo');
        $('#imp-lote').toggle(status === 'pendente');
        $('#imp-lote-importado').toggle(status === 'importado');
        $('#imp-lote-descartado').toggle(status === 'descartado');
        $('#sel-todos').prop('checked', false);
        $('.chk').prop('checked', false);
        $('#saida-lote').empty();
        carregarItens();
    });
})();
</script>

<?php include('../../baixo.php'); ?>
<script src="../../menu.js<?= $ext_mk ?>"></script>
</body>
</html>
