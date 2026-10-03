<?php
/** Teste independente; conecta somente ao schema separado do runner. */
require_once __DIR__ . '/../lib/Ajustes.php';
require_once __DIR__ . '/../lib/PrimeirosPassos.php';
Db::conectar(['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root',
    'pass' => getenv('FTTH_TEST_DB_PASS') ?: '', 'name' => 'mkradius_visualnet_ftth_test']);
$total = 0;
function verificarMapa(bool $condicao, string $nome): void {
    global $total;
    if (!$condicao) throw new RuntimeException($nome);
    $total++;
    echo "OK: $nome\n";
}
Config::set('mapa_provedor', '', 'teste');
Config::set('google_maps_key', '', 'teste');
verificarMapa(Ajustes::provedor() === 'osm', 'instalação sem chave usa OSM');
verificarMapa(PrimeirosPassos::estado()['feitos']['chave'], 'OSM desbloqueia primeiros passos sem chave');
Config::set('google_maps_key', 'chave-legada', 'teste');
verificarMapa(Ajustes::provedor() === 'google', 'instalação existente com chave conserva Google');
Ajustes::salvar(['mapa_provedor' => 'osm'], 'teste');
verificarMapa(Ajustes::provedor() === 'osm', 'escolha explícita OSM vence chave legada');
verificarMapa(Config::get('google_maps_key') === 'chave-legada', 'trocar provedor conserva a chave');
Ajustes::salvar(['mapa_provedor' => 'google', 'google_maps_key' => ''], 'teste');
verificarMapa(!PrimeirosPassos::estado()['feitos']['chave'], 'Google sem chave exige configuração');
$recusado = false;
try { Ajustes::salvar(['mapa_provedor' => 'invalido'], 'teste'); }
catch (InvalidArgumentException $e) { $recusado = true; }
verificarMapa($recusado && Ajustes::provedor() === 'google', 'provedor inválido não altera escolha');
echo "$total testes passaram\n";
