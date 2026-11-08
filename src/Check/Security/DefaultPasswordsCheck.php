<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Security;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use User;

class DefaultPasswordsCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'security.default_passwords';
    }

    public function getCategory(): string
    {
        return Category::SECURITY;
    }

    public function getTitle(): string
    {
        return __('Contas padrão com senha padrão', 'healthcheck');
    }

    public function run(): CheckResult
    {
        // Uses GLPI's own check (glpi/glpi, tech/tech, normal/normal, post-only/postonly)
        $logins = User::checkDefaultPasswords();

        if (count($logins) === 0) {
            return CheckResult::ok(__('Nenhuma conta padrão ativa utiliza a senha padrão.', 'healthcheck'));
        }

        $items = array_map(static fn ($login) => ['login' => $login], $logins);

        return CheckResult::critical(
            sprintf(__('%d conta(s) padrão ainda utilizam a senha de instalação: %s.', 'healthcheck'), count($logins), implode(', ', $logins)),
            Severity::CRITICAL
        )
            ->impact(__('Qualquer pessoa que conheça o GLPI pode entrar com essas contas; a conta "glpi" é super-administradora.', 'healthcheck'))
            ->cause(__('As senhas das contas criadas pela instalação nunca foram alteradas.', 'healthcheck'))
            ->recommendation(__('Altere imediatamente as senhas ou desative/exclua as contas não utilizadas.', 'healthcheck'))
            ->steps([
                __('Acesse Administração > Usuários e abra cada conta listada.', 'healthcheck'),
                __('Defina uma senha forte ou desmarque "Ativo".', 'healthcheck'),
                __('Prefira contas nominais para administradores, mantendo a conta "glpi" desativada.', 'healthcheck'),
            ])
            ->doc(self::DOC_INSTALL)
            ->items($items, ['login' => __('Login', 'healthcheck')]);
    }
}
