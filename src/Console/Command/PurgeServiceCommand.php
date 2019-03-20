<?php
declare(strict_types=1);

namespace duckpony\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use SystemCtl\Unit\Service;
use SystemCtl\SystemCtl;

class PurgeServiceCommand extends AbstractCommand
{
    protected $unitName;
    protected $pattern;

    protected function configure()
    {
        $this->addArgument('folder', InputArgument::REQUIRED, 'Deployment folder as reference');
        $this->addOption('unit', 'u', InputOption::VALUE_REQUIRED, 'Name of unit');
        $this->addOption('pattern', 'p', InputOption::VALUE_REQUIRED, 'Instance pattern');
        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Config', $this->getRootPath() . '/config/config.yml');

        $this->setName('service:purge')
            ->setDescription('Scan folder an purge services with same name')
            ->setHelp(
                <<<EOT
Disables and stops systemd services that have
no reference folder in given folder argument
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $folder = stream_resolve_include_path($input->getArgument('folder'));
        $config = Yaml::parse(file_get_contents($input->getOption('config')));
        $this->unitName = $input->getOption('unit');
        $this->pattern = $input->getOption('pattern') ?? $config['PurgeService']['pattern'];

        $finder = new Finder();
        $directories = $finder->depth(0)->directories()->in($folder);

        SystemCtl::setTimeout(10);
        $systemCtl = new SystemCtl();

        $services = array_filter($systemCtl->getServices($this->unitName), [$this, 'filterServices']);
        $directories->filter(function(\SplFileInfo $dir) {
            return (bool)preg_match($this->pattern, $dir->getFilename());
        });

        $dirList = [];
        foreach ($directories->directories() as $dir) {
            $dirList[] = $dir->getFileName();
        }

        /** @var Service[] $removeServices */
        $removeServices = array_filter($services, function(Service $service) use ($dirList) {
            $serviceName = $service->isMultiInstance() ? $service->getInstanceName() : $service->getName();
            return !in_array($serviceName, $dirList, true);
        });

        $io->title('Remove services');
        $io->progressStart(count($removeServices));
        foreach ($removeServices as $service) {
            $service->disable();
            $service->stop();
            $io->progressAdvance();
        }

        $io->progressFinish();

    }

    private function filterServices(Service $service)
    {
        if (strpos($service->getName(), $this->unitName) === false) {
            return false;
        }

        return preg_match($this->pattern, $service->getName());
    }
}
