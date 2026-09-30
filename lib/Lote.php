<?php
/**
 * ftth_doc :: seleção por área e exclusão em lote.
 *
 * Apagar uma rota era um serviço em três passos por caixa: desfazer as fusões no diagrama,
 * excluir cabo por cabo e só então as caixas. Aqui o usuário desenha a área no mapa e o lote
 * faz tudo numa transação, na ordem que as travas de cada serviço exigem.
 *
 * Regras (decididas com o Marcelo em 30/09/2026):
 *  - um vão sai quando QUALQUER uma das duas caixas dele sai. O que sobra do cabo fora da
 *    área continua terminando em caixa — nada fica solto;
 *  - cabo que perde só o miolo é partido em dois (ou mais) cabos, com os mesmos atributos;
 *  - fusão de caixa de FORA que usa fibra de um vão que sai também sai: a fibra deixou de
 *    existir, e uma ponta pendurada quebraria as invariantes da caixa vizinha;
 *  - cliente ligado em porta não bloqueia, mas exige confirmação digitada ("EXCLUIR"); ele é
 *    desvinculado pelo mesmo serviço da tela de clientes, que limpa o espelho nativo;
 *  - DC/POP nunca entra no lote: carrega OLT e DIO, e apagá-lo é decisão de uma caixa só.
 *
 * A prévia e a exclusão calculam a mesma coisa pelo mesmo método: o que a tela mostrou é o
 * que o banco faz — e o servidor nunca confia na lista que o navegador mandou de volta.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Versao.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Reserva.php';
require_once __DIR__ . '/Topologia.php';
require_once __DIR__ . '/Sincronizacao.php';
require_once __DIR__ . '/Caixa.php';

final class Lote
{
    /** Tipos que o lote não apaga (ver o cabeçalho). */
    public const PROTEGIDOS = ['DC'];

    /** O que o usuário digita para confirmar a exclusão com cliente ligado. */
    public const CONFIRMACAO = 'EXCLUIR';

    private const MAX_VERTICES = 200;
    private const MAX_CAIXAS   = 2000;

    /**
     * As caixas ativas da região dentro do polígono, com a prévia do que sairia.
     *
     * @param array $poligono [[lat,lng], ...] na ordem do desenho
     */
    public static function selecionar(int $regiaoId, array $poligono): Resultado
    {
        $pol = [];
        foreach (array_slice($poligono, 0, self::MAX_VERTICES) as $p) {
            if (!is_array($p) || count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
                continue;
            }
            $lat = (float) $p[0];
            $lng = (float) $p[1];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                return Resultado::erro('FTTH-GEO-002', ['lat' => $lat, 'lng' => $lng]);
            }
            $pol[] = [$lat, $lng];
        }
        if (count($pol) < 3) {
            return Resultado::erro('FTTH-LOTE-003');
        }

        $lats = array_column($pol, 0);
        $lngs = array_column($pol, 1);
        $ids  = [];
        foreach (Db::todos(
            'SELECT id, lat, lng FROM tab_ftth_caixa
              WHERE regiao_id = ? AND excluido_em IS NULL
                AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?',
            [$regiaoId, min($lats), max($lats), min($lngs), max($lngs)]) as $c) {
            if (self::dentro((float) $c['lat'], (float) $c['lng'], $pol)) {
                $ids[] = (int) $c['id'];
            }
        }

        return Resultado::ok(self::previa($regiaoId, $ids));
    }

    /**
     * O que a exclusão destas caixas levaria junto. Não grava nada.
     *
     * @return array{caixas:array, protegidas:array, vaos:int[], cabos:array, reservas:int[],
     *               ligacoes:int[], ligacoes_fora:array, splitters:int[], clientes:array,
     *               resumo:array}
     */
    public static function previa(int $regiaoId, array $caixaIds): array
    {
        $caixaIds = array_values(array_unique(array_filter(array_map('intval', $caixaIds))));
        $caixaIds = array_slice($caixaIds, 0, self::MAX_CAIXAS);

        $caixas = [];
        $protegidas = [];
        if ($caixaIds) {
            foreach (Db::todos(
                'SELECT id, tipo, nome, vao_id FROM tab_ftth_caixa
                  WHERE regiao_id = ? AND excluido_em IS NULL AND id IN (' . self::marcas($caixaIds) . ')
                  ORDER BY nome',
                array_merge([$regiaoId], $caixaIds)) as $c) {
                $linha = ['id' => (int) $c['id'], 'tipo' => $c['tipo'], 'nome' => $c['nome'],
                          'vao_id' => $c['vao_id'] !== null ? (int) $c['vao_id'] : null];
                if (in_array($c['tipo'], self::PROTEGIDOS, true)) {
                    $protegidas[] = $linha;
                } else {
                    $caixas[] = $linha;
                }
            }
        }
        $ids = array_column($caixas, 'id');

        // Vãos: os que encostam numa caixa que sai.
        $vaos = [];
        if ($ids) {
            $m = self::marcas($ids);
            foreach (Db::todos(
                "SELECT id FROM tab_ftth_cabo_vao
                  WHERE excluido_em IS NULL AND (caixa_ini_id IN ($m) OR caixa_fim_id IN ($m))",
                array_merge($ids, $ids)) as $v) {
                $vaos[] = (int) $v['id'];
            }
        }

        // Reservas: as dos vãos que saem vão junto (são o próprio cabo enrolado); as que
        // foram selecionadas direto já estão em $caixas.
        $reservas = [];
        if ($vaos) {
            foreach (Db::todos(
                'SELECT id FROM tab_ftth_caixa
                  WHERE tipo = "RESERVA" AND excluido_em IS NULL AND vao_id IN (' . self::marcas($vaos) . ')',
                $vaos) as $r) {
                if (!in_array((int) $r['id'], $ids, true)) {
                    $reservas[] = (int) $r['id'];
                }
            }
        }

        $cabos = self::cabosAfetados($vaos);

        // Ligações: todas as das caixas que saem, mais as de fora que usam fibra de vão que sai.
        $ligacoes = [];
        $fora = [];
        if ($ids) {
            foreach (Db::todos('SELECT id FROM tab_ftth_ligacao WHERE caixa_id IN (' . self::marcas($ids) . ')',
                               $ids) as $l) {
                $ligacoes[(int) $l['id']] = true;
            }
        }
        if ($vaos) {
            foreach (Db::todos(
                'SELECT DISTINCT p.ligacao_id, p.caixa_id, c.nome
                   FROM tab_ftth_ligacao_ponta p
                   JOIN tab_ftth_caixa c ON c.id = p.caixa_id
                  WHERE p.elemento = "VAO_FIBRA" AND p.elemento_id IN (' . self::marcas($vaos) . ')',
                $vaos) as $l) {
                if (isset($ligacoes[(int) $l['ligacao_id']])) {
                    continue;
                }
                $ligacoes[(int) $l['ligacao_id']] = true;
                $fora[$l['nome']] = ($fora[$l['nome']] ?? 0) + 1;
            }
        }
        ksort($fora);
        $ligacoesFora = [];
        foreach ($fora as $nome => $n) {
            $ligacoesFora[] = ['caixa' => $nome, 'ligacoes' => $n];
        }

        $splitters = [];
        $clientes  = [];
        if ($ids) {
            $m = self::marcas($ids);
            foreach (Db::todos("SELECT id FROM tab_ftth_splitter WHERE excluido_em IS NULL AND caixa_id IN ($m)",
                               $ids) as $s) {
                $splitters[] = (int) $s['id'];
            }
            foreach (Db::todos(
                "SELECT p.cliente_id, p.login, p.numero, s.nome AS splitter, c.nome AS caixa
                   FROM tab_ftth_porta p
                   JOIN tab_ftth_splitter s ON s.id = p.splitter_id
                   JOIN tab_ftth_caixa c    ON c.id = s.caixa_id
                  WHERE s.excluido_em IS NULL AND s.caixa_id IN ($m)
                  ORDER BY c.nome, s.nome, p.numero", $ids) as $p) {
                $clientes[] = ['cliente_id' => (int) $p['cliente_id'], 'login' => $p['login'],
                               'caixa' => $p['caixa'], 'splitter' => $p['splitter'],
                               'porta' => (int) $p['numero']];
            }
        }

        $porTipo = [];
        foreach ($caixas as $c) {
            $porTipo[$c['tipo']] = ($porTipo[$c['tipo']] ?? 0) + 1;
        }

        return [
            'caixas'        => $caixas,
            'protegidas'    => $protegidas,
            'vaos'          => $vaos,
            'cabos'         => $cabos,
            'reservas'      => $reservas,
            'ligacoes'      => array_keys($ligacoes),
            'ligacoes_fora' => $ligacoesFora,
            'splitters'     => $splitters,
            'clientes'      => $clientes,
            'resumo'        => [
                'caixas'          => count($caixas),
                'por_tipo'        => $porTipo,
                'protegidas'      => count($protegidas),
                'vaos'            => count($vaos),
                'cabos_excluidos' => count(array_filter($cabos, static fn($c) => $c['destino'] === 'excluir')),
                'cabos_partidos'  => count(array_filter($cabos, static fn($c) => $c['destino'] === 'partir')),
                'cabos_encurtados'=> count(array_filter($cabos, static fn($c) => $c['destino'] === 'encurtar')),
                'reservas'        => count($reservas),
                'ligacoes'        => count($ligacoes),
                'ligacoes_fora'   => array_sum($fora),
                'splitters'       => count($splitters),
                'clientes'        => count($clientes),
            ],
        ];
    }

    /**
     * Exclui as caixas e tudo o que depende delas, numa transação só.
     *
     * A lista é recalculada aqui: a tela manda só os ids das caixas.
     */
    public static function excluir(int $regiaoId, array $caixaIds, string $confirmacao, string $usuario): Resultado
    {
        $p = self::previa($regiaoId, $caixaIds);
        if (!$p['caixas']) {
            return Resultado::erro('FTTH-LOTE-001', ['protegidas' => count($p['protegidas'])],
                $p['protegidas']
                    ? 'A seleção só tem DC/POP, que não é excluído em lote. Exclua-o pela ficha.'
                    : null);
        }
        if ($p['clientes'] && strtoupper(trim($confirmacao)) !== self::CONFIRMACAO) {
            return Resultado::erro('FTTH-LOTE-002', ['clientes' => count($p['clientes'])]);
        }

        // Db::transacao só desfaz quando a closure LANÇA: cada recusa de serviço vira exceção
        // aqui dentro e volta a ser Resultado do lado de fora (mesmo padrão do modo Mover).
        $falha = null;
        try {
            return Db::transacao(function () use ($regiaoId, $p, $usuario, &$falha) {
                $exigir = static function (Resultado $r) use (&$falha): Resultado {
                    if (!$r->ok) {
                        $falha = $r;
                        throw new RuntimeException('lote recusado');
                    }
                    return $r;
                };

                // 1. Clientes: o serviço de sempre, que também limpa o espelho nativo.
                foreach ($p['clientes'] as $c) {
                    $exigir(Topologia::desvincularCliente($c['cliente_id'], $usuario));
                }

                // 2. Ligações (apagam de verdade; o estado vai para o histórico).
                foreach ($p['ligacoes'] as $id) {
                    $exigir(Topologia::desconectar($id, $usuario));
                }

                // 3. Splitters, agora sem cliente e sem fibra.
                foreach ($p['splitters'] as $id) {
                    $exigir(Topologia::excluirSplitter($id, null, $usuario));
                }

                // 4. Vãos, reservas deles e os cabos (excluídos, encurtados ou partidos).
                if ($p['vaos']) {
                    $m = self::marcas($p['vaos']);
                    Db::exec("UPDATE tab_ftth_cabo_vao SET excluido_em = NOW(), alterado_por = ?, alterado_em = NOW()
                               WHERE id IN ($m)", array_merge([$usuario], $p['vaos']));
                    Db::exec("DELETE FROM tab_ftth_diagrama_no WHERE tipo = 'VAO' AND elemento_id IN ($m)",
                             $p['vaos']);
                }
                if ($p['reservas']) {
                    Db::exec('UPDATE tab_ftth_caixa SET excluido_em = NOW(), alterado_por = ?, alterado_em = NOW()
                               WHERE id IN (' . self::marcas($p['reservas']) . ')',
                             array_merge([$usuario], $p['reservas']));
                }
                $cabosNovos = [];
                foreach ($p['cabos'] as $c) {
                    $cabosNovos = array_merge($cabosNovos, self::aplicarCabo($c, $regiaoId, $usuario));
                }

                // 5. As caixas. Reserva selecionada direto, num vão que fica, devolve os metros.
                $vaosParaRecalcular = [];
                foreach ($p['caixas'] as $c) {
                    Versao::avancar('caixa', $c['id'], null, $usuario);
                    Db::exec('UPDATE tab_ftth_caixa SET excluido_em = NOW() WHERE id = ?', [$c['id']]);
                    Auditoria::registrar('caixa', $c['id'], 'excluir', $c, ['lote' => true], $regiaoId);
                    if ($c['tipo'] === 'RESERVA' && $c['vao_id'] !== null && !in_array($c['vao_id'], $p['vaos'], true)) {
                        $vaosParaRecalcular[$c['vao_id']] = true;
                    }
                }
                foreach (array_keys($vaosParaRecalcular) as $vaoId) {
                    Reserva::recalcularVao((int) $vaoId, $usuario);
                }

                // Ponta livre de fora que perdeu o único vão que tinha sai também.
                Caixa::limparPontasOrfas($regiaoId, $usuario);

                // 6. O espelho da CTO na nativa sai junto (só existe para CTO ativa).
                $r = Resultado::ok();
                foreach ($p['caixas'] as $c) {
                    if ($c['tipo'] === 'CTO' || $c['tipo'] === 'CTO_AP') {
                        $r = Sincronizacao::caixa($c['id'], $r, $usuario);
                    }
                }

                Auditoria::registrar('regiao', $regiaoId, 'excluir_lote', null, $p['resumo'], $regiaoId);

                $r->data = ['resumo' => $p['resumo'], 'cabos_novos' => $cabosNovos];
                return $r;
            });
        } catch (Throwable $e) {
            if ($falha !== null) {
                return $falha;
            }
            throw $e;
        }
    }

    /**
     * Troca a cor dos pontos selecionados de uma vez (botão Cor do modo Selecionar).
     *
     * A ponta livre fica de fora: a cor dela é a do cabo. Os ids são conferidos contra a
     * região, como na exclusão — a tela manda só a lista.
     */
    public static function mudarCor(int $regiaoId, array $caixaIds, string $cor, string $usuario): Resultado
    {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $cor)) {
            return Resultado::erro('FTTH-SYS-002', ['cor' => $cor], 'Cor inválida.');
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $caixaIds)))), 0, self::MAX_CAIXAS);
        if (!$ids) {
            return Resultado::erro('FTTH-LOTE-001');
        }
        $cor = strtoupper($cor);
        return Db::transacao(function () use ($regiaoId, $ids, $cor, $usuario) {
            $alvos = array_map('intval', array_column(Db::todos(
                'SELECT id FROM tab_ftth_caixa
                  WHERE regiao_id = ? AND excluido_em IS NULL AND tipo <> "PONTA"
                    AND id IN (' . self::marcas($ids) . ')', array_merge([$regiaoId], $ids)), 'id'));
            foreach ($alvos as $id) {
                Versao::avancar('caixa', $id, null, $usuario);
            }
            if ($alvos) {
                Db::exec('UPDATE tab_ftth_caixa SET cor = ? WHERE id IN (' . self::marcas($alvos) . ')',
                         array_merge([$cor], $alvos));
                Auditoria::registrar('regiao', $regiaoId, 'cor_lote', null,
                    ['cor' => $cor, 'caixas' => $alvos], $regiaoId);
            }
            return Resultado::ok(['alterados' => count($alvos), 'cor' => $cor]);
        });
    }

    // ------------------------------------------------------------------ interno

    /**
     * Como cada cabo sai do lote: inteiro, encurtado ou partido em pedaços.
     *
     * Os vãos do cabo, na ordem, formam trechos contínuos separados pelos vãos que saem.
     * Nenhum trecho sobrando: o cabo sai. Um: fica encurtado. Mais de um: o primeiro fica
     * com o cabo e cada um dos outros vira um cabo novo.
     *
     * @return array<int, array{cabo_id:int, nome:?string, destino:string, trechos:int[][]}>
     */
    private static function cabosAfetados(array $vaosQueSaem): array
    {
        if (!$vaosQueSaem) {
            return [];
        }
        $saem = array_flip($vaosQueSaem);
        $cabos = [];
        foreach (Db::todos(
            'SELECT DISTINCT c.id, c.nome FROM tab_ftth_cabo c
               JOIN tab_ftth_cabo_vao v ON v.cabo_id = c.id
              WHERE c.excluido_em IS NULL AND v.id IN (' . self::marcas($vaosQueSaem) . ')',
            $vaosQueSaem) as $c) {
            $trechos = [];
            $atual   = [];
            foreach (Db::todos('SELECT id FROM tab_ftth_cabo_vao
                                 WHERE cabo_id = ? AND excluido_em IS NULL ORDER BY ordem, id',
                               [(int) $c['id']]) as $v) {
                if (isset($saem[(int) $v['id']])) {
                    if ($atual) {
                        $trechos[] = $atual;
                    }
                    $atual = [];
                } else {
                    $atual[] = (int) $v['id'];
                }
            }
            if ($atual) {
                $trechos[] = $atual;
            }
            $cabos[] = [
                'cabo_id' => (int) $c['id'],
                'nome'    => $c['nome'],
                'destino' => !$trechos ? 'excluir' : (count($trechos) === 1 ? 'encurtar' : 'partir'),
                'trechos' => $trechos,
            ];
        }
        return $cabos;
    }

    /** Aplica o destino de um cabo. Devolve os ids dos cabos novos (quando partido). */
    private static function aplicarCabo(array $c, int $regiaoId, string $usuario): array
    {
        $id = $c['cabo_id'];
        if ($c['destino'] === 'excluir') {
            $antes = Db::um('SELECT * FROM tab_ftth_cabo WHERE id = ?', [$id]);
            Db::exec('UPDATE tab_ftth_cabo SET excluido_em = NOW(), alterado_por = ?, alterado_em = NOW() WHERE id = ?',
                     [$usuario, $id]);
            Auditoria::registrar('cabo', $id, 'excluir', $antes, ['lote' => true], $regiaoId);
            return [];
        }

        self::renumerar($c['trechos'][0]);
        $novos = [];
        foreach (array_slice($c['trechos'], 1) as $trecho) {
            Db::exec(
                'INSERT INTO tab_ftth_cabo
                    (regiao_id, nome, fabricante, cabo_tipo_id, padrao_cores, cor_rota, status,
                     origem, importacao_id, criado_por, criado_em)
                 SELECT regiao_id, nome, fabricante, cabo_tipo_id, padrao_cores, cor_rota, status,
                        origem, importacao_id, ?, NOW()
                   FROM tab_ftth_cabo WHERE id = ?', [$usuario, $id]);
            $novo = Db::ultimoId();
            Db::exec('UPDATE tab_ftth_cabo_vao SET cabo_id = ? WHERE id IN (' . self::marcas($trecho) . ')',
                     array_merge([$novo], $trecho));
            self::renumerar($trecho);
            Auditoria::registrar('cabo', $novo, 'partir', ['cabo_origem' => $id], ['vaos' => $trecho], $regiaoId);
            $novos[] = $novo;
        }
        Versao::avancar('cabo', $id, null, $usuario);
        Auditoria::registrar('cabo', $id, $c['destino'] === 'partir' ? 'partir' : 'encurtar', null,
            ['vaos' => $c['trechos'][0], 'cabos_novos' => $novos], $regiaoId);
        return $novos;
    }

    /** Ordem 1..n no trecho, na sequência em que ele já estava. */
    private static function renumerar(array $vaoIds): void
    {
        foreach ($vaoIds as $i => $vaoId) {
            Db::exec('UPDATE tab_ftth_cabo_vao SET ordem = ? WHERE id = ?', [$i + 1, $vaoId]);
        }
    }

    /** Ponto no polígono (ray casting). Em área de bairro o plano lat/lng basta. */
    private static function dentro(float $lat, float $lng, array $pol): bool
    {
        $dentro = false;
        $n = count($pol);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$yi, $xi] = $pol[$i];
            [$yj, $xj] = $pol[$j];
            if ((($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $dentro = !$dentro;
            }
        }
        return $dentro;
    }

    private static function marcas(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
