<?php
/**
 * ftth_doc :: caixas (CTO, CEO, DC, poste, prédio, cliente, reserva, problema…).
 *
 * Toda criação/alteração passa por aqui — nem o mapa nem a importação escrevem direto
 * na tabela (3b.0). Regras: nome único por região entre as ativas, coordenada
 * válida, tipo conhecido, lock otimista e auditoria.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Versao.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Reserva.php';

final class Caixa
{
    public const TIPOS = ['DC', 'PREDIO', 'POSTE', 'CEO', 'CTO', 'CTO_AP',
                          'CLIENTE', 'RESERVA', 'PROBLEMA', 'FALHA', 'PONTA'];

    /**
     * O que o seletor de tipo OFERECE, na ordem em que aparece.
     *
     * É menor que TIPOS de propósito: poste, cliente e CTO AP saíram do menu por decisão
     * de 22/09/2026 (nenhuma caixa ativa os usava), mas continuam aceitos na validação —
     * senão as caixas antigas desses tipos deixariam de poder ser editadas, e o histórico
     * passaria a referenciar um tipo que o sistema não reconhece mais.
     */
    public const ROTULOS = [
        'CTO'      => 'CTO',
        'CEO'      => 'CEO',
        'DC'       => 'DC / POP',
        'PREDIO'   => 'Prédio',
        'POSTE'    => 'Poste',      // voltou ao menu em 30/09/2026, agora com cadastro próprio
        'PROBLEMA' => 'Problema',
        'RESERVA'  => 'Reserva',
    ];

    /** Ícones do Bootstrap Icons que o MK-AUTH já carrega — sem dependência nova. */
    public const ICONES = [
        'DC'       => 'bi-hdd-rack-fill',
        'PREDIO'   => 'bi-building',
        'POSTE'    => 'bi-signpost-2-fill',
        'CEO'      => 'bi-diagram-3-fill',
        'CLIENTE'  => 'bi-person-fill',
        'CTO'      => 'bi-box-seam',
        'CTO_AP'   => 'bi-buildings-fill',
        'PROBLEMA' => 'bi-exclamation-triangle-fill',
        'RESERVA'  => 'bi-bookmark-fill',
        'FALHA'    => 'bi-exclamation-octagon-fill',
        'PONTA'    => 'bi-record-circle',
    ];

    public static function icone(string $tipo): string
    {
        return self::ICONES[$tipo] ?? 'bi-box-seam';
    }

    /**
     * Silhueta da peça real, desenhada para tamanho de ícone.
     *
     * Vem do desenho técnico da caixa, mas redesenhada PARA 24px: o original tem texto de
     * 9px e parafusos de 4px, que nessa escala viram sujeira. O que sobrevive é o que
     * identifica a peça de longe — na CEO o topo abaulado e o anel de fechamento, na CTO
     * a caixa alta e a trava central de fecho.
     *
     * Mora aqui, e não no JS, para existir UMA fonte: o marcador do mapa, a ficha da caixa
     * e o seletor de tipo do modal desenham todos a partir desta constante.
     * `{cor}` é trocado por quem usa.
     */
    public const SILHUETAS = [
        'CEO' => '<path d="M7 11 a5 5 0 0 1 10 0 v5 h-10 z" fill="{cor}" stroke="#fff"'
               . ' stroke-width="1.6" stroke-linejoin="round"/>'
               . '<rect x="5.2" y="15.4" width="13.6" height="3.6" rx="1.2" fill="{cor}"'
               . ' stroke="#fff" stroke-width="1.6"/>'
               // As entradas de cabo saem do corpo, então usam a cor da caixa: em branco
               // elas sumiriam na ficha e no modal, que têm fundo claro.
               . '<path d="M10 18.8v2.6M14 18.8v2.6" stroke="{cor}" stroke-width="2"'
               . ' stroke-linecap="round"/>',

        'CTO' => '<rect x="6.2" y="3.6" width="11.6" height="16.8" rx="2.6" fill="{cor}"'
               . ' stroke="#fff" stroke-width="1.6"/>'
               . '<circle cx="12" cy="16.2" r="1.9" fill="none" stroke="#fff" stroke-width="1.5"/>'
               . '<path d="M9 7.4h6" stroke="#fff" stroke-width="1.5" stroke-linecap="round"'
               . ' opacity=".85"/>',

        // DC/POP: o rack, com as unidades empilhadas.
        'DC' => '<rect x="4.8" y="3.4" width="14.4" height="17.2" rx="2" fill="{cor}"'
              . ' stroke="#fff" stroke-width="1.6"/>'
              . '<path d="M7.6 7.6h8.8M7.6 12h8.8M7.6 16.4h8.8" stroke="#fff"'
              . ' stroke-width="1.5" stroke-linecap="round"/>',

        // Prédio: as janelas em duas colunas e a porta na base.
        'PREDIO' => '<rect x="6.4" y="3.4" width="11.2" height="17.2" rx="1.6" fill="{cor}"'
                  . ' stroke="#fff" stroke-width="1.6"/>'
                  . '<path d="M9.3 7.4h1.5M13.2 7.4h1.5M9.3 11h1.5M13.2 11h1.5"'
                  . ' stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>'
                  . '<path d="M10.6 20.6v-4.2h2.8v4.2" fill="none" stroke="#fff"'
                  . ' stroke-width="1.5" stroke-linejoin="round"/>',

        // Problema: o triângulo de alerta, que já é lido como aviso em qualquer lugar.
        'PROBLEMA' => '<path d="M12 3.4 20.8 19.6H3.2z" fill="{cor}" stroke="#fff"'
                    . ' stroke-width="1.6" stroke-linejoin="round"/>'
                    . '<path d="M12 9.4v4.2" stroke="#fff" stroke-width="1.9"'
                    . ' stroke-linecap="round"/>'
                    . '<circle cx="12" cy="16.6" r="1.1" fill="#fff"/>',

        // Poste: o símbolo do UpperX — a haste com a cruzeta e a mão-francesa. O contorno branco
        // por baixo é o que o separa do asfalto no satélite; o traço é fino de propósito, porque
        // postes são muitos e não podem competir com as caixas.
        'POSTE' => '<path d="M12 3.2v17.6M6 6.4h12M8.6 9.8h6.8" fill="none" stroke="#fff"'
                 . ' stroke-width="4.6" stroke-linecap="round"/>'
                 . '<path d="M12 3.2v17.6M6 6.4h12M8.6 9.8h6.8" fill="none" stroke="{cor}"'
                 . ' stroke-width="2.4" stroke-linecap="round"/>',

        // Reserva: a sobra de cabo enrolada — o rolo visto de frente.
        'RESERVA' => '<circle cx="12" cy="12" r="7.8" fill="{cor}" stroke="#fff" stroke-width="1.6"/>'
                   . '<circle cx="12" cy="12" r="3.9" fill="none" stroke="#fff" stroke-width="1.5"/>'
                   . '<circle cx="12" cy="12" r="1.2" fill="#fff"/>',
    ];

    /** O SVG pronto, ou null quando o tipo não tem silhueta (aí vale o Bootstrap Icon). */
    public static function silhueta(string $tipo, string $cor = '#4A5568', int $px = 24): ?string
    {
        if (!isset(self::SILHUETAS[$tipo])) {
            return null;
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' . $px
             . '" height="' . $px . '" class="ftth-silhueta">'
             . str_replace('{cor}', $cor, self::SILHUETAS[$tipo]) . '</svg>';
    }

    public const CORES = ['#29B6F6', '#1E40AF', '#FF9100', '#E53935', '#43A047',
                          '#FDD835', '#8E24AA', '#EC407A', '#212121', '#9E9E9E', '#795548'];

    /** O nome de cada cor da paleta, para os selects de cor (quarentena e seleção no mapa). */
    public const NOMES_CORES = [
        '#29B6F6' => 'Azul claro', '#1E40AF' => 'Azul', '#FF9100' => 'Laranja', '#E53935' => 'Vermelho',
        '#43A047' => 'Verde', '#FDD835' => 'Amarelo', '#8E24AA' => 'Roxo', '#EC407A' => 'Rosa',
        '#212121' => 'Preto', '#9E9E9E' => 'Cinza', '#795548' => 'Marrom',
    ];

    public static function criar(int $regiaoId, string $tipo, string $nome, string $cor,
                                 float $lat, float $lng, string $usuario, array $extra = []): Resultado
    {
        $nome = trim($nome);
        $tipo = strtoupper(trim($tipo));

        if (!in_array($tipo, self::TIPOS, true)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'tipo'], 'Tipo de caixa inválido.');
        }
        if ($tipo === 'PONTA') {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'tipo'],
                'A ponta livre nasce sozinha, na ponta de um cabo lançado sem caixa.');
        }
        if ($nome === '' || mb_strlen($nome) > 80) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'], 'Informe um nome de até 80 caracteres.');
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return Resultado::erro('FTTH-GEO-002', ['lat' => $lat, 'lng' => $lng]);
        }
        if (!Db::valor('SELECT id FROM tab_ftth_regiao WHERE id = ? AND excluido_em IS NULL', [$regiaoId])) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'regiao'], 'Região inválida.');
        }

        // O nome é único por região entre as caixas ATIVAS: o de uma excluída volta a ficar
        // livre (0.9.6). A auditoria é por id, então o histórico não se confunde.
        $existe = self::nomeOcupado($regiaoId, $nome);
        if ($existe) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome', 'caixa_id' => $existe],
                'Já existe uma caixa com esse nome nesta região.');
        }

        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $cor)) {
            $cor = '#00C853';
        }

        // Reserva nasce em cima de um cabo, colada no traçado, e com os metros que somam nele.
        $vaoId = null;
        $reservaM = null;
        if ($tipo === 'RESERVA') {
            $ancora = self::ancoraDaReserva($regiaoId, $lat, $lng, $extra['reserva_m'] ?? null);
            if ($ancora instanceof Resultado) {
                return $ancora;
            }
            [$vaoId, $reservaM, $lat, $lng] = $ancora;
        }

        // Ponto novo em cima da ponta livre de um cabo: ele assume a ponta, e o cabo passa
        // a terminar nele. A caixa vai para o lugar exato da ponta, como na emenda.
        $ponta = in_array($tipo, ['RESERVA', 'POSTE'], true) ? null : self::pontaProxima($regiaoId, $lat, $lng);
        if ($ponta !== null) {
            $lat = (float) $ponta['lat'];
            $lng = (float) $ponta['lng'];
        }

        return Db::transacao(function () use ($regiaoId, $tipo, $nome, $cor, $lat, $lng, $usuario, $extra,
                                              $vaoId, $reservaM, $ponta) {
            Db::exec(
                'INSERT INTO tab_ftth_caixa
                    (regiao_id, tipo, nome, cor, lat, lng, capacidade, reserva_m, vao_id, pai_id,
                     andar, observacao, origem, criado_por, criado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"manual",?,NOW())',
                [$regiaoId, $tipo, $nome, $cor, $lat, $lng,
                 $extra['capacidade'] ?? null, $reservaM, $vaoId, $extra['pai_id'] ?? null,
                 $extra['andar'] ?? null, $extra['observacao'] ?? null, $usuario]
            );
            $id = Db::ultimoId();
            if ($vaoId !== null) {
                Reserva::recalcularVao($vaoId, $usuario);
            }
            Auditoria::registrar('caixa', $id, 'criar', null,
                ['nome' => $nome, 'tipo' => $tipo, 'lat' => $lat, 'lng' => $lng], $regiaoId);
            $absorvida = $ponta !== null && self::absorverPonta((int) $ponta['id'], $id, $usuario);
            return Resultado::ok(['id' => $id, 'nome' => $nome, 'tipo' => $tipo, 'cor' => $cor,
                                  'lat' => $lat, 'lng' => $lng, 'ponta_absorvida' => $absorvida]);
        });
    }

    // ------------------------------------------------------------------ ponta livre de cabo

    /**
     * Âncora automática da ponta de um cabo lançado sem caixa (0.9.6).
     *
     * O vão continua ligando duas caixas — topologia, potência e diagrama dependem disso —,
     * só que uma delas é esta PONTA: sem splitter, sem fusão, sem espelho, e fora das listas.
     * Quem chama já está numa transação. O nome sai do id, então não disputa com os do usuário.
     */
    public static function criarPonta(int $regiaoId, float $lat, float $lng, string $cor, string $usuario): int
    {
        Db::exec(
            'INSERT INTO tab_ftth_caixa (regiao_id, tipo, nome, cor, lat, lng, origem, criado_por, criado_em)
             VALUES (?, "PONTA", ?, ?, ?, ?, "manual", ?, NOW())',
            [$regiaoId, 'PONTA.tmp.' . bin2hex(random_bytes(6)),
             preg_match('/^#[0-9A-Fa-f]{6}$/', $cor) ? $cor : '#00E676', $lat, $lng, $usuario]);
        $id = Db::ultimoId();
        Db::exec('UPDATE tab_ftth_caixa SET nome = ? WHERE id = ?', ['PONTA.' . $id, $id]);
        Auditoria::registrar('caixa', $id, 'criar', null,
            ['tipo' => 'PONTA', 'lat' => $lat, 'lng' => $lng], $regiaoId);
        return $id;
    }

    /** A ponta livre ativa mais perto do ponto, dentro do raio de emenda — ou null. */
    public static function pontaProxima(int $regiaoId, float $lat, float $lng, ?int $ignorar = null): ?array
    {
        $raio = Cabo::raioQuebra();
        $margem = ($raio + 5) / 110540.0;
        $melhor = null;
        foreach (Db::todos(
            'SELECT id, nome, lat, lng FROM tab_ftth_caixa
              WHERE regiao_id = ? AND tipo = "PONTA" AND excluido_em IS NULL AND id <> ?
                AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?',
            [$regiaoId, $ignorar ?? 0, $lat - $margem, $lat + $margem,
             $lng - $margem * 2, $lng + $margem * 2]) as $p) {
            $d = Geo::distancia($lat, $lng, (float) $p['lat'], (float) $p['lng']);
            if ($d <= $raio && ($melhor === null || $d < $melhor['distancia_m'])) {
                $melhor = $p + ['distancia_m' => $d];
            }
        }
        return $melhor;
    }

    /**
     * A caixa assume a ponta livre: os vãos que terminavam na PONTA passam a terminar nela,
     * as pontas do traçado vão para a posição da caixa e a PONTA sai. Recusa (false) quando
     * a PONTA e a caixa já são as duas pontas de um mesmo vão — ele viraria um laço.
     * Quem chama já está numa transação.
     */
    public static function absorverPonta(int $pontaId, int $caixaId, string $usuario): bool
    {
        if ($pontaId === $caixaId || (int) Db::valor(
                'SELECT COUNT(*) FROM tab_ftth_cabo_vao
                  WHERE excluido_em IS NULL
                    AND ((caixa_ini_id = ? AND caixa_fim_id = ?) OR (caixa_ini_id = ? AND caixa_fim_id = ?))',
                [$pontaId, $caixaId, $caixaId, $pontaId]) > 0) {
            return false;
        }
        $caixa = Db::um('SELECT regiao_id, lat, lng FROM tab_ftth_caixa WHERE id = ?', [$caixaId]);
        Db::exec('UPDATE tab_ftth_cabo_vao SET caixa_ini_id = ? WHERE caixa_ini_id = ? AND excluido_em IS NULL',
                 [$caixaId, $pontaId]);
        Db::exec('UPDATE tab_ftth_cabo_vao SET caixa_fim_id = ? WHERE caixa_fim_id = ? AND excluido_em IS NULL',
                 [$caixaId, $pontaId]);
        self::arrastarPontasDosVaos($caixaId, (float) $caixa['lat'], (float) $caixa['lng'], $usuario);
        Db::exec('UPDATE tab_ftth_caixa SET excluido_em = NOW(), alterado_por = ?, alterado_em = NOW() WHERE id = ?',
                 [$usuario, $pontaId]);
        Auditoria::registrar('caixa', $pontaId, 'ancorar', null, ['caixa' => $caixaId], (int) $caixa['regiao_id']);
        return true;
    }

    /**
     * Ancora a ponta livre numa caixa, a pedido do usuário (modo Mover: soltou uma em cima da
     * outra e confirmou). A caixa fica onde está; é o cabo que vai até ela.
     */
    public static function ancorarPonta(int $pontaId, int $caixaId, string $usuario): Resultado
    {
        $ponta = Db::um('SELECT id, regiao_id, tipo FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL',
                        [$pontaId]);
        $caixa = Db::um('SELECT id, regiao_id, tipo, nome FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL',
                        [$caixaId]);
        if (!$ponta || $ponta['tipo'] !== 'PONTA') {
            return Resultado::erro('FTTH-TOP-001', ['ponta' => $pontaId], 'Ponta livre não encontrada.');
        }
        if (!$caixa) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $caixaId]);
        }
        if (in_array($caixa['tipo'], ['PONTA', 'RESERVA', 'POSTE'], true)) {
            return Resultado::erro('FTTH-SYS-002', ['caixa' => $caixaId],
                'A ponta livre só se ancora num ponto de verdade (CTO, CEO, POP…).');
        }
        if ((int) $caixa['regiao_id'] !== (int) $ponta['regiao_id']) {
            return Resultado::erro('FTTH-SYS-002', ['caixa' => $caixaId],
                'A ponta e o ponto são de regiões diferentes.');
        }

        return Db::transacao(function () use ($pontaId, $caixaId, $caixa, $usuario) {
            if (!self::absorverPonta($pontaId, $caixaId, $usuario)) {
                return Resultado::erro('FTTH-GEO-003', ['caixa' => $caixaId],
                    'O cabo já sai de ' . $caixa['nome'] . ': ancorar a outra ponta ali fecharia um laço.');
            }
            return Resultado::ok(['caixa' => $caixaId, 'nome' => $caixa['nome']]);
        });
    }

    /** PONTA que ficou sem cabo (o cabo saiu) não tem razão de existir. Devolve quantas saíram. */
    public static function limparPontasOrfas(int $regiaoId, string $usuario): int
    {
        return Db::exec(
            'UPDATE tab_ftth_caixa c
                SET c.excluido_em = NOW(), c.alterado_por = ?, c.alterado_em = NOW()
              WHERE c.regiao_id = ? AND c.tipo = "PONTA" AND c.excluido_em IS NULL
                AND NOT EXISTS (SELECT 1 FROM tab_ftth_cabo_vao v
                                 WHERE v.excluido_em IS NULL
                                   AND (v.caixa_ini_id = c.id OR v.caixa_fim_id = c.id))',
            [$usuario, $regiaoId]);
    }

    /**
     * Onde a reserva vai morar: o vão sob o ponto e o ponto já colado no traçado.
     *
     * @return array{0:int,1:float,2:float,3:float}|Resultado [vao_id, metros, lat, lng] ou a recusa
     */
    private static function ancoraDaReserva(int $regiaoId, float $lat, float $lng, $metros)
    {
        $m = Reserva::metros($metros);
        if ($m === null || $m <= 0) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'reserva_m'],
                'Informe os metros da reserva (até ' . Reserva::MAX_METROS . ' m).');
        }
        $sob = Cabo::vaoSobPonto($regiaoId, $lat, $lng);
        if ($sob === null) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'ponto'],
                'A reserva precisa ficar em cima de um cabo. Marque o ponto sobre o traçado.');
        }
        return [(int) $sob['vao']['id'], $m,
                round((float) $sob['projecao']['lat'], 7), round((float) $sob['projecao']['lng'], 7)];
    }

    /**
     * Move a caixa e leva junto a ponta de cada cabo que encosta nela.
     *
     * O vínculo do vão é por id de caixa, mas o DESENHO vive em `vertices`, com as
     * coordenadas literais das duas pontas. Sem reescrevê-las, o cabo continuaria apontando
     * para onde a caixa estava — e, pior, `comprimento_optico` ficaria velho, que é o
     * número que o cálculo de potência usa. Por isso o recálculo vem junto, na mesma
     * transação: o mapa e a conta nunca discordam.
     */
    public static function mover(int $id, float $lat, float $lng, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $id]);
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return Resultado::erro('FTTH-GEO-002', ['lat' => $lat, 'lng' => $lng]);
        }

        return Db::transacao(function () use ($id, $lat, $lng, $versao, $usuario, $antes) {
            if (!Versao::avancar('caixa', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'caixa', 'id' => $id, 'versao_atual' => Versao::atual('caixa', $id)]);
            }
            Db::exec('UPDATE tab_ftth_caixa SET lat = ?, lng = ? WHERE id = ?', [$lat, $lng, $id]);
            $vaos = self::arrastarPontasDosVaos($id, $lat, $lng, $usuario);

            Auditoria::registrar('caixa', $id, 'mover',
                ['lat' => $antes['lat'], 'lng' => $antes['lng']],
                ['lat' => $lat, 'lng' => $lng, 'vaos_ajustados' => $vaos],
                (int) $antes['regiao_id']);

            return Resultado::ok(['id' => $id, 'lat' => $lat, 'lng' => $lng, 'vaos' => $vaos]);
        });
    }

    /**
     * Reescreve a ponta de cada vão que encosta nesta caixa e recalcula os comprimentos.
     * Devolve quantos vãos foram ajustados.
     */
    private static function arrastarPontasDosVaos(int $caixaId, float $lat, float $lng,
                                                  string $usuario): int
    {
        $folga = Config::num('fator_folga_cabo', 1.03);
        $ajustados = 0;

        foreach (Db::todos(
            'SELECT id, caixa_ini_id, caixa_fim_id, vertices, fator_folga, reserva_m
               FROM tab_ftth_cabo_vao
              WHERE (caixa_ini_id = ? OR caixa_fim_id = ?) AND excluido_em IS NULL',
            [$caixaId, $caixaId]) as $v) {

            $pontos = json_decode((string) $v['vertices'], true);
            if (!is_array($pontos) || count($pontos) < 2) {
                continue;
            }

            // A caixa pode estar nas duas pontas (não deveria, mas o dado manda).
            if ((int) $v['caixa_ini_id'] === $caixaId) {
                $pontos[0] = [$lat, $lng];
            }
            if ((int) $v['caixa_fim_id'] === $caixaId) {
                $pontos[count($pontos) - 1] = [$lat, $lng];
            }

            $metros = Geo::comprimento($pontos);
            $fator  = (float) ($v['fator_folga'] ?: $folga);
            $optico = Geo::comprimentoOptico($metros, $fator, (float) $v['reserva_m']);

            Db::exec(
                'UPDATE tab_ftth_cabo_vao
                    SET vertices = ?, comprimento_geo = ?, comprimento_optico = ?,
                        alterado_por = ?, alterado_em = NOW()
                  WHERE id = ?',
                [json_encode($pontos), round($metros, 2), $optico, $usuario, (int) $v['id']]
            );
            $ajustados++;
        }

        return $ajustados;
    }

    public static function alterar(int $id, array $campos, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $id]);
        }

        $nome = isset($campos['nome']) ? trim((string) $campos['nome']) : $antes['nome'];
        $tipo = isset($campos['tipo']) ? strtoupper((string) $campos['tipo']) : $antes['tipo'];
        $cor  = isset($campos['cor']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $campos['cor'])
                ? (string) $campos['cor'] : $antes['cor'];

        if ($nome === '' || !in_array($tipo, self::TIPOS, true)) {
            return Resultado::erro('FTTH-SYS-002');
        }
        if ($nome !== $antes['nome'] && self::nomeOcupado((int) $antes['regiao_id'], $nome, $id)) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'nome'],
                'Já existe uma caixa com esse nome nesta região.');
        }
        // A PONTA vira ponto de verdade (é assim que se ancora a ponta livre), mas nada vira PONTA.
        if ($tipo === 'PONTA' && $antes['tipo'] !== 'PONTA') {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'tipo'],
                'A ponta livre nasce sozinha, na ponta de um cabo lançado sem caixa.');
        }
        // Poste não é ponta de cabo: o cabo passa por ele (vértice), não termina nele.
        if ($tipo === 'POSTE' && $antes['tipo'] !== 'POSTE' && (int) Db::valor(
                'SELECT COUNT(*) FROM tab_ftth_cabo_vao
                  WHERE (caixa_ini_id = ? OR caixa_fim_id = ?) AND excluido_em IS NULL', [$id, $id]) > 0) {
            return Resultado::erro('FTTH-SYS-002', ['campo' => 'tipo'],
                'Este ponto é ponta de cabo e não pode virar poste: o cabo passa pelo poste, não termina nele.');
        }

        // Reserva: metros e o vão onde ela está. Vira reserva (ou é uma reserva do KMZ, sem
        // cabo) -> precisa de um cabo sob o ponto. Deixa de ser -> sai do cabo.
        $lat = (float) $antes['lat'];
        $lng = (float) $antes['lng'];
        $vaoId = $antes['vao_id'] !== null ? (int) $antes['vao_id'] : null;
        $reservaM = $antes['reserva_m'] !== null ? (float) $antes['reserva_m'] : null;
        if ($tipo === 'RESERVA') {
            if ($antes['tipo'] !== 'RESERVA' && (int) Db::valor(
                    'SELECT COUNT(*) FROM tab_ftth_cabo_vao
                      WHERE (caixa_ini_id = ? OR caixa_fim_id = ?) AND excluido_em IS NULL', [$id, $id]) > 0) {
                return Resultado::erro('FTTH-SYS-002', ['campo' => 'tipo'],
                    'Esta caixa é ponta de cabo e não pode virar reserva.');
            }
            $metros = array_key_exists('reserva_m', $campos) ? $campos['reserva_m'] : $reservaM;
            if ($vaoId === null) {
                $ancora = self::ancoraDaReserva((int) $antes['regiao_id'], $lat, $lng, $metros);
                if ($ancora instanceof Resultado) {
                    return $ancora;
                }
                [$vaoId, $reservaM, $lat, $lng] = $ancora;
            } else {
                $reservaM = Reserva::metros($metros);
                if ($reservaM === null || $reservaM <= 0) {
                    return Resultado::erro('FTTH-SYS-002', ['campo' => 'reserva_m'],
                        'Informe os metros da reserva (até ' . Reserva::MAX_METROS . ' m).');
                }
            }
        } else {
            $vaoId = null;
            $reservaM = null;
        }
        $vaoAntigo = $antes['vao_id'] !== null ? (int) $antes['vao_id'] : null;

        return Db::transacao(function () use ($id, $nome, $tipo, $cor, $campos, $versao, $usuario, $antes,
                                              $lat, $lng, $vaoId, $reservaM, $vaoAntigo) {
            if (!Versao::avancar('caixa', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'caixa', 'id' => $id, 'versao_atual' => Versao::atual('caixa', $id)]);
            }
            Db::exec(
                'UPDATE tab_ftth_caixa SET nome = ?, tipo = ?, cor = ?, capacidade = ?, observacao = ?,
                        lat = ?, lng = ?, vao_id = ?, reserva_m = ?
                  WHERE id = ?',
                [$nome, $tipo, $cor,
                 array_key_exists('capacidade', $campos) ? $campos['capacidade'] : $antes['capacidade'],
                 array_key_exists('observacao', $campos) ? $campos['observacao'] : $antes['observacao'],
                 $lat, $lng, $vaoId, $reservaM,
                 $id]
            );
            foreach (array_unique(array_filter([$vaoAntigo, $vaoId])) as $v) {
                Reserva::recalcularVao((int) $v, $usuario);
            }
            $depois = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ?', [$id]);
            Auditoria::registrar('caixa', $id, 'alterar', $antes, $depois, (int) $antes['regiao_id']);
            return Resultado::ok($depois);
        });
    }

    /** Exclusão lógica; recusa enquanto houver cabo ou ligação presos à caixa. */
    public static function excluir(int $id, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $id]);
        }

        $vaos = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_cabo_vao
              WHERE (caixa_ini_id = ? OR caixa_fim_id = ?) AND excluido_em IS NULL', [$id, $id]);
        $ligacoes = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_ligacao WHERE caixa_id = ?', [$id]);
        if ($vaos > 0 || $ligacoes > 0) {
            return Resultado::erro('FTTH-TOP-016', ['vaos' => $vaos, 'ligacoes' => $ligacoes],
                'A caixa ainda tem ' . $vaos . ' cabo(s) e ' . $ligacoes . ' ligação(ões).');
        }

        return Db::transacao(function () use ($id, $versao, $usuario, $antes) {
            if (!Versao::avancar('caixa', $id, $versao, $usuario)) {
                return Resultado::erro('FTTH-CONC-001',
                    ['entidade' => 'caixa', 'id' => $id, 'versao_atual' => Versao::atual('caixa', $id)]);
            }
            Db::exec('UPDATE tab_ftth_caixa SET excluido_em = NOW() WHERE id = ?', [$id]);
            // Reserva que sai leva os metros dela embora do comprimento do cabo.
            if ($antes['tipo'] === 'RESERVA' && $antes['vao_id'] !== null) {
                Reserva::recalcularVao((int) $antes['vao_id'], $usuario);
            }
            Auditoria::registrar('caixa', $id, 'excluir', $antes, null, (int) $antes['regiao_id']);
            return Resultado::ok(['id' => $id]);
        });
    }

    /**
     * Exclui a caixa e deixa os cabos dela no mapa, terminando numa ponta livre (0.9.6).
     *
     * Antes, tirar uma caixa de uma rota exigia apagar os cabos dela primeiro. Agora os
     * cabos ficam: as fusões e os splitters da caixa saem (eles moravam nela), e no lugar
     * dela nasce uma PONTA, onde outra caixa pode ser solta depois. Cliente ligado bloqueia —
     * perder a porta de um cliente é decisão que pede a confirmação do modo Selecionar.
     */
    public static function excluirMantendoCabos(int $id, ?int $versao, string $usuario): Resultado
    {
        $antes = Db::um('SELECT * FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$id]);
        if (!$antes) {
            return Resultado::erro('FTTH-TOP-001', ['caixa' => $id]);
        }
        if (in_array($antes['tipo'], ['DC', 'PONTA', 'RESERVA'], true)) {
            return Resultado::erro('FTTH-SYS-002', ['caixa' => $id],
                'Este tipo de ponto não pode ser trocado por uma ponta livre.');
        }
        $clientes = (int) Db::valor(
            'SELECT COUNT(*) FROM tab_ftth_porta p JOIN tab_ftth_splitter s ON s.id = p.splitter_id
              WHERE s.caixa_id = ? AND s.excluido_em IS NULL', [$id]);
        if ($clientes > 0) {
            return Resultado::erro('FTTH-TOP-011', ['clientes' => $clientes],
                'A caixa atende ' . $clientes . ' cliente(s). Desvincule antes, ou use o modo Selecionar, '
                . 'que exclui junto com a confirmação.');
        }
        $vaos = array_map('intval', array_column(Db::todos(
            'SELECT id FROM tab_ftth_cabo_vao
              WHERE (caixa_ini_id = ? OR caixa_fim_id = ?) AND excluido_em IS NULL', [$id, $id]), 'id'));
        if (!$vaos) {
            return self::excluir($id, $versao, $usuario);
        }

        $falha = null;
        try {
            return Db::transacao(function () use ($id, $versao, $usuario, $antes, $vaos, &$falha) {
                $exigir = static function (Resultado $r) use (&$falha): void {
                    if (!$r->ok) {
                        $falha = $r;
                        throw new RuntimeException('exclusão recusada');
                    }
                };
                if (!Versao::avancar('caixa', $id, $versao, $usuario)) {
                    $exigir(Resultado::erro('FTTH-CONC-001',
                        ['entidade' => 'caixa', 'id' => $id, 'versao_atual' => Versao::atual('caixa', $id)]));
                }
                foreach (Db::todos('SELECT id FROM tab_ftth_ligacao WHERE caixa_id = ?', [$id]) as $l) {
                    $exigir(Topologia::desconectar((int) $l['id'], $usuario));
                }
                foreach (Db::todos('SELECT id FROM tab_ftth_splitter WHERE caixa_id = ? AND excluido_em IS NULL',
                                   [$id]) as $s) {
                    $exigir(Topologia::excluirSplitter((int) $s['id'], null, $usuario));
                }
                Db::exec('DELETE FROM tab_ftth_diagrama_no WHERE caixa_id = ?', [$id]);

                $cor = (string) Db::valor('SELECT c.cor_rota FROM tab_ftth_cabo c
                                             JOIN tab_ftth_cabo_vao v ON v.cabo_id = c.id WHERE v.id = ?', [$vaos[0]]);
                $regiaoId = (int) $antes['regiao_id'];
                $ponta = self::criarPonta($regiaoId, (float) $antes['lat'], (float) $antes['lng'], $cor, $usuario);
                Db::exec('UPDATE tab_ftth_cabo_vao SET caixa_ini_id = ? WHERE caixa_ini_id = ? AND excluido_em IS NULL',
                         [$ponta, $id]);
                Db::exec('UPDATE tab_ftth_cabo_vao SET caixa_fim_id = ? WHERE caixa_fim_id = ? AND excluido_em IS NULL',
                         [$ponta, $id]);
                Db::exec('UPDATE tab_ftth_caixa SET excluido_em = NOW() WHERE id = ?', [$id]);
                Auditoria::registrar('caixa', $id, 'excluir', $antes, ['ponta_livre' => $ponta], $regiaoId);

                $r = Resultado::ok(['id' => $id, 'ponta' => $ponta, 'cabos' => count($vaos)]);
                return Sincronizacao::caixa($id, $r, $usuario);
            });
        } catch (Throwable $e) {
            if ($falha !== null) {
                return $falha;
            }
            throw $e;
        }
    }

    /** Id da caixa ATIVA que já usa este nome na região, ou null. */
    public static function nomeOcupado(int $regiaoId, string $nome, ?int $ignorar = null): ?int
    {
        $id = Db::valor(
            'SELECT id FROM tab_ftth_caixa
              WHERE regiao_id = ? AND nome = ? AND excluido_em IS NULL AND id <> ?',
            [$regiaoId, $nome, $ignorar ?? 0]);
        return $id ? (int) $id : null;
    }

    /**
     * Sugere o nome seguindo o padrão do provedor. Só sugestão: quem decide é o usuário.
     *
     * Sem base (o modal "Novo ponto"): a série é a do maior nome ATIVO do tipo, e a sugestão
     * é o MENOR número livre dela — excluir CTO.03 e CTO.04 faz a próxima ser CTO.03 de novo,
     * e um buraco no meio (CTO.02 excluída entre 01 e 03) é preenchido primeiro.
     * Com base: o primeiro livre DEPOIS da base (CTO.02.05 -> CTO.02.06, pulando ocupados).
     */
    public static function sugerirNome(int $regiaoId, string $tipo, ?string $base = null): ?string
    {
        $prefixo = $tipo === 'CTO_AP' ? 'CTO' : $tipo;
        if ($base !== null && trim($base) !== '') {
            // Nome sem número no fim ("Caixa", "UCD-ATRIA") não tem sequência: sem sugestão.
            if (!preg_match('/^(.*?)(\d+)$/', trim($base), $m)) {
                return null;
            }
            return self::primeiroLivre($regiaoId, $m[1], strlen($m[2]), (int) $m[2] + 1);
        }

        // Só nomes que terminam em número servem de base. Antes, um "Caixa" aqui fazia a
        // função chamar a si mesma para sempre e o 504 derrubava o painel inteiro (29/09/2026).
        $ultimo = Db::valor(
            'SELECT nome FROM tab_ftth_caixa
              WHERE regiao_id = ? AND tipo = ? AND excluido_em IS NULL AND nome REGEXP \'[0-9]$\'
              ORDER BY nome DESC LIMIT 1', [$regiaoId, $tipo]);
        if (!$ultimo || !preg_match('/^(.*?)(\d+)$/', (string) $ultimo, $m)) {
            return self::primeiroLivre($regiaoId, $prefixo . '.', 2, 1);
        }
        return self::primeiroLivre($regiaoId, $m[1], strlen($m[2]), 1);
    }

    /**
     * O menor "prefixo + número" livre entre as caixas ativas, a partir de $inicio.
     * Uma consulta só: os números ocupados da série vêm juntos e a conta é feita aqui.
     */
    private static function primeiroLivre(int $regiaoId, string $prefixo, int $largura, int $inicio): ?string
    {
        $ocupados = [];
        foreach (Db::todos(
            'SELECT nome FROM tab_ftth_caixa
              WHERE regiao_id = ? AND excluido_em IS NULL AND nome LIKE ?',
            [$regiaoId, addcslashes($prefixo, '%_\\') . '%']) as $r) {
            $resto = substr((string) $r['nome'], strlen($prefixo));
            if ($resto !== '' && ctype_digit($resto)) {
                $ocupados[(int) $resto] = true;
            }
        }
        for ($i = max(1, $inicio); $i <= $inicio + 1000; $i++) {
            if (!isset($ocupados[$i])) {
                return $prefixo . str_pad((string) $i, $largura, '0', STR_PAD_LEFT);
            }
        }
        return null;
    }
}
