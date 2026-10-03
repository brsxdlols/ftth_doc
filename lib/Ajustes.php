<?php
/**
 * ftth_doc :: ajustes do addon e estado do banco.
 *
 * Mora aqui, e não numa tela, desde que os ajustes foram para a aba Ajustes do painel do
 * mapa (24/09/2026). A mesma regra serve ao painel e ao aviso de primeira instalação, que
 * pede a chave do Google antes de existir mapa.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Schema.php';

final class Ajustes
{
    public const TIPOS_MAPA = ['hybrid' => 'Híbrido', 'satellite' => 'Satélite',
                               'roadmap' => 'Ruas', 'terrain' => 'Relevo'];

    /**
     * Camadas do mapa, na ordem da aba Camadas. Ao contrário dos ajustes acima, valem POR
     * USUÁRIO do painel e ficam no banco — sobrevivem a fechar a página, a logoff e a trocar
     * de computador (30/09/2026). POSTES já está aqui para o cadastro de postes que vem a seguir.
     */
    public const CAMADAS = ['CTO', 'CEO', 'DC', 'PREDIO', 'PROBLEMA', 'RESERVA', 'POSTES',
                            'CABOS', 'QUARENTENA'];

    /** Instalações com chave conservam o Google; novas instalações usam OSM. */
    public static function provedor(): string
    {
        $padrao = trim((string) Config::get('google_maps_key', '')) !== '' ? 'google' : 'osm';
        $valor = (string) Config::get('mapa_provedor', $padrao);
        return in_array($valor, ['osm', 'google'], true) ? $valor : $padrao;
    }

    /** @return array<string,bool> todas ligadas, menos as que o usuário desligou */
    public static function camadas(string $usuario): array
    {
        $gravado = json_decode((string) Config::get(self::chaveCamadas($usuario), ''), true);
        $saida = [];
        foreach (self::CAMADAS as $c) {
            $saida[$c] = !is_array($gravado) || !array_key_exists($c, $gravado) || (bool) $gravado[$c];
        }
        return $saida;
    }

    /** Grava o que veio da tela, só com as camadas conhecidas. */
    public static function salvarCamadas(array $camadas, string $usuario): array
    {
        $limpo = [];
        foreach (self::CAMADAS as $c) {
            $limpo[$c] = !array_key_exists($c, $camadas) || filter_var($camadas[$c], FILTER_VALIDATE_BOOLEAN);
        }
        Config::set(self::chaveCamadas($usuario), json_encode($limpo), $usuario);
        return $limpo;
    }

    /** `camadas:<login>` — login longo vira hash, para caber na chave de 64. */
    private static function chaveCamadas(string $usuario): string
    {
        return 'camadas:' . (strlen($usuario) > 55 ? md5($usuario) : $usuario);
    }

    /** O que a aba mostra hoje. */
    public static function valores(): array
    {
        return [
            'mapa_provedor'      => self::provedor(),
            'google_maps_key'    => (string) Config::get('google_maps_key', ''),
            'mapa_tipo'          => (string) Config::get('mapa_tipo', 'hybrid'),
            'mapa_rotulo_zoom'   => (int) Config::num('mapa_rotulo_zoom', 17),
            'raio_quebra_cabo_m' => (int) Config::num('raio_quebra_cabo_m', 10),
        ];
    }

    /**
     * Grava só as chaves que a tela oferece. Uma lista fixa evita que um POST forjado escreva
     * qualquer coisa em tab_ftth_config — inclusive os interruptores de escrita em tabela
     * nativa, que continuam só por SQL, de propósito.
     */
    public static function salvar(array $post, string $usuario): array
    {
        $salvas = [];

        if (isset($post['mapa_provedor'])) {
            if (!in_array($post['mapa_provedor'], ['osm', 'google'], true)) {
                throw new InvalidArgumentException('Provedor de mapa inválido.');
            }
            Config::set('mapa_provedor', (string) $post['mapa_provedor'], $usuario);
            $salvas[] = 'mapa_provedor';
        }

        if (isset($post['google_maps_key'])) {
            Config::set('google_maps_key', trim((string) $post['google_maps_key']), $usuario);
            $salvas[] = 'google_maps_key';
        }
        if (isset($post['mapa_tipo'])) {
            $tipo = isset(self::TIPOS_MAPA[$post['mapa_tipo']]) ? (string) $post['mapa_tipo'] : 'hybrid';
            Config::set('mapa_tipo', $tipo, $usuario);
            $salvas[] = 'mapa_tipo';
        }
        if (isset($post['mapa_rotulo_zoom'])) {
            $zoom = max(3, min(21, (int) $post['mapa_rotulo_zoom']));
            Config::set('mapa_rotulo_zoom', (string) $zoom, $usuario);
            $salvas[] = 'mapa_rotulo_zoom';
        }
        if (isset($post['raio_quebra_cabo_m'])) {
            $raio = max(1, min(100, (int) $post['raio_quebra_cabo_m']));
            Config::set('raio_quebra_cabo_m', (string) $raio, $usuario);
            $salvas[] = 'raio_quebra_cabo_m';
        }

        return ['salvas' => $salvas] + self::valores();
    }

    /** Estado do banco e do addon, no formato curto do rodapé da aba. */
    public static function estado(): array
    {
        $schema = new Schema(__DIR__ . '/../sql');
        $e = $schema->estado();

        $manifest = json_decode((string) @file_get_contents(__DIR__ . '/../manifest.json'), true) ?: [];
        $baseline = $e['ledger']['baseline'] ?? null;

        return [
            'completo'    => empty($e['faltando']),
            'tabelas'     => count($e['tabelas'] ?? []),
            'tabelas_de'  => count(Schema::TABELAS),
            'schema'      => $baseline ? (string) $baseline['versao'] : null,
            'schema_em'   => $baseline ? substr((string) $baseline['executed_at'], 0, 16) : null,
            'aposentadas' => count($e['aposentadas'] ?? []),
            'addon'       => (string) ($manifest['version'] ?? ''),
            'instalador'  => 'wget -O - ' . FTTH_URL_INSTALADOR . ' | bash',
            'ultima_url'  => FTTH_URL_ULTIMA_VERSAO,
        ];
    }
}
