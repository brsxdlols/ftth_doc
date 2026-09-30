<?php
/**
 * Suite 19 :: postes (30/09/2026).
 *
 * O poste é um ponto do mapa, mas não é caixa: o cabo PASSA por ele. Clicar num poste no
 * traçado marca um vértice, ele não recebe fusão, splitter nem emenda, e no KMZ ele nunca
 * rouba a ponta do cabo da CTO que está ao lado.
 */
require_once __DIR__ . '/../../lib/Cabo.php';
require_once __DIR__ . '/../../lib/Caixa.php';
require_once __DIR__ . '/../../lib/Kmz.php';
require_once __DIR__ . '/../../lib/Topologia.php';

T::suite('Postes');

$regiao = (int) Regiao::criar('Postes', -25.01, -52.55, 15, 'teste')->data['id'];
$t6 = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "6 FO"');

$rP = Caixa::criar($regiao, 'POSTE', 'POSTE.01', '#FF9100', -25.0100, -52.5490, 'teste');
T::certo('cadastra um poste', $rP->ok, json_encode($rP->errors));
$poste = (int) $rP->data['id'];
T::igual('a sugestão segue a série dos postes', 'POSTE.02', Caixa::sugerirNome($regiao, 'POSTE'));
T::certo('poste aparece na lista de pontos',
    in_array($poste, array_map('intval', array_column(Mapa::pontos($regiao), 'id')), true));

$a = (int) Caixa::criar($regiao, 'CEO', 'PO.A', '#FF9100', -25.0100, -52.5500, 'teste')->data['id'];
$b = (int) Caixa::criar($regiao, 'CTO', 'PO.B', '#43A047', -25.0100, -52.5480, 'teste')->data['id'];

// Clicar no poste no meio do traçado vira vértice: um vão só, de A a B, passando pelo poste.
$r = Cabo::criar($regiao, ['cabo_tipo_id' => $t6], [
    ['tipo' => 'CAIXA', 'id' => $a],
    ['tipo' => 'CAIXA', 'id' => $poste],
    ['tipo' => 'CAIXA', 'id' => $b],
], 'teste');
T::certo('cabo passando pelo poste', $r->ok, json_encode($r->errors));
T::igual('o poste não parte o cabo: um vão só', 1, count($r->data['vaos'] ?? []));
$verts = json_decode((string) Db::valor('SELECT vertices FROM tab_ftth_cabo_vao WHERE id = ?',
    [(int) $r->data['vaos'][0]]), true);
T::igual('o traçado passa exatamente no poste', [-25.01, -52.549], array_map('floatval', $verts[1]));
T::igual('e o poste não é ponta de vão nenhum', 0, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_cabo_vao WHERE caixa_ini_id = ? OR caixa_fim_id = ?', [$poste, $poste]));

T::igual('poste não emenda cabo', false,
    Cabo::quebrarVao((int) $r->data['vaos'][0], $poste, 'teste')->ok);
T::igual('poste não recebe splitter', false, Topologia::criarSplitter($poste,
    ['funcao' => 'DERIVACAO', 'razao' => '1:2', 'saidas' => 2], 'teste')->ok);
T::igual('ponto que é ponta de cabo não vira poste', false,
    Caixa::alterar($a, ['tipo' => 'POSTE'], null, 'teste')->ok);

// ------------------------------------------------------------------ KMZ
$kml = sys_get_temp_dir() . '/ftth_postes_' . getmypid() . '.kml';
file_put_contents($kml, '<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2"><Document>
  <Placemark><name>P-01</name><description>Tipo: Poste</description>
    <Point><coordinates>-52.5400,-25.0200,0</coordinates></Point></Placemark>
  <Folder><name>Postes</name>
    <Placemark><name></name><Point><coordinates>-52.5401,-25.0200,0</coordinates></Point></Placemark>
  </Folder>
  <Placemark><name>PT-15</name><Point><coordinates>-52.5402,-25.0200,0</coordinates></Point></Placemark>
  <Placemark><name>CTO.77</name><description>Tipo: Caixa de Atendimento</description>
    <Point><coordinates>-52.5403,-25.0200,0</coordinates></Point></Placemark>
</Document></kml>');
$lido = Kmz::ler($kml);
@unlink($kml);
$tipos = array_column($lido['itens'], 'subtipo');
T::igual('KMZ: descrição, pasta e nome reconhecem o poste', ['POSTE', 'POSTE', 'POSTE', 'CTO'], $tipos);
T::igual('KMZ: poste sem nome não é alerta', [], $lido['itens'][1]['alertas']);

// ------------------------------------------------------------------ arquivo de postes da concessionária
// Google Earth: sem descrição, nome = coordenada, e só o documento diz que são postes.
require_once __DIR__ . '/../../lib/Quarentena.php';
$kmlGe = sys_get_temp_dir() . '/ftth_postes_ge_' . getmypid() . '.kml';
file_put_contents($kmlGe, '<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>postes (3) sem projeto</name>
  <Placemark><name>-25.030000,-52.540000</name><Point><coordinates>-52.5400,-25.0300,0</coordinates></Point></Placemark>
  <Placemark><name>-25.030100,-52.540100</name><Point><coordinates>-52.5401,-25.0301,0</coordinates></Point></Placemark>
</Document></kml>');
$ge = Kmz::ler($kmlGe);
T::igual('o nome do documento diz que são postes', ['POSTE', 'POSTE'], array_column($ge['itens'], 'subtipo'));
T::igual('nome que é coordenada conta como sem nome', null, $ge['itens'][0]['nome']);
T::igual('com "Tipo dos pontos" no envio, todos entram com ele', ['CEO', 'CEO'],
    array_column(Kmz::ler($kmlGe, 'CEO')['itens'], 'subtipo'));

// Na quarentena: entrou como CTO (sem pista), troca em lote para Poste e importa.
$kmlSem = sys_get_temp_dir() . '/ftth_sem_pista_' . getmypid() . '.kml';
file_put_contents($kmlSem, '<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>levantamento</name>
  <Placemark><name>-25.040000,-52.540000</name><Point><coordinates>-52.5400,-25.0400,0</coordinates></Point></Placemark>
  <Placemark><name>-25.040100,-52.540100</name><Point><coordinates>-52.5401,-25.0401,0</coordinates></Point></Placemark>
</Document></kml>');
$imp = Kmz::importarParaQuarentena($kmlSem, 'levantamento.kml', $regiao, 'teste', true);
$idsQ = array_map('intval', array_column(Db::todos(
    'SELECT id FROM tab_ftth_importacao_item WHERE importacao_id = ?', [$imp['importacao_id']]), 'id'));
T::igual('sem pista, entram como CTO e com alerta de sem nome', 2, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_importacao_item WHERE importacao_id = ? AND subtipo = "CTO" AND alertas_json IS NOT NULL',
    [$imp['importacao_id']]));
$troca = Quarentena::alterarTipo($idsQ, 'POSTE', 'teste');
T::igual('mudar tipo em lote para Poste', 2, $troca->data['alterados'] ?? null);
T::igual('e o alerta de sem nome some (poste ganha nome ao importar)', 0, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_importacao_item WHERE importacao_id = ? AND alertas_json IS NOT NULL',
    [$imp['importacao_id']]));
T::igual('tipo inválido é recusado', false, Quarentena::alterarTipo($idsQ, 'BANANA', 'teste')->ok);
$lote = Quarentena::importarLote($idsQ, 'teste');
T::igual('importa os dois postes', 2, count($lote->data['importados'] ?? []));
T::igual('com nome na série', 2, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_caixa WHERE regiao_id = ? AND tipo = "POSTE" AND nome LIKE "POSTE.%" AND excluido_em IS NULL',
    [$regiao]) - 1);
@unlink($kmlGe);
@unlink($kmlSem);

// Âncora que virou poste na quarentena não segura a ponta do cabo: ela fica livre.
$kmlCabo = sys_get_temp_dir() . '/ftth_ancora_poste_' . getmypid() . '.kml';
file_put_contents($kmlCabo, '<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>rede</name>
  <Placemark><name>AP.01</name><description>Tipo: Caixa de Emenda</description>
    <Point><coordinates>-52.5300,-25.0500,0</coordinates></Point></Placemark>
  <Placemark><name>AP.02</name><description>Tipo: Caixa de Atendimento</description>
    <Point><coordinates>-52.5290,-25.0500,0</coordinates></Point></Placemark>
  <Placemark><name>Cabo 6FO</name>
    <LineString><coordinates>-52.5300,-25.0500,0 -52.5290,-25.0500,0</coordinates></LineString></Placemark>
</Document></kml>');
$impC = Kmz::importarParaQuarentena($kmlCabo, 'rede.kml', $regiao, 'teste', true);
@unlink($kmlCabo);
$itensC = Db::todos('SELECT id, nome, tipo_sugerido FROM tab_ftth_importacao_item WHERE importacao_id = ?',
                    [$impC['importacao_id']]);
$idAp02 = 0;
foreach ($itensC as $it) {
    if ($it['nome'] === 'AP.02') { $idAp02 = (int) $it['id']; }
}
Quarentena::alterarTipo([$idAp02], 'POSTE', 'teste');
$loteC = Quarentena::importarLote(array_map('intval', array_column($itensC, 'id')), 'teste');
T::igual('importa os três itens', 3, count($loteC->data['importados'] ?? []), json_encode($loteC->data));
$vaoC = Db::um('SELECT v.* FROM tab_ftth_cabo_vao v JOIN tab_ftth_importacao_item i ON i.gerado_id = v.id
                 WHERE i.importacao_id = ? AND i.tipo_sugerido = "VAO"', [$impC['importacao_id']]);
T::igual('a ponta que era do AP.02 (agora poste) virou ponta livre', 'PONTA',
    Db::valor('SELECT tipo FROM tab_ftth_caixa WHERE id = ?', [(int) ($vaoC['caixa_fim_id'] ?? 0)]));

// ------------------------------------------------------------------ cor na importação e no mapa
require_once __DIR__ . '/../../lib/Lote.php';
$kmlCor = sys_get_temp_dir() . '/ftth_cor_' . getmypid() . '.kml';
file_put_contents($kmlCor, '<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>postes</name>
  <Placemark><name>-25.060000,-52.540000</name><Point><coordinates>-52.5400,-25.0600,0</coordinates></Point></Placemark>
  <Placemark><name>-25.060100,-52.540100</name><Point><coordinates>-52.5401,-25.0601,0</coordinates></Point></Placemark>
</Document></kml>');
T::igual('"Cor dos pontos" no envio pinta todos', ['#795548', '#795548'],
    array_column(Kmz::ler($kmlCor, null, '#795548')['itens'], 'cor'));
$impCor = Kmz::importarParaQuarentena($kmlCor, 'postes-cor.kml', $regiao, 'teste', true);
@unlink($kmlCor);
$idsCor = array_map('intval', array_column(Db::todos(
    'SELECT id FROM tab_ftth_importacao_item WHERE importacao_id = ?', [$impCor['importacao_id']]), 'id'));
T::igual('mudar cor em lote na quarentena', 2, Quarentena::alterarCor($idsCor, '#8e24aa', 'teste')->data['alterados'] ?? null);
T::igual('cor inválida é recusada', false, Quarentena::alterarCor($idsCor, 'roxo', 'teste')->ok);
Quarentena::importarLote($idsCor, 'teste');
T::igual('a cor escolhida chega nos postes importados', 2, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_caixa c JOIN tab_ftth_importacao_item i ON i.gerado_id = c.id
      WHERE i.importacao_id = ? AND c.cor = "#8E24AA"', [$impCor['importacao_id']]));

// Selecionar no mapa + Cor: pinta os pontos, deixa a ponta livre com a cor do cabo.
$pc = Cabo::criar($regiao, ['cabo_tipo_id' => $t6, 'cor_rota' => '#0D47A1'], [
    ['tipo' => 'CAIXA', 'id' => $b],
    ['tipo' => 'VERTICE', 'lat' => -25.0110, 'lng' => -52.5470],
], 'teste');
$pontaCor = (int) Db::valor('SELECT caixa_fim_id FROM tab_ftth_cabo_vao WHERE id = ?', [(int) $pc->data['vaos'][0]]);
$mc = Lote::mudarCor($regiao, [$poste, $b, $pontaCor], '#e53935', 'teste');
T::igual('Cor em lote pinta os pontos, sem a ponta livre', 2, $mc->data['alterados'] ?? null);
T::igual('o poste ficou vermelho', '#E53935', Db::valor('SELECT cor FROM tab_ftth_caixa WHERE id = ?', [$poste]));
T::igual('a ponta livre continua na cor do cabo', '#0D47A1',
    Db::valor('SELECT cor FROM tab_ftth_caixa WHERE id = ?', [$pontaCor]));

// ------------------------------------------------------------------ desfazer a decisão (reverter)
// Os dois postes da importação de cor: voltam para pendentes, e os pontos saem do mapa.
$revP = Quarentena::reverter($idsCor, 'pendente', 'teste');
T::igual('importados voltam para pendentes', 2, $revP->data['revertidos'] ?? null);
T::igual('e o que foi criado saiu do mapa', 0, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_caixa c WHERE c.excluido_em IS NULL AND c.id IN
        (SELECT gerado_id FROM tab_ftth_importacao_item WHERE importacao_id = ?)', [$impCor['importacao_id']]));
T::igual('ficaram pendentes', 2, (int) Db::valor(
    'SELECT COUNT(*) FROM tab_ftth_importacao_item WHERE importacao_id = ? AND status = "pendente" AND gerado_id IS NULL',
    [$impCor['importacao_id']]));
Quarentena::descartar($idsCor, 'teste');
T::igual('descartados voltam para pendentes', 2, Quarentena::reverter($idsCor, 'pendente', 'teste')->data['revertidos'] ?? null);

// Importado que já foi ligado à rede não é revertido: o ponto AP.01 é ponta do cabo importado.
$ap01 = 0;
foreach ($itensC as $it) {
    if ($it['nome'] === 'AP.01') { $ap01 = (int) $it['id']; }
}
$revRecusa = Quarentena::reverter([$ap01], 'pendente', 'teste');
T::igual('ponto com cabo fica de fora', 0, $revRecusa->data['revertidos'] ?? null);
T::igual('com o motivo', 1, count($revRecusa->data['pulados'] ?? []));
// Revertendo o cabo e o ponto juntos: o cabo sai primeiro e libera o ponto.
$vaoItem = 0;
foreach ($itensC as $it) {
    if ($it['tipo_sugerido'] === 'VAO') { $vaoItem = (int) $it['id']; }
}
$revJunto = Quarentena::reverter([$ap01, $vaoItem], 'descartado', 'teste');
T::igual('cabo e ponto juntos: os dois saem', 2, $revJunto->data['revertidos'] ?? null, json_encode($revJunto->data));
