<?php
/**
 * Suite 18 :: primeiros passos com a ponta livre (0.9.6).
 *
 * O estado dos passos olha o banco INTEIRO, não uma região. Por isso esta suíte roda por último
 * e começa apagando (logicamente) a rede que as outras deixaram — o banco de teste é recriado
 * a cada execução, então isso não custa nada.
 *
 * O que ela vigia: o passo 5 só conta quando o traçado dos cabos liga o POP a uma CTO/CEO.
 * Um cabo que parou numa ponta livre deixa o passo aberto e marcado como "cabo solto".
 */
require_once __DIR__ . '/../../lib/Cabo.php';
require_once __DIR__ . '/../../lib/Caixa.php';
require_once __DIR__ . '/../../lib/PrimeirosPassos.php';

T::suite('Primeiros passos com ponta livre');

Config::set('google_maps_key', 'chave-de-teste', 'teste');   // sem chave o assistente para no passo 1

Db::exec('UPDATE tab_ftth_cabo_vao SET excluido_em = NOW() WHERE excluido_em IS NULL');
Db::exec('UPDATE tab_ftth_cabo SET excluido_em = NOW() WHERE excluido_em IS NULL');
Db::exec('UPDATE tab_ftth_caixa SET excluido_em = NOW() WHERE excluido_em IS NULL');

$regiao = (int) Regiao::criar('Passos', -24.99, -52.50, 15, 'teste')->data['id'];
$t6 = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');
$pop = (int) Caixa::criar($regiao, 'DC', 'PP.POP', '#1B3A6B', -24.9900, -52.5000, 'teste')->data['id'];
$ceo = (int) Caixa::criar($regiao, 'CEO', 'PP.CEO', '#FF9100', -24.9900, -52.4980, 'teste')->data['id'];

$e = PrimeirosPassos::estado();
T::igual('com POP e caixa, falta o cabo', 'cabo', $e['atual']);
T::igual('e ainda não há cabo solto', false, $e['cabo_solto']);

// Cabo do POP que parou no meio do caminho, numa ponta livre.
$r = Cabo::criar($regiao, ['cabo_tipo_id' => $t6], [
    ['tipo' => 'CAIXA',   'id' => $pop],
    ['tipo' => 'VERTICE', 'lat' => -24.9900, 'lng' => -52.4990],
], 'teste');
$e = PrimeirosPassos::estado();
T::igual('cabo parado numa ponta livre NÃO fecha o passo 5', 'cabo', $e['atual']);
T::igual('e o estado diz que o cabo está solto', true, $e['cabo_solto']);

// Continuar até a CEO fecha o passo.
$ponta = (int) Db::valor('SELECT caixa_fim_id FROM tab_ftth_cabo_vao WHERE id = ?', [(int) $r->data['vaos'][0]]);
$c = Cabo::criar($regiao, ['cabo_tipo_id' => $t6, 'continuar_de' => $ponta], [
    ['tipo' => 'CAIXA', 'id' => $ponta],
    ['tipo' => 'CAIXA', 'id' => $ceo],
], 'teste');
T::certo('continua o cabo até a CEO', $c->ok, json_encode($c->errors));
$e = PrimeirosPassos::estado();
T::igual('agora o POP chega na CEO: tudo pronto', null, $e['atual']);
T::igual('e o cabo não está mais solto', false, $e['cabo_solto']);

// ------------------------------------------------------------------ camadas por usuário
require_once __DIR__ . '/../../lib/Ajustes.php';
T::igual('sem nada gravado, todas as camadas aparecem', true,
    !in_array(false, Ajustes::camadas('fulano'), true));
Ajustes::salvarCamadas(['QUARENTENA' => false, 'INVENTADA' => false], 'fulano');
Config::limparCache();
$cam = Ajustes::camadas('fulano');
T::igual('a quarentena desmarcada volta desmarcada', false, $cam['QUARENTENA']);
T::igual('camada que não existe não é gravada', false, array_key_exists('INVENTADA', $cam));
T::igual('e a escolha é só deste usuário', true, Ajustes::camadas('ciclano')['QUARENTENA']);
