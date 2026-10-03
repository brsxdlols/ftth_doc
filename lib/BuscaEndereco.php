<?php
/** Busca explícita, com cache e limite global de uma consulta por segundo ao Nominatim. */
final class BuscaEndereco
{
    private static function consultar(string $url): array
    {
        // Alguns MK-AUTH usam PHP estático sem caminho de certificados configurado.
        $certificado = is_readable('/etc/ssl/certs/ca-certificates.crt')
            ? '/etc/ssl/certs/ca-certificates.crt' : null;
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'FTTH-Doc-VisualNet/0.9.8 (+https://github.com/brsxdlols/ftth_doc)',
                CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            if ($certificado) curl_setopt($curl, CURLOPT_CAINFO, $certificado);
            $resposta = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($resposta === false || $status !== 200) {
                throw new RuntimeException('Serviço de endereço indisponível. Tente novamente.');
            }
            $dados = json_decode($resposta, true);
            if (!is_array($dados)) throw new RuntimeException('Resposta inválida do serviço de endereço.');
            return $dados;
        }
        $contexto = stream_context_create(['http' => [
            'timeout' => 12, 'ignore_errors' => false,
            'header' => "User-Agent: FTTH-Doc-VisualNet/0.9.8 (+https://github.com/brsxdlols/ftth_doc)\r\nAccept: application/json\r\n",
        ], 'ssl' => $certificado ? ['cafile' => $certificado] : []]);
        $resposta = @file_get_contents($url, false, $contexto);
        if ($resposta === false) throw new RuntimeException('Serviço de endereço indisponível. Tente novamente.');
        $dados = json_decode($resposta, true);
        if (!is_array($dados)) throw new RuntimeException('Resposta inválida do serviço de endereço.');
        return $dados;
    }

    public static function buscar(string $termo): array
    {
        $termo = trim($termo);
        if (mb_strlen($termo) < 3 || mb_strlen($termo) > 200) {
            throw new InvalidArgumentException('Informe um endereço ou CEP válido.');
        }
        $diretorio = sys_get_temp_dir() . '/ftth_doc_enderecos';
        if (!is_dir($diretorio) && !@mkdir($diretorio, 0700, true) && !is_dir($diretorio)) {
            throw new RuntimeException('Não foi possível preparar o cache de endereços.');
        }
        $arquivo = $diretorio . '/' . hash('sha256', $termo) . '.json';
        if (is_file($arquivo) && filemtime($arquivo) > time() - 86400) {
            return json_decode((string) file_get_contents($arquivo), true) ?: [];
        }
        $endereco = null;
        if (preg_match('/^\d{5}-?\d{3}$/', $termo)) {
            $endereco = self::consultar('https://viacep.com.br/ws/' . str_replace('-', '', $termo) . '/json/');
            if (!empty($endereco['erro'])) throw new InvalidArgumentException('CEP não encontrado.');
            $termo = implode(', ', array_filter([$endereco['logradouro'] ?? '', $endereco['bairro'] ?? '',
                $endereco['localidade'] ?? '', $endereco['uf'] ?? '', 'Brasil']));
        }
        $lock = fopen($diretorio . '/nominatim.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            throw new RuntimeException('Outra busca está em andamento. Aguarde e tente novamente.');
        }
        try {
            $anterior = (float) stream_get_contents($lock);
            if (microtime(true) - $anterior < 1.1) {
                throw new RuntimeException('Aguarde um segundo antes de buscar novamente.');
            }
            rewind($lock); ftruncate($lock, 0); fwrite($lock, (string) microtime(true)); fflush($lock);
            $dados = self::consultar('https://nominatim.openstreetmap.org/search?' . http_build_query([
                'format' => 'jsonv2', 'q' => $termo, 'countrycodes' => 'br', 'limit' => 5,
                'accept-language' => 'pt-BR',
            ]));
            $saida = ['endereco' => $endereco, 'resultados' => array_map(static function ($item) {
                return ['lat' => (float) $item['lat'], 'lng' => (float) $item['lon'],
                    'rotulo' => (string) $item['display_name']];
            }, $dados)];
            file_put_contents($arquivo, json_encode($saida), LOCK_EX);
            return $saida;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
