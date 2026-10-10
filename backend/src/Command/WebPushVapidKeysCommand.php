<?php

namespace App\Command;

use App\Service\WebPush\WebPushClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Génère la paire de clés VAPID qui identifie le serveur auprès des services
 * push des navigateurs. À faire UNE SEULE FOIS : changer les clés plus tard
 * invalide tous les abonnements existants (les adhérents devraient se
 * réabonner).
 */
#[AsCommand(name: 'app:push:vapid-keys', description: 'Génère les clés VAPID des notifications push web (à copier dans .env.local).')]
class WebPushVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ec = $key !== false ? (openssl_pkey_get_details($key)['ec'] ?? null) : null;
        if (!is_array($ec)) {
            $output->writeln('<error>Génération impossible : l\'extension PHP openssl ne gère pas les courbes elliptiques.</error>');
            return Command::FAILURE;
        }

        // x, y et d peuvent perdre leurs zéros de tête : on les remet sur 32 octets.
        $public = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $private = str_pad($ec['d'], 32, "\0", STR_PAD_LEFT);

        $output->writeln('Ajoutez ces lignes à backend/.env.local (puis, si ce fichier existe, regénérez .env.local.php avec « composer dump-env prod ») :');
        $output->writeln('');
        $output->writeln('VAPID_PUBLIC_KEY='.WebPushClient::b64uEncode($public));
        $output->writeln('VAPID_PRIVATE_KEY='.WebPushClient::b64uEncode($private));
        $output->writeln('VAPID_SUBJECT=mailto:contact@triathlontoulousemetropole.com');
        $output->writeln('');
        $output->writeln('<comment>La clé privée est secrète : ne la commitez pas. Ne régénérez pas ces clés une fois les adhérents abonnés.</comment>');

        return Command::SUCCESS;
    }
}
