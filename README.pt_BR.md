# Plugin Health Check para GLPI

[![Licença](https://img.shields.io/badge/Licen%C3%A7a-GPLv3+-blue.svg)](LICENSE)
[![GLPI](https://img.shields.io/badge/GLPI-10.0.x-brightgreen.svg)](https://glpi-project.org)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4.svg)](https://www.php.net)

[English](README.md) | **Português (Brasil)**

O Health Check é um plugin gratuito e open source para **diagnóstico, monitoramento e prevenção** de problemas operacionais, técnicos e de segurança em instalações GLPI.

Ele analisa a instalação, identifica configurações inadequadas, falhas e riscos operacionais e explica cada problema com orientações claras de correção. O plugin é **exclusivamente consultivo**: nunca altera arquivos, banco de dados ou configurações do GLPI. As únicas escritas são o próprio histórico de diagnósticos e a sua configuração.

## Sumário

- [Funcionalidades](#funcionalidades)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Uso](#uso)
- [Configuração](#configuração)
- [Privacidade e funcionamento offline](#privacidade-e-funcionamento-offline)
- [Estendendo o plugin](#estendendo-o-plugin)
- [Limitações conhecidas](#limitações-conhecidas)
- [Contribuindo](#contribuindo)
- [Licença](#licença)

## Funcionalidades

### Painel

Disponível em **Configurar > Health Check**:

- indicador de saúde (0–100) e situação geral: verde (saudável), amarelo (atenção necessária) ou vermelho (problema crítico);
- contadores de verificações aprovadas, alertas, críticas, informativas e não verificadas;
- atalho **Comece por aqui** para o problema mais grave;
- **mapa das verificações**: uma célula por verificação, agrupadas por categoria, mostrando onde os problemas se concentram;
- problemas ordenados por criticidade, cada um expansível;
- evolução do indicador e comparação com o diagnóstico anterior;
- botões para executar diagnóstico completo ou leve e exportar o relatório.

Cada resultado traz: nome da verificação, status, criticidade, descrição, impacto operacional, possível causa, recomendação, passo a passo, link para a documentação oficial do GLPI quando disponível e os dados que embasaram a conclusão.

### Verificações

| Área | O que é verificado |
|---|---|
| **Atualização e requisitos** | Versão do GLPI e versões mais recentes (consulta opcional), versão do PHP e fim do suporte de segurança, requisitos do instalador (extensões, memória, fusos horários…), versão do esquema do banco, migrações estruturais pendentes (InnoDB, utf8mb4, timestamps, chaves sem sinal, formato de linha) |
| **Banco de dados** | Conexão e latência, versão, compatibilidade e fim de suporte do MySQL/MariaDB, configurações críticas (`max_allowed_packet`, charset, diferença de relógio PHP × banco…), tabelas corrompidas, tabelas sem chave primária, AUTO_INCREMENT perto do limite, integridade do esquema e índices esperados |
| **Ações automáticas (cron)** | Funcionamento e última execução do cron, modo GLPI × CLI, tarefas essenciais e de limpeza desativadas, tarefas atrasadas ou nunca executadas, travadas e com falhas recorrentes |
| **E-mail** | Configuração de envio, fila de notificações (pendentes, em retentativa, rejeitadas definitivamente), falhas de envio/autenticação no `mail-error.log`, coletores (erros, última coleta, SSL), e-mails não importados, conectividade TCP com SMTP/IMAP |
| **Diretórios e permissões** | Existência, leitura e escrita dos diretórios de documentos, cache, sessões, plugins, marketplace, logs e configuração; diretórios graváveis por todos e segredos legíveis por todos (Linux/Unix) |
| **Plugins** | Plugins instalados com versão e status, estados anormais, compatibilidade declarada com o GLPI/PHP atuais e com a próxima versão principal do GLPI, atualizações do Marketplace (consulta opcional) |
| **Segurança** | HTTPS e URL da aplicação, contas padrão com senha padrão, raiz do servidor web e exposição de `files/` e `config/`, arquivos sensíveis esquecidos, cookies de sessão, configurações inseguras (`display_errors`, modo debug…), política de senhas, acesso administrativo, exposição da API REST |
| **Desempenho** | Espaço em disco, memória, OPcache e limites do PHP, cache do GLPI, volume de logs e erros recentes, volume e fragmentação do banco, indicadores do servidor de banco, ações automáticas lentas |
| **Integridade operacional** | Registros órfãos, usuários duplicados e e-mails compartilhados, usuários sem autorização, entidades inconsistentes, ativos sem responsável, regras incompletas, documentos sem arquivo |

### Status e indicador

| Status | Significado |
|---|---|
| Aprovado | Verificação OK |
| Alerta | Atenção necessária (criticidade baixa, média ou alta) |
| Crítico | Problema crítico (criticidade alta ou crítica) |
| Informativo | Informação útil, não é um problema |
| Não verificado | A verificação não pôde ser feita (contexto CLI, recurso indisponível, opção desativada). O plugin informa em vez de supor |
| Erro na verificação | A verificação falhou inesperadamente; as demais não são afetadas |

O indicador parte de 100 e perde pontos a cada problema, conforme a criticidade. A situação geral fica vermelha quando há pelo menos um problema crítico (ou indicador abaixo de 50), amarela quando há alertas (ou abaixo de 85) e verde nos demais casos.

### Diagnóstico leve e completo

- **Leve**: somente verificações rápidas. Executado automaticamente **todos os dias** (ação automática `HealthcheckLight`).
- **Completo**: inclui comparação do esquema do banco, verificação de índices, varreduras de integridade e testes de conexão de e-mail. Executado **toda semana** entre 01h e 06h (ação automática `HealthcheckFull`) e sob demanda pelo painel.

### Histórico, comparação e exportação

- Cada execução é registrada com data, tipo, origem, indicador e contadores, respeitando a retenção configurada (padrão: 180 dias / 500 execuções).
- **Histórico > Comparar selecionados** mostra, verificação por verificação, novos problemas, problemas resolvidos, pioras e melhoras.
- Qualquer execução pode ser exportada em **PDF** (relatório completo), **JSON** (dados estruturados para integração) ou **CSV** (planilha, separador `;`, UTF-8).

## Requisitos

- GLPI **>= 10.0.0** e **< 10.0.99**
- PHP **>= 7.4** (dentro da faixa suportada pela sua versão do GLPI)
- Cron do sistema configurado para o GLPI, para os diagnósticos agendados

## Instalação

### A partir de uma release

1. Baixe o arquivo mais recente na [página de releases](https://github.com/JoaoPauloSCosta/glpi-health-check/releases).
2. Extraia no diretório `plugins/` (ou `marketplace/`) do GLPI. O diretório **deve** se chamar `healthcheck`.
3. Acesse **Configurar > Plugins**, instale e ative o **Health Check**.

### A partir do código-fonte

```sh
cd /caminho/do/glpi/plugins
git clone https://github.com/JoaoPauloSCosta/glpi-health-check.git healthcheck
```

Depois instale e ative em **Configurar > Plugins**, ou pelo console do GLPI:

```sh
php bin/console plugin:install healthcheck --username=glpi
php bin/console plugin:activate healthcheck
```

O plugin não tem dependências externas: não é preciso executar `composer install` nem `npm install`.

### Desinstalação

A desinstalação em **Configurar > Plugins** remove as tabelas, a configuração e as ações automáticas do plugin.

## Uso

1. Acesse **Configurar > Health Check**.
2. Clique em **Executar diagnóstico completo**.
3. Comece pelo problema indicado em **Comece por aqui** e siga a lista ordenada por prioridade.

### Direitos

| Ação | Direito necessário |
|---|---|
| Ver painel, histórico e exportações | *Configurar > Geral* — leitura |
| Executar diagnósticos, alterar configurações, remover histórico | *Configurar > Geral* — atualização |

### Diagnósticos agendados

Frequência, faixa de horário e modo de execução de `HealthcheckLight` e `HealthcheckFull` são gerenciados em **Configurar > Ações automáticas**. As duas são registradas em modo CLI, então o cron do sistema precisa chamar o GLPI:

```sh
* * * * * php /caminho/do/glpi/front/cron.php
```

Algumas verificações dependem do contexto web (raiz do servidor web, cookies de sessão, OPcache do servidor web) e aparecem como *Não verificado* quando o diagnóstico roda pelo cron. Execute um diagnóstico manual pelo navegador para avaliá-las.

## Configuração

Disponível em **Configurar > Health Check > Configurações**:

| Configuração | Padrão |
|---|---|
| Permitir consultas externas opcionais | Desativado |
| Testar conexão com os servidores de e-mail configurados (somente diagnóstico completo) | Ativado |
| Limite de alerta / crítico de espaço livre em disco | 15% / 5% |
| Limite de alerta / crítico de notificações pendentes | 100 / 1000 |
| Retenção do histórico | 180 dias, 500 execuções |

## Privacidade e funcionamento offline

- Todas as verificações locais funcionam **sem internet**.
- Nenhum dado do ambiente, credencial, usuário ou chamado é enviado a terceiros.
- **Consultas externas são opcionais e vêm desativadas.** Quando habilitadas, fazem:
  - um GET anônimo da lista pública de releases do GLPI no GitHub (cache de 24 horas);
  - uma consulta ao catálogo do Marketplace do GLPI para atualizações de plugins (usa a chave GLPI Network já configurada no GLPI).

  Ambas usam o proxy configurado no GLPI.
- Sem consultas externas, a verificação de versão usa a informação gravada pela ação automática `checkupdate` do próprio GLPI, quando existir.
- O teste de conectividade de e-mail apenas abre uma conexão TCP com os servidores já configurados no GLPI: não autentica e não envia dados. Pode ser desativado.
- Relatórios exportados podem conter nomes de servidores, logins e e-mails: trate-os como documentos internos.

## Estendendo o plugin

Cada verificação é uma classe independente que estende `GlpiPlugin\Healthcheck\Check\AbstractCheck`:

```php
namespace GlpiPlugin\Meuplugin;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;

class MinhaVerificacao extends AbstractCheck
{
    public function getKey(): string      { return 'meuplugin.minha_verificacao'; }
    public function getCategory(): string { return Category::SECURITY; }
    public function getTitle(): string    { return __('Minha verificação', 'meuplugin'); }
    public function isLight(): bool       { return true; } // false = somente no diagnóstico completo

    public function run(): CheckResult
    {
        if ($problema_detectado) {
            return CheckResult::warning(__('Descrição do problema', 'meuplugin'), Severity::MEDIUM)
                ->impact('...')
                ->cause('...')
                ->recommendation('...')
                ->steps(['...', '...'])
                ->doc(self::DOC_INSTALL)
                ->facts(['Indicador' => 'valor'])
                ->items([['coluna' => 'valor']], ['coluna' => 'Cabeçalho']);
        }
        return CheckResult::ok(__('Tudo certo', 'meuplugin'));
    }
}
```

Outros plugins podem registrar suas verificações sem alterar o Health Check, na função `plugin_init_xxx()`:

```php
$PLUGIN_HOOKS['healthcheck_checks']['meuplugin'] = [\GlpiPlugin\Meuplugin\MinhaVerificacao::class];
```

Regras seguidas por todas as verificações:

- **somente leitura**: nunca alteram arquivos, banco de dados ou configuração do GLPI;
- **independentes**: nunca dependem do resultado de outra verificação;
- **sem suposições**: retornam `CheckResult::skipped()` quando não podem ser concluídas.

Cada verificação roda dentro de um `try/catch` com buffer de saída: uma exceção vira o status *Erro na verificação*, é registrada em `files/_log/php-errors.log` e não interrompe o diagnóstico.

### Estrutura do projeto

```
healthcheck/
├── setup.php, hook.php     Registro do plugin, autoload PSR-4, instalação/desinstalação
├── healthcheck.xml         Descritor para o catálogo de plugins do GLPI
├── front/                  Páginas: painel, execução, histórico, comparação, exportação, configurações
├── templates/              Templates Twig (@healthcheck/...)
├── css/                    Folha de estilo do plugin
└── src/
    ├── Check/              AbstractCheck, CheckResult, Status, Severity, Category e as verificações por área
    ├── Registry.php        Lista de verificações e hook de extensão
    ├── Runner.php          Executa cada verificação isoladamente e grava a execução
    ├── RunRepository.php   Persistência (glpi_plugin_healthcheck_runs / _results)
    ├── Score.php           Indicador de saúde e situação geral
    ├── ReportView.php      Ordenação, agrupamento e comparação de execuções
    ├── Exporter.php        Exportação em PDF (TCPDF do GLPI), JSON e CSV
    ├── Cron.php            Ações automáticas
    ├── ReleaseInfo.php     Versões do GLPI (consulta opcional / offline)
    └── Settings.php        Configuração (glpi_configs, contexto "plugin:healthcheck")
```

## Limitações conhecidas

- A interface está disponível apenas em português do Brasil por enquanto.
- Em servidores Windows, ACLs NTFS não são avaliadas; somente o acesso efetivo de leitura e escrita.
- As datas de fim de suporte do PHP e do MySQL/MariaDB vêm de uma tabela interna; versões fora dela não geram alerta de fim de suporte.
- Falhas de autenticação SMTP/IMAP são inferidas dos logs e do contador de erros dos coletores; o plugin nunca autentica.
- A consulta externa de versões exige que o PHP tenha um bundle de certificados configurado (`curl.cainfo` / `openssl.cafile`); caso contrário, a verificação informa a falha de SSL.

## Contribuindo

- Relate erros e sugestões no [rastreador de issues](https://github.com/JoaoPauloSCosta/glpi-health-check/issues).
- Pull requests são bem-vindos. Siga os [padrões de código do GLPI](https://glpi-developer-documentation.readthedocs.io/en/master/codingstandards.html) e mantenha as verificações somente leitura.
- Traduções: os textos usam o domínio gettext `healthcheck`.

Veja também a [documentação de desenvolvimento de plugins do GLPI](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/index.html).

## Licença

Copyright (C) 2026 João Paulo S. Costa

Este programa é software livre: você pode redistribuí-lo e/ou modificá-lo sob os termos da Licença Pública Geral GNU, conforme publicada pela Free Software Foundation, na versão 3 da Licença ou (a seu critério) qualquer versão posterior. Veja [LICENSE](LICENSE).
