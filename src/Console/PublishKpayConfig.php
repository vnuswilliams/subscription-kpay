<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class PublishKpayConfig extends Command
{
    protected $signature = 'kpay:publish-config';

    protected $description = 'Publie le fichier de configuration KPay en demandant si l\'on doit écraser les fichiers existants';

    public function handle(Filesystem $files): int
    {
        $target = config_path('kpay.php');

        if ($files->exists($target)) {
            if (! $this->confirm("Le fichier de configuration 'kpay.php' existe déjà. Voulez-vous l\'écraser ?", false)) {
                $this->info('Opération annulée — aucun fichier écrasé.');
                return 0;
            }
        }

        $this->call('vendor:publish', [
            '--provider' => \Vnuswilliams\SubscriptionKpay\SubscriptionKpayServiceProvider::class,
            '--tag' => 'kpay-config',
            '--force' => true,
        ]);

        $this->info('Fichier de configuration publié.');

        return 0;
    }
}
