<?php
/**
 * ftth_doc :: exportação da rede para KMZ (01/10/2026).
 *
 * Dois caminhos, o mesmo arquivo:
 *  - aba Ajustes: os tipos marcados, da região aberta ou de todas;
 *  - modo Selecionar: exatamente os pontos e cabos da seleção.
 *
 * Regras (decididas com o Marcelo em 01/10/2026):
 *  - cabo vai como UM TRECHO POR VÃO, numa pasta com o nome do cabo: reimportado, cada
 *    vão volta ancorado nas mesmas caixas — uma linha por cabo perderia as do meio;
 *  - o arquivo é legível no Google Earth (descrição no padrão do UpperX, cor no ícone e na
 *    linha) e carrega no ExtendedData o que o addon precisa para voltar igual: tipo, cor e
 *    o rótulo EXATO do tipo de cabo ("72 FO MULT (6x12)" voltaria como "72 FO" pelo nome);
 *  - o estilo vai DENTRO de cada Placemark, que é onde o Kmz::ler procura;
 *  - ponta livre, quarentena e excluídos ficam de fora.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Caixa.php';
require_once __DIR__ . '/Regiao.php';

final class KmzExport
{
    /** Camada da tela -> tipos do banco (os legados seguem a camada em que aparecem no mapa). */
    public const GRUPOS = [
        'CTO'      => ['CTO', 'CTO_AP'],
        'CEO'      => ['CEO'],
        'DC'       => ['DC'],
        'PREDIO'   => ['PREDIO', 'CLIENTE'],
        'PROBLEMA' => ['PROBLEMA', 'FALHA'],
        'RESERVA'  => ['RESERVA'],
        'POSTE'    => ['POSTE'],
    ];

    /** Pasta de cada tipo no arquivo. */
    private const PASTAS = [
        'CTO' => 'CTO', 'CTO_AP' => 'CTO', 'CEO' => 'CEO', 'DC' => 'DC / POP',
        'PREDIO' => 'Prédio', 'CLIENTE' => 'Prédio', 'PROBLEMA' => 'Problema', 'FALHA' => 'Problema',
        'RESERVA' => 'Reserva', 'POSTE' => 'Postes',
    ];

    /** A descrição no padrão do UpperX: é o que o Kmz::ler (e quem abre no Earth) entende. */
    private const DESCRICOES = [
        'CTO' => 'Caixa de atendimento', 'CTO_AP' => 'CTO AP', 'CEO' => 'Caixa de emenda',
        'DC' => 'Data Center', 'PREDIO' => 'Prédio', 'CLIENTE' => 'Cliente', 'POSTE' => 'Poste',
        'PROBLEMA' => 'Problema', 'FALHA' => 'Falha', 'RESERVA' => 'Reserva',
    ];

    /** Ícone neutro que o Earth pinta com a cor do IconStyle (o paddle "wht" viraria branco na volta). */
    private const ICONE = 'http://maps.google.com/mapfiles/kml/shapes/placemark_circle.png';

    /**
     * O que vai no arquivo. Com `$caixaIds`/`$caboIds` (modo Selecionar) vale a lista; sem,
     * valem os tipos marcados.
     *
     * @param int[]      $regiaoIds
     * @param string[]   $grupos    chaves de GRUPOS
     * @return array<int, array{nome:string, pontos:array, vaos:array}>
     */
    public static function dados(array $regiaoIds, array $grupos, bool $cabos,
                                 ?array $caixaIds = null, ?array $caboIds = null): array
    {
        $regiaoIds = array_values(array_unique(array_filter(array_map('intval', $regiaoIds))));
        if (!$regiaoIds) {
            return [];
        }
        $mr = self::marcas($regiaoIds);
        $selecao = $caixaIds !== null || $caboIds !== null;

        $pontos = [];
        if ($selecao) {
            $ids = array_values(array_filter(array_map('intval', (array) $caixaIds)));
            if ($ids) {
                $pontos = Db::todos(
                    "SELECT id, regiao_id, tipo, nome, cor, lat, lng, reserva_m FROM tab_ftth_caixa
                      WHERE regiao_id IN ($mr) AND excluido_em IS NULL AND tipo <> 'PONTA'
                        AND id IN (" . self::marcas($ids) . ') ORDER BY tipo, nome',
                    array_merge($regiaoIds, $ids));
            }
        } else {
            $tipos = [];
            foreach ($grupos as $g) {
                $tipos = array_merge($tipos, self::GRUPOS[(string) $g] ?? []);
            }
            if ($tipos) {
                $pontos = Db::todos(
                    "SELECT id, regiao_id, tipo, nome, cor, lat, lng, reserva_m FROM tab_ftth_caixa
                      WHERE regiao_id IN ($mr) AND excluido_em IS NULL
                        AND tipo IN (" . self::marcas($tipos) . ') ORDER BY tipo, nome',
                    array_merge($regiaoIds, $tipos));
            }
        }

        $vaos = [];
        $filtroCabo = null;
        if ($selecao) {
            $filtroCabo = array_values(array_filter(array_map('intval', (array) $caboIds)));
        }
        if ($selecao ? (bool) $filtroCabo : $cabos) {
            $vaos = Db::todos(
                "SELECT v.id, v.regiao_id, v.cabo_id, v.ordem, v.vertices, v.comprimento_geo,
                        cb.nome AS cabo_nome, cb.cor_rota, t.rotulo AS cabo_tipo,
                        ci.nome AS caixa_ini, cf.nome AS caixa_fim
                   FROM tab_ftth_cabo_vao v
                   JOIN tab_ftth_cabo cb     ON cb.id = v.cabo_id AND cb.excluido_em IS NULL
                   JOIN tab_ftth_cabo_tipo t ON t.id = cb.cabo_tipo_id
                   JOIN tab_ftth_caixa ci    ON ci.id = v.caixa_ini_id
                   JOIN tab_ftth_caixa cf    ON cf.id = v.caixa_fim_id
                  WHERE v.regiao_id IN ($mr) AND v.excluido_em IS NULL"
                  . ($filtroCabo ? ' AND v.cabo_id IN (' . self::marcas($filtroCabo) . ')' : '')
                  . ' ORDER BY cb.nome, cb.id, v.ordem, v.id',
                array_merge($regiaoIds, $filtroCabo ?: []));
        }

        $saida = [];
        foreach ($regiaoIds as $id) {
            $r = Regiao::obter($id);
            if ($r) {
                $saida[$id] = ['nome' => (string) $r['nome'], 'pontos' => [], 'vaos' => []];
            }
        }
        foreach ($pontos as $p) {
            if (isset($saida[(int) $p['regiao_id']])) {
                $saida[(int) $p['regiao_id']]['pontos'][] = $p;
            }
        }
        foreach ($vaos as $v) {
            if (isset($saida[(int) $v['regiao_id']])) {
                $saida[(int) $v['regiao_id']]['vaos'][] = $v;
            }
        }
        return $saida;
    }

    /** Quantos pontos e vãos o arquivo teria — o modal mostra antes de baixar. */
    public static function contar(array $dados): array
    {
        $p = 0;
        $v = 0;
        foreach ($dados as $r) {
            $p += count($r['pontos']);
            $v += count($r['vaos']);
        }
        return ['pontos' => $p, 'vaos' => $v];
    }

    /**
     * O arquivo pronto para download.
     *
     * @return Resultado data: {conteudo, nome, tipo_mime, pontos, vaos}
     */
    public static function exportar(array $dados, string $titulo): Resultado
    {
        $conta = self::contar($dados);
        if (!$conta['pontos'] && !$conta['vaos']) {
            return Resultado::erro('FTTH-SYS-002', [], 'Nada a exportar com essa escolha.');
        }
        $kml = self::kml($dados, $titulo);

        $base = 'ftth_' . trim(preg_replace('/[^A-Za-z0-9]+/', '_', self::semAcento($titulo)), '_')
              . '_' . date('Y-m-d');
        if (!class_exists('ZipArchive')) {
            // Sem a extensão no servidor: o KML puro abre igual no Earth e no importador.
            return Resultado::ok(['conteudo' => $kml, 'nome' => $base . '.kml',
                                  'tipo_mime' => 'application/vnd.google-earth.kml+xml'] + $conta);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ftthkmz');
        $zip = new ZipArchive();
        if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            return Resultado::erro('FTTH-SYS-001', [], 'Não foi possível montar o KMZ no servidor.');
        }
        $zip->addFromString('doc.kml', $kml);
        $zip->close();
        $conteudo = (string) file_get_contents($tmp);
        @unlink($tmp);
        return Resultado::ok(['conteudo' => $conteudo, 'nome' => $base . '.kmz',
                              'tipo_mime' => 'application/vnd.google-earth.kmz'] + $conta);
    }

    /** O KML: uma pasta por região (quando há mais de uma), por tipo de ponto e por cabo. */
    public static function kml(array $dados, string $titulo): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
           . '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>'
           . '<name>' . self::e('FTTH Doc — ' . $titulo) . '</name>';
        $varias = count($dados) > 1;
        foreach ($dados as $r) {
            if (!$r['pontos'] && !$r['vaos']) {
                continue;
            }
            if ($varias) {
                $x .= '<Folder><name>' . self::e($r['nome']) . '</name>';
            }
            $x .= self::pastasDePontos($r['pontos']) . self::pastaDeCabos($r['vaos']);
            if ($varias) {
                $x .= '</Folder>';
            }
        }
        return $x . '</Document></kml>';
    }

    private static function pastasDePontos(array $pontos): string
    {
        $porPasta = [];
        foreach ($pontos as $p) {
            $porPasta[self::PASTAS[$p['tipo']] ?? (string) $p['tipo']][] = $p;
        }
        $x = '';
        foreach ($porPasta as $pasta => $lista) {
            $x .= '<Folder><name>' . self::e($pasta) . '</name>';
            foreach ($lista as $p) {
                $desc = 'Tipo: ' . (self::DESCRICOES[$p['tipo']] ?? $p['tipo']);
                if ($p['tipo'] === 'RESERVA' && (float) $p['reserva_m'] > 0) {
                    $desc .= ' · ' . rtrim(rtrim(number_format((float) $p['reserva_m'], 2, '.', ''), '0'), '.') . ' m';
                }
                $x .= '<Placemark><name>' . self::e((string) $p['nome']) . '</name>'
                    . '<description>' . self::e($desc) . '</description>'
                    . '<Style><IconStyle><color>' . self::corKml((string) $p['cor']) . '</color>'
                    . '<Icon><href>' . self::ICONE . '</href></Icon></IconStyle></Style>'
                    . self::extendido(['ftth_tipo' => $p['tipo'], 'ftth_cor' => strtoupper((string) $p['cor'])])
                    . '<Point><coordinates>' . self::coord((float) $p['lat'], (float) $p['lng'])
                    . '</coordinates></Point></Placemark>';
            }
            $x .= '</Folder>';
        }
        return $x;
    }

    private static function pastaDeCabos(array $vaos): string
    {
        if (!$vaos) {
            return '';
        }
        $porCabo = [];
        foreach ($vaos as $v) {
            $porCabo[(int) $v['cabo_id']][] = $v;
        }
        $x = '<Folder><name>Cabos</name>';
        foreach ($porCabo as $lista) {
            $c = $lista[0];
            $nomeCabo = trim((string) $c['cabo_nome']);
            $x .= '<Folder><name>' . self::e(($nomeCabo !== '' ? $nomeCabo . ' · ' : '') . $c['cabo_tipo']) . '</name>';
            foreach ($lista as $v) {
                $pts = json_decode((string) $v['vertices'], true) ?: [];
                $coords = [];
                foreach ($pts as $p) {
                    if (is_array($p) && isset($p[0], $p[1])) {
                        $coords[] = self::coord((float) $p[0], (float) $p[1]);
                    }
                }
                if (count($coords) < 2) {
                    continue;
                }
                $x .= '<Placemark><name>' . self::e($nomeCabo !== '' ? $nomeCabo : 'Cabo ' . $c['cabo_tipo']) . '</name>'
                    . '<description>' . self::e($v['cabo_tipo'] . ' · ' . $v['caixa_ini'] . ' → ' . $v['caixa_fim']
                                               . ' · ' . round((float) $v['comprimento_geo']) . ' m') . '</description>'
                    . '<Style><LineStyle><color>' . self::corKml((string) $v['cor_rota'])
                    . '</color><width>4</width></LineStyle></Style>'
                    . self::extendido(['ftth_cabo_tipo' => $v['cabo_tipo'], 'ftth_cabo' => $nomeCabo,
                                       'ftth_cor' => strtoupper((string) $v['cor_rota'])])
                    . '<LineString><tessellate>1</tessellate><coordinates>' . implode(' ', $coords)
                    . '</coordinates></LineString></Placemark>';
            }
            $x .= '</Folder>';
        }
        return $x . '</Folder>';
    }

    private static function extendido(array $dados): string
    {
        $x = '<ExtendedData>';
        foreach ($dados as $nome => $valor) {
            $x .= '<Data name="' . self::e($nome) . '"><value>' . self::e((string) $valor) . '</value></Data>';
        }
        return $x . '</ExtendedData>';
    }

    /** #RRGGBB -> aabbggrr do KML (opaco). */
    private static function corKml(string $hex): string
    {
        if (!preg_match('/^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $hex, $m)) {
            return 'ff00e676';
        }
        return strtolower('ff' . $m[3] . $m[2] . $m[1]);
    }

    private static function coord(float $lat, float $lng): string
    {
        return round($lng, 7) . ',' . round($lat, 7) . ',0';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function semAcento(string $s): string
    {
        return strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e',
                          'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
                          'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E',
                          'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C']);
    }

    private static function marcas(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
