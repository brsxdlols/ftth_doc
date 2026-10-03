<?php
/**
 * ftth_doc :: primeiros passos — o assistente de quem acabou de instalar o addon.
 *
 * O progresso sai do próprio banco, nunca de uma marca "passo 3 concluído": instalação que já
 * tem rede não vê o assistente, e apagar a única região faz ele voltar no passo certo. A única
 * coisa gravada é a escolha de "pular por agora" (`onboarding` em tab_ftth_config).
 *
 * A ordem é a da rede de verdade: sem chave não há mapa, sem região não há onde marcar, o POP
 * vem antes de tudo porque é dele que as fibras saem, e a primeira caixa da rua vem antes do
 * primeiro cabo para ele ter onde chegar. Desde a 0.9.6 o cabo pode terminar numa ponta livre,
 * então o passo 5 só conta quando o traçado dos cabos LIGA o POP a uma CTO/CEO — um cabo
 * qualquer no mapa não basta (30/09/2026).
 *
 * Chave aceita pelo Google é coisa que só o navegador descobre (gm_authFailure): aqui o passo 1
 * conta como feito quando existe chave, e a tela desfaz isso se o Google recusar.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Ajustes.php';

final class PrimeirosPassos
{
    public const PASSOS = ['chave', 'regiao', 'pop', 'caixa', 'cabo'];

    public static function estado(): array
    {
        $chave = Ajustes::provedor() === 'osm' || trim((string) Config::get('google_maps_key', '')) !== '';

        $regioes = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_regiao WHERE excluido_em IS NULL');

        $pop = Db::um(
            'SELECT id, nome, regiao_id, lat, lng FROM tab_ftth_caixa
              WHERE tipo = "DC" AND excluido_em IS NULL ORDER BY id LIMIT 1'
        );

        // A primeira caixa da rua: CEO ou CTO — provedor pequeno sai do POP direto numa CTO.
        $caixa = Db::um(
            'SELECT id, nome, tipo, regiao_id, lat, lng FROM tab_ftth_caixa
              WHERE tipo IN ("CEO", "CTO") AND excluido_em IS NULL ORDER BY id LIMIT 1'
        );

        $cabos = (int) Db::valor('SELECT COUNT(*) FROM tab_ftth_cabo WHERE excluido_em IS NULL');
        $ligado = $cabos > 0 && self::popChegaEmCaixa();

        $feitos = [
            'chave'  => $chave,
            'regiao' => $regioes > 0,
            'pop'    => $pop !== null,
            'caixa'  => $caixa !== null,
            'cabo'   => $ligado,
        ];

        $atual = null;
        foreach (self::PASSOS as $p) {
            if (!$feitos[$p]) { $atual = $p; break; }
        }

        return [
            'feitos'  => $feitos,
            'atual'   => $atual,                      // null = tudo pronto
            'total'   => count(self::PASSOS),
            'prontos' => count(array_filter($feitos)),
            'pulado'  => (string) Config::get('onboarding', '') === 'pulado',
            'pop'     => $pop ? self::ponto($pop) : null,
            'caixa'   => $caixa ? self::ponto($caixa) : null,
            // Há cabo, mas ele ainda não chega do POP a uma caixa (terminou numa ponta livre).
            'cabo_solto' => $cabos > 0 && !$ligado,
        ];
    }

    /** "Pular por agora" esconde o assistente nesta instalação; a pílula da barra o traz de volta. */
    public static function pular(bool $pular, string $usuario): array
    {
        Config::set('onboarding', $pular ? 'pulado' : '', $usuario);
        return self::estado();
    }

    /**
     * O traçado dos cabos liga algum POP a alguma CTO/CEO? Busca em largura pelo grafo dos
     * vãos ativos (caixa — vão — caixa). A rede de um provedor pequeno tem centenas de vãos:
     * cabe na memória sem esforço.
     */
    private static function popChegaEmCaixa(): bool
    {
        $tipos = [];
        foreach (Db::todos('SELECT id, tipo FROM tab_ftth_caixa WHERE excluido_em IS NULL') as $c) {
            $tipos[(int) $c['id']] = $c['tipo'];
        }
        $viz = [];
        foreach (Db::todos('SELECT v.caixa_ini_id, v.caixa_fim_id FROM tab_ftth_cabo_vao v
                              JOIN tab_ftth_cabo c ON c.id = v.cabo_id
                             WHERE v.excluido_em IS NULL AND c.excluido_em IS NULL') as $v) {
            $a = (int) $v['caixa_ini_id'];
            $b = (int) $v['caixa_fim_id'];
            $viz[$a][] = $b;
            $viz[$b][] = $a;
        }
        $fila = array_keys(array_filter($tipos, static fn($t) => $t === 'DC'));
        $visto = array_fill_keys($fila, true);
        while ($fila) {
            $id = array_shift($fila);
            if (in_array($tipos[$id] ?? '', ['CTO', 'CEO', 'CTO_AP'], true)) {
                return true;
            }
            foreach ($viz[$id] ?? [] as $prox) {
                if (!isset($visto[$prox]) && isset($tipos[$prox])) {
                    $visto[$prox] = true;
                    $fila[] = $prox;
                }
            }
        }
        return false;
    }

    private static function ponto(array $c): array
    {
        return [
            'id'        => (int) $c['id'],
            'nome'      => (string) $c['nome'],
            'tipo'      => (string) ($c['tipo'] ?? 'DC'),
            'regiao_id' => (int) $c['regiao_id'],
            'lat'       => (float) $c['lat'],
            'lng'       => (float) $c['lng'],
        ];
    }
}
