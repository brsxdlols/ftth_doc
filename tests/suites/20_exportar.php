<?php
/**
 * Suite 20 :: exportar KMZ (01/10/2026).
 *
 * O teste que importa é a IDA E VOLTA: o arquivo que o addon gera, lido pelo próprio
 * importador, tem de devolver os mesmos tipos, cores e capacidade de cabo. Um "72 FO MULT
 * (6x12)" que voltasse "72 FO", ou uma CTO roxa que voltasse verde, seria exportação pela metade.
 */
require_once __DIR__ . '/../../lib/Cabo.php';
require_once __DIR__ . '/../../lib/Caixa.php';
require_once __DIR__ . '/../../lib/Kmz.php';
require_once __DIR__ . '/../../lib/KmzExport.php';

T::suite('Exportar KMZ');

$regiao = (int) Regiao::criar('Exportar', -25.05, -52.60, 15, 'teste')->data['id'];
$t72 = (int) Db::valor('SELECT id FROM tab_ftth_cabo_tipo WHERE rotulo = "72 FO MULT (6x12)"');

$ceoA = (int) Caixa::criar($regiao, 'CEO', 'EX.CEO.A', '#FF9100', -25.0500, -52.6000, 'teste')->data['id'];
$cto  = (int) Caixa::criar($regiao, 'CTO', 'EX.CTO.1', '#8E24AA', -25.0500, -52.5990, 'teste')->data['id'];
$ceoB = (int) Caixa::criar($regiao, 'CEO', 'EX.CEO.B', '#FF9100', -25.0500, -52.5980, 'teste')->data['id'];
$ctoC = (int) Caixa::criar($regiao, 'CTO', 'EX.CTO.2', '#43A047', -25.0500, -52.5970, 'teste')->data['id'];
$poste = (int) Caixa::criar($regiao, 'POSTE', 'EX.POSTE.1', '#FF9100', -25.0510, -52.5990, 'teste')->data['id'];
$rCabo = Cabo::criar($regiao, ['cabo_tipo_id' => $t72, 'nome' => 'EX.TRONCO', 'cor_rota' => '#8E24AA'],
    array_map(static fn($id) => ['tipo' => 'CAIXA', 'id' => $id], [$ceoA, $cto, $ceoB, $ctoC]), 'teste');
T::certo('monta a rede: 5 pontos e um 72 FO com 3 vãos', $rCabo->ok && count($rCabo->data['vaos']) === 3,
    json_encode($rCabo->errors));

// ------------------------------------------------------------------ filtros
$tudo = KmzExport::dados([$regiao], array_keys(KmzExport::GRUPOS), true);
T::igual('tudo: 5 pontos e 3 vãos', ['pontos' => 5, 'vaos' => 3], KmzExport::contar($tudo));
T::igual('só CTO, sem cabos', ['pontos' => 2, 'vaos' => 0],
    KmzExport::contar(KmzExport::dados([$regiao], ['CTO'], false)));
T::igual('só cabos', ['pontos' => 0, 'vaos' => 3], KmzExport::contar(KmzExport::dados([$regiao], [], true)));
T::igual('grupo desconhecido não exporta nada', ['pontos' => 0, 'vaos' => 0],
    KmzExport::contar(KmzExport::dados([$regiao], ["CTO') OR 1=1 -- "], false)));
T::igual('seleção: um ponto e o cabo inteiro', ['pontos' => 1, 'vaos' => 3],
    KmzExport::contar(KmzExport::dados([$regiao], [], false, [$poste], [(int) $rCabo->data['cabo_id']])));
T::igual('seleção sem cabo não leva vão', ['pontos' => 2, 'vaos' => 0],
    KmzExport::contar(KmzExport::dados([$regiao], [], true, [$cto, $ceoA], [])));
T::igual('nada escolhido é recusado', 'FTTH-SYS-002',
    KmzExport::exportar(KmzExport::dados([$regiao], [], false), 'Exportar')->primeiroCodigo());

// ------------------------------------------------------------------ o arquivo
$r = KmzExport::exportar($tudo, 'Exportar');
T::certo('gera o arquivo', $r->ok, json_encode($r->errors));
T::igual('é um KMZ', '.kmz', substr($r->data['nome'], -4));
$arq = tempnam(sys_get_temp_dir(), 'ftthexp') . '.kmz';
file_put_contents($arq, $r->data['conteudo']);
$zip = new ZipArchive();
T::certo('abre como ZIP', $zip->open($arq) === true);
$kml = (string) $zip->getFromName('doc.kml');
$zip->close();
T::certo('o doc.kml é XML válido', simplexml_load_string($kml) !== false);

// ------------------------------------------------------------------ ida e volta
$lido = Kmz::ler($arq);
@unlink($arq);
$pontos = array_values(array_filter($lido['itens'], static fn($i) => $i['tipo_sugerido'] === 'CAIXA'));
$linhas = array_values(array_filter($lido['itens'], static fn($i) => $i['tipo_sugerido'] === 'VAO'));
$porNome = array_column($pontos, null, 'nome');
T::igual('volta com 5 pontos e 3 trechos', [5, 3], [count($pontos), count($linhas)]);
T::igual('a CTO roxa volta CTO e roxa', ['CTO', '#8E24AA'],
    [$porNome['EX.CTO.1']['subtipo'] ?? null, $porNome['EX.CTO.1']['cor'] ?? null]);
T::igual('o poste volta poste', 'POSTE', $porNome['EX.POSTE.1']['subtipo'] ?? null);
T::igual('o cabo volta com o rótulo exato', '72 FO MULT (6x12)', $linhas[0]['subtipo'] ?? null);
T::igual('com o nome e a cor do cabo', ['EX.TRONCO', '#8E24AA'],
    [$linhas[0]['nome'] ?? null, $linhas[0]['cor'] ?? null]);
T::igual('e as coordenadas de onde saiu', [-25.05, -52.6],
    [round($porNome['EX.CEO.A']['geometria'][0][0], 6), round($porNome['EX.CEO.A']['geometria'][0][1], 6)]);
