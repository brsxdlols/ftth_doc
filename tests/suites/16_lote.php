<?php
/**
 * Suite 16 :: seleção por área e exclusão em lote.
 *
 * Rota de teste, em linha reta de oeste para leste:
 *
 *     A ──── B ──── C ──── D ──── E        (um cabo só, 4 vãos)
 *
 * Selecionar C (e só C) tem de: levar os vãos B–C e C–D, partir o cabo em dois (A–B e D–E),
 * desfazer a fusão de B que usava fibra do vão B–C e manter a de B que usa o vão A–B.
 * Selecionar a área inteira leva tudo, e cliente ligado exige a confirmação.
 */
require_once __DIR__ . '/../../lib/Cabo.php';
require_once __DIR__ . '/../../lib/Caixa.php';
require_once __DIR__ . '/../../lib/Topologia.php';
require_once __DIR__ . '/../../lib/Lote.php';

T::suite('Seleção e exclusão em lote');

$regiao = (int) Regiao::criar('Lote', -24.95, -52.40, 15, 'teste')->data['id'];
$t12 = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "12 FO"');

$cx = [];
foreach (['A', 'B', 'C', 'D', 'E'] as $i => $n) {
    $cx[$n] = (int) Caixa::criar($regiao, $n === 'C' ? 'CTO' : 'CEO', 'L.' . $n, '#FF9100',
                                 -24.9500, -52.4000 + $i * 0.001, 'teste')->data['id'];
}
$rCabo = Cabo::criar($regiao, ['cabo_tipo_id' => $t12, 'nome' => 'L.CABO'], array_map(
    static fn($n) => ['tipo' => 'CAIXA', 'id' => $cx[$n]], ['A', 'B', 'C', 'D', 'E']), 'teste');
T::certo('cria o cabo A–E com 4 vãos', $rCabo->ok && count($rCabo->data['vaos']) === 4,
    json_encode($rCabo->errors));
[$vAB, $vBC, $vCD, $vDE] = array_map('intval', $rCabo->data['vaos']);
$cabo = (int) $rCabo->data['cabo_id'];

// Em B: passagem da fibra 1 (A–B com B–C) — vai sair, porque B–C sai.
$ligB = Topologia::conectar($cx['B'],
    ['elemento' => 'VAO_FIBRA', 'elemento_id' => $vAB, 'numero' => 1],
    ['elemento' => 'VAO_FIBRA', 'elemento_id' => $vBC, 'numero' => 1], null, 'teste');
T::certo('funde A–B com B–C na caixa B', $ligB->ok, json_encode($ligB->errors));

// Em B também: splitter alimentado pela fibra 2 de A–B — fica, porque A–B fica.
$splB = (int) Topologia::criarSplitter($cx['B'], ['nome' => 'L.SPL.B', 'funcao' => 'DERIVACAO',
    'razao' => '1:2', 'saidas' => 2], 'teste')->data['id'];
$ligFica = Topologia::conectar($cx['B'],
    ['elemento' => 'VAO_FIBRA',   'elemento_id' => $vAB,  'numero' => 2],
    ['elemento' => 'SPLITTER_IN', 'elemento_id' => $splB, 'numero' => 0], 'FUSAO', 'teste');
T::certo('funde A–B no splitter de B', $ligFica->ok, json_encode($ligFica->errors));

// Em C: splitter de atendimento com um cliente.
Db::exec('INSERT INTO sis_cliente (id, login, nome) VALUES (901, "lote.cliente", "Cliente do Lote")
          ON DUPLICATE KEY UPDATE login = VALUES(login)');
$splC = (int) Topologia::criarSplitter($cx['C'], ['funcao' => 'ATENDIMENTO', 'razao' => '1:8',
    'saidas' => 8], 'teste')->data['id'];
T::certo('liga o cliente numa porta de C', Topologia::vincularCliente(901, $splC, 1, 'teste')->ok);

// ------------------------------------------------------------------ seleção por área
// Um retângulo em volta de C, e só de C.
$area = [[-24.9490, -52.3985], [-24.9490, -52.3975], [-24.9510, -52.3975], [-24.9510, -52.3985]];
$sel = Lote::selecionar($regiao, $area);
T::certo('seleciona pela área', $sel->ok, json_encode($sel->errors));
T::igual('só C está dentro', [$cx['C']], array_column($sel->data['caixas'], 'id'));
T::igual('os vãos que encostam em C', [$vBC, $vCD], $sel->data['vaos']);
T::igual('o cabo é partido', 'partir', $sel->data['cabos'][0]['destino']);
T::igual('a fusão de B sobre B–C entra na lista de fora', 'L.B', $sel->data['ligacoes_fora'][0]['caixa'] ?? null);
T::igual('o cliente aparece na prévia', 'lote.cliente', $sel->data['clientes'][0]['login'] ?? null);
T::igual('área com menos de 3 cantos é recusada', 'FTTH-LOTE-003',
    Lote::selecionar($regiao, [[-24.95, -52.40], [-24.96, -52.41]])->primeiroCodigo());

// ------------------------------------------------------------------ exclusão
T::igual('com cliente, sem confirmar, recusa', 'FTTH-LOTE-002',
    Lote::excluir($regiao, [$cx['C']], '', 'teste')->primeiroCodigo());
T::certo('C continua de pé depois da recusa',
    Db::valor('SELECT excluido_em FROM tab_ftth_caixa WHERE id = ?', [$cx['C']]) === null);

$ex = Lote::excluir($regiao, [$cx['C']], 'excluir', 'teste');
T::certo('com EXCLUIR, exclui', $ex->ok, json_encode($ex->errors));
T::certo('C saiu', Db::valor('SELECT excluido_em FROM tab_ftth_caixa WHERE id = ?', [$cx['C']]) !== null);
T::igual('o cliente foi desvinculado', 0,
    (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_porta WHERE cliente_id = 901'));
T::igual('os vãos B–C e C–D saíram', 2, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE id IN (?, ?) AND excluido_em IS NOT NULL', [$vBC, $vCD]));
T::igual('A–B continua no cabo original', $cabo,
    (int) Db::valor('SELECT cabo_id FROM tab_ftth_cabo_vao WHERE id = ?', [$vAB]));
$caboDE = (int) Db::valor('SELECT cabo_id FROM tab_ftth_cabo_vao WHERE id = ?', [$vDE]);
T::certo('D–E foi para um cabo novo', $caboDE > 0 && $caboDE !== $cabo);
T::igual('o cabo novo herdou nome e capacidade', ['L.CABO', $t12], array_values(Db::um(
    'SELECT nome, cabo_tipo_id FROM tab_ftth_cabo WHERE id = ?', [$caboDE])));
T::igual('D–E virou o vão 1 do cabo novo', 1,
    (int) Db::valor('SELECT ordem FROM tab_ftth_cabo_vao WHERE id = ?', [$vDE]));
T::igual('a fusão de B sobre B–C saiu', null,
    Db::valor('SELECT id FROM tab_ftth_ligacao WHERE id = ?', [(int) $ligB->data['id']]));
T::certo('a fusão de B sobre A–B ficou',
    (bool) Db::valor('SELECT id FROM tab_ftth_ligacao WHERE id = ?', [(int) $ligFica->data['id']]));
T::igual('invariantes limpas em B', [], Topologia::invariantes($cx['B']));
T::igual('invariantes limpas em D', [], Topologia::invariantes($cx['D']));

// A área inteira: o resto sai, e os cabos junto.
$tudo = [[-24.9480, -52.4010], [-24.9480, -52.3950], [-24.9520, -52.3950], [-24.9520, -52.4010]];
$sel2 = Lote::selecionar($regiao, $tudo);
T::igual('a área inteira pega os quatro que sobraram', 4, count($sel2->data['caixas']));
$ex2 = Lote::excluir($regiao, array_column($sel2->data['caixas'], 'id'), '', 'teste');
T::certo('sem cliente, exclui sem confirmação', $ex2->ok, json_encode($ex2->errors));
T::igual('nenhum cabo da rota sobrou', 0, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_cabo WHERE id IN (?, ?) AND excluido_em IS NULL', [$cabo, $caboDE]));
T::igual('seleção vazia é recusada', 'FTTH-LOTE-001',
    Lote::excluir($regiao, [], '', 'teste')->primeiroCodigo());
