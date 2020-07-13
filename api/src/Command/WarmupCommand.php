<?php

// src/Command/CreateUserCommand.php

namespace App\Command;

use App\Service\HealthService;
use App\Service\KadasterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class WarmupCommand extends Command
{
    private $em;
    private $kadasterService;

    public function __construct(EntityManagerInterface $em, KadasterService $kadasterService)
    {
        $this->em = $em;
        $this->kadasterService = $kadasterService;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
        ->setName('app:cache:warmup')
        // the short description shown while running "php bin/console list"
        ->setDescription('Warms up the cache with slow requests.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command warmup the cache for addresses that are notoriously slow to load')
        ->setDescription('Warm up the cache')
        ->addOption('houseNumber', null, InputOption::VALUE_OPTIONAL, 'the house number of the objects to cache')
        ->addOption('postcode', null, InputOption::VALUE_OPTIONAL, 'the postcode of the objects to cache');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        if($input->getOption('houseNumber') && $input->getOption('postcode')){
            $houseNumber = $input->getOption('houseNumber');
            $postcode = $input->getOption('postcode');
        }else{
            $postcode='5382JX';
            $houseNumber=1;
        }


        /** @var string $version */
        $io->text("Warming up cache for postcode $postcode with house number $houseNumber");
        $this->kadasterService->getAdresOnHuisnummerPostcode($houseNumber, $postcode);
        $io->text("Cache warmed up with postcode $postcode and house number $houseNumber");
    }
}
