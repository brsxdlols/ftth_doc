# FTTH Doc Visual Net — documentação da rede óptica dentro do MK-AUTH

Correção 0.9.13: imagens do mapa ficam contidas na área do mapa, abaixo da ficha
do ponto. O satélite consulta a cobertura antes de carregar tiles e usa a imagem
disponível do nível anterior. A transição translúcida dos tiles foi removida.

Correção 0.9.12: satélite usa a imagem do nível anterior quando falta cobertura no zoom
solicitado, em vez de exibir a tela cinza da Esri. A aproximação e as coordenadas dos
pontos são mantidas; o detalhamento da imagem depende da cobertura disponível.

## Novidades da 0.9.11

Digite para receber sugestões de endereços via Photon após uma pausa curta (800 ms).
Pressione **Enter** ou clique em **Endereço / CEP** para pesquisar o endereço completo ou CEP.
Resultados de pontos da rede e endereços aparecem na mesma lista. Use as setas e Enter
para escolher um resultado, ou clique nele para aproximar o mapa.

No provedor gratuito, os botões **Mapa / Satélite**, no canto superior direito, alternam
entre OpenStreetMap e Esri World Imagery. A escolha fica salva no navegador; os pontos
e cabos continuam visíveis. Atribuições dos fornecedores aparecem no mapa.
As sugestões Photon usam cache e limite de consultas. Nominatim é usado somente na
pesquisa explícita, nunca como autocomplete. Os serviços públicos têm limites e sua
disponibilidade e cobertura dependem dos fornecedores.

Derivado do [FTTH Doc de Marcelo Silvestro](https://github.com/marcelosilvestro/ftth_doc), sob licença MIT.

## Versão Visual Net 0.9.8

Correção 0.9.9: cache de endereços na pasta de dados do addon e bloqueio de consultas pelo
MySQL, compatível com o usuário do painel e as restrições do AppArmor no MK-AUTH.
Mensagens de CEP inválido e serviços indisponíveis aparecem diretamente na tela.

- OpenStreetMap com Leaflet 1.9.4, gratuito e sem chave, como padrão quando não existe chave Google.
- Google Maps continua disponível em **Ajustes → Provedor do mapa**; instalações com chave conservam o Google.
- Marcadores, traçado de cabos, edição de vértices, seleção por área e camadas usam os mesmos cadastros.
- Digite um endereço com cidade ou CEP: sugestões aparecem automaticamente; Enter ou **Endereço / CEP** pesquisa explicitamente.
- ViaCEP retorna o endereço postal; Nominatim procura coordenadas aproximadas. Confirme o ponto antes de cadastrar uma caixa.
- A busca externa ocorre somente ao clicar, com cache de 24 horas e limite global de consultas.
- No provedor gratuito, **Mapa** usa OpenStreetMap e **Satélite** usa Esri. Híbrido, relevo e Street View permanecem disponíveis no Google.
- Os mapas públicos OSM têm [política de utilização](https://operations.osmfoundation.org/policies/tiles/) e disponibilidade sem garantia. Sem downloads em massa ou mapas offline.

Instale ou atualize usando o repositório Visual Net:

```bash
wget -O - https://raw.githubusercontent.com/brsxdlols/ftth_doc/main/instalar.sh | bash
```

O instalador mantém o backup original. Não use o instalador do repositório upstream para atualizar esta variante.

### Validação da 0.9.8

Sintaxe PHP 8.0 e shell validada; sete testes específicos de provedor em banco separado;
marcadores, polígonos e edição de vértices verificados no navegador. A suíte original completa
depende de `tests/dados/AsBuilt_Palmital.kmz`, que não está publicado no upstream.

## Documentação original

Addon para MK-AUTH que documenta a planta FTTH: caixas e cabos no mapa, importação do KMZ do
projeto, diagrama de emendas de cada CEO/CTO, POP com OLT e DIO, e cálculo de potência do sinal
que chega em cada cliente.

> **Versão 0.9.7 — beta.** Está rodando em produção no provedor que o desenvolve, mas é a
> primeira versão publicada. Instale primeiro num servidor de teste.

## Instalação

No terminal do servidor MK-AUTH, como root:

```bash
wget -O - https://raw.githubusercontent.com/marcelosilvestro/ftth_doc/main/instalar.sh | bash
```

O script faz tudo: confere o ambiente, baixa a última versão, cria as tabelas, ajusta
permissões e coloca o link no menu do painel. O mesmo comando também **atualiza** — rode de
novo quando sair uma versão nova, que ele compara e só troca o que precisa.

Depois de instalar, entre no painel: **PROVEDOR → Documentação FTTH**.

### O que você precisa ter

- MK-AUTH instalado (o script confere `/opt/mk-auth`)
- PHP 8.0 ou superior com `pdo_mysql`, `zip`, `dom`, `simplexml` e `mbstring`
- Para Google Maps, uma **chave da Maps JavaScript API**, cadastrada em Ajustes e restrita
  ao domínio do painel. OpenStreetMap funciona sem chave.

### Se o seu MySQL não usa a senha padrão

O instalador descobre sozinho na maioria dos servidores. Se não conseguir, ele pergunta — ou
você já entrega:

```bash
wget -O - https://raw.githubusercontent.com/marcelosilvestro/ftth_doc/main/instalar.sh \
  | FTTH_DB_PASS='sua-senha' bash
```

### Outras opções

```bash
# só conferir uma instalação existente, sem mexer em nada
wget -O - .../instalar.sh | bash -s -- --diagnostico

# instalar uma versão específica
wget -O - .../instalar.sh | bash -s -- --versao=v0.9.6

# remover as tabelas que o addon aposentou
wget -O - .../instalar.sh | bash -s -- --limpar

# instalar a partir de um pacote local (sem GitHub), gerado pelo empacotar.sh
bash instalar.sh --pacote=/root/ftth_doc-0.9.6.tar.gz
```

## Exportar KMZ, seleção por camada e cores do DIO (0.9.7)

- **Exportar KMZ.** Na aba **Ajustes → Exportar KMZ**, escolha os tipos (CTO, CEO, DC/POP,
  postes, cabos...) da região aberta ou de todas. No modo **Selecionar**, o botão **Exportar**
  baixa só o que está selecionado. O arquivo abre no Google Earth com as cores do cadastro e,
  reimportado no addon, volta com os mesmos tipos, cores e capacidades de cabo.
- **Selecionar obedece às camadas.** O que está escondido no mapa não entra na seleção, nem na
  Cor nem no Excluir. Com a camada **Cabos** ligada, o cabo que passa pela área entra inteiro:
  dá para pintar ou excluir só cabos.
- **Interligar cabos de capacidades diferentes.** No diagrama, um 72 FO que já entregou
  Fo01–Fo12 continua do Fo13 no próximo cabo. Cabos iguais continuam casando número com número.
- **DIO do POP.** O cartão da porta e o seletor de saída mostram a cor do tubo e da fibra.
- **Camadas** com Marcar/Desmarcar todos, e um aviso quando você abre um ponto cuja camada está
  desligada.

## Ponta livre, seleção por área e postes (0.9.6)

- **Cabo sem caixa na ponta.** O cabo pode começar ou terminar no vazio: ali nasce uma *ponta
  livre* (bolinha branca na cor do cabo). Para ancorar, solte um ponto em cima dela, arraste-a
  até uma caixa no modo **Mover** (o addon pergunta *Ancorar?*) ou use **Virar ponto** na
  ficha dela. **Continuar cabo** segue desenhando o mesmo cabo, com a mesma capacidade e cor —
  trocar de capacidade exige uma CEO.
- **Selecionar.** Desenhe uma área no mapa e exclua ou pinte de uma vez tudo o que ficou dentro:
  pontos, cabos (o que liga um ponto de dentro a um de fora sai inteiro até ele) e as fusões
  que usavam essas fibras. Cliente ligado aparece na prévia antes de confirmar. DC/POP nunca
  entra no lote.
- **Postes.** Tipo próprio, com ícone. O cabo **passa** pelo poste: clicado no traçado, ele vira
  um vértice, não uma emenda. Filtro *Postes* na aba Pontos e camada própria.
- **Importação.** Na quarentena, **Tipo** e **Cor** mudam por linha ou em lote, e o envio aceita
  *Tipo dos pontos* e *Cor dos pontos* para arquivos que não dizem o que são (o de postes da
  concessionária, por exemplo). Importados e descartados podem voltar para pendentes.
- **Nome livre de novo.** O nome de um ponto excluído pode ser usado outra vez, e a sugestão de
  nome preenche o primeiro número livre da série.
- **Camadas** ficam gravadas por usuário: o que você desliga continua desligado no próximo acesso.

## Emendar uma caixa num cabo já lançado

O cabo quase sempre é lançado antes de as caixas serem documentadas, e num rompimento entram
duas caixas de emenda no meio de um lance existente. Para isso não é preciso apagar e
redesenhar o cabo: **solte a caixa em cima dele**.

- No modo **Caixa**, clique sobre o traçado: o addon pergunta se você quer emendar antes de
  abrir o cadastro da caixa.
- No modo **Mover**, arraste uma caixa existente para cima do cabo: a pergunta vem depois do
  Concluir.

Confirmando, o cabo é cortado em dois trechos que passam a chegar na caixa, ela encosta no
traçado e todas as fibras atravessam como **passagem** (perda zero) — o sinal dos clientes
continua batendo no mesmo instante. No diagrama da caixa você troca por fusão ou sangria o que
precisar; ele já abre com o cabo que vem do POP à esquerda e o que segue para a rua à direita.

O caminho de volta existe: ao **excluir** uma caixa que só faz essa emenda, o addon avisa que
vai juntar os dois trechos num lance só e faz isso — preservando o que já estava fundido nas
pontas. Caixa com splitter, DIO ou fusão cruzada não entra nessa conta, porque aí ela tem
função de verdade.

A distância em que o mapa considera que a caixa caiu "em cima" do cabo é ajustável em
*Configurações → Raio de emenda*, e começa em 10 m.

## Como começar a usar

1. **Ajustes** → escolha OpenStreetMap ou cadastre a chave do Google Maps.
2. **Regiões** → crie a primeira região (uma cidade ou bairro), com as coordenadas do centro.
3. **Importar KMZ** → envie o as-built do seu projeto. Nada entra na rede direto: tudo fica em
   *quarentena* para você conferir item por item antes de aprovar.
4. No mapa, abra uma caixa e clique em **Diagrama** para montar as emendas e os splitters.
5. **POP / Data Center** → cadastre a OLT e o DIO; a partir daí o addon calcula o sinal
   estimado em cada porta de cliente.

## O que o addon escreve no seu banco

Só tabelas próprias, todas com o prefixo `tab_ftth_`. As tabelas nativas do MK-AUTH são lidas,
nunca alteradas — com **duas exceções**, que vêm **desligadas** e só ligam por SQL:

| Chave em `tab_ftth_config` | O que passa a fazer quando ligada |
|---|---|
| `sync_sis_cliente` | grava `caixa_herm` e `porta_splitter` em `sis_cliente` |
| `sync_cto_nativa` | espelha as CTOs documentadas na tabela nativa `cto` |

### Convive com o HelpFiber, mas não depende dele

Se o servidor tiver o addon **HelpFiber** instalado, o FTTH Doc oferece vincular cada OLT ao
cadastro que já existe lá, e pode espelhar as CTOs documentadas na tabela `cto` (desligado por
padrão). Sem o HelpFiber, nada disso aparece e você cadastra as OLTs no próprio addon, na tela
POP / Data Center.

Toda alteração feita pelo addon fica registrada em `tab_ftth_historico`: quem, quando, o que
era antes e o que virou.

## Segurança

O MK-AUTH sai de fábrica com uma senha conhecida no MySQL. Se o seu servidor ainda usa essa
senha, **troque** — o diagnóstico do addon avisa quando esse é o caso. Depois de trocar, ajuste
`/opt/mk-auth/conf/ftth_doc.php`, que é onde o addon guarda o acesso ao banco (640, root:www-data,
fora do diretório servido pelo Apache).

## Suporte e problemas

Abra uma *issue* neste repositório com a saída de:

```bash
php /opt/mk-auth/admin/addons/ftth_doc/cli/diagnostico.php
```

Ela mostra versão, estado do banco, permissões e o que está faltando — sem expor senha nenhuma.
