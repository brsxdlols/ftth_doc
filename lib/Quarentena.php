<?php
/**
 * ftth_doc :: promocao de itens da quarentena para a rede real.
 *
 * Esta e a unica porta entre "o que veio do arquivo" e "o que e a rede". Regras:
 *  - um VAO so entra depois das duas caixas das pontas (FTTH-KMZ-004);
 *  - item ja decidido nao volta atras (FTTH-KMZ-005);
 *  - lote e tudo-ou-nada (3b.7): uma falha desfaz a operacao inteira;
 *  - itens com alerta ficam de fora do lote, salvo pedido explicito;
 *  - cada promocao deixa rastro: o item guarda o id gerado, e a auditoria guarda o resto.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Caixa.php';
require_once __DIR__ . '/Cabo.php';

final class Quarentena
{
    public static function listar(int $regiaoId, string $status = 'pendente', int $limite = 500): array
    {
        return Db::todos(
            'SELECT * FROM tab_ftth_importacao_item
              WHERE regiao_id = ? AND status = ?
              ORDER BY tipo_sugerido, id
              LIMIT ' . (int) $limite,
            [$regiaoId, $status]
        );
    }

    public static function contar(int $regiaoId): array
    {
        $r = Db::um(
            'SELECT
                SUM(status = "pendente")   AS pendentes,
                SUM(status = "importado")  AS importados,
                SUM(status = "descartado") AS descartados,
                SUM(status = "pendente" AND alertas_json IS NOT NULL) AS com_alerta
             FROM tab_ftth_importacao_item WHERE regiao_id = ?',
            [$regiaoId]
        ) ?: [];
        return array_map('intval', $r);
    }

    /** Importa um item. Usado tambem pelo lote. */
    public static function importarItem(int $itemId, string $usuario, array $ajustes = []): Resultado
    {
        return Db::transacao(function () use ($itemId, $usuario, $ajustes) {
            $item = Db::um('SELECT * FROM tab_ftth_importacao_item WHERE id = ? FOR UPDATE', [$itemId]);
            if (!$item || $item['status'] !== 'pendente') {
                return Resultado::erro('FTTH-KMZ-005', ['item' => $itemId]);
            }

            $geo = json_decode((string) $item['geometria'], true);
            if (!is_array($geo) || !Geo::rotaValida($geo) && $item['tipo_sugerido'] === 'VAO') {
                return Resultado::erro('FTTH-GEO-001', ['item' => $itemId]);
            }

            return $item['tipo_sugerido'] === 'CAIXA'
                ? self::criarCaixa($item, $geo, $usuario, $ajustes)
                : self::criarVao($item, $geo, $usuario, $ajustes);
        });
    }

    /**
     * Importa varios de uma vez: caixas primeiro, depois os vaos cujas duas ancoras ja existem.
     * Tudo numa transacao — se um falhar, nenhum entra.
     *
     * @param int[] $ids
     */
    public static function importarLote(array $ids, string $usuario, bool $incluirComAlerta = false): Resultado
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return Resultado::erro('FTTH-SYS-002', [], 'Nenhum item selecionado.');
        }

        return Db::transacao(function () use ($ids, $usuario, $incluirComAlerta) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $itens = Db::todos(
                "SELECT * FROM tab_ftth_importacao_item
                  WHERE id IN ($marcas) AND status = 'pendente'
                  ORDER BY FIELD(tipo_sugerido, 'CAIXA', 'VAO'), id", $ids);

            $importados = [];
            $pulados    = [];

            foreach ($itens as $item) {
                if (!$incluirComAlerta && $item['alertas_json'] !== null) {
                    $pulados[] = ['id' => (int) $item['id'], 'motivo' => 'item com alerta'];
                    continue;
                }
                $r = self::importarItem((int) $item['id'], $usuario);
                if ($r->ok) {
                    $importados[] = ['id' => (int) $item['id'], 'gerado' => $r->data];
                } else {
                    $pulados[] = ['id' => (int) $item['id'], 'motivo' => $r->primeiroCodigo()];
                }
            }

            Auditoria::registrar('importacao_lote', count($ids), 'importar_lote', null,
                ['importados' => count($importados), 'pulados' => count($pulados)],
                isset($itens[0]) ? (int) $itens[0]['regiao_id'] : null);

            return Resultado::ok(['importados' => $importados, 'pulados' => $pulados]);
        });
    }

    public static function descartar(array $ids, string $usuario, string $motivo = ''): Resultado
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return Resultado::erro('FTTH-SYS-002', [], 'Nenhum item selecionado.');
        }
        return Db::transacao(function () use ($ids, $usuario, $motivo) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $n = Db::exec(
                "UPDATE tab_ftth_importacao_item
                    SET status = 'descartado', decidido_por = ?, decidido_em = NOW()
                  WHERE id IN ($marcas) AND status = 'pendente'",
                array_merge([$usuario], $ids));
            Auditoria::registrar('importacao_item', $ids[0], 'descartar', null,
                ['quantidade' => $n, 'motivo' => $motivo]);
            self::recontar($ids);
            return Resultado::ok(['descartados' => $n]);
        });
    }

    /**
     * Desfaz a decisão tomada sobre itens: importado volta para pendente ou descartado, e
     * descartado volta para pendente (30/09/2026).
     *
     * Reverter um IMPORTADO apaga o que a importação criou, pelos serviços de sempre
     * (Cabo::excluir, Caixa::excluir) — com as travas deles: ponto que já recebeu cabo ou
     * fusão, cabo com fibra ligada ou que já foi emendado/continuado no mapa ficam de fora,
     * com o motivo. Ligar a rede de novo é trabalho do usuário, não é algo a desfazer calado.
     * Cabos saem antes dos pontos: é o cabo que prende a caixa.
     *
     * @param string $destino 'pendente' | 'descartado'
     */
    public static function reverter(array $ids, string $destino, string $usuario): Resultado
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return Resultado::erro('FTTH-SYS-002', [], 'Nenhum item selecionado.');
        }
        if (!in_array($destino, ['pendente', 'descartado'], true)) {
            return Resultado::erro('FTTH-SYS-002', ['destino' => $destino]);
        }

        return Db::transacao(function () use ($ids, $destino, $usuario) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $itens = Db::todos(
                "SELECT * FROM tab_ftth_importacao_item
                  WHERE id IN ($marcas) AND status IN ('importado', 'descartado')
                  ORDER BY FIELD(gerado_tipo, 'VAO', 'CAIXA'), id", $ids);

            $revertidos = 0;
            $pulados = [];
            foreach ($itens as $i) {
                if ($i['status'] === $destino) {
                    continue;                 // descartado → descartado: nada a fazer
                }
                if ($i['status'] === 'importado') {
                    $motivo = self::desfazerGerado($i, $usuario);
                    if ($motivo !== null) {
                        $pulados[] = ['id' => (int) $i['id'], 'nome' => $i['nome'], 'motivo' => $motivo];
                        continue;
                    }
                }
                Db::exec('UPDATE tab_ftth_importacao_item
                             SET status = ?, gerado_tipo = NULL, gerado_id = NULL,
                                 decidido_por = ?, decidido_em = NOW()
                           WHERE id = ?', [$destino, $usuario, (int) $i['id']]);
                $revertidos++;
            }
            if ($revertidos > 0) {
                Auditoria::registrar('importacao_item', $ids[0], 'reverter', null,
                    ['destino' => $destino, 'quantidade' => $revertidos]);
                self::recontar($ids);
            }
            return Resultado::ok(['revertidos' => $revertidos, 'pulados' => $pulados]);
        });
    }

    /** Apaga o que a importação do item criou. Devolve o motivo da recusa, ou null se saiu. */
    private static function desfazerGerado(array $item, string $usuario): ?string
    {
        $id = (int) $item['gerado_id'];
        if ($item['gerado_tipo'] === 'VAO') {
            $vao = Db::um('SELECT id, cabo_id FROM tab_ftth_cabo_vao WHERE id = ? AND excluido_em IS NULL', [$id]);
            if (!$vao) {
                return null;                  // já foi apagado no mapa: só volta o status
            }
            $vaosDoCabo = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo_vao
                                            WHERE cabo_id = ? AND excluido_em IS NULL', [(int) $vao['cabo_id']]);
            if ($vaosDoCabo > 1) {
                return 'o cabo já foi emendado ou continuado no mapa';
            }
            $r = Cabo::excluir((int) $vao['cabo_id'], $usuario);
            return $r->ok ? null : (string) $r->primeiraMensagem();
        }
        if ($item['gerado_tipo'] === 'CAIXA') {
            if (!Db::valor('SELECT id FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$id])) {
                return null;
            }
            $r = Caixa::excluir($id, null, $usuario);
            return $r->ok ? null : (string) $r->primeiraMensagem();
        }
        return null;
    }

    /**
     * Troca o tipo de itens PENDENTES: um por vez (o select da linha) ou em lote ("Mudar tipo
     * para"). Ponto recebe um tipo do cadastro de ponto (Caixa::ROTULOS); cabo, um rótulo de
     * capacidade do catálogo. Item da outra espécie na seleção é pulado, não é erro: "mudar
     * para Poste" com cabos marcados junto muda só os pontos.
     *
     * O alerta de "sem nome" acompanha: poste sem nome é o normal (ganha POSTE.NN ao importar);
     * os outros tipos continuam pedindo revisão.
     */
    public static function alterarTipo(array $ids, string $subtipo, string $usuario): Resultado
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return Resultado::erro('FTTH-SYS-002', [], 'Nenhum item selecionado.');
        }
        $ehPonto = isset(Caixa::ROTULOS[$subtipo]);
        if (!$ehPonto && !Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = ? AND ativo = 1', [$subtipo])) {
            return Resultado::erro('FTTH-SYS-002', ['subtipo' => $subtipo], 'Tipo inválido.');
        }

        return Db::transacao(function () use ($ids, $subtipo, $usuario, $ehPonto) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $n = 0;
            foreach (Db::todos(
                "SELECT id, nome, alertas_json FROM tab_ftth_importacao_item
                  WHERE id IN ($marcas) AND status = 'pendente' AND tipo_sugerido = ?",
                array_merge($ids, [$ehPonto ? 'CAIXA' : 'VAO'])) as $i) {
                $alertas = json_decode((string) $i['alertas_json'], true) ?: [];
                if ($ehPonto) {
                    $alertas = array_values(array_filter($alertas, static function ($a) {
                        return ($a['code'] ?? '') !== 'FTTH-KMZ-001';
                    }));
                    if (trim((string) $i['nome']) === '' && $subtipo !== 'POSTE') {
                        $alertas[] = ['code' => 'FTTH-KMZ-001', 'message' => 'Item sem nome no arquivo.'];
                    }
                }
                Db::exec('UPDATE tab_ftth_importacao_item SET subtipo = ?, alertas_json = ? WHERE id = ?',
                    [$subtipo, $alertas ? json_encode($alertas, JSON_UNESCAPED_UNICODE) : null, (int) $i['id']]);
                $n++;
            }
            if ($n > 0) {
                Auditoria::registrar('importacao_item', $ids[0], 'alterar_tipo', null,
                    ['subtipo' => $subtipo, 'quantidade' => $n]);
                self::recontar($ids);
            }
            return Resultado::ok(['alterados' => $n, 'pulados' => count($ids) - $n]);
        });
    }

    /**
     * Troca a cor de itens PENDENTES — a do ponto, ou a da rota no caso de cabo. Um por vez
     * (select da linha) ou em lote: importar 2 mil postes e depois pintar um a um no mapa não
     * é opção (30/09/2026).
     */
    public static function alterarCor(array $ids, string $cor, string $usuario): Resultado
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return Resultado::erro('FTTH-SYS-002', [], 'Nenhum item selecionado.');
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $cor)) {
            return Resultado::erro('FTTH-SYS-002', ['cor' => $cor], 'Cor inválida.');
        }
        return Db::transacao(function () use ($ids, $cor, $usuario) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $n = Db::exec("UPDATE tab_ftth_importacao_item SET cor = ?
                            WHERE id IN ($marcas) AND status = 'pendente'",
                          array_merge([strtoupper($cor)], $ids));
            if ($n > 0) {
                Auditoria::registrar('importacao_item', $ids[0], 'alterar_cor', null,
                    ['cor' => strtoupper($cor), 'quantidade' => $n]);
            }
            return Resultado::ok(['alterados' => $n]);
        });
    }

    // ------------------------------------------------------------------ interno

    private static function criarCaixa(array $item, array $geo, string $usuario, array $ajustes): Resultado
    {
        $nome = trim((string) ($ajustes['nome'] ?? $item['nome'] ?? ''));
        $tipo = (string) ($ajustes['tipo'] ?? $item['subtipo'] ?? 'CTO');
        // Ponto sem nome (ou com a coordenada no lugar do nome) ganha o próximo da série do
        // tipo: POSTE.NN é o normal num KMZ de postes; nos outros tipos o alerta já avisou.
        if ($nome === '') {
            $nome = (string) Caixa::sugerirNome((int) $item['regiao_id'], $tipo);
        }
        if ($nome === '') {
            return Resultado::erro('FTTH-SYS-002', ['item' => $item['id']], 'A caixa precisa de um nome.');
        }

        $jaExiste = Caixa::nomeOcupado((int) $item['regiao_id'], $nome);
        if ($jaExiste) {
            return Resultado::erro('FTTH-KMZ-003', ['nome' => $nome, 'caixa_id' => (int) $jaExiste],
                'Já existe uma caixa com esse nome na região.');
        }

        Db::exec(
            'INSERT INTO tab_ftth_caixa
                (regiao_id, tipo, nome, cor, lat, lng, capacidade, origem, importacao_id, criado_por, criado_em)
             VALUES (?,?,?,?,?,?,?,"kmz",?,?,NOW())',
            [$item['regiao_id'], $tipo, $nome, $ajustes['cor'] ?? $item['cor'] ?? '#00C853',
             $geo[0][0], $geo[0][1], $ajustes['capacidade'] ?? null,
             $item['importacao_id'], $usuario]
        );
        $caixaId = Db::ultimoId();

        self::marcarImportado((int) $item['id'], 'CAIXA', $caixaId, $usuario);
        Auditoria::registrar('caixa', $caixaId, 'importar_kmz', null,
            ['nome' => $nome, 'tipo' => $tipo, 'item' => (int) $item['id']], (int) $item['regiao_id']);

        return Resultado::ok(['tipo' => 'CAIXA', 'id' => $caixaId, 'nome' => $nome]);
    }

    private static function criarVao(array $item, array $geo, string $usuario, array $ajustes): Resultado
    {
        // Resolve as ancoras: caixa real direta, ou o item de quarentena ja importado.
        $ini = self::resolverAncora($item['ancora_ini_id'], $item['ancora_ini_item_id']);
        $fim = self::resolverAncora($item['ancora_fim_id'], $item['ancora_fim_item_id']);

        // Ponta sem caixa nenhuma por perto no arquivo: vira ponta livre (0.9.6), em vez de
        // travar o vão. Âncora que existe mas ainda não foi importada continua esperando.
        $ultimo = count($geo) - 1;
        // Âncora que virou poste ou reserva (o usuário trocou o tipo na quarentena) não segura
        // ponta de cabo: o cabo passa pelo poste. A ponta fica livre ali.
        if (self::ancoraNaoSegura($item['ancora_ini_id'], $item['ancora_ini_item_id'])) {
            $item['ancora_ini_id'] = $item['ancora_ini_item_id'] = null;
            $ini = null;
        }
        if (self::ancoraNaoSegura($item['ancora_fim_id'], $item['ancora_fim_item_id'])) {
            $item['ancora_fim_id'] = $item['ancora_fim_item_id'] = null;
            $fim = null;
        }
        if ($ini === null && !$item['ancora_ini_id'] && !$item['ancora_ini_item_id']) {
            $ini = Caixa::criarPonta((int) $item['regiao_id'], (float) $geo[0][0], (float) $geo[0][1],
                                     (string) ($item['cor'] ?? ''), $usuario);
        }
        if ($fim === null && !$item['ancora_fim_id'] && !$item['ancora_fim_item_id']) {
            $fim = Caixa::criarPonta((int) $item['regiao_id'], (float) $geo[$ultimo][0], (float) $geo[$ultimo][1],
                                     (string) ($item['cor'] ?? ''), $usuario);
        }

        if ($ini === null || $fim === null) {
            return Resultado::erro('FTTH-KMZ-004', [
                'item' => (int) $item['id'],
                'falta' => $ini === null ? 'caixa inicial' : 'caixa final',
            ]);
        }
        if ($ini === $fim) {
            return Resultado::erro('FTTH-GEO-003', ['item' => (int) $item['id'], 'caixa' => $ini]);
        }

        $rotulo = (string) ($ajustes['cabo_tipo'] ?? $item['subtipo'] ?? '6 FO');
        $tipo   = self::tipoDeCabo($rotulo);
        $tipoId = $tipo['id'];
        if (!$tipoId) {
            return Resultado::erro('FTTH-SYS-002', ['rotulo' => $rotulo], 'Tipo de cabo não encontrado.');
        }

        // Um cabo por vao importado: o agrupamento em cabo logico e decisao humana, depois.
        Db::exec(
            'INSERT INTO tab_ftth_cabo
                (regiao_id, nome, cabo_tipo_id, cor_rota, origem, importacao_id, criado_por, criado_em)
             VALUES (?,?,?,?,"kmz",?,?,NOW())',
            [$item['regiao_id'], $item['nome'], $tipoId, $item['cor'] ?? '#00E676',
             $item['importacao_id'], $usuario]
        );
        $caboId = Db::ultimoId();

        $metros = Geo::comprimento($geo);
        $folga  = Config::num('fator_folga_cabo', 1.03);

        Db::exec(
            'INSERT INTO tab_ftth_cabo_vao
                (cabo_id, regiao_id, ordem, caixa_ini_id, caixa_fim_id, vertices,
                 comprimento_geo, fator_folga, reserva_m, comprimento_optico,
                 origem, importacao_id, criado_por, criado_em)
             VALUES (?,?,?,?,?,?,?,?,0,?,"kmz",?,?,NOW())',
            [$caboId, $item['regiao_id'], 1, $ini, $fim, json_encode($geo),
             round($metros, 2), $folga, Geo::comprimentoOptico($metros, $folga, 0),
             $item['importacao_id'], $usuario]
        );
        $vaoId = Db::ultimoId();

        self::marcarImportado((int) $item['id'], 'VAO', $vaoId, $usuario);
        Auditoria::registrar('vao', $vaoId, 'importar_kmz', null,
            ['cabo' => $caboId, 'caixa_ini' => $ini, 'caixa_fim' => $fim, 'metros' => round($metros, 2)],
            (int) $item['regiao_id']);

        $r = Resultado::ok(['tipo' => 'VAO', 'id' => $vaoId, 'cabo_id' => $caboId,
                            'caixa_ini_id' => $ini, 'caixa_fim_id' => $fim,
                            'cabo_tipo' => $tipo['rotulo']]);

        // Cair no padrão em silêncio foi o que fez sete cabos de 24 FO entrarem como 6 FO:
        // dezoito fibras por cabo que simplesmente não existiam no diagrama, e ninguém
        // tinha como saber. Agora a importação diz o que não conseguiu reconhecer.
        return $tipo['adivinhado']
            ? $r->addAviso('FTTH-KMZ-006', ['lido' => $rotulo, 'usado' => $tipo['rotulo']])
            : $r;
    }

    /**
     * Acha o tipo de cabo pelo rótulo do KMZ.
     *
     * O casamento não pode ser só pelo texto exato: o KMZ escreve "Cabo 24FO", e o catálogo
     * tem "24 FO (Monotubo)" e "24 FO MULT (4x6)" — nenhum dos dois casa com "24 FO". Então,
     * falhando o texto, vale a CAPACIDADE: procura um tipo com o mesmo número de fibras,
     * preferindo o monotubo, porque o KMZ não diz nada sobre tubagem.
     *
     * @return array{id:?int, rotulo:string, adivinhado:bool}
     */
    private static function tipoDeCabo(string $rotulo): array
    {
        $exato = Db::um('SELECT id, rotulo FROM tab_ftth_cabo_tipo WHERE rotulo = ? AND ativo = 1',
                        [$rotulo]);
        if ($exato) {
            return ['id' => (int) $exato['id'], 'rotulo' => $exato['rotulo'], 'adivinhado' => false];
        }

        if (preg_match('/(\d+)/', $rotulo, $m)) {
            $porFibras = Db::um(
                'SELECT id, rotulo FROM tab_ftth_cabo_tipo
                  WHERE fibras = ? AND ativo = 1
                  ORDER BY tubos ASC, ordem ASC LIMIT 1', [(int) $m[1]]);
            if ($porFibras) {
                return ['id' => (int) $porFibras['id'], 'rotulo' => $porFibras['rotulo'],
                        'adivinhado' => false];
            }
        }

        $padrao = Db::um('SELECT id, rotulo FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');
        return ['id' => $padrao ? (int) $padrao['id'] : null,
                'rotulo' => $padrao['rotulo'] ?? '6 FO', 'adivinhado' => true];
    }

    /** Caixa real informada, ou a caixa que nasceu do item de quarentena indicado. */
    /** A âncora é (ou vai ser) um poste ou uma reserva — que não seguram ponta de cabo? */
    private static function ancoraNaoSegura($caixaId, $itemId): bool
    {
        $naoSeguram = ['POSTE', 'RESERVA', 'PONTA'];
        if ($caixaId) {
            return in_array((string) Db::valor('SELECT tipo FROM tab_ftth_caixa WHERE id = ?', [$caixaId]),
                            $naoSeguram, true);
        }
        if ($itemId) {
            return in_array((string) Db::valor('SELECT subtipo FROM tab_ftth_importacao_item WHERE id = ?', [$itemId]),
                            $naoSeguram, true);
        }
        return false;
    }

    private static function resolverAncora($caixaId, $itemId): ?int
    {
        if ($caixaId) {
            $existe = Db::valor('SELECT id FROM tab_ftth_caixa WHERE id = ? AND excluido_em IS NULL', [$caixaId]);
            return $existe ? (int) $existe : null;
        }
        if ($itemId) {
            $gerado = Db::um(
                'SELECT gerado_tipo, gerado_id FROM tab_ftth_importacao_item WHERE id = ? AND status = "importado"',
                [$itemId]);
            if ($gerado && $gerado['gerado_tipo'] === 'CAIXA') {
                return (int) $gerado['gerado_id'];
            }
        }
        return null;
    }

    private static function marcarImportado(int $itemId, string $tipo, int $geradoId, string $usuario): void
    {
        Db::exec(
            'UPDATE tab_ftth_importacao_item
                SET status = "importado", gerado_tipo = ?, gerado_id = ?, decidido_por = ?, decidido_em = NOW()
              WHERE id = ?',
            [$tipo, $geradoId, $usuario, $itemId]);
        self::recontar([$itemId]);
    }

    /** Atualiza os contadores da importacao a que os itens pertencem. */
    private static function recontar(array $itemIds): void
    {
        if (!$itemIds) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($itemIds), '?'));
        $imps = Db::todos(
            "SELECT DISTINCT importacao_id FROM tab_ftth_importacao_item WHERE id IN ($marcas)", $itemIds);
        foreach ($imps as $i) {
            Db::exec(
                'UPDATE tab_ftth_importacao imp
                    SET pendentes  = (SELECT COUNT(*) FROM tab_ftth_importacao_item x
                                       WHERE x.importacao_id = imp.id AND x.status = "pendente"),
                        importados = (SELECT COUNT(*) FROM tab_ftth_importacao_item x
                                       WHERE x.importacao_id = imp.id AND x.status = "importado"),
                        descartados= (SELECT COUNT(*) FROM tab_ftth_importacao_item x
                                       WHERE x.importacao_id = imp.id AND x.status = "descartado")
                  WHERE imp.id = ?', [$i['importacao_id']]);
        }
    }
}
