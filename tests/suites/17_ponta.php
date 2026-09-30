<?php
/**
 * Suite 17 :: ponta livre de cabo (0.9.6).
 *
 * O cabo pode ser lançado sem caixa na ponta. O modelo não muda — o vão continua ligando duas
 * "caixas" —, só que uma delas é uma PONTA, criada sozinha. O que esta suíte vigia é o ciclo
 * inteiro: nascer, ser continuada, ser ancorada num ponto, virar ponto, e sumir com o cabo.
 */
require_once __DIR__ . '/../../lib/Cabo.php';
require_once __DIR__ . '/../../lib/Caixa.php';
require_once __DIR__ . '/../../lib/Mapa.php';
require_once __DIR__ . '/../../lib/Topologia.php';

T::suite('Ponta livre de cabo');

$regiao = (int) Regiao::criar('Pontas', -24.97, -52.45, 15, 'teste')->data['id'];
$t12 = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "12 FO"');
$t6  = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');
$pontaDo = static function (int $vaoId, string $lado): array {
    return Db::um('SELECT c.* FROM tab_ftth_cabo_vao v JOIN tab_ftth_caixa c ON c.id = v.caixa_' . $lado . '_id
                    WHERE v.id = ?', [$vaoId]);
};

$a = (int) Caixa::criar($regiao, 'CEO', 'P.A', '#FF9100', -24.9700, -52.4500, 'teste')->data['id'];

// ------------------------------------------------------------------ nascer
$r = Cabo::criar($regiao, ['cabo_tipo_id' => $t12, 'nome' => 'P.CABO', 'cor_rota' => '#0D47A1'], [
    ['tipo' => 'CAIXA',   'id' => $a],
    ['tipo' => 'VERTICE', 'lat' => -24.9700, 'lng' => -52.4490],
], 'teste');
T::certo('cabo lançado até o vazio', $r->ok, json_encode($r->errors));
$cabo = (int) $r->data['cabo_id'];
$v1   = (int) $r->data['vaos'][0];
$p1   = $pontaDo($v1, 'fim');
T::igual('a ponta é uma PONTA', 'PONTA', $p1['tipo']);
T::igual('com o nome pelo id', 'PONTA.' . $p1['id'], $p1['nome']);
T::igual('na cor do cabo', '#0D47A1', $p1['cor']);
T::certo('fora da lista de pontos',
    !in_array((int) $p1['id'], array_column(Mapa::pontos($regiao), 'id'), false));
T::igual('não recebe splitter', false, Topologia::criarSplitter((int) $p1['id'],
    ['funcao' => 'DERIVACAO', 'razao' => '1:2', 'saidas' => 2], 'teste')->ok);
T::igual('não é criada à mão', false,
    Caixa::criar($regiao, 'PONTA', 'X', '#FF9100', -24.97, -52.44, 'teste')->ok);

// ------------------------------------------------------------------ continuar
$c2 = Cabo::criar($regiao, ['cabo_tipo_id' => $t12], [
    ['tipo' => 'CAIXA',   'id' => (int) $p1['id']],
    ['tipo' => 'VERTICE', 'lat' => -24.9705, 'lng' => -52.4485],
    ['tipo' => 'VERTICE', 'lat' => -24.9700, 'lng' => -52.4480],
], 'teste');
T::certo('continua a partir da ponta', $c2->ok, json_encode($c2->errors));
T::igual('no mesmo cabo', $cabo, (int) $c2->data['cabo_id']);
T::igual('com o mesmo vão, emendado', $v1, (int) $c2->data['vaos'][0]);
T::certo('a ponta antiga saiu',
    Db::valor('SELECT excluido_em FROM tab_ftth_caixa WHERE id = ?', [(int) $p1['id']]) !== null);
$p2 = $pontaDo($v1, 'fim');
T::igual('e o vão termina numa ponta nova', 'PONTA', $p2['tipo']);
T::igual('o vão tem os vértices dos dois traçados', 4, count(json_decode(
    (string) Db::valor('SELECT vertices FROM tab_ftth_cabo_vao WHERE id = ?', [$v1]), true)));

// Trocar de cabo exige uma CEO: capacidade diferente numa ponta livre é recusada, e não nasce
// outro cabo encostado nela (30/09/2026).
$outra = Cabo::criar($regiao, ['cabo_tipo_id' => $t6], [
    ['tipo' => 'CAIXA',   'id' => (int) $p2['id']],
    ['tipo' => 'VERTICE', 'lat' => -24.9695, 'lng' => -52.4475],
], 'teste');
T::igual('capacidade diferente na ponta livre é recusada', false, $outra->ok);
T::certo('e a mensagem fala da CEO', str_contains((string) $outra->primeiraMensagem(), 'CEO'));

// "Continuar cabo" impõe o próprio cabo, mesmo que a tela mande outra capacidade.
$cont = Cabo::criar($regiao, ['cabo_tipo_id' => $t6, 'cor_rota' => '#E53935', 'continuar_de' => (int) $p2['id']], [
    ['tipo' => 'CAIXA',   'id' => (int) $p2['id']],
    ['tipo' => 'VERTICE', 'lat' => -24.9695, 'lng' => -52.4475],
], 'teste');
T::certo('Continuar cabo usa a capacidade do próprio cabo', $cont->ok && (int) $cont->data['cabo_id'] === $cabo,
    json_encode($cont->errors));
$p2 = $pontaDo($v1, 'fim');
T::igual('a ponta nova sai na cor do cabo, não na mandada', '#0D47A1', $p2['cor']);
T::igual('Continuar cabo tem de sair da ponta', 'FTTH-SYS-002', Cabo::criar($regiao,
    ['cabo_tipo_id' => $t12, 'continuar_de' => (int) $p2['id']], [
        ['tipo' => 'CAIXA', 'id' => $a],
        ['tipo' => 'VERTICE', 'lat' => -24.9695, 'lng' => -52.4400],
    ], 'teste')->primeiroCodigo());
T::igual('ponta livre no meio do traçado é recusada', 'FTTH-SYS-002', Cabo::criar($regiao,
    ['cabo_tipo_id' => $t12], [
        ['tipo' => 'CAIXA', 'id' => $a],
        ['tipo' => 'CAIXA', 'id' => (int) $p2['id']],
        ['tipo' => 'VERTICE', 'lat' => -24.9695, 'lng' => -52.4400],
    ], 'teste')->primeiroCodigo());

// ------------------------------------------------------------------ ancorar e virar ponto
// Cabo novo com ponta livre no vazio, e uma CTO criada a 3 m dela.
$r3 = Cabo::criar($regiao, ['cabo_tipo_id' => $t12], [
    ['tipo' => 'CAIXA',   'id' => $a],
    ['tipo' => 'VERTICE', 'lat' => -24.9710, 'lng' => -52.4500],
], 'teste');
$v3 = (int) $r3->data['vaos'][0];
$p3 = $pontaDo($v3, 'fim');
$cto = Caixa::criar($regiao, 'CTO', 'P.CTO', '#43A047', -24.97102, -52.45001, 'teste');
T::certo('CTO criada em cima da ponta', $cto->ok, json_encode($cto->errors));
T::certo('diz que assumiu a ponta', $cto->data['ponta_absorvida'] === true);
T::igual('o cabo agora termina na CTO', (int) $cto->data['id'],
    (int) Db::valor('SELECT caixa_fim_id FROM tab_ftth_cabo_vao WHERE id = ?', [$v3]));
T::igual('a CTO foi para o lugar da ponta', (float) $p3['lat'], (float) $cto->data['lat']);

// A ponta vira ponto pela edição.
$p2v = (int) Db::valor('SELECT versao FROM tab_ftth_caixa WHERE id = ?', [(int) $p2['id']]);
$vira = Caixa::alterar((int) $p2['id'], ['tipo' => 'CEO', 'nome' => 'P.VIROU'], $p2v, 'teste');
T::certo('ponta livre vira CEO pela edição', $vira->ok, json_encode($vira->errors));
T::igual('uma caixa comum não vira PONTA', false,
    Caixa::alterar($a, ['tipo' => 'PONTA'], null, 'teste')->ok);

// ------------------------------------------------------------------ excluir mantendo os cabos
$ex = Caixa::excluirMantendoCabos((int) $cto->data['id'], null, 'teste');
T::certo('exclui a CTO mantendo o cabo', $ex->ok, json_encode($ex->errors));
T::igual('o cabo passou a terminar numa ponta livre', 'PONTA', $pontaDo($v3, 'fim')['tipo']);
T::igual('o vão continua ativo', null,
    Db::valor('SELECT excluido_em FROM tab_ftth_cabo_vao WHERE id = ?', [$v3]));

// ------------------------------------------------------------------ ancorar no modo Mover
// Soltar a ponta em cima de um ponto NÃO ancora sozinho: a tela pergunta, e só o "Ancorar"
// chama Caixa::ancorarPonta (30/09/2026).
$r4 = Cabo::criar($regiao, ['cabo_tipo_id' => $t12], [
    ['tipo' => 'CAIXA',   'id' => $a],
    ['tipo' => 'VERTICE', 'lat' => -24.9690, 'lng' => -52.4500],
], 'teste');
$v4 = (int) $r4->data['vaos'][0];
$p4 = (int) $pontaDo($v4, 'fim')['id'];
$ceo = (int) Caixa::criar($regiao, 'CEO', 'P.CEO', '#FF9100', -24.9680, -52.4500, 'teste')->data['id'];
$mv = Mapa::aplicarMovimentos([['id' => $p4, 'lat' => -24.96801, 'lng' => -52.45001]], [], 'teste');
T::certo('mover a ponta para cima do ponto grava', $mv->ok, json_encode($mv->errors));
T::igual('mas não ancora sozinho', $p4,
    (int) Db::valor('SELECT caixa_fim_id FROM tab_ftth_cabo_vao WHERE id = ?', [$v4]));

T::igual('não ancora em ponta livre', 'FTTH-SYS-002',
    Caixa::ancorarPonta($p4, $p4, 'teste')->primeiroCodigo());
T::igual('não ancora na caixa da outra ponta (laço)', 'FTTH-GEO-003',
    Caixa::ancorarPonta($p4, $a, 'teste')->primeiroCodigo());
// A ancoragem vai no Concluir, junto com os arrastos e na mesma transação: se ela for
// recusada, o arrasto do ponto também não fica.
$ceoAntes = Db::um('SELECT lat, lng FROM tab_ftth_caixa WHERE id = ?', [$ceo]);
$ruim = Mapa::aplicarMovimentos([['id' => $ceo, 'lat' => -24.96805, 'lng' => -52.45005]], [], 'teste',
                                [['ponta' => $p4, 'caixa' => $a]]);
T::igual('ancoragem recusada no Concluir devolve o erro', 'FTTH-GEO-003', $ruim->primeiroCodigo());
T::igual('e desfaz o arrasto que vinha junto', $ceoAntes,
    Db::um('SELECT lat, lng FROM tab_ftth_caixa WHERE id = ?', [$ceo]));
$an = Mapa::aplicarMovimentos([['id' => $ceo, 'lat' => -24.9680, 'lng' => -52.4500]], [], 'teste',
                              [['ponta' => $p4, 'caixa' => $ceo]]);
T::certo('ancora no Concluir', $an->ok && $an->data['ancoras'] === 1, json_encode($an->errors));
T::igual('o cabo passa a terminar no ponto', $ceo,
    (int) Db::valor('SELECT caixa_fim_id FROM tab_ftth_cabo_vao WHERE id = ?', [$v4]));
$fimV4 = json_decode((string) Db::valor('SELECT vertices FROM tab_ftth_cabo_vao WHERE id = ?', [$v4]), true);
T::igual('e o traçado vai até ele', [-24.968, -52.45], array_map('floatval', end($fimV4)));
T::certo('a ponta saiu', Db::valor('SELECT excluido_em FROM tab_ftth_caixa WHERE id = ?', [$p4]) !== null);

// ------------------------------------------------------------------ o cabo sai, a ponta sai junto
$p3novo = (int) $pontaDo($v3, 'fim')['id'];
T::certo('exclui o cabo', Cabo::excluir((int) $r3->data['cabo_id'], 'teste')->ok);
T::certo('a ponta dele saiu junto',
    Db::valor('SELECT excluido_em FROM tab_ftth_caixa WHERE id = ?', [$p3novo]) !== null);
