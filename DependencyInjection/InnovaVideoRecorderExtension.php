<?php

namespace Innova\VideoRecorderBundle\DependencyInjection;

use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\Config\FileLocator;

class InnovaVideoRecorderExtension extends Extension implements CompilerPassInterface
{

    private $searched = 'Innova\VideoRecorderBundle\InnovaVideoRecorderBundle';
    private $iniFilePath = __DIR__.'/../../../../app/config/bundles.ini';
    /*
    * {@inheritDoc}
    */
    public function process(ContainerBuilder $container)
    {
        // checks that libav-tools is installed on the Caroline host
        // if not it should disable the plugin in the app/config/bundles.ini file
        $cmd = 'avconv -version';
        exec($cmd, $output, $returnVar);
        if (count($output) === 0 || $returnVar !== 0) {
            $message = 'avconv is not installed';
            $this->disablePlugin();
        }
    }

    private function disablePlugin()
    {
        $bundles = parse_ini_file($this->iniFilePath);
        $res = [];
        foreach ($bundles as $key => $val) {
            $value = $val === '1' ? 'true' : 'false';
            if ($key === $this->searched) {
                array_push($res, $key.' = false');
            } else {
                array_push($res, $key.' = '.$value);
            }
        }

        $this->safefilerewrite($this->iniFilePath, implode("\r\n", $res));
    }

    /**
    * Safely rewrite file
    **/
    private function safefilerewrite($fileName, $dataToSave)
    {
        if ($fp = fopen($fileName, 'w')) {
            $startTime = microtime();
            do {
                $canWrite = flock($fp, LOCK_EX);
               // If lock not obtained sleep for 0 - 100 milliseconds, to avoid collision and CPU load
               if (!$canWrite) {
                   usleep(round(rand(0, 100) * 1000));
               }
            } while ((!$canWrite) and ((microtime() - $startTime) < 1000));

            //file was locked so now we can store information
            if ($canWrite) {
                fwrite($fp, $dataToSave);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $locator = new FileLocator(__DIR__.'/../Resources/config');
        $loader = new YamlFileLoader($container, $locator);
    }
}
